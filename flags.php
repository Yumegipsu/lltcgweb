<?php
/**
 * Profile flag catalog (country/region flags for leaderboard).
 * Images live under assets/flags/; Antarctica is intentionally excluded.
 */

function tcgFlagsDir(): string {
    return __DIR__ . '/assets/flags';
}

/** Derive display name from filename like CA_Canada_rect.png → Canada */
function tcgFlagNameFromFile(string $file): string {
    $base = preg_replace('/\.[^.]+$/', '', $file);
    if (!is_string($base)) {
        $base = $file;
    }
    $base = preg_replace('/_rect$/i', '', $base);
    if (!is_string($base)) {
        $base = $file;
    }
    $base = preg_replace('/^[A-Z]{2}_/', '', $base);
    if (!is_string($base)) {
        $base = $file;
    }
    $name = trim(str_replace('_', ' ', $base));
    $name = str_replace(["A\xCC\x8A", "a\xCC\x8A"], ['Å', 'å'], $name);
    return $name !== '' ? $name : $file;
}

/**
 * @return list<array{id: string, url: string, name: string}>
 */
function tcgAvailableFlags(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $dir = tcgFlagsDir();
    $flags = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if ($file === 'AQ_Antarctica_rect.png') {
                continue;
            }
            if (!preg_match('/\.(png|jpe?g|gif|webp)$/i', $file)) {
                continue;
            }
            $flags[] = [
                'id' => $file,
                'url' => 'assets/flags/' . $file,
                'name' => tcgFlagNameFromFile($file),
            ];
        }
    }
    usort($flags, static function ($a, $b) {
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    $cache = $flags;
    return $cache;
}

/** @return array<string, array{id: string, url: string, name: string}> */
function tcgFlagMapById(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    foreach (tcgAvailableFlags() as $flag) {
        $map[$flag['id']] = $flag;
    }
    return $map;
}

/**
 * Normalize stored / submitted flag id to a catalog filename, or empty for none.
 */
function tcgNormalizeEquippedFlag(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '' || $raw === '__none__' || strcasecmp($raw, 'none') === 0) {
        return '';
    }
    // Accept full URL or path — keep basename only.
    if (str_contains($raw, '/') || str_contains($raw, '\\')) {
        $raw = basename(parse_url($raw, PHP_URL_PATH) ?: $raw);
    }
    $raw = rawurldecode($raw);
    if ($raw === 'AQ_Antarctica_rect.png') {
        return '';
    }
    if (!isset(tcgFlagMapById()[$raw])) {
        return '';
    }
    return $raw;
}

/**
 * @return array{id: string, url: string, name: string}|null
 */
function tcgFormatEquippedFlag(?string $raw): ?array {
    $id = tcgNormalizeEquippedFlag($raw);
    if ($id === '') {
        return null;
    }
    return tcgFlagMapById()[$id] ?? null;
}
