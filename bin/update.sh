#!/usr/bin/env sh
set -eu

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_DIR"

if [ -f "$REPO_DIR/bin/live_manager.py" ] && [ -f "$REPO_DIR/.env.live" ]; then
  if command -v python3 >/dev/null 2>&1; then
    exec python3 "$REPO_DIR/bin/live_manager.py" deploy --env-file "$REPO_DIR/.env.live" "$@"
  fi
  if command -v python >/dev/null 2>&1; then
    exec python "$REPO_DIR/bin/live_manager.py" deploy --env-file "$REPO_DIR/.env.live" "$@"
  fi
fi

git pull --rebase
docker compose up -d --build
