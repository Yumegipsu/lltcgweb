<?php
/**
 * LLSIF sticker helpers — manifest validation and profile favorites.
 */

function tcgStampManifestPath(): string {
    return __DIR__ . '/stamps_manifest.json';
}

/** @return array{locales: array<string, list<array>>, version?: int}|null */
function tcgLoadStampManifest(): ?array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = tcgStampManifestPath();
    if (!is_readable($path)) {
        $cache = null;
        return null;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data) || empty($data['locales']) || !is_array($data['locales'])) {
        $cache = null;
        return null;
    }
    $cache = $data;
    return $cache;
}

function tcgStampManifestIds(string $locale): array {
    $manifest = tcgLoadStampManifest();
    if (!$manifest) {
        return [];
    }
    $locale = $locale === 'en' ? 'en' : 'ja';
    $ids = [];
    foreach ($manifest['locales'][$locale] ?? [] as $row) {
        $id = trim((string)($row['id'] ?? ''));
        if ($id !== '') {
            $ids[$id] = true;
        }
    }
    return $ids;
}

function tcgIsValidStampId(string $stampId, string $locale): bool {
    $stampId = trim($stampId);
    if ($stampId === '') {
        return false;
    }
    $allowed = tcgStampManifestIds($locale === 'en' ? 'en' : 'ja');
    if ($allowed) {
        return isset($allowed[$stampId]);
    }
    return (bool) preg_match('/^st_\d{3}_\d{3}$/', $stampId);
}

/** @return array{ja: list<string>, en: list<string>, profile: list<string>} */
function tcgParseStampFavorites(?string $json): array {
    $out = ['ja' => [], 'en' => [], 'profile' => []];
    if ($json === null || $json === '') {
        return $out;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return $out;
    }
    foreach (['ja', 'en', 'profile'] as $key) {
        $list = $data[$key] ?? [];
        if (!is_array($list)) {
            continue;
        }
        $seen = [];
        foreach ($list as $id) {
            $id = trim((string) $id);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $locale = $key === 'profile' ? 'ja' : $key;
            if (!tcgIsValidStampId($id, $locale) && $key === 'profile') {
                if (!tcgIsValidStampId($id, 'en')) {
                    continue;
                }
            } elseif (!tcgIsValidStampId($id, $locale)) {
                continue;
            }
            $seen[$id] = true;
            $out[$key][] = $id;
            $max = $key === 'profile' ? 6 : 24;
            if (count($out[$key]) >= $max) {
                break;
            }
        }
    }
    return $out;
}

function tcgFormatStampFavorites(?string $json): array {
    return tcgParseStampFavorites($json);
}

/** @param list<string> $ids @return list<string> */
function tcgSanitizeStampIdList(array $ids, string $bucket, int $max): array {
    $out = [];
    $seen = [];
    foreach ($ids as $id) {
        $id = trim((string) $id);
        if ($id === '' || isset($seen[$id])) {
            continue;
        }
        if ($bucket === 'profile') {
            if (!tcgIsValidStampId($id, 'ja') && !tcgIsValidStampId($id, 'en')) {
                continue;
            }
        } elseif (!tcgIsValidStampId($id, $bucket)) {
            continue;
        }
        $seen[$id] = true;
        $out[] = $id;
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}
