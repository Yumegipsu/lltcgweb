#!/usr/bin/env bash
# Harden stream.loveliveradio.ca /tcg/api/ so browsers/crawlers do not get the
# playable UI — only known PHP API scripts are proxied. Reloads nginx.
#
# Requires operator OK for: systemctl reload nginx
set -euo pipefail
CONF=/etc/nginx/sites-available/stream-hls
cp -a "$CONF" "${CONF}.bak.api_ui_$(date +%Y%m%d%H%M%S)"
python3 <<'PY'
from pathlib import Path
import re
p = Path('/etc/nginx/sites-available/stream-hls')
text = p.read_text()

api_block = '''    # TCG game API (Phase 2 Docker on :5003) — PHP endpoints only (no UI shell)
    location /tcg/api/ {
        # Exact root / index → noindex blank (do not proxy the game hub)
        location = /tcg/api/ {
            add_header X-Robots-Tag "noindex, nofollow, noarchive" always;
            add_header Content-Type "text/plain; charset=utf-8" always;
            return 404 "LLTCG match API — not a public site.\\n";
        }
        location = /tcg/api/index.html {
            add_header X-Robots-Tag "noindex, nofollow, noarchive" always;
            return 404;
        }
        location = /tcg/api/robots.txt {
            add_header Content-Type "text/plain; charset=utf-8" always;
            return 200 "User-agent: *\\nDisallow: /\\n";
        }
        # Allowlisted match/account PHP only (strip /tcg/api/ like the old proxy_pass …/)
        location ~ ^/tcg/api/((?:api|account|spectate|bannerimg|cardimg|cardimg_cache|replay)\.php)$ {
            proxy_pass http://127.0.0.1:5003/$1$is_args$args;
            proxy_http_version 1.1;
            proxy_set_header Host $host;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto $scheme;
            proxy_connect_timeout 5s;
            proxy_read_timeout 60s;
            proxy_hide_header Access-Control-Allow-Origin;
            proxy_hide_header Access-Control-Allow-Methods;
            proxy_hide_header Access-Control-Allow-Headers;
            proxy_hide_header Access-Control-Allow-Credentials;
            proxy_hide_header Vary;
            add_header Access-Control-Allow-Origin $tcg_cors_origin always;
            add_header Access-Control-Allow-Methods "GET, POST, OPTIONS" always;
            add_header Access-Control-Allow-Headers "Content-Type, X-Player-Token, X-Auth-Token, Authorization" always;
            add_header Vary Origin always;
            add_header X-Robots-Tag "noindex, nofollow, noarchive" always;
            if ($request_method = OPTIONS) { return 204; }
        }
        # Everything else under /tcg/api/ (client JS, CSS, index, docs) → 404
        add_header X-Robots-Tag "noindex, nofollow, noarchive" always;
        return 404;
    }'''

text2, n = re.subn(
    r'    # TCG game API \(Phase 2 Docker on :5003\).*?location /tcg/api/ \{.*?\n    \}',
    api_block,
    text,
    count=1,
    flags=re.S,
)
if n != 1:
    raise SystemExit(f'api location replace count={n} — update regex for current stream-hls config')
p.write_text(text2)
print('rewrote /tcg/api/ to PHP-only (no UI)')
PY
nginx -t
systemctl reload nginx
echo RELOAD_OK
echo '--- root (expect 404, no hub HTML) ---'
curl -sS -D - -o /tmp/tcg_api_root.body "https://stream.loveliveradio.ca/tcg/api/" | head -n 20
head -c 200 /tmp/tcg_api_root.body; echo
echo '--- ping (expect 200 JSON) ---'
curl -sS "https://stream.loveliveradio.ca/tcg/api/api.php?action=ping" | head -c 200; echo
echo '--- robots ---'
curl -sS "https://stream.loveliveradio.ca/tcg/api/robots.txt"; echo
