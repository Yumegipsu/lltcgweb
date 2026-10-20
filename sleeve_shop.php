<?php
/**
 * Sleeve ownership, catalog load, login-day tracking, shop helpers.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sleeves.php';
require_once __DIR__ . '/coins.php';

function tcgSleeveCatalogPath(): string {
    return __DIR__ . '/sleeves_catalog.json';
}

/**
 * Display title for sleeves: strip vendor branding and pack-count suffixes.
 * Applied on load so future catalog imports stay clean even if source names are raw.
 */
function tcgSleeveDisplayName(string $name): string {
    $s = trim($name);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/\bbushiroad\b\s*/iu', '', $s) ?? $s;
    $s = preg_replace('/\(\s*\d+\s*[- ]?\s*packs?\s*\)/iu', '', $s) ?? $s;
    $s = preg_replace('/\s{2,}/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+([:,])/u', '$1', $s) ?? $s;
    $s = preg_replace('/([:,])\s*/u', '$1 ', $s) ?? $s;
    return trim($s, " \t-:");
}

function tcgIdolPortraitsPath(): string {
    return __DIR__ . '/idol_portraits.json';
}

/** @return list<array{id: string, name: string, group: string, idol: string, src: string}> */
function tcgLoadSleeveCatalog(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = tcgSleeveCatalogPath();
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
        $id = tcgNormalizeSleeveId($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $orient = strtolower(trim((string)($row['orientation'] ?? 'portrait')));
        if ($orient !== 'landscape') {
            $orient = 'portrait';
        }
        $out[] = [
            'id' => $id,
            'name' => tcgSleeveDisplayName((string)($row['name'] ?? $id)),
            'group' => (string)($row['group'] ?? 'Other'),
            'idol' => (string)($row['idol'] ?? 'Other'),
            'orientation' => $orient,
            'src' => (string)($row['src'] ?? ('assets/sleeves/' . $id . '.webp')),
            'added_at' => (string)($row['added_at'] ?? $row['addedAt'] ?? ''),
        ];
    }
    $cache = $out;
    return $cache;
}

function tcgSleeveCatalogById(string $id): ?array {
    $id = tcgNormalizeSleeveId($id);
    if ($id === '') {
        return null;
    }
    foreach (tcgLoadSleeveCatalog() as $row) {
        if ($row['id'] === $id) {
            return $row;
        }
    }
    return null;
}

function tcgSleeveCatalogIdValid(string $id): bool {
    return tcgSleeveCatalogById($id) !== null;
}

/** @return list<array{id: string, name: string, unit: string, portrait: string}> */
function tcgLoadIdolPortraits(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = tcgIdolPortraitsPath();
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
        $id = trim((string)($row['id'] ?? $row['name'] ?? ''));
        if ($id === '') {
            continue;
        }
        $out[] = [
            'id' => $id,
            'name' => (string)($row['name'] ?? $id),
            'unit' => (string)($row['unit'] ?? 'Other'),
            'portrait' => (string)($row['portrait'] ?? ''),
        ];
    }
    $cache = $out;
    return $cache;
}

/** @return list<string> */
function tcgOwnedSleeveIds(string $discordId): array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT sleeve_id FROM tcg_owned_sleeves WHERE discord_id = ? ORDER BY acquired_at ASC');
    $stmt->execute([$discordId]);
    $ids = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = tcgNormalizeSleeveId($row['sleeve_id'] ?? '');
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    return $ids;
}

/** @return list<string> owned sleeve ids that still need first-equip intro */
function tcgOwnedSleevesNeedingIntro(string $discordId): array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT sleeve_id FROM tcg_owned_sleeves
        WHERE discord_id = ? AND COALESCE(equip_intro_seen, 0) = 0 ORDER BY acquired_at ASC');
    $stmt->execute([$discordId]);
    $ids = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = tcgNormalizeSleeveId($row['sleeve_id'] ?? '');
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    return $ids;
}

