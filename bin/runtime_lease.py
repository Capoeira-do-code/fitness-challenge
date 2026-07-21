"""SQLite runtime leases shared by the local integration workers."""

from __future__ import annotations

import contextlib
import os
import socket
import sqlite3
import uuid
from pathlib import Path
from typing import Iterator, Optional


def runtime_owner_id(service: str) -> str:
    return f"{service}:{socket.gethostname()}:{os.getpid()}:{uuid.uuid4().hex[:8]}"


class RuntimeLease:
    def __init__(
        self,
        connection: sqlite3.Connection,
        service: str,
        owner_id: str,
        ttl_seconds: int = 90,
    ) -> None:
        self.connection = connection
        self.service = service
        self.owner_id = owner_id
        self.ttl_seconds = max(30, int(ttl_seconds))
        self.connection.execute(
            "CREATE TABLE IF NOT EXISTS integration_runtime_leases ("
            "service TEXT PRIMARY KEY, owner_id TEXT NOT NULL, heartbeat_at TEXT NOT NULL, "
            "lease_until TEXT NOT NULL, last_success_at TEXT, last_error TEXT)"
        )
        self.connection.commit()

    @property
    def ttl_modifier(self) -> str:
        return f"+{self.ttl_seconds} seconds"

    def acquire(self) -> bool:
        cursor = self.connection.execute(
            "INSERT INTO integration_runtime_leases "
            "(service, owner_id, heartbeat_at, lease_until, last_success_at, last_error) "
            "VALUES (?, ?, datetime('now'), datetime('now', ?), NULL, NULL) "
            "ON CONFLICT(service) DO UPDATE SET "
            "owner_id = excluded.owner_id, heartbeat_at = excluded.heartbeat_at, "
            "lease_until = excluded.lease_until, last_error = NULL "
            "WHERE integration_runtime_leases.owner_id = excluded.owner_id "
            "OR integration_runtime_leases.lease_until <= datetime('now')",
            (self.service, self.owner_id, self.ttl_modifier),
        )
        self.connection.commit()
        return cursor.rowcount > 0

    def heartbeat(self, *, success: bool = False, error: Optional[str] = None) -> bool:
        safe_error = (error or "")[:500] if error else None
        cursor = self.connection.execute(
            "UPDATE integration_runtime_leases SET "
            "heartbeat_at = datetime('now'), lease_until = datetime('now', ?), "
            "last_success_at = CASE WHEN ? = 1 THEN datetime('now') ELSE last_success_at END, "
            "last_error = CASE WHEN ? IS NOT NULL THEN ? WHEN ? = 1 THEN NULL ELSE last_error END "
            "WHERE service = ? AND owner_id = ?",
            (
                self.ttl_modifier,
                1 if success else 0,
                safe_error,
                safe_error,
                1 if success else 0,
                self.service,
                self.owner_id,
            ),
        )
        self.connection.commit()
        return cursor.rowcount > 0

    def release(self) -> None:
        self.connection.execute(
            "UPDATE integration_runtime_leases SET lease_until = datetime('now'), "
            "heartbeat_at = datetime('now') WHERE service = ? AND owner_id = ?",
            (self.service, self.owner_id),
        )
        self.connection.commit()


@contextlib.contextmanager
def maintenance_shared_lock(db_path: Path) -> Iterator[None]:
    """Coordinate Python writes with PHP backup/restore maintenance mode."""

    lock_path = db_path.parent / ".maintenance.lock"
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    handle = lock_path.open("a+b")
    try:
        try:
            import fcntl

            fcntl.flock(handle.fileno(), fcntl.LOCK_SH)
            yield
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
        except ImportError:
            # Windows local development has no fcntl; SQLite still serializes DB
            # writes, while restore remains protected on Unix production hosts.
            yield
    finally:
        handle.close()
