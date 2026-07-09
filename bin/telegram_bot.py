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
import math
from datetime import datetime, timedelta
from pathlib import Path
from typing import Optional

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_DB = ROOT / "storage" / "fitness.sqlite"
TELEGRAM_API_BASE = "https://api.telegram.org"
POLL_TIMEOUT = 20           # long-poll seconds
REMINDER_INTERVAL = 60      # seconds between reminder passes
MAX_SENDS_PER_PASS = 25
MAX_REMINDERS_PER_DAY = 3   # first nudge + up to 2 follow-ups while still unlogged
FOLLOWUP_MINUTES = 90       # gap between follow-up nudges
ADD_DATA_PATH = "/?page=entries&mode=data"

MESSAGES = {
    "en": {
        "linked": "Linked! You will get reminders and motivation here.\nType /progress anytime to see how you are doing.",
        "invalid_link": "This Telegram link is invalid or expired. Open Settings in the app and press Link Telegram again.",
        "not_linked": "Your Telegram is not linked yet. Open Settings in the app and press Link Telegram.",
        "no_challenge": "There is no active challenge right now.",
        "rem1": "{name}, you have not logged your day yet. One minute and you are done.",
        "rem2": "{name}, your log for today is still pending. Do not let it slip.",
        "rem3": "{name}, last nudge today: keep your streak alive.",
        "cta": "Log now:",
        "progress_head": "Your progress, {name}",
        "motivation": "Motivation for today: {quote}",
        "motivation_progress": "You are at {pct}% consistency. Keep going!",
        "help": "Commands:\n/progress - your challenge progress\n/week - this week so far\n/streak - your current streak\n/today - is today logged?\n/ranking - team ranking\n/help - this help",
        "l_streak": "Streak", "l_logged": "Days logged", "l_steps": "Steps",
        "l_workouts": "Workouts", "l_stepgoal": "Step goal", "l_remaining": "Remaining",
        "u_days": "days", "u_avg": "avg", "u_perday": "/day",
        "week_head": "This week, {name}", "l_week": "This week",
        "streak_msg": "{name}, your streak is {streak} days. {tip}",
        "streak_tip_on": "Keep it alive!", "streak_tip_off": "Log today to start a new one.",
        "today_logged": "Today is logged. Nice work, {name}.",
        "today_not_logged": "Today is NOT logged yet, {name}.",
        "ranking_head": "Team ranking", "ranking_empty": "No data to rank yet.",
        "milestone": "{name}, {streak}-day streak! You are on fire. Keep going!",
    },
    "es": {
        "linked": "Vinculado! Recibiras recordatorios y motivacion aqui.\nEscribe /progress cuando quieras ver como vas.",
        "invalid_link": "Este enlace de Telegram no es valido o ha caducado. Abre Ajustes en la app y pulsa Vincular Telegram otra vez.",
        "not_linked": "Tu Telegram no esta vinculado. Ve a Ajustes en la app y pulsa Vincular Telegram.",
        "no_challenge": "No hay un challenge activo ahora mismo.",
        "rem1": "{name}, aun no registraste tu dia. Un minuto y listo.",
        "rem2": "{name}, sigue pendiente tu registro de hoy. No lo dejes pasar.",
        "rem3": "{name}, ultimo aviso de hoy: no rompas tu racha.",
        "cta": "Registrar ahora:",
        "progress_head": "Tu progreso, {name}",
        "motivation": "Motivacion de hoy: {quote}",
        "motivation_progress": "Vas al {pct}% de constancia. Sigue asi!",
        "help": "Comandos:\n/progress - tu progreso del challenge\n/week - tu semana hasta ahora\n/streak - tu racha actual\n/today - has registrado hoy?\n/ranking - ranking del equipo\n/help - esta ayuda",
        "l_streak": "Racha", "l_logged": "Dias registrados", "l_steps": "Pasos",
        "l_workouts": "Entrenos", "l_stepgoal": "Meta de pasos", "l_remaining": "Quedan",
        "u_days": "dias", "u_avg": "media", "u_perday": "/dia",
        "week_head": "Tu semana, {name}", "l_week": "Esta semana",
        "streak_msg": "{name}, tu racha es de {streak} dias. {tip}",
        "streak_tip_on": "Mantenla viva!", "streak_tip_off": "Registra hoy para empezar una nueva.",
        "today_logged": "Hoy ya esta registrado. Bien hecho, {name}.",
        "today_not_logged": "Hoy AUN no esta registrado, {name}.",
        "ranking_head": "Ranking del equipo", "ranking_empty": "Aun no hay datos para el ranking.",
        "milestone": "{name}, racha de {streak} dias! Imparable. Sigue asi!",
    },
    "it": {
        "linked": "Collegato! Riceverai promemoria e motivazione qui.\nScrivi /progress per vedere come stai andando.",
        "invalid_link": "Questo link Telegram non e valido o e scaduto. Apri Impostazioni nell'app e premi Collega Telegram di nuovo.",
        "not_linked": "Il tuo Telegram non e collegato. Apri Impostazioni nell'app e premi Collega Telegram.",
        "no_challenge": "Non c'e una challenge attiva al momento.",
        "rem1": "{name}, non hai ancora registrato la giornata. Un minuto e hai finito.",
        "rem2": "{name}, la registrazione di oggi e ancora in sospeso. Non lasciarla perdere.",
        "rem3": "{name}, ultimo promemoria di oggi: non spezzare la tua striscia.",
        "cta": "Registra ora:",
        "progress_head": "I tuoi progressi, {name}",
        "motivation": "Motivazione di oggi: {quote}",
        "motivation_progress": "Sei al {pct}% di costanza. Continua cosi!",
        "help": "Comandi:\n/progress - i tuoi progressi\n/week - la tua settimana\n/streak - la tua striscia\n/today - hai registrato oggi?\n/ranking - classifica del team\n/help - questo aiuto",
        "week_head": "La tua settimana, {name}", "l_week": "Questa settimana",
        "streak_msg": "{name}, la tua striscia e di {streak} giorni. {tip}",
        "streak_tip_on": "Tienila viva!", "streak_tip_off": "Registra oggi per iniziarne una nuova.",
        "today_logged": "Oggi e registrato. Ottimo, {name}.",
        "today_not_logged": "Oggi NON e ancora registrato, {name}.",
        "ranking_head": "Classifica del team", "ranking_empty": "Ancora nessun dato per la classifica.",
        "milestone": "{name}, striscia di {streak} giorni! Inarrestabile. Continua!",
        "l_streak": "Striscia", "l_logged": "Giorni registrati", "l_steps": "Passi",
        "l_workouts": "Allenamenti", "l_stepgoal": "Obiettivo passi", "l_remaining": "Restano",
        "u_days": "giorni", "u_avg": "media", "u_perday": "/giorno",
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


def T(locale: str, key: str, **params) -> str:
    locale = (locale or "en").lower()
    table = MESSAGES.get(locale) or MESSAGES["en"]
    template = table.get(key) or MESSAGES["en"].get(key, key)
    for name, value in params.items():
        template = template.replace("{" + name + "}", str(value))
    return template


def fmt_int(value) -> str:
    try:
        return f"{int(round(float(value))):,}".replace(",", ".")
    except (TypeError, ValueError):
        return "0"


class BotDB:
    def __init__(self, path: Path):
        self.conn = sqlite3.connect(str(path), timeout=10)
        self.conn.row_factory = sqlite3.Row
        self.conn.execute("PRAGMA busy_timeout = 5000")
        self.conn.execute(
            "CREATE TABLE IF NOT EXISTS telegram_progress ("
            "user_id INTEGER PRIMARY KEY, last_streak_milestone INTEGER DEFAULT 0)"
        )
        self.conn.commit()

    def milestone(self, user_id: int) -> int:
        row = self.conn.execute(
            "SELECT last_streak_milestone FROM telegram_progress WHERE user_id = ?", (user_id,)
        ).fetchone()
        return int(row["last_streak_milestone"]) if row else 0

    def set_milestone(self, user_id: int, value: int) -> None:
        self.conn.execute(
            "INSERT INTO telegram_progress (user_id, last_streak_milestone) VALUES (?, ?) "
            "ON CONFLICT(user_id) DO UPDATE SET last_streak_milestone = excluded.last_streak_milestone",
            (user_id, value),
        )
        self.conn.commit()

    def all_active_users(self) -> list[sqlite3.Row]:
        return self.conn.execute("SELECT * FROM users WHERE active = 1").fetchall()

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
        base_url = self.setting("app_base_url", "").strip().rstrip("/")
        if not base_url:
            base_url = os.environ.get("APP_BASE_URL", "").strip().rstrip("/")
        return {
            "enabled": self.setting("telegram_enabled", "0") in ("1", "true", "yes", "on"),
            "token": self.setting("telegram_bot_token", "").strip(),
            "username": self.setting("telegram_bot_username", "").strip(),
            "external": self.setting("telegram_external_bot", "0") in ("1", "true", "yes", "on"),
            "offset": int(self.setting("telegram_update_offset", "0") or "0"),
            "base_url": base_url,
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
        locale = str(user["locale"] or "en").strip().lower() if "locale" in user.keys() else "en"
        # Prefer a quote in the user's language (or one tagged for all languages).
        # The locale column is added lazily by the app, so degrade gracefully.
        try:
            row = self.conn.execute(
                "SELECT quote_text FROM motivational_quotes WHERE active = 1 "
                "AND (locale = ? OR locale = 'any') ORDER BY RANDOM() LIMIT 1",
                (locale,),
            ).fetchone()
            if row is None:
                row = self.conn.execute(
                    "SELECT quote_text FROM motivational_quotes WHERE active = 1 ORDER BY RANDOM() LIMIT 1"
                ).fetchone()
        except sqlite3.OperationalError:
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

    def mark_reminder_sent(self, user_id: int, when: str, count: int, date: str) -> None:
        self.conn.execute(
            "UPDATE users SET telegram_last_reminded_at = ?, telegram_reminder_count = ?, "
            "telegram_last_reminded_on = ? WHERE id = ?",
            (when, count, date, user_id),
        )
        self.conn.commit()

    def user_by_chat_id(self, chat_id: str) -> Optional[sqlite3.Row]:
        return self.conn.execute(
            "SELECT * FROM users WHERE telegram_chat_id = ? AND active = 1", (chat_id,)
        ).fetchone()

    def challenge_range(self) -> Optional[tuple[str, str]]:
        row = self.conn.execute(
            "SELECT challenge_start, challenge_end FROM challenge_settings WHERE id = 1"
        ).fetchone()
        if row is None:
            return None
        start = str(row["challenge_start"] or "").strip()
        end = str(row["challenge_end"] or "").strip()
        if not start or not end:
            return None
        return start, end

    def logs_between(self, user_id: int, start: str, end: str) -> list[sqlite3.Row]:
        return self.conn.execute(
            "SELECT * FROM daily_logs WHERE user_id = ? AND log_date BETWEEN ? AND ? "
            "ORDER BY log_date ASC",
            (user_id, start, end),
        ).fetchall()

    def outbox_ensure(self) -> None:
        self.conn.execute(
            "CREATE TABLE IF NOT EXISTS telegram_outbox ("
            "id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, "
            "text TEXT NOT NULL, created_at TEXT NOT NULL, sent_at TEXT)"
        )
        self.conn.commit()

    def outbox_pending(self, limit: int = 25) -> list[sqlite3.Row]:
        self.outbox_ensure()
        return self.conn.execute(
            "SELECT * FROM telegram_outbox WHERE sent_at IS NULL ORDER BY id ASC LIMIT ?",
            (limit,),
        ).fetchall()

    def chat_id_for(self, user_id: int) -> str:
        row = self.conn.execute(
            "SELECT telegram_chat_id FROM users WHERE id = ?", (user_id,)
        ).fetchone()
        return str(row["telegram_chat_id"]).strip() if row and row["telegram_chat_id"] else ""

    def mark_outbox_sent(self, outbox_id: int) -> None:
        self.conn.execute(
            "UPDATE telegram_outbox SET sent_at = ? WHERE id = ?", (now_iso(), outbox_id)
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


def claim_ownership(db: BotDB, settings: dict) -> None:
    """Verify the configured bot token and cache its username for deep links."""
    token = str(settings.get("token") or "").strip()
    if not token:
        return

    info, error = api_call(token, "getMe")
    if error is not None:
        log(f"bot ownership check failed: {error}")
        return
    if not isinstance(info, dict):
        log("bot ownership check returned an unexpected response")
        return

    username = str(info.get("username") or "").strip()
    if username:
        db.set_setting("telegram_bot_username", username)
        settings["username"] = username
        log(f"connected to Telegram bot @{username}")
    else:
        log("connected to Telegram bot but no username was returned")


def rget(row, key, default=None):
    try:
        return row[key] if key in row.keys() else default
    except Exception:
        return default


def _parse_date(value):
    try:
        return datetime.strptime(str(value)[:10], "%Y-%m-%d").date()
    except (TypeError, ValueError):
        return None


def _parse_dt(value):
    try:
        return datetime.strptime(str(value)[:19], "%Y-%m-%d %H:%M:%S")
    except (TypeError, ValueError):
        return None


def _log_is_meaningful(row) -> bool:
    return (
        int(rget(row, "steps", 0) or 0) > 0
        or int(rget(row, "workout_done", 0) or 0) == 1
        or float(rget(row, "distance_km", 0) or 0) > 0
        or rget(row, "weight") is not None
        or str(rget(row, "notes", "") or "").strip() != ""
    )


def compute_stats(db: BotDB, user: sqlite3.Row) -> Optional[dict]:
    rng = db.challenge_range()
    if rng is None:
        return None
    start, end = _parse_date(rng[0]), _parse_date(rng[1])
    if start is None or end is None:
        return None
    today = now().date()
    if today < start:
        return {"not_started": True, "days_total": (end - start).days + 1,
                "days_remaining": (end - today).days}
    eff_end = min(today, end)
    days_total = (end - start).days + 1
    days_elapsed = (eff_end - start).days + 1
    days_remaining = max(0, (end - today).days) if today <= end else 0

    user_id = int(user["id"])
    step_goal = int(rget(user, "step_goal", 0) or 0)
    workout_target = int(rget(user, "workout_target", 0) or 0)

    total_steps = 0
    total_km = 0.0
    total_workouts = 0
    step_goal_hits = 0
    logged_dates = set()
    for row in db.logs_between(user_id, rng[0], eff_end.strftime("%Y-%m-%d")):
        if not _log_is_meaningful(row):
            continue
        d = _parse_date(row["log_date"])
        if d:
            logged_dates.add(d)
        steps = int(rget(row, "steps", 0) or 0)
        total_steps += steps
        total_km += float(rget(row, "distance_km", 0) or 0)
        if int(rget(row, "workout_done", 0) or 0) == 1:
            total_workouts += 1
        if step_goal > 0 and steps >= step_goal:
            step_goal_hits += 1

    days_logged = len(logged_dates)
    consistency = round(days_logged / days_elapsed * 100) if days_elapsed else 0
    avg_steps = round(total_steps / days_elapsed) if days_elapsed else 0

    streak = 0
    cursor = today if today in logged_dates else today - timedelta(days=1)
    while cursor in logged_dates and cursor >= start:
        streak += 1
        cursor -= timedelta(days=1)

    weeks = max(1, math.ceil(days_elapsed / 7))
    expected_workouts = workout_target * weeks
    step_goal_pct = round(step_goal_hits / days_elapsed * 100) if (step_goal > 0 and days_elapsed) else None

    return {
        "not_started": False,
        "days_total": days_total, "days_elapsed": days_elapsed, "days_remaining": days_remaining,
        "days_logged": days_logged, "consistency": consistency, "streak": streak,
        "total_steps": total_steps, "avg_steps": avg_steps, "total_km": round(total_km, 1),
        "total_workouts": total_workouts, "expected_workouts": expected_workouts,
        "step_goal": step_goal, "step_goal_hits": step_goal_hits, "step_goal_pct": step_goal_pct,
    }


def stats_block(stats: Optional[dict], locale: str) -> str:
    if not stats or stats.get("not_started"):
        return ""
    days = T(locale, "u_days")
    lines = [
        f"\U0001F525 {T(locale, 'l_streak')}: {stats['streak']} {days}",
        f"\U0001F4C5 {T(locale, 'l_logged')}: {stats['days_logged']}/{stats['days_elapsed']} ({stats['consistency']}%)",
        f"\U0001F45F {T(locale, 'l_steps')}: {fmt_int(stats['total_steps'])} ({T(locale, 'u_avg')} {fmt_int(stats['avg_steps'])}{T(locale, 'u_perday')})",
    ]
    if stats["expected_workouts"] > 0:
        lines.append(f"\U0001F3CB️ {T(locale, 'l_workouts')}: {stats['total_workouts']}/{stats['expected_workouts']}")
    else:
        lines.append(f"\U0001F3CB️ {T(locale, 'l_workouts')}: {stats['total_workouts']}")
    if stats["step_goal_pct"] is not None:
        lines.append(f"\U0001F3AF {T(locale, 'l_stepgoal')}: {stats['step_goal_hits']}/{stats['days_elapsed']} {days} ({stats['step_goal_pct']}%)")
    lines.append(f"⏳ {T(locale, 'l_remaining')}: {stats['days_remaining']} {days}")
    return "\n".join(lines)


def add_data_url(settings: dict) -> str:
    base = str(settings.get("base_url") or "").strip().rstrip("/")
    return base + ADD_DATA_PATH if base else ""


def build_reminder(user: sqlite3.Row, stats: Optional[dict], settings: dict, attempt: int) -> str:
    locale = user["locale"]
    head = T(locale, f"rem{min(max(attempt, 1), 3)}", name=str(user["display_name"] or ""))
    parts = [head]
    block = stats_block(stats, locale)
    if block:
        parts.append(block)
    url = add_data_url(settings)
    if url:
        parts.append(f"{T(locale, 'cta')} {url}")
    return "\n\n".join(parts)


def build_progress(user: sqlite3.Row, stats: Optional[dict], locale: str) -> str:
    if not stats or stats.get("not_started"):
        return T(locale, "no_challenge")
    head = T(locale, "progress_head", name=str(user["display_name"] or ""))
    return head + "\n\n" + stats_block(stats, locale)


def build_motivation(user: sqlite3.Row, stats: Optional[dict], quote: str, locale: str) -> str:
    text = T(locale, "motivation", quote=quote)
    if stats and not stats.get("not_started"):
        text += "\n" + T(locale, "motivation_progress", pct=stats["consistency"])
    return text


STREAK_MILESTONES = (7, 14, 30, 60, 100)


def compute_week(db: BotDB, user: sqlite3.Row) -> dict:
    today = now().date()
    monday = today - timedelta(days=today.weekday())
    steps = 0
    workouts = 0
    dist = 0.0
    logged = set()
    for row in db.logs_between(int(user["id"]), monday.strftime("%Y-%m-%d"), today.strftime("%Y-%m-%d")):
        if not _log_is_meaningful(row):
            continue
        d = _parse_date(row["log_date"])
        if d:
            logged.add(d)
        steps += int(rget(row, "steps", 0) or 0)
        dist += float(rget(row, "distance_km", 0) or 0)
        if int(rget(row, "workout_done", 0) or 0) == 1:
            workouts += 1
    return {"steps": steps, "workouts": workouts, "distance": round(dist, 1),
            "days_logged": len(logged), "days_elapsed": (today - monday).days + 1}


def build_week(user: sqlite3.Row, wk: dict, locale: str) -> str:
    days = T(locale, "u_days")
    return "\n".join([
        T(locale, "week_head", name=str(user["display_name"] or "")),
        "",
        f"\U0001F4C5 {T(locale, 'l_logged')}: {wk['days_logged']}/{wk['days_elapsed']} {days}",
        f"\U0001F45F {T(locale, 'l_steps')}: {fmt_int(wk['steps'])}",
        f"\U0001F3CB️ {T(locale, 'l_workouts')}: {wk['workouts']}",
    ])


def build_streak(user: sqlite3.Row, stats: Optional[dict], locale: str) -> str:
    streak = stats["streak"] if stats and not stats.get("not_started") else 0
    tip = T(locale, "streak_tip_on" if streak > 0 else "streak_tip_off")
    return T(locale, "streak_msg", name=str(user["display_name"] or ""), streak=streak, tip=tip)


def build_today(db: BotDB, user: sqlite3.Row, settings: dict, locale: str) -> str:
    if db.logged_today(int(user["id"]), now().strftime("%Y-%m-%d")):
        return T(locale, "today_logged", name=str(user["display_name"] or ""))
    url = add_data_url(settings)
    msg = T(locale, "today_not_logged", name=str(user["display_name"] or ""))
    return msg + (f"\n{T(locale, 'cta')} {url}" if url else "")


def build_ranking(db: BotDB, locale: str) -> str:
    rows = []
    for user in db.all_active_users():
        st = compute_stats(db, user)
        if not st or st.get("not_started"):
            continue
        rows.append((str(user["display_name"] or user["username"] or "?"), st["consistency"], st["total_steps"]))
    if not rows:
        return T(locale, "ranking_empty")
    rows.sort(key=lambda r: (r[1], r[2]), reverse=True)
    medals = ["\U0001F947", "\U0001F948", "\U0001F949"]
    lines = [T(locale, "ranking_head"), ""]
    for i, (name, cons, steps) in enumerate(rows):
        prefix = medals[i] if i < 3 else f"{i + 1}."
        lines.append(f"{prefix} {name} - {cons}% - {fmt_int(steps)} {T(locale, 'l_steps').lower()}")
    return "\n".join(lines)


def check_streak_milestone(db: BotDB, user: sqlite3.Row, stats: Optional[dict], settings: dict) -> bool:
    if not stats or stats.get("not_started"):
        return False
    streak = int(stats["streak"])
    stored = db.milestone(int(user["id"]))
    if streak < STREAK_MILESTONES[0]:
        if stored != 0:
            db.set_milestone(int(user["id"]), 0)
        return False
    reached = max(m for m in STREAK_MILESTONES if m <= streak)
    if stored >= reached:
        return False
    text = T(user["locale"], "milestone", name=str(user["display_name"] or ""), streak=reached)
    ok, _ = send_message(settings["token"], str(user["telegram_chat_id"]), text)
    if ok:
        db.set_milestone(int(user["id"]), reached)
        log(f"milestone {reached}d -> #{user['id']} ({user['username']})")
    return ok


def update_locale(message: dict) -> str:
    sender = message.get("from") if isinstance(message, dict) else {}
    if not isinstance(sender, dict):
        sender = {}
    raw = str(sender.get("language_code") or "").strip().lower()
    locale = raw.split("-", 1)[0] if raw else "es"
    return locale if locale in MESSAGES else "es"


def process_update(db: BotDB, settings: dict, update: dict) -> None:
    message = update.get("message") or {}
    text = str(message.get("text") or "").strip()
    chat = message.get("chat") or {}
    chat_id = str(chat.get("id") or "")
    if not text or not chat_id:
        return

    # /start <code> links a Telegram chat to an app user.
    match = re.match(r"^/start(?:@\w+)?\s+(\S+)", text, re.IGNORECASE)
    if match:
        user = db.user_by_link_code(match.group(1))
        if user is None:
            send_message(settings["token"], chat_id, T(update_locale(message), "invalid_link"))
            log(f"invalid link code from chat={chat_id}")
            return
        db.link_user(int(user["id"]), chat_id)
        ok, error = send_message(settings["token"], chat_id, T(user["locale"], "linked"))
        log(f"linked user #{user['id']} ({user['username']}) chat={chat_id}"
            + ("" if ok else f" (confirmation send failed: {error})"))
        return

    # Any other command needs an already-linked user.
    command = text.split()[0].lower().lstrip("/").split("@")[0]
    user = db.user_by_chat_id(chat_id)
    if user is None:
        send_message(settings["token"], chat_id, T(update_locale(message), "not_linked"))
        return

    locale = user["locale"]
    if command in ("progress", "stats", "start"):
        send_message(settings["token"], chat_id, build_progress(user, compute_stats(db, user), locale))
    elif command == "week":
        send_message(settings["token"], chat_id, build_week(user, compute_week(db, user), locale))
    elif command == "streak":
        send_message(settings["token"], chat_id, build_streak(user, compute_stats(db, user), locale))
    elif command == "today":
        send_message(settings["token"], chat_id, build_today(db, user, settings, locale))
    elif command == "ranking":
        send_message(settings["token"], chat_id, build_ranking(db, locale))
    else:
        send_message(settings["token"], chat_id, T(locale, "help"))


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
    current_naive = current.replace(tzinfo=None)
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

        # Celebrate streak milestones once each (progressions).
        if check_streak_milestone(db, user, compute_stats(db, user), settings):
            sends += 1

        # Escalating reminders: a first nudge, then follow-ups while still unlogged,
        # up to MAX_REMINDERS_PER_DAY, spaced by FOLLOWUP_MINUTES.
        if int(user["telegram_reminders_enabled"] or 0) == 1 and not logged:
            last_at_raw = str(rget(user, "telegram_last_reminded_at", "") or "")
            count_today = int(rget(user, "telegram_reminder_count", 0) or 0) if last_at_raw[:10] == today else 0
            last_at = _parse_dt(last_at_raw)
            due = count_today == 0 or (
                last_at is not None and (current_naive - last_at) >= timedelta(minutes=FOLLOWUP_MINUTES)
            )
            if count_today < MAX_REMINDERS_PER_DAY and due:
                attempt = count_today + 1
                text = build_reminder(user, compute_stats(db, user), settings, attempt)
                ok, error = send_message(settings["token"], chat_id, text)
                if ok:
                    sends += 1
                    db.mark_reminder_sent(user_id, current.strftime("%Y-%m-%d %H:%M:%S"), attempt, today)
                    log(f"reminder #{attempt}/{MAX_REMINDERS_PER_DAY} -> #{user_id} ({user['username']})")
                else:
                    log(f"reminder send failed -> #{user_id}: {error}")

        # Motivation: once per day, with a progress nudge.
        if (int(user["telegram_motivation_enabled"] or 0) == 1
                and str(user["telegram_last_motivation_on"] or "") != today):
            quote = db.pick_quote(user)
            if quote:
                text = build_motivation(user, compute_stats(db, user), quote, user["locale"])
                ok, error = send_message(settings["token"], chat_id, text)
                if ok:
                    sends += 1
                    db.mark_motivated(user_id, today)
                    log(f"motivation -> #{user_id} ({user['username']})")
                else:
                    log(f"motivation send failed -> #{user_id}: {error}")


def drain_outbox(db: BotDB, settings: dict) -> None:
    """Send queued event-driven push messages (friend/duel/competition activity)."""
    sent = 0
    for row in db.outbox_pending():
        if sent >= MAX_SENDS_PER_PASS:
            break
        outbox_id = int(row["id"])
        chat_id = db.chat_id_for(int(row["user_id"]))
        if not chat_id:
            db.mark_outbox_sent(outbox_id)  # recipient unlinked; drop it
            continue
        ok, error = send_message(settings["token"], chat_id, str(row["text"]))
        if ok:
            db.mark_outbox_sent(outbox_id)
            sent += 1
            log(f"outbox -> #{row['user_id']}")
        else:
            log(f"outbox send failed -> #{row['user_id']}: {error}")


def run_forever(db_path: Path, verbose: bool) -> int:
    log(f"Fitness Challenge Telegram bot starting. DB: {db_path}")
    log(f"Timezone: {TZ if TZ is not None else 'system local (zoneinfo unavailable)'}")
    last_reminder = 0.0
    started = False
    while True:
        try:
            if not db_path.exists():
                if verbose:
                    log(f"waiting for the database at {db_path} (start the app first)...")
                time.sleep(5)
                continue
            db = BotDB(db_path)
            settings = db.settings()
            if not settings["enabled"] or not settings["token"]:
                if verbose:
                    log("bot disabled or no token; waiting...")
                db.conn.close()
                time.sleep(10)
                continue
            if not started:
                ensure_no_webhook(settings["token"])
                claim_ownership(db, settings)
                if not settings["external"]:
                    # Claim ownership of Telegram I/O so the PHP scheduler defers
                    # and there is no double polling/sending while this bot runs.
                    db.set_setting("telegram_external_bot", "1")
                    settings["external"] = True
                    log("enabled external-bot mode so the web app defers to this process")
                log("polling for Telegram updates... (link users from Settings, then press Start)")
                started = True

            poll_once(db, settings, POLL_TIMEOUT)

            # Event-driven pushes are low-latency: drain every loop iteration.
            drain_outbox(db, settings)

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
    claim_ownership(db, settings)
    if not settings["external"]:
        db.set_setting("telegram_external_bot", "1")
        settings["external"] = True
    poll_once(db, settings, 0)
    drain_outbox(db, settings)
    run_reminders(db, settings)
    db.conn.close()
    return 0


def main() -> int:
    # Logs may include accented names/quotes; avoid crashing on a legacy Windows
    # console code page.
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(encoding="utf-8", errors="replace")
        except Exception:
            pass

    parser = argparse.ArgumentParser(description="Standalone Telegram bot for Fitness Challenge Tracker.")
    parser.add_argument("--once", action="store_true", help="Run one poll + reminder pass, then exit (cron-friendly).")
    parser.add_argument("--db", default="", help="Path to the SQLite database (default: DB_PATH env or storage/fitness.sqlite).")
    parser.add_argument("--verbose", action="store_true", help="Verbose logging.")
    args = parser.parse_args()

    db_path = Path(args.db).resolve() if args.db else Path(os.environ.get("DB_PATH", str(DEFAULT_DB))).resolve()

    if args.once:
        if not db_path.exists():
            log(f"Database not found: {db_path}. Start the app once to create it, or pass --db.")
            return 2
        return run_once(db_path, args.verbose)
    # Loop mode tolerates a missing DB so it can be co-launched with the app.
    return run_forever(db_path, args.verbose)


if __name__ == "__main__":
    sys.exit(main())
