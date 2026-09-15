<?php
/**
 * Profile titles (character Fan badges, etc.).
 *
 * Catalog: titles_catalog.json (committed). Art under titles/Sprite/ (deployed, often gitignored).
 * Unlock: Stage Member plays for the mapped idol (same counters as play-stat milestones).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/play_stats.php';

const TCG_TITLE_FAN_UNLOCK_PLAYS = 500;

function tcgTitlesCatalogPath(): string {
    return __DIR__ . '/titles_catalog.json';
}

/**
 * @return list<array<string,mixed>>
 */
function tcgTitlesCatalog(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = tcgTitlesCatalogPath();
    if (!is_file($path)) {
        $cache = [];
        return $cache;
    }
    $raw = json_decode((string)file_get_contents($path), true);
    $list = is_array($raw['titles'] ?? null) ? $raw['titles'] : (is_array($raw) ? $raw : []);
    $out = [];
    foreach ($list as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        // Friend tier reserved for a later drop — ignore if present in catalog.
        $tier = strtolower(trim((string)($row['tier'] ?? 'fan')));
        if ($tier === 'friend') {
            continue;
        }
        $out[] = $row;
    }
    usort($out, static function ($a, $b) {
        $sa = intval($a['sort'] ?? 0);
        $sb = intval($b['sort'] ?? 0);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }
        return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });
    $cache = $out;
    return $cache;
}

/**
 * @return array<string,mixed>|null
 */
function tcgTitleDefById(string $id): ?array {
    $id = trim($id);
    if ($id === '') {
        return null;
    }
    foreach (tcgTitlesCatalog() as $row) {
        if ((string)($row['id'] ?? '') === $id) {
            return $row;
        }
    }
    return null;
}

function tcgTitleImageUrl(array $def): string {
    $rel = trim((string)($def['image'] ?? ''));
    if ($rel === '') {
        $id = trim((string)($def['id'] ?? ''));
        if ($id !== '') {
            $rel = 'titles/Sprite/' . $id . '.png';
        }
    }
    return $rel;
}

function tcgTitleUnlockPlays(array $def): int {
    $n = intval($def['unlock_plays'] ?? TCG_TITLE_FAN_UNLOCK_PLAYS);
    return $n > 0 ? $n : TCG_TITLE_FAN_UNLOCK_PLAYS;
}

/** Stage Member play count for the title's idol (exact play-stat key). */
function tcgTitleIdolPlayCount(string $discordId, array $def): int {
    $idol = trim((string)($def['idol'] ?? ''));
    if ($discordId === '' || $idol === '') {
        return 0;
    }
    return tcgGetPlayStat($discordId, TCG_PLAY_TRACKER_STAGE, TCG_PLAY_DIM_IDOL, $idol);
}

function tcgTitleIsUnlocked(string $discordId, array $def): bool {
    return tcgTitleIdolPlayCount($discordId, $def) >= tcgTitleUnlockPlays($def);
}

/**
 * Public payload for an equipped / catalog title.
 *
 * @param array<string,mixed>|null $progressOpts include progress when true (self picker)
 * @return array<string,mixed>|null
 */
function tcgFormatTitle(?array $def, ?string $discordId = null, array $opts = []): ?array {
    if (!$def) {
        return null;
    }
    $id = trim((string)($def['id'] ?? ''));
    if ($id === '') {
        return null;
    }
    $style = strtolower(trim((string)($def['style'] ?? 'wide')));
    if ($style !== 'portrait') {
        $style = 'wide';
    }
    $name = trim((string)($def['name'] ?? $id));
    $idol = trim((string)($def['idol'] ?? ''));
    $idolShort = trim((string)($def['idol_short'] ?? ''));
    $need = tcgTitleUnlockPlays($def);
    $payload = [
        'id' => $id,
        'name' => $name,
        'style' => $style,
        'tier' => strtolower(trim((string)($def['tier'] ?? 'fan'))) ?: 'fan',
        'unit' => (string)($def['unit'] ?? ''),
        'idol' => $idol,
        'idol_short' => $idolShort !== '' ? $idolShort : $idol,
        'url' => tcgTitleImageUrl($def),
        'unlock_plays' => $need,
    ];
    if (!empty($opts['include_progress']) && $discordId) {
        $have = tcgTitleIdolPlayCount($discordId, $def);
        $unlocked = $have >= $need;
        $payload['progress'] = $have;
        $payload['unlocked'] = $unlocked;
        $payload['unlock_hint'] = tcgTitleUnlockHint($def, $have, $need);
    }
    return $payload;
}

function tcgTitleUnlockHint(array $def, int $have, int $need): string {
    $idol = trim((string)($def['idol'] ?? $def['idol_short'] ?? 'this Member'));
    if ($have >= $need) {
        return 'Unlocked by playing ' . $idol . ' as a Stage Member ' . $need . ' times.';
    }
    return 'Play ' . $idol . ' as a Stage Member ' . $need . ' times. (' . $have . '/' . $need . ')';
}

function tcgFormatEquippedTitle(?string $titleId): ?array {
    $def = tcgTitleDefById((string)$titleId);
    if (!$def) {
        return null;
    }
    return tcgFormatTitle($def);
}

/**
 * Catalog slots for the title picker (locked = blank slot still reserved).
 *
 * @return list<array<string,mixed>>
 */
function tcgTitlesListForUser(string $discordId): array {
    $out = [];
    foreach (tcgTitlesCatalog() as $def) {
        $row = tcgFormatTitle($def, $discordId, ['include_progress' => true]);
        if ($row) {
            $out[] = $row;
        }
    }
    return $out;
}

function tcgNormalizeTitleId(?string $raw): string {
    $id = trim((string)$raw);
    if ($id === '' || strcasecmp($id, 'none') === 0 || $id === '__none__') {
        return '';
    }
    return tcgTitleDefById($id) ? $id : '';
}

function tcgApiTitlesList(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $user = tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $equippedId = trim((string)($user['title_id'] ?? ''));
    return [
        'success' => true,
        'equipped_title_id' => $equippedId !== '' ? $equippedId : null,
        'equipped_title' => tcgFormatEquippedTitle($equippedId !== '' ? $equippedId : null),
        'titles' => tcgTitlesListForUser($uid),
        'unlock_plays' => TCG_TITLE_FAN_UNLOCK_PLAYS,
    ];
}

function tcgApiTitleSet(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $raw = trim((string)($body['title_id'] ?? $body['equipped_title'] ?? ''));
    $clear = ($raw === '' || strcasecmp($raw, 'none') === 0 || $raw === '__none__');
    $titleId = '';
    if (!$clear) {
        $def = tcgTitleDefById($raw);
        if (!$def) {
            throw new Exception('Unknown title', 400);
        }
        if (!tcgTitleIsUnlocked($uid, $def)) {
            $have = tcgTitleIdolPlayCount($uid, $def);
            $need = tcgTitleUnlockPlays($def);
            throw new Exception(
                'Title locked — play ' . trim((string)($def['idol'] ?? 'this Member'))
                . ' as a Stage Member ' . $need . ' times (' . $have . '/' . $need . ').',
                403
            );
        }
        $titleId = (string)$def['id'];
    }
    tcgDb()->prepare('UPDATE tcg_users SET title_id = ?, updated_at = ? WHERE discord_id = ?')
        ->execute([$titleId !== '' ? $titleId : null, time(), $uid]);
    return [
        'success' => true,
        'title_id' => $titleId !== '' ? $titleId : null,
        'title' => tcgFormatEquippedTitle($titleId !== '' ? $titleId : null),
    ];
}
