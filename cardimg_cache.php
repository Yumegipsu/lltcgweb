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
    $hosts = [];
    $cardsFile = tcgPath('cards');
    if (!is_file($cardsFile)) {
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
            $hosts[strtolower($host)] = true;
        }
    }
    return array_keys($hosts);
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

    return [
        'ok'      => true,
        'cached'  => false,
        'path'    => 'cardimg/' . basename($dest),
    ];
}
