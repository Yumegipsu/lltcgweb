# TCG security notes

## Fixed risks (issues #37–#39)

### cache_card_image (#37)

`api.php?action=cache_card_image` resolves image URLs **only** from `cards.json` via `lookupCardImageUrl($cardNo)`. Client-supplied `url` is ignored. Downloads are restricted to hostnames present in `cards.json` image URLs.

### games/ token exposure (#38)

`games/*.json` and `experiment_decks/*.json` hold session tokens. Both directories ship with `.htaccess` (`Deny from all`) on Apache/Hostinger. Docker dev uses `docker/apache.conf` for the same deny rules.

### CORS (#39)

Wildcard `Access-Control-Allow-Origin: *` was replaced with an allowlist in `config/cors.php`. Configure `TCG_CORS_ORIGINS` (comma-separated) for additional origins.

## Rate limiting

Basic file-based limits (see `config/rate_limit.php`):

- `create_room`: 30 requests / 10 minutes per client IP
- `cache_card_image`: 120 requests / 10 minutes per client IP

## Deferred hardening

- Sanitize error messages returned to clients in production
- Broader rate limits on join/action endpoints
- Move runtime directories outside the public web root when production Docker is available

## Environment variables

| Variable | Purpose |
|----------|---------|
| `TCG_CORS_ORIGINS` | Comma-separated allowed browser origins |
| `TCG_RATE_LIMIT_DIR` | Optional override for rate-limit state files |
| `TCG_DATA_DIR` | SQLite + rate limits (see `docs/RUNTIME.md`) |
