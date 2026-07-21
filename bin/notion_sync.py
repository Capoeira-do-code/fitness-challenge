#!/usr/bin/env python3
"""Standalone Notion sync for Fitness Challenge Tracker.

Runs the team-wide Notion integration as its own process (unlike the PHP
scheduler, which only fires on page loads), reading the same config and data
from the SQLite database the web app uses:

- Push: daily training logs of the current challenge -> a Notion database (create
  or update one page per user/day). The first run "creates everything from
  scratch" in Notion.
- Pull (two-way mode): Notion edits -> app, app wins on conflict.

Config (token, database id, field mapping, direction, schedule) is managed from
the web app. The database itself is created or connected from the web UI, while
runtime ownership is coordinated automatically through an expiring SQLite lease.

Standard library only, no dependencies. Requires outbound HTTPS.

Usage:
  python bin/notion_sync.py            # sync on start, then daily at run_time
  python bin/notion_sync.py --once     # one sync pass, then exit (cron-friendly)
  python bin/notion_sync.py --verbose
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import sqlite3
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime
from pathlib import Path
from typing import Callable, Optional

from runtime_lease import RuntimeLease, maintenance_shared_lock, runtime_owner_id

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_DB = ROOT / "storage" / "fitness.sqlite"
NOTION_API_BASE = "https://api.notion.com/v1"
NOTION_API_VERSION = "2022-06-28"
SYNC_BATCH_LIMIT = 60
PULL_MAX_PAGES = 25
LOOP_CHECK_SECONDS = 60

# field key -> (default Notion property name, notion type). Mirrors app/notion.php.
FIELD_DEFS = {
    "date": ("Date", "date"),
    "user": ("User", "rich_text"),
    "steps": ("Steps", "number"),
    "distance_km": ("Distance km", "number"),
    "workout_done": ("Workout", "checkbox"),
    "workout_type": ("Workout type", "rich_text"),
    "weight": ("Weight", "number"),
    "notes": ("Notes", "rich_text"),
    "log_id": ("Log ID", "number"),
}
PULL_FIELDS = ("steps", "distance_km", "weight", "notes", "workout_done")


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


def default_field_map() -> dict:
    return {field: names[0] for field, names in FIELD_DEFS.items()}


def num_str(value) -> str:
    if value is None or value == "":
        return ""
    try:
        f = float(value)
        return str(int(f)) if f == int(f) else repr(f)
    except (TypeError, ValueError):
        return str(value)


def truncate(value: str, limit: int) -> str:
    return value[:limit]


class SyncDB:
    def __init__(self, path: Path):
        self._closed = False
        self._maintenance_lock = maintenance_shared_lock(path)
        self._maintenance_lock.__enter__()
        self.conn = sqlite3.connect(str(path), timeout=10)
        self.conn.row_factory = sqlite3.Row
        self.conn.execute("PRAGMA busy_timeout = 5000")
        self.conn.execute("PRAGMA journal_mode = WAL")
        self.conn.execute("PRAGMA foreign_keys = ON")
        self.conn.execute(
            "CREATE TABLE IF NOT EXISTS notion_sync_state ("
            "log_id INTEGER PRIMARY KEY, user_id INTEGER, notion_page_id TEXT, "
            "content_hash TEXT, synced_at TEXT)"
        )
        self.conn.commit()

    def close(self) -> None:
        if self._closed:
            return
        self._closed = True
        try:
            self.conn.close()
        finally:
            self._maintenance_lock.__exit__(None, None, None)

    def setting(self, key: str, default: str = "") -> str:
        row = self.conn.execute(
            "SELECT setting_value FROM app_settings WHERE setting_key = ?", (key,)
        ).fetchone()
        return default if row is None or row["setting_value"] is None else str(row["setting_value"])

    def set_setting(self, key: str, value: str) -> None:
        self.conn.execute(
            "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) "
            "ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, "
            "updated_at = excluded.updated_at",
            (key, value, now_iso()),
        )
        self.conn.commit()

    def settings(self) -> dict:
        truthy = ("1", "true", "yes", "on")
        raw_map = self.setting("notion_field_map", "")
        try:
            stored = json.loads(raw_map) if raw_map else {}
        except ValueError:
            stored = {}
        field_map = default_field_map()
        if isinstance(stored, dict):
            for field in field_map:
                if field in stored:
                    field_map[field] = str(stored[field]).strip()
        return {
            "enabled": self.setting("notion_enabled", "0").lower() in truthy,
            "external": self.setting("notion_external_sync", "0").lower() in truthy,
            "token": self.setting("notion_token", "").strip(),
            "database_id": self.setting("notion_database_id", "").strip(),
            "direction": self.setting("notion_sync_direction", "push_only").strip().lower(),
            "frequency": self.setting("notion_sync_frequency", "off").strip().lower(),
            "run_time": self.setting("notion_sync_run_time", "03:00").strip(),
            "last_sync_at": self.setting("notion_last_sync_at", "").strip(),
            "field_map": field_map,
        }

    def challenge_range(self) -> Optional[tuple[str, str]]:
        row = self.conn.execute(
            "SELECT challenge_start, challenge_end FROM challenge_settings WHERE id = 1"
        ).fetchone()
        if row is None:
            return None
        start = str(row["challenge_start"] or "").strip()
        end = str(row["challenge_end"] or "").strip()
        return (start, end) if start and end else None

    def active_users(self) -> list[sqlite3.Row]:
        return self.conn.execute("SELECT * FROM users WHERE active = 1").fetchall()

    def logs_between(self, user_id: int, start: str, end: str) -> list[sqlite3.Row]:
        return self.conn.execute(
            "SELECT * FROM daily_logs WHERE user_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date ASC",
            (user_id, start, end),
        ).fetchall()

    def state_by_log(self, log_id: int) -> Optional[sqlite3.Row]:
        return self.conn.execute(
            "SELECT * FROM notion_sync_state WHERE log_id = ?", (log_id,)
        ).fetchone()

    def state_by_page(self, page_id: str) -> Optional[sqlite3.Row]:
        return self.conn.execute(
            "SELECT * FROM notion_sync_state WHERE notion_page_id = ?", (page_id,)
        ).fetchone()

    def store_state(self, log_id: int, user_id: int, page_id: str, content_hash: str) -> None:
        self.conn.execute(
            "INSERT INTO notion_sync_state (log_id, user_id, notion_page_id, content_hash, synced_at) "
            "VALUES (?, ?, ?, ?, ?) ON CONFLICT(log_id) DO UPDATE SET "
            "user_id=excluded.user_id, notion_page_id=excluded.notion_page_id, "
            "content_hash=excluded.content_hash, synced_at=excluded.synced_at",
            (log_id, user_id, page_id, content_hash, now_iso()),
        )
        self.conn.commit()

    def get_log(self, log_id: int) -> Optional[sqlite3.Row]:
        return self.conn.execute("SELECT * FROM daily_logs WHERE id = ?", (log_id,)).fetchone()

    def update_log(self, log_id: int, updates: dict) -> None:
        allowed = [c for c in updates if c in PULL_FIELDS]
        if not allowed:
            return
        sets = ", ".join(f"{c} = ?" for c in allowed) + ", updated_at = ?"
        params = [updates[c] for c in allowed] + [now_iso(), log_id]
        self.conn.execute(f"UPDATE daily_logs SET {sets} WHERE id = ?", params)
        self.conn.commit()


def api_call(token: str, method: str, path: str, body: Optional[dict] = None) -> tuple[Optional[dict], int, Optional[str]]:
    if not token:
        return None, 0, "missing_token"
    url = NOTION_API_BASE + path
    headers = {
        "Authorization": "Bearer " + token,
        "Notion-Version": NOTION_API_VERSION,
        "Accept": "application/json",
    }
    data = None
    if body is not None:
        data = json.dumps(body).encode("utf-8")
        headers["Content-Type"] = "application/json"

    attempt = 0
    while True:
        request = urllib.request.Request(url, data=data, headers=headers, method=method)
        status = 0
        payload = {}
        try:
            with urllib.request.urlopen(request, timeout=25) as response:
                status = response.status
                payload = json.loads(response.read().decode("utf-8") or "{}")
        except urllib.error.HTTPError as exc:
            status = exc.code
            try:
                payload = json.loads(exc.read().decode("utf-8") or "{}")
            except Exception:
                payload = {}
        except Exception as exc:
            if attempt < 1:
                attempt += 1
                time.sleep(1.3)
                continue
            return None, 0, str(exc)

        if status in (429, 502, 503, 504) and attempt < 1:
            attempt += 1
            time.sleep(1.3)
            continue

        if 200 <= status < 300:
            return payload, status, None
        return payload, status, friendly_error(status, str(payload.get("message", "")))


def friendly_error(status: int, message: str) -> str:
    if status == 401:
        return "Unauthorized: check the Notion integration token."
    if status == 404:
        return "Not found: share the database with your integration (••• > Connections) and verify the id."
    if status == 429:
        return "Rate limited by Notion: try again shortly."
    if status == 0:
        return message or "Could not reach Notion (network/proxy)."
    return message or f"HTTP {status}"


def rich_text(value: str) -> list:
    value = (value or "").strip()
    return [{"type": "text", "text": {"content": truncate(value, 1900)}}] if value else []


def plain_text(rich: list) -> str:
    out = ""
    for seg in rich or []:
        if isinstance(seg, dict):
            out += str(seg.get("plain_text") or (seg.get("text") or {}).get("content") or "")
    return out


def value_for_type(prop_type: str, raw) -> Optional[dict]:
    text = ("1" if raw else "0") if isinstance(raw, bool) else str(raw if raw is not None else "").strip()
    if prop_type == "title":
        return {"title": rich_text(text)}
    if prop_type == "rich_text":
        return {"rich_text": rich_text(text)}
    if prop_type == "number":
        return {"number": None if text == "" else float(raw)}
    if prop_type == "checkbox":
        return {"checkbox": truthy(raw)}
    if prop_type == "date":
        return None if text == "" else {"date": {"start": text}}
    if prop_type == "select":
        return None if text == "" else {"select": {"name": truncate(text, 100)}}
    if prop_type in ("url", "email", "phone_number"):
        return {prop_type: None if text == "" else text}
    return None


def read_property_value(prop_type: str, payload: dict):
    if prop_type == "number":
        return payload.get("number")
    if prop_type == "checkbox":
        return bool(payload.get("checkbox"))
    if prop_type == "title":
        return plain_text(payload.get("title") or [])
    if prop_type == "rich_text":
        return plain_text(payload.get("rich_text") or [])
    if prop_type == "select":
        return str((payload.get("select") or {}).get("name") or "")
    if prop_type == "date":
        return str((payload.get("date") or {}).get("start") or "")
    if prop_type in ("url", "email", "phone_number"):
        return str(payload.get(prop_type) or "")
    return None


def truthy(raw) -> bool:
    if isinstance(raw, bool):
        return raw
    return str(raw).strip().lower() in ("1", "true", "yes", "on")


def content_hash(log: sqlite3.Row, field_map: dict) -> str:
    payload = [
        str(log["log_date"] or ""),
        str(log["steps"] if log["steps"] is not None else ""),
        num_str(log["distance_km"]),
        int(log["workout_done"] or 0),
        str(log["workout_type"] or ""),
        num_str(log["weight"]),
        str(log["notes"] or ""),
        field_map,
    ]
    return hashlib.md5(json.dumps(payload, separators=(",", ":"), sort_keys=True).encode("utf-8")).hexdigest()


def field_values(log: sqlite3.Row, user: sqlite3.Row) -> dict:
    name = str(user["display_name"] or user["username"] or "User").strip() or "User"
    return {
        "date": str(log["log_date"] or ""),
        "user": name,
        "steps": log["steps"],
        "distance_km": log["distance_km"],
        "workout_done": int(log["workout_done"] or 0) == 1,
        "workout_type": str(log["workout_type"] or ""),
        "weight": log["weight"],
        "notes": str(log["notes"] or ""),
        "log_id": int(log["id"]),
    }


def build_properties(log: sqlite3.Row, user: sqlite3.Row, schema: dict, field_map: dict) -> dict:
    present = schema.get("present") or {}
    values = field_values(log, user)
    props: dict = {}
    for field, prop_name in field_map.items():
        prop_name = str(prop_name or "").strip()
        if not prop_name or field not in values or prop_name not in present:
            continue
        value = value_for_type(present[prop_name], values[field])
        if value is not None:
            props[prop_name] = value
    title_prop = schema.get("title_prop") or ""
    if title_prop and title_prop not in props:
        props[title_prop] = {"title": rich_text(f"{values['user']} - {values['date']}")}
    return props


def pull_field_change(field: str, raw, log: sqlite3.Row):
    is_empty = raw is None or raw == ""
    if field == "steps":
        return None if is_empty else ("steps", int(raw), int(log["steps"] or 0))
    if field == "distance_km":
        cur = None if log["distance_km"] is None else float(log["distance_km"])
        return None if is_empty else ("distance_km", float(raw), cur)
    if field == "weight":
        cur = None if log["weight"] is None else float(log["weight"])
        return None if is_empty else ("weight", float(raw), cur)
    if field == "notes":
        return None if is_empty else ("notes", str(raw), str(log["notes"] or ""))
    if field == "workout_done":
        return ("workout_done", 1 if truthy(raw) else 0, int(log["workout_done"] or 0))
    return None


def fetch_schema(settings: dict) -> dict:
    database_id = settings["database_id"]
    if not database_id:
        return {"ok": False, "error": "missing_database", "title_prop": "", "present": {}}
    data, status, error = api_call(settings["token"], "GET", "/databases/" + urllib.request.quote(database_id, safe=""))
    if error is not None:
        return {"ok": False, "error": error, "title_prop": "", "present": {}}
    properties = (data or {}).get("properties") or {}
    title_prop = ""
    present = {}
    for name, definition in properties.items():
        ptype = str((definition or {}).get("type") or "")
        if ptype == "title" and not title_prop:
            title_prop = name
        present[name] = ptype
    return {"ok": True, "error": "", "title_prop": title_prop, "present": present}


def active_field_map(field_map: dict, schema: dict) -> dict:
    present = schema.get("present") or {}
    return {f: p for f, p in field_map.items() if str(p or "").strip() and str(p).strip() in present}


def renew_lease(heartbeat: Optional[Callable[[], bool]]) -> None:
    if heartbeat is not None and not heartbeat():
        raise RuntimeError("Notion runtime lease was lost during synchronization.")


def push(
    db: SyncDB,
    settings: dict,
    schema: dict,
    field_map: dict,
    limit: int,
    heartbeat: Optional[Callable[[], bool]] = None,
) -> dict:
    result = {"created": 0, "updated": 0, "skipped": 0, "failed": 0, "reached_limit": False, "first_error": ""}
    rng = db.challenge_range()
    if rng is None:
        return result
    start, end = rng
    processed = 0
    for user in db.active_users():
        for log in db.logs_between(int(user["id"]), start, end):
            log_id = int(log["id"])
            new_hash = content_hash(log, field_map)
            state = db.state_by_log(log_id)
            page_id = str(state["notion_page_id"]).strip() if state else ""
            if state and page_id and str(state["content_hash"] or "") == new_hash:
                result["skipped"] += 1
                continue
            props = build_properties(log, user, schema, field_map)
            if not props:
                result["skipped"] += 1
                continue
            if page_id:
                data, status, error = api_call(settings["token"], "PATCH", "/pages/" + urllib.request.quote(page_id, safe=""), {"properties": props})
            else:
                data, status, error = api_call(settings["token"], "POST", "/pages", {"parent": {"database_id": settings["database_id"]}, "properties": props})
            renew_lease(heartbeat)
            if error is not None:
                result["failed"] += 1
                if not result["first_error"]:
                    result["first_error"] = error
                continue
            new_page_id = page_id or str((data or {}).get("id") or "")
            if not new_page_id:
                result["failed"] += 1
                continue
            db.store_state(log_id, int(log["user_id"] or user["id"]), new_page_id, new_hash)
            result["updated" if page_id else "created"] += 1
            processed += 1
            if processed >= limit:
                result["reached_limit"] = True
                return result
    return result


def pull(
    db: SyncDB,
    settings: dict,
    schema: dict,
    field_map: dict,
    limit: int,
    heartbeat: Optional[Callable[[], bool]] = None,
) -> dict:
    result = {"pulled": 0, "checked": 0, "failed": 0, "reached_limit": False, "first_error": ""}
    present = schema.get("present") or {}
    pull_fields = {f: p for f, p in active_field_map(field_map, schema).items() if f in PULL_FIELDS}
    if not pull_fields:
        return result
    cursor = None
    pages_scanned = 0
    while True:
        if pages_scanned >= PULL_MAX_PAGES:
            result["reached_limit"] = True
            break
        pages_scanned += 1
        body = {"page_size": 100}
        if cursor:
            body["start_cursor"] = cursor
        data, status, error = api_call(settings["token"], "POST", "/databases/" + urllib.request.quote(settings["database_id"], safe="") + "/query", body)
        renew_lease(heartbeat)
        if error is not None:
            result["failed"] += 1
            result["first_error"] = error
            break
        for page in (data or {}).get("results") or []:
            page_id = str(page.get("id") or "")
            if not page_id:
                continue
            state = db.state_by_page(page_id)
            if state is None:
                continue
            result["checked"] += 1
            if pull_page(db, page, state, pull_fields, present, field_map):
                result["pulled"] += 1
                if result["pulled"] >= limit:
                    result["reached_limit"] = True
                    return result
        if (data or {}).get("has_more"):
            cursor = (data or {}).get("next_cursor") or None
        else:
            cursor = None
        if not cursor:
            break
    return result


def pull_page(db: SyncDB, page: dict, state: sqlite3.Row, pull_fields: dict, present: dict, field_map: dict) -> bool:
    log_id = int(state["log_id"] or 0)
    if log_id <= 0:
        return False
    log = db.get_log(log_id)
    if log is None:
        return False
    # App wins on conflict: skip if the app row changed since last sync.
    if content_hash(log, field_map) != str(state["content_hash"] or ""):
        return False
    last_edited = parse_dt(str(page.get("last_edited_time") or ""))
    if last_edited is None:
        return False
    synced_at = parse_dt(str(state["synced_at"] or ""))
    if synced_at is not None and last_edited <= synced_at:
        return False
    properties = page.get("properties") or {}
    updates = {}
    for field, prop_name in pull_fields.items():
        raw = read_property_value(str(present.get(prop_name) or ""), properties.get(prop_name) or {})
        change = pull_field_change(field, raw, log)
        if change is None:
            continue
        column, new_value, current_value = change
        if new_value != current_value:
            updates[column] = new_value
    if not updates:
        return False
    db.update_log(log_id, updates)
    fresh = db.get_log(log_id) or log
    db.store_state(log_id, int(log["user_id"] or 0), str(page.get("id") or ""), content_hash(fresh, field_map))
    return True


def parse_dt(value: str):
    value = (value or "").strip()
    for fmt in ("%Y-%m-%dT%H:%M:%S.%f%z", "%Y-%m-%dT%H:%M:%S%z", "%Y-%m-%dT%H:%M:%S.%fZ", "%Y-%m-%dT%H:%M:%SZ"):
        try:
            dt = datetime.strptime(value, fmt)
            return dt.timestamp()
        except ValueError:
            continue
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00")).timestamp()
    except ValueError:
        return None


def sync_run(db: SyncDB, verbose: bool, heartbeat: Optional[Callable[[], bool]] = None) -> dict:
    settings = db.settings()
    summary = {"ok": False, "status": "not_configured", "pulled": 0, "created": 0, "updated": 0, "skipped": 0, "failed": 0, "message": ""}
    if not settings["enabled"] or not settings["token"] or not settings["database_id"]:
        summary["message"] = "Notion sync disabled or not configured."
        return summary

    schema = fetch_schema(settings)
    if not schema["ok"]:
        summary["status"] = "error"
        summary["message"] = "Could not read Notion database: " + schema["error"]
        record_run(db, summary)
        return summary
    if not schema["title_prop"]:
        summary["status"] = "error"
        summary["message"] = "The Notion database has no title property."
        record_run(db, summary)
        return summary

    field_map = settings["field_map"]
    if not active_field_map(field_map, schema):
        summary["status"] = "error"
        summary["message"] = "No app fields map to existing Notion properties."
        record_run(db, summary)
        return summary

    first_error = ""
    if settings["direction"] == "two_way":
        p = pull(db, settings, schema, field_map, SYNC_BATCH_LIMIT, heartbeat)
        summary["pulled"] = p["pulled"]
        summary["failed"] += p["failed"]
        first_error = first_error or p["first_error"]

    pu = push(db, settings, schema, field_map, SYNC_BATCH_LIMIT, heartbeat)
    summary["created"] = pu["created"]
    summary["updated"] = pu["updated"]
    summary["skipped"] = pu["skipped"]
    summary["failed"] += pu["failed"]
    first_error = first_error or pu["first_error"]

    summary["ok"] = summary["failed"] == 0
    summary["status"] = "ok" if summary["failed"] == 0 else "partial"
    summary["message"] = (
        f"Pulled {summary['pulled']}, created {summary['created']}, updated {summary['updated']}, "
        f"skipped {summary['skipped']}, failed {summary['failed']}."
        + (f" First error: {first_error}" if first_error else "")
    )
    record_run(db, summary)
    return summary


def record_run(db: SyncDB, summary: dict) -> None:
    db.set_setting("notion_last_sync_at", now_iso())
    db.set_setting("notion_last_status", summary.get("status", ""))
    db.set_setting("notion_last_summary", summary.get("message", ""))


def due_daily(last_sync_at: str, run_time: str) -> bool:
    try:
        hour, minute = (int(x) for x in run_time.split(":"))
    except (ValueError, AttributeError):
        hour, minute = 3, 0
    current = now()
    today_run = current.replace(hour=hour, minute=minute, second=0, microsecond=0)
    if current < today_run:
        return False
    last = parse_dt(last_sync_at.replace(" ", "T")) if last_sync_at else None
    if last is None:
        return True
    return last < today_run.timestamp()


def run_forever(db_path: Path, verbose: bool) -> int:
    log(f"Fitness Challenge Notion sync starting. DB: {db_path}")
    started = False
    owner_id = runtime_owner_id("notion")
    while True:
        lease = None
        try:
            if not db_path.exists():
                if verbose:
                    log(f"waiting for the database at {db_path}...")
                time.sleep(5)
                continue
            db = SyncDB(db_path)
            settings = db.settings()
            if not settings["enabled"] or not settings["token"] or not settings["database_id"]:
                if verbose:
                    log("Notion sync disabled or not configured; waiting...")
                db.close()
                time.sleep(15)
                continue
            lease = RuntimeLease(db.conn, "notion", owner_id, ttl_seconds=300)
            if not lease.acquire():
                if verbose:
                    log("another Notion worker owns the active lease; waiting...")
                db.close()
                time.sleep(15)
                continue
            if not started:
                log("running initial Notion sync...")
                summary = sync_run(db, verbose, lease.heartbeat)
                log(summary["message"])
                started = True
            elif settings["frequency"] == "daily" and due_daily(settings["last_sync_at"], settings["run_time"]):
                log("running scheduled Notion sync...")
                summary = sync_run(db, verbose, lease.heartbeat)
                log(summary["message"])
            lease.heartbeat(success=True)
            db.close()
        except KeyboardInterrupt:
            log("stopping (keyboard interrupt).")
            return 0
        except Exception as exc:  # noqa: BLE001
            safe_error = str(exc)
            token = str((settings if 'settings' in locals() else {}).get("token", ""))
            if token:
                safe_error = safe_error.replace(token, "[redacted]")
            if lease is not None:
                try:
                    lease.heartbeat(error=safe_error)
                except Exception:
                    pass
            if 'db' in locals():
                try:
                    db.close()
                except Exception:
                    pass
            log(f"loop error: {safe_error}")
            time.sleep(10)
        time.sleep(LOOP_CHECK_SECONDS)


def run_once(db_path: Path, verbose: bool) -> int:
    db = SyncDB(db_path)
    settings = db.settings()
    lease = RuntimeLease(db.conn, "notion", runtime_owner_id("notion-once"), ttl_seconds=300)
    if not lease.acquire():
        log("another Notion worker owns the active lease; skipping.")
        db.close()
        return 0
    summary = sync_run(db, verbose, lease.heartbeat)
    lease.heartbeat(
        success=bool(summary.get("ok")),
        error=None if summary.get("ok") else str(summary.get("message", "sync_failed")),
    )
    lease.release()
    log(summary["message"])
    db.close()
    return 0 if summary.get("ok") or summary.get("status") in ("not_configured", "ok") else 1


def main() -> int:
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(encoding="utf-8", errors="replace")
        except Exception:
            pass

    parser = argparse.ArgumentParser(description="Standalone Notion sync for Fitness Challenge Tracker.")
    parser.add_argument("--once", action="store_true", help="Run one sync pass, then exit (cron-friendly).")
    parser.add_argument("--db", default="", help="Path to the SQLite database (default: DB_PATH env or storage/fitness.sqlite).")
    parser.add_argument("--verbose", action="store_true", help="Verbose logging.")
    args = parser.parse_args()

    db_path = Path(args.db).resolve() if args.db else Path(os.environ.get("DB_PATH", str(DEFAULT_DB))).resolve()

    if args.once:
        if not db_path.exists():
            log(f"Database not found: {db_path}. Start the app once to create it, or pass --db.")
            return 2
        return run_once(db_path, args.verbose)
    return run_forever(db_path, args.verbose)


if __name__ == "__main__":
    sys.exit(main())
