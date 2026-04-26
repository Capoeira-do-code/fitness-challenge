#!/usr/bin/env sh
set -eu

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_DIR"

git pull --rebase

docker compose up -d --build
