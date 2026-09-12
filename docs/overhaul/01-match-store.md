# Match store (Redis / file)

## Goals

- Authoritative live room state off Hostinger filesystem locks.
- Same PHP rules engine (`api.php` / `effects.php`) against a pluggable store.
- Crash recovery via optional disk snapshot.

## Env

| Variable | Values | Default |
|----------|--------|---------|
| `TCG_GAME_STORE` | `file`, `redis` | `file` |
| `TCG_REDIS_URL` | `redis://host:6379` or `tcp://host:6379` | empty |
| `TCG_REDIS_PREFIX` | key prefix | `lltcg:room:` |
| `TCG_GAME_SNAPSHOT_DIR` | optional disk snapshot dir | empty (off) |
| `TCG_GAME_SNAPSHOT_MODE` | `off`, `finished`, `all` | `finished` when dir set |
| `TCG_GAME_SNAPSHOT_MAX_AGE_SEC` | cleanup age for `games/*.json` | `3600` (`GAME_TIMEOUT`) |

## Interface

```php
interface GameStore {
    public function load(string $roomId): ?array;
    public function save(string $roomId, array $state): void;
    public function withLock(string $roomId, callable $fn, ?float $timeoutSec = null): mixed;
    public function delete(string $roomId): void;
}
```

Facades `loadGame` / `saveGame` / `withLock` in `api.php` delegate to `tcgGameStore()`.

## Redis key layout

| Key | Type | Purpose |
|-----|------|---------|
| `{prefix}{ROOM}` | string (JSON) | Full room state |
| `{prefix}lock:{ROOM}` | string + SET NX PX | Mutual exclusion |
| `{prefix}meta:{ROOM}` | hash optional | seq, phase, updated_at for cheap notify |

TTL: refresh on save (e.g. 48h) so abandoned rooms expire.

### Lock algorithm

1. `SET lock NX PX timeoutMs`
2. Run callback; on success `save` state JSON
3. `DEL lock` in `finally`
4. Retry with backoff until deadline (mirror current flock timeout)

Prefer Redis `SET NX` over Redlock for single-instance Redis.

## File store

Preserves current behavior: `GAMES_DIR/{ROOM}.json` + `lock_{ROOM}` flock files. Side files (`presence_*`, `poll_tick_*`, `spectators_*`) remain filesystem for v1 even when room body is Redis.

## Snapshot

When `TCG_GAME_SNAPSHOT_DIR` is set, `RedisGameStore::save` may write a best-effort JSON copy.

**VPS production:** use `TCG_GAME_SNAPSHOT_MODE=finished` (compose default). That writes a disk copy only after the match ends (status finished / winner set) so overflow `replay_export` can retry briefly. **Do not** use `all` on the VPS — every-action dual-write filled the root disk (~30k files) and kicked players into reconnect loops.

After Hostinger archives the replay into SQLite (`tcg_replays` / `tcg_tournament_replays`), it calls VPS `delete_game_snapshot` (internal secret) to unlink the file. Redis room keys still expire via TTL.

**Historic vs active:**

| Concern | Where |
|---------|--------|
| Active match body | VPS Redis (`TCG_GAME_STORE=redis`) |
| Durable replay / history | Hostinger SQLite |
| Watching a replay | Hostinger short-lived `mode=replay_view` room |
| Never | Bulk-upload finished `games/*.json` to Hostinger, or pull archives onto VPS to spectate |

Cleanup: `api.php?action=cleanup` + `scripts/vps_cleanup_game_snapshots.sh` (cron every 30m; mtime hours).

## Cutover

Read-only readiness (no restarts): `bash scripts/verify_match_primary.sh`

1. Run VPS Docker with Redis + `TCG_GAME_STORE=redis` (`compose.overflow.yaml` / `vps_overflow_up.sh`) — only after explicit operator OK.
2. Route client match traffic via `client/js/runtime-flags.js` (`TCG_MATCH_API_PRIMARY` / `DEFAULT_MATCH_API_PRIMARY`).
3. Hostinger: set `TCG_HOSTINGER_MATCH_WRITES=0` so create/join/action cannot write `games/*.json`.
4. Hostinger keeps `TCG_GAME_STORE=file` only for legacy room drain reads; prefer no new Hostinger rooms.

Rollback: `DEFAULT_MATCH_API_PRIMARY = false`, unset/restore `TCG_HOSTINGER_MATCH_WRITES=1`.
