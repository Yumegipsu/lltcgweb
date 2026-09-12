# Production deployment

## Current (Hostinger static + selective VPS)

| Layer | Where | Role |
|-------|--------|------|
| Static UI | Hostinger `/tcg/` | `index.html`, `client/js/`, art, i18n |
| SSE notify | VPS `:5001` via Apache `sync-stream` proxy | Seq bumps only ([`wrapped/tcg_sync.py`](../Chiichan/wrapped/tcg_sync.py)) |
| Game API | Hostinger PHP **or** VPS `:5003` | `api.php`, `account.php`, room JSON, SQLite |

### Phase 1 — SSE off Hostinger PHP (default)

Browsers open EventSource on **`https://stream.loveliveradio.ca/tcg/sync/stream`** (VPS nginx → `wrapped_api` `:5001`). No Hostinger PHP worker is held.

Fallbacks in order: Hostinger `./sync-stream` (if Apache `[P]` works) → `/wrapped/api.php?action=tcg_sync_stream` → short `poll=0`.

After deploy, confirm in Network: EventSource URL is `stream.loveliveradio.ca`. Hostinger CPU should drop during multiplayer evenings.

Client helper: `tcgSyncStatsSnapshot()` in DevTools. Verify script: `bash scripts/verify_sync_stream.sh`.

### Phase 2 — Overflow Plan B (Hostinger primary, VPS standby)

Hostinger remains the default game/account API. The VPS runs a **capped** Docker API (`compose.overflow.yaml`, 0.5 CPU / 512 MB) behind:

`https://stream.loveliveradio.ca/tcg/api/`

**Client behavior** ([`client/js/api-client.js`](../client/js/api-client.js)):

- Counts Hostinger timeouts / 5xx / 429.
- After a short streak, opens an overflow window (~2 minutes).
- **May** retry hub reads (`me`, decks, …) and **new** casual rooms/joins on VPS.
- **Never** migrates an in-progress Hostinger match (room is locked to its origin).
- **Ranked matchmaking stays on Hostinger** (shared queue must not split).
- DevTools: `tcgOverflowStats()`.

**Operator setup:**

```bash
# From lltcgweb checkout (Git Bash)
bash scripts/vps_overflow_up.sh
bash scripts/sync_overflow_db.sh   # seed/refresh accounts DB on VPS
# Optional cron on operator machine every 10–15 min: sync_overflow_db.sh
```

Do **not** upload `USE_VPS_API` for this mode — that marker is full cutover only.

### Phase 3 — Match-primary on VPS + Redis (architecture overhaul)

Authoritative live rooms move off Hostinger `games/*.json` flock onto VPS Redis (`TCG_GAME_STORE=redis`). PHP rules still run in the overflow/match Docker API (`compose.overflow.yaml` includes Redis).

**Client:** deploy [`client/js/runtime-flags.js`](../client/js/runtime-flags.js) with `TCG_MATCH_API_PRIMARY` true (or `?match_primary=1` / `localStorage.tcg_match_api_primary=1` for soak). Casual create/join/`action` and **ranked live rooms** use `TCG_OVERFLOW_ORIGIN` (VPS Redis). Account, ranked queue, Elo/PR, boosters, and login bonus stay on Hostinger. Hostinger `ranked_join` seeds rooms via `seed_ranked_room` (secret); VPS finish posts `ranked_apply_result` for Elo. Legacy Hostinger ranked `games/*.json` may still accept `action` for drain only (`match_api` unset).

**Secrets (Hostinger `tcg_sync.local.php` + VPS compose env):** `TCG_INTERNAL_MATCH_SECRET` (shared), `TCG_MATCH_SEED_URL` (Hostinger→VPS), `TCG_ELO_APPLY_URL` (VPS→Hostinger). Recreate Docker / inject env only after explicit operator OK.

**Hostinger kill switch:** upload an empty `MATCH_WRITES_DISABLED` next to `api.php` so `create_room` / `join_room` / `casual_*` / `action` / `dry_run_actions` return `503` + `match_writes_disabled`. Reads (`get_state`, `get_cards`, `ping`) and account APIs stay available for drain. The marker is gitignored, so it reaches Hostinger only by upload. Do **not** place it on the VPS match API.

Never put `SetEnv TCG_HOSTINGER_MATCH_WRITES 0` in the tracked `.htaccess`: the VPS match host serves this same repo from its docroot, and the `php:apache` image grants `AllowOverride All`, so the file is honored there too and disables writes on the host that owns the rooms. `api.php` now ignores both switches when `TCG_GAME_STORE=redis` (`tcgIsMatchHost`), but keep host-specific config out of tracked files regardless.

**Operator:**

```bash
bash scripts/verify_match_primary.sh   # read-only health (no compose up / no restart)
bash scripts/vps_overflow_up.sh        # only after explicit OK — redis + tcg-api
# Confirm redis health + api.php?action=ping on :5003
# Then: runtime-flags.js primary=true + Hostinger TCG_HOSTINGER_MATCH_WRITES=0
```

**Realtime:** SSE notify remains on `wrapped/tcg_sync.py` (seq-only wake → client `get_state`). Optional later: WebSocket fan-out from the same VPS process if poll pressure remains — see `docs/overhaul/01-match-store.md` and Part 1C in `docs/OVERHAUL_PROGRESS.md`.

**Active vs historic storage (required):**

| Role | Store | Notes |
|------|--------|--------|
| Live rooms | VPS Redis | `TCG_GAME_STORE=redis` in `compose.overflow.yaml` |
| Finished-match history | Hostinger SQLite | `tcg_replays`, `tcg_tournament_replays` via autosave / tournament archive |
| Replay watch sessions | Hostinger `games/` | Short-lived `mode=replay_view` only — not long-lived room dumps |
| VPS `games/*.json` | Finished-only snapshots | `TCG_GAME_SNAPSHOT_MODE=finished` — never every-action dual-write |

Do **not** bulk-upload VPS `games/*.json` to Hostinger or hydrate finished rooms onto VPS Redis just to spectate. After SQLite archive, Hostinger calls `api.php?action=delete_game_snapshot` on the VPS (keeps Redis until TTL).

**VPS disk hygiene (cron):**

```bash
# On stream match host — every 30 minutes
*/30 * * * * /opt/lltcgweb/scripts/vps_cleanup_game_snapshots.sh >>/var/log/lltcgweb-games-cleanup.log 2>&1
# Equivalent: curl -fsS http://127.0.0.1:5003/api.php?action=cleanup
```

Optional later: alert when `/` > 85%.

**Rollback:** `runtime-flags.js` / `TCG_MATCH_API_PRIMARY=false`; Hostinger `TCG_HOSTINGER_MATCH_WRITES=1` (or unset); Hostinger `TCG_GAME_STORE=file`.

### Hostinger-only deploy (no VPS API yet)

Continue using Chiichan `deploy-loveliveradio-ca.sh` with `LLR_TCG_ROOT` → this repo. Runtime dirs stay under `/tcg/` until Phase 2 cutover.

### Ops notes

- **Healthcheck:** `GET /api.php?action=ping`
- **Rollback Phase 2:** remove `USE_VPS_API` (Apache stops proxying API).
- **Rollback Phase 1 SSE:** clients auto-fall back to PHP proxy; or revert `.htaccess` RewriteRule.
- **Backups:** cron `data/tcg.db` on whichever host owns SQLite.
- **wrapped-api restart:** only after explicit operator confirmation (Chiichan VPS rules).
