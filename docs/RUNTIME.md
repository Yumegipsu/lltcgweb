# Runtime boundaries

This document describes what belongs in git vs on-disk runtime state for [lltcgweb](https://github.com/Yumegipsu/lltcgweb).

## Source (in git)

| Path | Role |
|------|------|
| `*.php`, `index.html`, `client/js/` | Application code |
| `cards.json`, `tutorial*.json`, manifests | Card data and static JSON |
| `config/loveca_points.json` | Official Loveca Point System card list + limit (April 2026); run `php scripts/apply_loveca_points.php` after edits |
| `config/` | Path, CORS, rate-limit helpers |
| `src/` | Extracted PHP modules |
| `tests/`, `scripts/` | Verification |
| `migrations/` | Versioned SQLite schema |

## Runtime (not in git)

| Path | Env override | Role |
|------|--------------|------|
| `data/` | `TCG_DATA_DIR` | SQLite `tcg.db` (accounts, collection, ranked, **play stats**), queue caches, rate limits |
| `games/` | `TCG_GAMES_DIR` | Live match JSON per room |
| `experiment_decks/` | `TCG_EXPERIMENT_DECKS_DIR` | Guest deck experiment saves |
| `cardimg/` | `TCG_CARDIMG_DIR` | Cached card face images |
| `exports/` | `TCG_EXPORTS_DIR` | Operator exports (if used) |

HTTP access to `data/`, `games/`, and `experiment_decks/` is blocked via `.htaccess` (Apache) or `docker/apache.conf`.

## External assets (not in git)

| Path | Role |
|------|------|
| `assets/`, `bg/`, `icons/` | UI art and audio |
| Root `*.png` / `*.jpg` | Playmat, logos |

Supply these on the server locally; they are not redistributed in the repo.

## Secrets / config

| File / env | Role |
|------------|------|
| `llr_auth.php` | Production Discord OAuth (gitignored) |
| `TCG_LLR_AUTH_FILE` | Optional path override for auth bootstrap |
| `tcg_sync.local.php` | Sync secrets (gitignored); env vars preferred in Docker |
| `TCG_SYNC_*` | Publish URL, shared secret, internal token |

## Hostinger vs Docker

**Hostinger (current production):** Chiichan `deploy-loveliveradio-ca.sh` copies selected files from `LLR_TCG_ROOT` to `public_html/tcg/`. Runtime dirs live beside source under `/tcg/` on the host.

**Docker dev:** `compose.yaml` mounts the repo as `/var/www/html` and uses named volumes for `data`, `games`, `experiment_decks`, and `cardimg` so runtime state survives container rebuilds.

## Backups

Back up at minimum:

- `data/tcg.db` (accounts, collection, ranked)
- `games/` if you need to preserve in-progress matches (usually ephemeral)
- `cardimg/` to avoid re-downloading faces

## Local development

```bash
# Docker (recommended)
docker compose up

# PHP built-in server (fallback)
php -S localhost:8080
```

Run verification before deploy:

```bash
composer install
composer test
php scripts/validate_json.php
```
