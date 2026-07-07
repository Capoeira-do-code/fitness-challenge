#!/usr/bin/env python3
"""Standalone Telegram bot for Fitness Challenge Tracker.

Runs as an always-on process (unlike the PHP scheduler, which only fires on page
loads), reading config and users from the same SQLite database the web app uses:

- Links users who open the deep link and send "/start <code>" (instant, long-poll).
- Sends each linked user a daily reminder (only if the day is still unlogged) and
  an optional motivation message, at their configured time.

Config (bot token, per-user preferences) is managed from the web app
(Admin > App and Settings). Enable "Use the standalone Python bot" in Admin > App
so the PHP app stops polling/sending and this process owns all Telegram I/O.

Standard library only, no dependencies. Requires outbound HTTPS.

Usage:
  python bin/telegram_bot.py            # run forever
  python bin/telegram_bot.py --once     # one poll + reminder pass, then exit (cron)
  python bin/telegram_bot.py --verbose
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sqlite3
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime
from pathlib import Path
from typing import Optional

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_DB = ROOT / "storage" / "fitness.sqlite"
TELEGRAM_API_BASE = "https://api.telegram.org"
POLL_TIMEOUT = 20           # long-poll seconds
REMINDER_INTERVAL = 60      # seconds between reminder passes
MAX_SENDS_PER_PASS = 25

MESSAGES = {
    "linked": {
        "en": "Linked! You will get reminders and motivation here. Manage this in Settings.",
        "es": "Vinculado! Recibiras recordatorios y motivacion aqui. Gestionalo en Ajustes.",
        "it": "Collegato! Riceverai promemoria e motivazione qui. Gestisci tutto in Impostazioni.",
    },
    "reminder": {
        "en": "{name}, you have not logged your training today. Open the app and note your day.",
        "es": "{name}, todavia no registraste tu entreno de hoy. Abre la app y anota tu dia.",
        "it": "{name}, non hai ancora registrato il tuo allenamento di oggi. Apri l'app e annota la giornata.",
    },
    "motivation": {
        "en": "Motivation for today: {quote}",
        "es": "Motivacion de hoy: {quote}",
        "it": "Motivazione di oggi: {quote}",
    },
}


def resolve_tz(name: str):
    try:
        from zoneinfo import ZoneInfo

        return ZoneInfo(name)
    except Exception:
        return None


TZ = resolve_tz(os.environ.get("APP_TIMEZONE", "Europe/Madrid"))


def now() -> datetime:
    return datetime.now(TZ) if TZ is not None else datetime.now()


def now_iso() -> str:
    return now().strftime("%Y-%m-%d %H:%M:%S")


def log(message: str) -> None:
    print(f"[{now_iso()}] {message}", flush=True)


def message_for(locale: str, key: str, **params) -> str:
    locale = (locale or "en").lower()
    table = MESSAGES.get(key, {})
    template = table.get(locale) or table.get("en", "")
    for name, value in params.items():
        template = template.replace("{" + name + "}", str(value))
    return template


class BotDB:
    def __init__(self, path: Path):
        self.conn = sqlite3.connect(str(path), timeout=10)
        self.conn.row_factory = sqlite3.Row
        self.conn.execute("PRAGMA busy_timeout = 5000")

    def setting(self, key: str, default: str = "") -> str:
        row = self.conn.execute(
            "SELECT setting_value FROM app_settings WHERE setting_key = ?", (key,)
        ).fetchone()
        if row is None or row["setting_value"] is None:
            return default
        return str(row["setting_value"])

    def set_setting(self, key: str, value: str) -> None:
        self.conn.execute(
            "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) "
            "ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, "
            "updated_at = excluded.updated_at",
            (key, value, now_iso()),
        )
        self.conn.commit()

    def settings(self) -> dict:
        return {
            "enabled": self.setting("telegram_enabled", "0") in ("1", "true", "yes", "on"),
            "token": self.setting("telegram_bot_token", "").strip(),
            "username": self.setting("telegram_bot_username", "").strip(),
            "external": self.setting("telegram_external_bot", "0") in ("1", "true", "yes", "on"),
            "offset": int(self.setting("telegram_update_offset", "0") or "0"),
        }

    def user_by_link_code(self, code: str) -> Optional[sqlite3.Row]:
        return self.conn.execute(
            "SELECT * FROM users WHERE telegram_link_code = ?", (code,)
        ).fetchone()

    def link_user(self, user_id: int, chat_id: str) -> None:
        self.conn.execute(
            "UPDATE users SET telegram_chat_id = ?, telegram_link_code = NULL, "
            "telegram_reminders_enabled = 1, telegram_motivation_enabled = 1, updated_at = ? "
            "WHERE id = ?",
            (chat_id, now_iso(), user_id),
        )
        self.conn.commit()

    def reminder_candidates(self) -> list[sqlite3.Row]:
        return self.conn.execute(
            "SELECT * FROM users WHERE active = 1 "
            "AND telegram_chat_id IS NOT NULL AND TRIM(telegram_chat_id) <> '' "
            "AND (telegram_reminders_enabled = 1 OR telegram_motivation_enabled = 1)"
        ).fetchall()

    def logged_today(self, user_id: int, date: str) -> bool:
        row = self.conn.execute(
            "SELECT id FROM daily_logs WHERE user_id = ? AND log_date = ? "
            "AND (COALESCE(steps, 0) > 0 OR workout_done = 1 OR COALESCE(distance_km, 0) > 0 "
            "OR weight IS NOT NULL OR COALESCE(TRIM(notes), '') <> '')",
            (user_id, date),
        ).fetchone()
        return row is not None

    def pick_quote(self, user: sqlite3.Row) -> str:
        own = str(user["motivation_quote"] or "").strip() if "motivation_quote" in user.keys() else ""
        if own:
            return own
        row = self.conn.execute(
            "SELECT quote_text FROM motivational_quotes WHERE active = 1 ORDER BY RANDOM() LIMIT 1"
        ).fetchone()
        return str(row["quote_text"]).strip() if row else ""

    def mark_reminded(self, user_id: int, date: str) -> None:
        self.conn.execute(
            "UPDATE users SET telegram_last_reminded_on = ? WHERE id = ?", (date, user_id)
        )
        self.conn.commit()

    def mark_motivated(self, user_id: int, date: str) -> None:
        self.conn.execute(
            "UPDATE users SET telegram_last_motivation_on = ? WHERE id = ?", (date, user_id)
        )
        self.conn.commit()


def api_call(token: str, method: str, params: Optional[dict] = None, timeout: int = 25):
    if not token:
        return None, "missing_token"
    url = f"{TELEGRAM_API_BASE}/bot{token}/{method}"
    data = json.dumps(params or {}).encode("utf-8")
    request = urllib.request.Request(url, data=data, headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            body = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        try:
            body = json.loads(exc.read().decode("utf-8"))
        except Exception:
            return None, f"http_{exc.code}"
    except Exception as exc:
        return None, str(exc)
    if not body.get("ok"):
        return None, str(body.get("description", "telegram_error"))
    return body.get("result"), None


def send_message(token: str, chat_id: str, text: str) -> tuple[bool, Optional[str]]:
    result, error = api_call(
        token, "sendMessage", {"chat_id": chat_id, "text": text, "disable_web_page_preview": True}
    )
    return (error is None), error


def ensure_no_webhook(token: str) -> None:
    """getUpdates and webhooks are mutually exclusive; drop any stale webhook so
    long-polling works."""
    info, error = api_call(token, "getWebhookInfo")
    if error is None and isinstance(info, dict) and str(info.get("url") or "") == "":
        return
    _, error = api_call(token, "deleteWebhook", {"drop_pending_updates": False})
    if error is None:
        log("cleared an active webhook so getUpdates polling can work")
    else:
        log(f"deleteWebhook failed: {error}")


def process_update(db: BotDB, settings: dict, update: dict) -> None:
    message = update.get("message") or {}
    text = str(message.get("text") or "").strip()
    chat = message.get("chat") or {}
    chat_id = str(chat.get("id") or "")
    if not text or not chat_id:
        return
    match = re.match(r"^/start\s+(\S+)", text)
    if not match:
        return
    user = db.user_by_link_code(match.group(1))
    if user is None:
        return
    db.link_user(int(user["id"]), chat_id)
    ok, error = send_message(settings["token"], chat_id, message_for(user["locale"], "linked"))
    log(f"linked user #{user['id']} ({user['username']}) chat={chat_id}"
        + ("" if ok else f" (confirmation send failed: {error})"))


def poll_once(db: BotDB, settings: dict, timeout: int) -> None:
    result, error = api_call(
        settings["token"], "getUpdates",
        {"offset": settings["offset"], "timeout": timeout, "allowed_updates": ["message"]},
        timeout=timeout + 10,
    )
    if error is not None:
        log(f"getUpdates failed: {error}")
        return
    max_offset = settings["offset"]
    for update in result or []:
        update_id = int(update.get("update_id", 0))
        if update_id >= max_offset:
            max_offset = update_id + 1
        try:
            process_update(db, settings, update)
        except Exception as exc:  # noqa: BLE001
            log(f"update error: {exc}")
    if max_offset != settings["offset"]:
        db.set_setting("telegram_update_offset", str(max_offset))
        settings["offset"] = max_offset


def run_reminders(db: BotDB, settings: dict) -> None:
    current = now()
    today = current.strftime("%Y-%m-%d")
    now_hm = current.strftime("%H:%M")
    sends = 0
    for user in db.reminder_candidates():
        if sends >= MAX_SENDS_PER_PASS:
            break
        reminder_time = str(user["telegram_reminder_time"] or "20:00")
        if now_hm < reminder_time:
            continue
        chat_id = str(user["telegram_chat_id"])
        user_id = int(user["id"])
        logged = db.logged_today(user_id, today)

        if (int(user["telegram_reminders_enabled"] or 0) == 1
                and str(user["telegram_last_reminded_on"] or "") != today
                and not logged):
            text = message_for(user["locale"], "reminder", name=str(user["display_name"] or ""))
            ok, error = send_message(settings["token"], chat_id, text)
            if ok:
                sends += 1
                db.mark_reminded(user_id, today)
                log(f"reminder -> #{user_id} ({user['username']})")
            else:
                log(f"reminder send failed -> #{user_id}: {error}")

        if (int(user["telegram_motivation_enabled"] or 0) == 1
                and str(user["telegram_last_motivation_on"] or "") != today):
            quote = db.pick_quote(user)
            if quote:
                text = message_for(user["locale"], "motivation", quote=quote)
                ok, error = send_message(settings["token"], chat_id, text)
                if ok:
                    sends += 1
                    db.mark_motivated(user_id, today)
                    log(f"motivation -> #{user_id} ({user['username']})")
                else:
                    log(f"motivation send failed -> #{user_id}: {error}")


def run_forever(db_path: Path, verbose: bool) -> int:
    log(f"Fitness Challenge Telegram bot starting. DB: {db_path}")
    log(f"Timezone: {TZ if TZ is not None else 'system local (zoneinfo unavailable)'}")
    last_reminder = 0.0
    warned_external = False
    webhook_checked = False
    while True:
        try:
            db = BotDB(db_path)
            settings = db.settings()
            if not settings["enabled"] or not settings["token"]:
                if verbose:
                    log("bot disabled or no token; waiting...")
                db.conn.close()
                time.sleep(10)
                continue
            if not webhook_checked:
                ensure_no_webhook(settings["token"])
                log("polling for Telegram updates... (link users from Settings, then press Start)")
                webhook_checked = True
            if not settings["external"] and not warned_external:
                log("WARNING: 'Use the standalone Python bot' is OFF in Admin > App. "
                    "Enable it so the PHP app stops polling and does not double-send.")
                warned_external = True

            poll_once(db, settings, POLL_TIMEOUT)

            if time.time() - last_reminder >= REMINDER_INTERVAL:
                run_reminders(db, settings)
                last_reminder = time.time()

            db.conn.close()
        except KeyboardInterrupt:
            log("stopping (keyboard interrupt).")
            return 0
        except Exception as exc:  # noqa: BLE001
            log(f"loop error: {exc}")
            time.sleep(5)


def run_once(db_path: Path, verbose: bool) -> int:
    db = BotDB(db_path)
    settings = db.settings()
    if not settings["enabled"] or not settings["token"]:
        log("bot disabled or no token; nothing to do.")
        db.conn.close()
        return 0
    ensure_no_webhook(settings["token"])
    poll_once(db, settings, 0)
    run_reminders(db, settings)
    db.conn.close()
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Standalone Telegram bot for Fitness Challenge Tracker.")
    parser.add_argument("--once", action="store_true", help="Run one poll + reminder pass, then exit (cron-friendly).")
    parser.add_argument("--db", default="", help="Path to the SQLite database (default: DB_PATH env or storage/fitness.sqlite).")
    parser.add_argument("--verbose", action="store_true", help="Verbose logging.")
    args = parser.parse_args()

    db_path = Path(args.db).resolve() if args.db else Path(os.environ.get("DB_PATH", str(DEFAULT_DB))).resolve()
    if not db_path.exists():
        log(f"Database not found: {db_path}. Start the app once to create it, or pass --db.")
        return 2

    if args.once:
        return run_once(db_path, args.verbose)
    return run_forever(db_path, args.verbose)


if __name__ == "__main__":
    sys.exit(main())
