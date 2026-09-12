#!/usr/bin/env bash
# Prune stale VPS games/*.json snapshots (mtime hours). Live rooms stay in Redis.
# Safe to cron every 30 minutes on the match host.
set -euo pipefail

GAMES_DIR="${TCG_GAMES_DIR:-/opt/lltcgweb/games}"
MAX_AGE_HOURS="${TCG_GAME_SNAPSHOT_MAX_AGE_HOURS:-4}"
API_CLEANUP_URL="${TCG_CLEANUP_URL:-http://127.0.0.1:5003/api.php?action=cleanup}"

if [[ -d "$GAMES_DIR" ]]; then
  # Full room trees only — leave tiny presence/poll side files alone
  find "$GAMES_DIR" -maxdepth 1 -type f -name '*.json' \
    ! -name 'presence_*' ! -name 'poll_tick_*' ! -name 'spectators_*' ! -name 'lock_*' \
    -mmin "+$((MAX_AGE_HOURS * 60))" -delete 2>/dev/null || true
fi

# Prefer in-process cleanup (respects TCG_GAME_SNAPSHOT_MAX_AGE_SEC when set in compose)
if command -v curl >/dev/null 2>&1; then
  curl -fsS "$API_CLEANUP_URL" || true
fi

echo "vps_cleanup_game_snapshots: done (dir=$GAMES_DIR age_h=$MAX_AGE_HOURS)"
