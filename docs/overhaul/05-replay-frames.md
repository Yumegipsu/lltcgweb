# Replay format (schema v2)

Live matches still record a small **baseline** + **action_log** on the room
(see `captureReplayBaselineIfNeeded` / `appendReplayAction` in `replay.php`).

**Finished-match export / library autosave store slim schema v1** (baseline +
actions only). Hostinger converts to seekable **schema v2 frames** lazily on
`replay_get` / `replay_start` so long matches do not time out mid-save.

**Viewer rooms and seek use schema v2:**

- `frames[0]` = board at start (sanitized baseline)
- `frames[k]` = board after the first `k` recorded actions
- Seek (`replay_goto`) **installs** `frames[step]` — it does not re-run the rules engine
- Frames are **slimmed** (no image URLs / deck stubs / dropped log+timer junk) so
  finished-match export stays small enough for autosave → Hostinger library
- **`full_log` + `log_ends`** (one copy per payload) restore cumulative `log` on
  each seek step — spectacle priming and forward animations still work
- Library SQLite gzip-encodes payloads over ~512 KB (`LLTCG_GZ1:`)

Opening a schema v1 JSON converts once via `convertReplayPayloadToV2` (best-effort
re-sim with soft-skips), then seeks on frames. CLI:
`php scripts/convert_replays_to_v2.php path/to/replay.json`.
