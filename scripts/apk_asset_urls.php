<?php
/**
 * Shared helpers for APK static-asset URLs (manifest + tests).
 */

function tcgApkAssetUrlEligible(string $url): bool
{
    $url = trim($url);
    if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'blob:')) {
        return false;
    }
    $path = $url;
    $qPos = strpos($path, '?');
    if ($qPos !== false) {
        $path = substr($path, 0, $qPos);
    }
    $path = str_replace('\\', '/', $path);
    if (preg_match('#(?:^|/)api\.php$#', $path)) {
        return false;
    }
    if (preg_match('#_catalog\.json$#', $path)) {
        return false;
    }
    if (preg_match('#(?:playmat|lltcg-back)\.png$#', $path)) {
        return true;
    }
    if (preg_match('#cardimg\.php$#', $path)) {
        return true;
    }
    foreach ([
        '/assets/sleeves/',
        '/assets/playmats/',
        '/assets/stamps/',
        '/assets/sfx/',
        'assets/sleeves/',
        'assets/playmats/',
        'assets/stamps/',
        'assets/sfx/',
    ] as $needle) {
        if (str_contains($path, $needle)) {
            return true;
        }
    }
    return false;
}

/**
 * @return list<array{url:string,bytes?:int}>
 */
function tcgBuildApkAssetManifestEntries(string $root): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $seen = [];
    $out = [];

    $add = static function (string $url, ?int $bytes = null) use (&$seen, &$out): void {
        $url = ltrim(str_replace('\\', '/', $url), '/');
        if ($url === '' || isset($seen[$url])) {
            return;
        }
        $seen[$url] = true;
        $row = ['url' => $url];
        if ($bytes !== null && $bytes >= 0) {
            $row['bytes'] = $bytes;
        }
        $out[] = $row;
    };

    $cardsFile = $root . '/cards.json';
    $raw = is_file($cardsFile) ? file_get_contents($cardsFile) : false;
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $cards = is_array($data) && isset($data['cards']) && is_array($data['cards']) ? $data['cards'] : [];
    foreach ($cards as $card) {
        $no = is_array($card) ? trim((string)($card['card_no'] ?? '')) : '';
        if ($no === '') {
            continue;
        }
        $enc = rawurlencode($no);
        $add('cardimg.php?card_no=' . $enc);
        foreach ([96, 180, 256] as $w) {
            $add('cardimg.php?card_no=' . $enc . '&w=' . $w);
        }
    }

    $walkDir = static function (string $dir, string $webPrefix) use ($add): void {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'wav', 'mp3', 'ogg', 'm4a'], true)) {
                continue;
            }
            $full = str_replace('\\', '/', $file->getPathname());
            $rel = substr($full, strlen(rtrim(str_replace('\\', '/', $dir), '/') . '/'));
            $url = rtrim($webPrefix, '/') . '/' . str_replace('\\', '/', $rel);
            $add($url, $file->getSize());
        }
    };

    $walkDir($root . '/assets/sleeves', 'assets/sleeves');
    $walkDir($root . '/assets/playmats', 'assets/playmats');
    $walkDir($root . '/assets/stamps', 'assets/stamps');
    $walkDir($root . '/assets/sfx', 'assets/sfx');
    foreach (['playmat.png', 'lltcg-back.png'] as $rootAsset) {
        $path = $root . '/' . $rootAsset;
        if (is_file($path)) {
            $add($rootAsset, filesize($path) ?: null);
        }
    }

    $sfxMan = $root . '/sfx_manifest.web.json';
    if (is_file($sfxMan)) {
        $sfx = json_decode((string)file_get_contents($sfxMan), true);
        $events = is_array($sfx) && isset($sfx['events']) && is_array($sfx['events']) ? $sfx['events'] : [];
        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            if (!empty($ev['file'])) {
                $add('assets/sfx/' . ltrim((string)$ev['file'], '/'));
            }
            foreach ($ev['variants'] ?? [] as $v) {
                if (is_array($v) && !empty($v['file'])) {
                    $add('assets/sfx/' . ltrim((string)$v['file'], '/'));
                }
            }
        }
    }

    $stampMan = $root . '/stamps_manifest.json';
    if (is_file($stampMan)) {
        $st = json_decode((string)file_get_contents($stampMan), true);
        $locales = is_array($st) && isset($st['locales']) && is_array($st['locales']) ? $st['locales'] : [];
        foreach ($locales as $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach (['image', 'voice', 'se'] as $k) {
                    $rel = trim((string)($row[$k] ?? ''));
                    if ($rel === '') {
                        continue;
                    }
                    $add('assets/stamps/' . ltrim($rel, '/'));
                }
            }
        }
    }

    return $out;
}
