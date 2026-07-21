#!/usr/bin/env python3
"""Deterministic lease ownership and stale-worker recovery checks."""

from __future__ import annotations

import sqlite3
import tempfile
from pathlib import Path

from runtime_lease import RuntimeLease
from live_manager import runtime_status_rows


def check(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)
    print(f"[ok] {message}")


def main() -> int:
    with tempfile.TemporaryDirectory(prefix="fitness_leases_") as directory:
        db_path = Path(directory) / "fitness.sqlite"
        first_connection = sqlite3.connect(db_path)
        second_connection = sqlite3.connect(db_path)
        first = RuntimeLease(first_connection, "telegram", "worker-a", ttl_seconds=30)
        second = RuntimeLease(second_connection, "telegram", "worker-b", ttl_seconds=30)
        check(first.acquire(), "first worker acquires the lease")
        check(not second.acquire(), "second worker cannot double-own an active lease")
        check(first.heartbeat(success=True), "owner refreshes heartbeat and success")
        first_connection.execute(
            "UPDATE integration_runtime_leases SET lease_until = datetime('now', '-1 second') WHERE service = 'telegram'"
        )
        first_connection.commit()
        check(second.acquire(), "a new worker recovers an expired lease")
        check(not first.heartbeat(), "stale owner cannot refresh the recovered lease")
        second_connection.execute(
            "UPDATE integration_runtime_leases SET heartbeat_at = datetime('now', '-121 seconds'), "
            "lease_until = datetime('now', '+30 seconds'), last_error = NULL WHERE service = 'telegram'"
        )
        second_connection.commit()
        check(runtime_status_rows(db_path)[0]["state"] == "delayed", "manager reports a delayed heartbeat")
        second_connection.execute(
            "UPDATE integration_runtime_leases SET last_error = 'qa failure' WHERE service = 'telegram'"
        )
        second_connection.commit()
        check(runtime_status_rows(db_path)[0]["state"] == "error", "manager reports the last worker error")
        secret = "123456:QA-secret-token"
        second_connection.execute(
            "CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)"
        )
        second_connection.execute(
            "INSERT INTO app_settings (setting_key, setting_value) VALUES ('telegram_bot_token', ?)",
            (secret,),
        )
        second_connection.execute(
            "UPDATE integration_runtime_leases SET last_error = ? WHERE service = 'telegram'",
            (f"network error at https://api.telegram.org/bot{secret}/getUpdates",),
        )
        second_connection.commit()
        safe_error = str(runtime_status_rows(db_path)[0]["last_error"])
        check(secret not in safe_error and "[redacted]" in safe_error, "manager redacts integration secrets from status output")
        second.release()
        first_connection.close()
        second_connection.close()
    print("Runtime lease checks passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
