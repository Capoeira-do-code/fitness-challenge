#!/usr/bin/env python3
"""Fail when runtime data or accidental duplicate copies enter source control."""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def tracked_files() -> list[str]:
    result = subprocess.run(
        ["git", "ls-files", "-z"],
        cwd=ROOT,
        check=True,
        stdout=subprocess.PIPE,
    )
    return [item.decode("utf-8", "replace") for item in result.stdout.split(b"\0") if item]


def main() -> int:
    violations: list[str] = []
    for path in tracked_files():
        if path.startswith("liquid-glass/"):
            continue
        if "/__pycache__/" in f"/{path}" or path.endswith((".pyc", ".pyo")):
            violations.append(f"python cache: {path}")
        if path.startswith("e2e-report/"):
            violations.append(f"E2E artifact: {path}")
        if path.startswith(("storage/uploads/", "storage/backups/")):
            violations.append(f"runtime storage: {path}")
        if path.startswith("public/uploads/") and path != "public/uploads/.gitkeep":
            violations.append(f"user upload: {path}")
        if " 2." in Path(path).name:
            violations.append(f"duplicate copy name: {path}")

    if violations:
        print("Repository hygiene violations:", file=sys.stderr)
        for violation in violations:
            print(f"- {violation}", file=sys.stderr)
        return 1
    print("Repository hygiene checks passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
