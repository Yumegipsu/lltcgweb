<?php
/**
 * Serve permanently cached card images (tcg/cardimg/*.png).
 * GET ?card_no=LL-bp1-001-R+  → image bytes or 404
 */

require_once __DIR__ . '/cardimg_cache.php';
require_once __DIR__ . '/config/cors.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    tcgSendCorsPreflight('GET, OPTIONS', 'Content-Type');
    http_response_code(200);
    exit;
}

$cardNo = (string)($_GET['card_no'] ?? '');
$variantWidth = tcgCardImageVariantWidth($_GET['w'] ?? 0);
// Apache rewrites a missing static thumb (cardimg/thumbs/<w>/<name>.webp) here.
if (isset($_GET['thumb_path'])) {
    $reqPath = rawurldecode((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
    if (preg_match('#/cardimg/thumbs/(96|180|256)/([^/]+)\.webp$#', $reqPath, $m)) {
        $variantWidth = (int)$m[1];
        $cardNo = $m[2];
    }
}
$file = localCardImageFile($cardNo);

if (!$file && $cardNo !== '') {
    $url = lookupCardImageUrl($cardNo);
    if ($url !== '') {
        try {
            cacheCardImageFromUrl($cardNo, $url);
            $file = localCardImageFile($cardNo);
        } catch (Throwable $e) {
            // fall through to 404
        }
    }
}

if (!$file) {
    tcgSendCorsHeaders();
    http_response_code(404);
    exit;
}

if ($variantWidth > 0) {
    $file = ensureCardImageVariant($cardNo, $file, $variantWidth);
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
];

header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Cache-Control: public, max-age=31536000, immutable');
tcgSendCorsHeaders();
readfile($file);
