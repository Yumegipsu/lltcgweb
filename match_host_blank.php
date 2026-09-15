<?php
/**
 * VPS match-host stub — stream.loveliveradio.ca/tcg/api/ must not look like the game.
 * Served only when .htaccess rewrites the stream Host away from index.html.
 */
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');
http_response_code(404);
echo "LLTCG match API — not a public site.\n";
