# LLTCG web — Security notes

## Fixed risks (issues #37–#39)

### cache_card_image (#37)

`api.php?action=cache_card_image` resolves image URLs **only** from `cards.json` via `lookupCardImageUrl($cardNo)`. Client-supplied `url` is ignored. Downloads are restricted to hostnames present in `cards.json` image URLs.

**Verified by:** `tests/Security/CacheCardImageTest.php`

### games/ token exposure (#38)

`games/*.json` and `experiment_decks/*.json` hold session tokens. Both directories ship with `.htaccess` (`Deny from all`) on Apache/Hostinger. Docker dev uses `docker/apache.conf` for the same deny rules.

**Verified by:** `tests/Security/HtaccessPresentTest.php`

### CORS (#39)

Wildcard `Access-Control-Allow-Origin: *` was replaced with an allowlist in `config/cors.php`. Configure `TCG_CORS_ORIGINS` (comma-separated) for additional origins.

**Verified by:** `tests/Security/CorsAllowlistTest.php`

## Rate limiting

File-based limits in `config/rate_limit.php` (10-minute window unless noted):

| Bucket | Limit | Key |
|--------|-------|-----|
| `create_room` | 30 | client IP |
| `join_room` | 60 | client IP |
| `action` | 1200 | IP + room_id |
| `get_state` | 4000 (in-room) / 60 (no room_id) | IP + room_id, else IP |
| `cache_card_image` | 120 | client IP |
| `casual_join` | 30 | client IP |
| `open_booster` | 20 | auth token (fallback IP) |
| `ranked_join` | 20 | auth token (fallback IP) |

Override state directory with `TCG_RATE_LIMIT_DIR` (defaults under `TCG_DATA_DIR`).

**Verified by:** `tests/Security/RateLimitTest.php`

## Production error messages

`config/errors.php` sanitizes 500-class errors when `TCG_DEBUG` is unset (default on Hostinger). Set `TCG_DEBUG=1` locally or in PHPUnit for full exception text. Set `TCG_PRODUCTION=0` to disable sanitization without enabling debug.

**Verified by:** `tests/Security/PublicErrorTest.php`

## Deferred hardening

- Move runtime directories outside the public web root when production Docker is available
- Additional account endpoints (deck_save spam, etc.) if abuse appears

## Environment variables

| Variable | Purpose |
|----------|---------|
| `TCG_CORS_ORIGINS` | Comma-separated allowed browser origins |
| `TCG_RATE_LIMIT_DIR` | Optional override for rate-limit state files |
| `TCG_DATA_DIR` | SQLite + rate limits (see `docs/RUNTIME.md`) |
| `TCG_DEBUG` | `1` = expose full API error messages |
| `TCG_PRODUCTION` | `1` = force error sanitization (default when `TCG_DEBUG` unset) |
