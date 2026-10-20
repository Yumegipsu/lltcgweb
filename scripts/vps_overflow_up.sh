#!/usr/bin/env bash
# Stand up capped TCG API overflow on VPS (Plan B). Does not flip Hostinger primary.
set -euo pipefail
VPS_HOST="${VPS_HOST:-root@stream.loveliveradio.ca}"
VPS_DIR="${VPS_TCG_DIR:-/opt/lltcgweb}"
REPO_URL="${LLTCGWEB_REPO:-https://github.com/Yumegipsu/lltcgweb.git}"
LOCAL_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "==> Prune unused docker images on VPS (best-effort, time-capped)"
ssh "$VPS_HOST" 'timeout 60 docker image prune -af >/dev/null 2>&1 || true; df -h / | tail -1'

echo "==> Clone/update ${VPS_DIR}"
ssh "$VPS_HOST" "mkdir -p '${VPS_DIR}' && if [ -d '${VPS_DIR}/.git' ]; then
  cd '${VPS_DIR}' && git fetch --depth=1 origin main && git reset --hard origin/main
else
  rm -rf '${VPS_DIR}' && git clone --depth=1 '${REPO_URL}' '${VPS_DIR}'
fi
mkdir -p '${VPS_DIR}/data' '${VPS_DIR}/games' '${VPS_DIR}/experiment_decks' '${VPS_DIR}/cardimg'
"

echo "==> Upload auth + sync secrets (local gitignored files)"
if [[ -f "${LOCAL_ROOT}/llr_auth.php" ]]; then
  scp -q "${LOCAL_ROOT}/llr_auth.php" "${VPS_HOST}:${VPS_DIR}/llr_auth.php"
else
  echo "warn: no local llr_auth.php — overflow account APIs may be offline mode" >&2
fi
if [[ -f "${LOCAL_ROOT}/tcg_sync.local.php" ]]; then
  scp -q "${LOCAL_ROOT}/tcg_sync.local.php" "${VPS_HOST}:${VPS_DIR}/tcg_sync.local.php"
fi
if [[ -f "${LOCAL_ROOT}/iframe_bearer_token_stub.php" ]]; then
  scp -q "${LOCAL_ROOT}/iframe_bearer_token_stub.php" "${VPS_HOST}:${VPS_DIR}/iframe_bearer_token_stub.php"
fi

echo "==> Build/start overflow container (compose.overflow.yaml)"
scp -q "${LOCAL_ROOT}/compose.overflow.yaml" "${VPS_HOST}:${VPS_DIR}/compose.overflow.yaml"
ssh "$VPS_HOST" "cd '${VPS_DIR}' && docker compose -f compose.overflow.yaml up -d --build"

echo "==> Health"
for i in $(seq 1 15); do
  if ssh "$VPS_HOST" "curl -fsS http://127.0.0.1:5003/api.php?action=ping" >/dev/null 2>&1; then
    echo "OK: overflow API on 127.0.0.1:5003"
    break
  fi
  if [[ "$i" -eq 15 ]]; then
    echo "error: overflow API failed health check" >&2
    exit 1
  fi
  sleep 2
done

echo "==> Public nginx path check"
code=$(curl -sS -o /dev/null -w '%{http_code}' "https://stream.loveliveradio.ca/tcg/api/api.php?action=ping" || true)
echo "stream.loveliveradio.ca/tcg/api/api.php → HTTP ${code}"
echo "Done. Client overflow uses this path automatically when Hostinger fails."
