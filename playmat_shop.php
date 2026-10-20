<?php
/**
 * Playmat ownership, catalog load, shop helpers.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/playmats.php';
require_once __DIR__ . '/coins.php';
require_once __DIR__ . '/sleeve_shop.php'; // reuse generation / portrait helpers

function tcgPlaymatCatalogPath(): string {
    return __DIR__ . '/playmats_catalog.json';
}

function tcgPlaymatDisplayName(string $name): string {
    $s = trim($name);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/\bbushiroad\b\s*/iu', '', $s) ?? $s;
    $s = preg_replace('/^ラバーマットコレクション\s*(V2\s*)?/u', '', $s) ?? $s;
    $s = preg_replace('/\s{2,}/u', ' ', $s) ?? $s;
    return trim($s, " \t-:");
}

/** @return list<array{id: string, name: string, group: string, idol: string, src: string, vol?: int|null, line?: string}> */
function tcgLoadPlaymatCatalog(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = tcgPlaymatCatalogPath();
    if (!is_file($path)) {
        $cache = [];
        return $cache;
    }
    $raw = json_decode((string)file_get_contents($path), true);
    if (!is_array($raw)) {
        $cache = [];
        return $cache;
    }
    $items = is_array($raw['items'] ?? null) ? $raw['items'] : $raw;
    $out = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = tcgNormalizePlaymatId($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $out[] = [
            'id' => $id,
            'name' => tcgPlaymatDisplayName((string)($row['name'] ?? $id)),
            'group' => (string)($row['group'] ?? 'Other'),
            'idol' => (string)($row['idol'] ?? 'Group'),
            'src' => (string)($row['src'] ?? ('assets/playmats/' . $id . '.webp')),
            'vol' => isset($row['vol']) ? intval($row['vol']) : null,
            'line' => (string)($row['line'] ?? ''),
            'added_at' => (string)($row['added_at'] ?? $row['addedAt'] ?? ''),
        ];
    }
    $cache = $out;
    return $cache;
}

function tcgPlaymatCatalogById(string $id): ?array {
    $id = tcgNormalizePlaymatId($id);
    if ($id === '') {
        return null;
    }
    foreach (tcgLoadPlaymatCatalog() as $row) {
        if ($row['id'] === $id) {
            return $row;
        }
    }
    return null;
}

function tcgPlaymatCatalogIdValid(string $id): bool {
    return tcgPlaymatCatalogById($id) !== null;
}

/** @return list<string> */
function tcgOwnedPlaymatIds(string $discordId): array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT playmat_id FROM tcg_owned_playmats WHERE discord_id = ? ORDER BY acquired_at ASC');
    $stmt->execute([$discordId]);
    $ids = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = tcgNormalizePlaymatId($row['playmat_id'] ?? '');
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    return $ids;
}

function tcgOwnsPlaymat(string $discordId, string $playmatId): bool {
    $playmatId = tcgNormalizePlaymatId($playmatId);
    if ($playmatId === '') {
        return true; // default board
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT 1 FROM tcg_owned_playmats WHERE discord_id = ? AND playmat_id = ?');
    $stmt->execute([$discordId, $playmatId]);
    return (bool)$stmt->fetchColumn();
}

function tcgGrantOwnedPlaymat(string $discordId, string $playmatId, string $source = 'shop'): void {
    $playmatId = tcgNormalizePlaymatId($playmatId);
    if ($playmatId === '' || !tcgPlaymatCatalogIdValid($playmatId)) {
        throw new Exception('Unknown playmat', 400);
    }
    $db = tcgDb();
    $db->prepare('INSERT OR IGNORE INTO tcg_owned_playmats (discord_id, playmat_id, acquired_at, source)
        VALUES (?, ?, ?, ?)')
        ->execute([$discordId, $playmatId, time(), $source]);
    // "Own 10 playmats" milestone — however the playmat was obtained.
    try {
        if (!function_exists('tcgMissionCheckOwnedCosmetics')) {
            require_once __DIR__ . '/missions.php';
        }
        tcgMissionCheckOwnedCosmetics($discordId);
    } catch (Throwable $e) {
        // ignore
    }
}
