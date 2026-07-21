#!/usr/bin/env python3
"""Fast, network-free regression checks for Telegram preference controls."""

from __future__ import annotations

import importlib.util
import sqlite3
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("fitness_telegram_bot", ROOT / "bin" / "telegram_bot.py")
assert SPEC is not None and SPEC.loader is not None
bot = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(bot)


def create_db(path: Path) -> None:
    conn = sqlite3.connect(path)
    conn.executescript(
        """
        CREATE TABLE app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT,
            updated_at TEXT
        );
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            display_name TEXT,
            locale TEXT,
            active INTEGER,
            telegram_chat_id TEXT,
            telegram_link_code TEXT,
            telegram_reminders_enabled INTEGER DEFAULT 0,
            telegram_motivation_enabled INTEGER DEFAULT 0,
            telegram_reminder_time TEXT DEFAULT '20:00',
            telegram_quiet_start TEXT DEFAULT '',
            telegram_quiet_end TEXT DEFAULT '',
            telegram_weekends_off INTEGER DEFAULT 0,
            telegram_tz TEXT DEFAULT '',
            telegram_notify_duel INTEGER DEFAULT 1,
            telegram_notify_streak INTEGER DEFAULT 1,
            telegram_notify_social INTEGER DEFAULT 1,
            updated_at TEXT
        );
        INSERT INTO users (
            id, username, display_name, locale, active, telegram_chat_id,
            telegram_reminders_enabled, telegram_motivation_enabled, telegram_reminder_time
        ) VALUES (1, 'qa', 'QA User', 'es', 1, '12345', 1, 1, '00:15');
        """
    )
    conn.commit()
    conn.close()


def main() -> int:
    with tempfile.TemporaryDirectory(prefix="fitness-telegram-qa-") as temp_dir:
        db_path = Path(temp_dir) / "qa.sqlite"
        create_db(db_path)
        db = bot.BotDB(db_path)
        settings = {"token": "test-token", "base_url": "https://fitness.example"}
        calls: list[tuple[str, dict]] = []

        def fake_api(_token: str, method: str, params=None, timeout: int = 25):
            del timeout
            calls.append((method, params or {}))
            return True, None

        bot.api_call = fake_api

        user = db.user_by_chat_id("12345")
        assert user is not None
        panel = bot.build_notifications_panel(user)
        keyboard = bot.notification_keyboard(user, settings)
        assert "Avisos de Telegram" in panel
        assert any(button.get("callback_data") == "tgpref:social" for row in keyboard["inline_keyboard"] for button in row)

        bot.process_update(db, settings, {
            "callback_query": {
                "id": "cb-toggle",
                "data": "tgpref:reminders",
                "from": {"language_code": "es"},
                "message": {"message_id": 77, "chat": {"id": 12345}},
            }
        })
        changed = db.user_by_id(1)
        assert changed is not None and int(changed["telegram_reminders_enabled"]) == 0
        assert any(method == "editMessageText" for method, _params in calls)
        assert any(method == "answerCallbackQuery" for method, _params in calls)

        bot.process_update(db, settings, {
            "callback_query": {
                "id": "cb-time",
                "data": "tgtime:-30",
                "from": {"language_code": "es"},
                "message": {"message_id": 77, "chat": {"id": 12345}},
            }
        })
        changed = db.user_by_id(1)
        assert changed is not None and changed["telegram_reminder_time"] == "23:45"

        bot.process_update(db, settings, {
            "message": {"text": "/time 07:30", "chat": {"id": 12345}, "from": {"language_code": "es"}}
        })
        changed = db.user_by_id(1)
        assert changed is not None and changed["telegram_reminder_time"] == "07:30"

        # A queued social event is discarded without sending if the user turns
        # that category off before the outbox is drained.
        db.set_notification_pref(1, "social", False)
        db.outbox_ensure()
        db.conn.execute(
            "INSERT INTO telegram_outbox (user_id, kind, text, created_at) VALUES (1, 'friend_request', 'QA', ?)",
            (bot.now_iso(),),
        )
        db.conn.commit()
        calls.clear()
        bot.drain_outbox(db, settings)
        outbox = db.conn.execute("SELECT sent_at FROM telegram_outbox ORDER BY id DESC LIMIT 1").fetchone()
        assert outbox is not None and outbox["sent_at"]
        assert not any(method == "sendMessage" for method, _params in calls)

        assert bot.parse_switch("si") is True
        assert bot.parse_switch("off") is False
        assert bot.normalize_hm("24:00") == ""
        secret = "123456:QA-secret-token"
        leaked_url = f"network error at https://api.telegram.org/bot{secret}/getUpdates"
        redacted = bot.redact_secrets(leaked_url, secret)
        assert secret not in redacted and "bot[redacted]" in redacted
        db.conn.close()

    print("Telegram preference QA: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
