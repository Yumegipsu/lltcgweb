# Production deployment (follow-up)

Production Docker is **not** implemented yet. When ready, target layout:

## Services

| Service | Role |
|---------|------|
| `tcg-web` | This PHP app (immutable image from CI) |
| `wrapped-api` | Existing wrapped/SSE service for multiplayer sync |
| `reverse-proxy` | Caddy, Traefik, or Nginx with TLS |

## Volumes

Mount outside the image:

- `TCG_DATA_DIR` — SQLite + rate limits
- `TCG_GAMES_DIR` — match JSON (ephemeral)
- `TCG_EXPERIMENT_DECKS_DIR`
- `TCG_CARDIMG_DIR` — image cache
- Secrets: `TCG_LLR_AUTH_FILE`, `TCG_SYNC_*`

## Operations

- **Healthcheck:** `GET /api.php?action=ping`
- **Rollback:** deploy previous image tag
- **Backups:** cron `data/tcg.db` + optional `cardimg/`

## Hostinger until then

Continue using Chiichan `deploy-loveliveradio-ca.sh` with `LLR_TCG_ROOT` pointing at this repo. Runtime dirs stay beside source under `/tcg/`.
