<?php
/**
 * Permanent on-disk card image cache (tcg/cardimg/).
 *
 * Used by cardimg.php (GET) and api.php cache_card_image. Downloads from official
 * URLs in cards.json when missing locally; dedupes by safe card_no basename.
 */

require_once __DIR__ . '/config/paths.php';
tcgDefinePathConstants();

function ensureCardimgDir(): void {
    if (!is_dir(CARDIMG_DIR)) {
        mkdir(CARDIMG_DIR, 0755, true);
    }
}

function safeCardImgBasename(string $cardNo): string {
    $s = preg_replace('/[^\w\-+.]/u', '_', trim($cardNo));
    if (str_ends_with($s, '-PRproteinbar')) {
        // v2: earlier cache files held the base print's art, not the promo face.
        $s .= '_v2';
    }
    return $s !== '' ? $s : 'unknown';
}

function localCardImageFile(string $cardNo): ?string {
    if ($cardNo === '') {
        return null;
    }
    ensureCardimgDir();
    $base = safeCardImgBasename($cardNo);
    foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $ext) {
        $path = CARDIMG_DIR . $base . '.' . $ext;
        if (is_file($path) && filesize($path) > 200) {
            return $path;
        }
    }
    return null;
}

function localCardImageWebPath(string $cardNo): ?string {
    $file = localCardImageFile($cardNo);
    return $file ? 'cardimg/' . basename($file) : null;
}

/** Fixed widths prevent arbitrary query values from filling runtime storage. */
function tcgCardImageVariantWidth(mixed $value): int {
    $width = filter_var($value, FILTER_VALIDATE_INT);
    return in_array($width, [96, 180, 256], true) ? $width : 0;
}

function cardImageVariantDir(int $width): string {
    return CARDIMG_DIR . 'thumbs/' . $width . '/';
}

function localCardImageVariantFile(string $cardNo, int $width): ?string {
    if ($cardNo === '' || $width <= 0) {
        return null;
    }
    $path = cardImageVariantDir($width) . safeCardImgBasename($cardNo) . '.webp';
    return is_file($path) && filesize($path) > 200 ? $path : null;
}

/**
 * Build a transparent WebP thumbnail from the permanent full-art cache.
 * If GD/WebP is unavailable, return the full image rather than failing the card.
 */
function ensureCardImageVariant(string $cardNo, string $source, int $width): string {
    if ($width <= 0 || !is_file($source)) {
        return $source;
    }
    $existing = localCardImageVariantFile($cardNo, $width);
    if ($existing && filemtime($existing) >= filemtime($source)) {
        return $existing;
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
        return $source;
    }

    $bytes = @file_get_contents($source);
    $src = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
    if (!$src) {
        return $source;
    }
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW < 1 || $srcH < 1) {
        imagedestroy($src);
        return $source;
    }

    $targetW = min($width, $srcW);
    $targetH = max(1, (int)round($srcH * ($targetW / $srcW)));
    $dst = imagecreatetruecolor($targetW, $targetH);
    if (!$dst) {
        imagedestroy($src);
        return $source;
    }
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $transparent);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $srcW, $srcH);

    $dir = cardImageVariantDir($width);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $dest = $dir . safeCardImgBasename($cardNo) . '.webp';
    $tmp = is_dir($dir) ? @tempnam($dir, '.thumb-') : false;
    $written = is_string($tmp) && @imagewebp($dst, $tmp, 82);
    imagedestroy($dst);
    imagedestroy($src);
    if (!$written || !is_string($tmp)) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        return $source;
    }
    // tempnam() creates 0600; the web server must be able to read thumbs served as static files.
    @chmod($tmp, 0644);
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        return $source;
    }
    return $dest;
}

function lookupCardImageUrl(string $cardNo): string {
    if ($cardNo === '') {
        return '';
    }
    $cardsFile = tcgPath('cards');
    if (!is_file($cardsFile)) {
        return '';
    }
    $data = json_decode(file_get_contents($cardsFile), true);
    foreach ($data['cards'] ?? [] as $c) {
        if (($c['card_no'] ?? '') === $cardNo) {
            return (string)($c['image'] ?? '');
        }
    }
    return '';
}

function tcgCardImageAllowedHosts(): array {
    static $hosts = null;
    if ($hosts !== null) {
        return $hosts;
    }
    $map = [];
    $cardsFile = tcgPath('cards');
    if (!is_file($cardsFile)) {
        $hosts = [];
        return $hosts;
    }
    $data = json_decode(file_get_contents($cardsFile), true);
    foreach ($data['cards'] ?? [] as $c) {
        $image = (string)($c['image'] ?? '');
        if ($image === '') {
            continue;
        }
        $host = parse_url($image, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $map[strtolower($host)] = true;
        }
    }
    $hosts = array_keys($map);
    return $hosts;
}

function tcgAssertCardImageUrlAllowed(string $url): void {
    if (!preg_match('#^https?://#i', $url)) {
        throw new InvalidArgumentException('Invalid image url');
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        throw new InvalidArgumentException('Invalid image url host');
    }
    $allowed = tcgCardImageAllowedHosts();
    if ($allowed !== [] && !in_array(strtolower($host), array_map('strtolower', $allowed), true)) {
        throw new InvalidArgumentException('Image host not allowed');
    }
}

function cacheCardImageFromUrl(string $cardNo, string $url): array {
    if ($cardNo === '' || $url === '') {
        throw new InvalidArgumentException('card_no and url required');
    }
    tcgAssertCardImageUrlAllowed($url);

    ensureCardimgDir();

    $existing = localCardImageFile($cardNo);
    if ($existing) {
        // Warm board/grid thumbs even when full art was already on disk.
        tcgPrebuildCardImageThumbs($cardNo, $existing);
        return [
            'ok'      => true,
            'cached'  => true,
            'path'    => 'cardimg/' . basename($existing),
        ];
    }

    $pathPart = parse_url($url, PHP_URL_PATH) ?: '';
    $ext = strtolower(pathinfo($pathPart, PATHINFO_EXTENSION) ?: 'png');
    $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'png';
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
        $ext = 'png';
    }

    $dest = CARDIMG_DIR . safeCardImgBasename($cardNo) . '.' . $ext;

    $ctx = stream_context_create([
        'http' => [
            'timeout'     => 20,
            'user_agent'  => 'LLTCG-ImageCache/1.0',
            'follow_location' => 1,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || strlen($data) < 200) {
        throw new RuntimeException('Could not download card image');
    }

    if (@file_put_contents($dest, $data) === false) {
        throw new RuntimeException('Could not write card image cache');
    }

    tcgPrebuildCardImageThumbs($cardNo, $dest);

    return [
        'ok'      => true,
        'cached'  => false,
        'path'    => 'cardimg/' . basename($dest),
    ];
}

/** Build common board/grid WebP thumbs so first paint is not a cold GD hit. */
function tcgPrebuildCardImageThumbs(string $cardNo, string $source): void {
    if ($cardNo === '' || !is_file($source)) {
        return;
    }
    foreach ([180, 256] as $width) {
        try {
            ensureCardImageVariant($cardNo, $source, $width);
        } catch (Throwable $e) {
            // Thumb build is best-effort; full art remains usable.
        }
    }
}