function tcgOwnsSleeve(string $discordId, string $sleeveId): bool {
    $sleeveId = tcgNormalizeSleeveId($sleeveId);
    if ($sleeveId === '') {
        return true; // default back
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT 1 FROM tcg_owned_sleeves WHERE discord_id = ? AND sleeve_id = ?');
    $stmt->execute([$discordId, $sleeveId]);
    return (bool)$stmt->fetchColumn();
}

function tcgGrantOwnedSleeve(string $discordId, string $sleeveId, string $source = 'shop'): void {
    $sleeveId = tcgNormalizeSleeveId($sleeveId);
    if ($sleeveId === '' || !tcgSleeveCatalogIdValid($sleeveId)) {
        throw new Exception('Unknown sleeve', 400);
    }
    $db = tcgDb();
    $db->prepare('INSERT OR IGNORE INTO tcg_owned_sleeves (discord_id, sleeve_id, acquired_at, source, equip_intro_seen)
        VALUES (?, ?, ?, ?, 0)')
        ->execute([$discordId, $sleeveId, time(), $source]);
    // "Own 10 sleeves" milestone — however the sleeve was obtained.
    try {
        if (!function_exists('tcgMissionCheckOwnedCosmetics')) {
            require_once __DIR__ . '/missions.php';
        }
        tcgMissionCheckOwnedCosmetics($discordId);
    } catch (Throwable $e) {
        // ignore
    }
}

function tcgSleeveEquipIntroSeen(string $discordId, string $sleeveId): bool {
    $sleeveId = tcgNormalizeSleeveId($sleeveId);
    if ($sleeveId === '') {
        return true;
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT equip_intro_seen FROM tcg_owned_sleeves WHERE discord_id = ? AND sleeve_id = ?');
    $stmt->execute([$discordId, $sleeveId]);
    $val = $stmt->fetchColumn();
    return $val === false ? true : !empty($val);
}

function tcgMarkSleeveEquipIntroSeen(string $discordId, string $sleeveId): void {
    $sleeveId = tcgNormalizeSleeveId($sleeveId);
    if ($sleeveId === '') {
        return;
    }
    $db = tcgDb();
    $db->prepare('UPDATE tcg_owned_sleeves SET equip_intro_seen = 1 WHERE discord_id = ? AND sleeve_id = ?')
        ->execute([$discordId, $sleeveId]);
}

function tcgGetFreeSleeveClaims(string $discordId): int {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT free_sleeve_claims FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    return max(0, intval($stmt->fetchColumn() ?: 0));
}

function tcgSetFreeSleeveClaims(string $discordId, int $n): void {
    $db = tcgDb();
    $db->prepare('UPDATE tcg_users SET free_sleeve_claims = ?, updated_at = ? WHERE discord_id = ?')
        ->execute([max(0, $n), time(), $discordId]);
}

function tcgGetLoginDays(string $discordId): int {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT login_days FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    return max(0, intval($stmt->fetchColumn() ?: 0));
}

/**
 * Bootstrap + daily bump. Opening the hub/me once per JST day counts.
 * Retroactive: first run sets login_days from account age (capped at 10).
 */
function tcgTouchLoginDays(string $discordId): int {
    $db = tcgDb();
    $today = tcgTodayJst();
    $stmt = $db->prepare('SELECT login_days, login_days_last_date, login_days_bootstrapped, created_at
        FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0;
    }
    // Weekly "log in 7 days": remember every distinct JST day (best effort, never blocks login).
    try {
        if (!function_exists('tcgMissionOnLoginDay')) {
            require_once __DIR__ . '/missions.php';
        }
        tcgMissionOnLoginDay($discordId);
    } catch (Throwable $e) {
        // ignore
    }
    $days = max(0, intval($row['login_days'] ?? 0));
    $last = isset($row['login_days_last_date']) && $row['login_days_last_date'] !== ''
        ? (string)$row['login_days_last_date']
        : null;
    $bootstrapped = !empty($row['login_days_bootstrapped']);

    if (!$bootstrapped) {
        $created = intval($row['created_at'] ?? time());
        try {
            $tz = new DateTimeZone('Asia/Tokyo');
            $createdDay = (new DateTimeImmutable('@' . $created))->setTimezone($tz)->format('Y-m-d');
            $d0 = new DateTimeImmutable($createdDay, $tz);
            $d1 = new DateTimeImmutable($today, $tz);
            $age = max(1, (int)$d0->diff($d1)->days + 1);
        } catch (Throwable $e) {
            $age = 1;
        }
        $days = max($days, min(10, $age));
        $db->prepare('UPDATE tcg_users SET login_days = ?, login_days_bootstrapped = 1,
            login_days_last_date = ?, updated_at = ? WHERE discord_id = ?')
            ->execute([$days, $today, time(), $discordId]);
        $last = $today;
        if (function_exists('tcgMissionCheckLoginDays')) {
            tcgMissionCheckLoginDays($discordId);
        }
        return $days;
    }

    if ($last !== $today) {
        $days += 1;
        $db->prepare('UPDATE tcg_users SET login_days = ?, login_days_last_date = ?, updated_at = ?
            WHERE discord_id = ?')
            ->execute([$days, $today, time(), $discordId]);
        if (function_exists('tcgMissionCheckLoginDays')) {
            tcgMissionCheckLoginDays($discordId);
        }
    }
    return $days;
}

/** Map sleeve folder group → shop generation unit label. */
function tcgSleeveShopUnitForGroup(string $group): string {
    $g = strtolower(trim($group));
    return match ($g) {
        'muse', "µ's", "μ's", 'mus', "mu's" => "µ's",
        'aqours', 'sunshine' => 'Aqours',
        'nijigasaki', 'niji' => 'Nijigasaki',
        'liella', 'liella!', 'superstar' => 'Liella!',
        'hasunosora', 'hasu' => 'Hasunosora',
        'mixed', 'crossover', 'collab', 'multi' => 'Mixed',
        default => 'Other',
    };
}

/** Idol Story unit logo for sleeve-shop generation / group tiles. */
function tcgSleeveShopUnitIconUrl(string $unit): string {
    return match ($unit) {
        "µ's" => 'https://i.idol.st/static/img/i_unit/%CE%BC-s.png',
        'Aqours' => 'https://i.idol.st/static/img/i_unit/Aqours.png',
        'Nijigasaki' => 'https://i.idol.st/static/img/i_unit/Nijigasaki-High-School.png',
        'Liella!', 'Liella' => 'https://i.idol.st/static/img/i_unit/Liella.png',
        'Hasunosora' => 'https://i.idol.st/static/img/i_unit/Hasunosora-Girls-High-School-Idol-Club.png',
        // Mixed / crossover sleeves — no dedicated series mark on idol.st; reuse Other.
        'Mixed' => 'https://i.idol.st/static/img/i_unit/Other.png',
        default => 'https://i.idol.st/static/img/i_unit/Other.png',
    };
}

/**
 * True when catalog idol is a whole-group bucket (not a single character).
 * e.g. Muse / Mu's / Group under a generation.
 */
function tcgSleeveShopIsGroupIdol(string $idol, string $unit): bool {
    $i = strtolower(trim($idol));
    if ($i === '' || $i === 'group' || $i === 'unit' || $i === 'mixed' || $i === 'crossover') {
        return true;
    }
    $u = strtolower(trim($unit));
    $aliases = match ($u) {
        "µ's", "μ's", 'muse', 'mus' => ['muse', "mu's", "μ's", "µ's", 'mus'],
        'aqours' => ['aqours', 'sunshine'],
        'nijigasaki' => ['nijigasaki', 'niji'],
        'liella!', 'liella' => ['liella', 'liella!', 'superstar'],
        'hasunosora' => ['hasunosora', 'hasu'],
        'mixed' => ['mixed', 'crossover', 'collab', 'multi', 'group'],
        default => [],
    };
    return in_array($i, $aliases, true);
}

/** Generation display order. */
function tcgSleeveShopGenerationOrder(): array {
    return ["µ's", 'Aqours', 'Nijigasaki', 'Liella!', 'Hasunosora', 'Mixed', 'Other'];
}
