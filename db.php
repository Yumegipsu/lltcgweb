<?php
/**
 * SQLite persistence for TCG accounts (Hostinger-friendly).
 *
 * tcg_users, collection, deck presets, booster box pity, ranked ELO (tcg_rank),
 * and matchmaking queue rows. WAL mode; migrations in tcgDbMigrate().
 */
define('TCG_DATA_DIR', __DIR__ . '/data/');
define('TCG_DB_PATH', TCG_DATA_DIR . 'tcg.db');

require_once __DIR__ . '/deck_validate.php';

const TCG_STAR_GEMS_PER_DUPE = 10;
const TCG_STAR_GEMS_PACK_COST = 100;
const TCG_STAR_GEMS_BOX_COST = 3000;

/** Star Gems awarded when a duplicate is converted (above deck copy limit). */
function tcgStarGemsForDupe(?array $card, string $cardNo = ''): int {
    $rarity = strtoupper(trim((string)($card['rarity'] ?? '')));
    if ($rarity === '') {
        return TCG_STAR_GEMS_PER_DUPE;
    }

    $typeEn = (string)($card['card_type_en'] ?? '');
    if ($typeEn === '') {
        $typeEn = match ((string)($card['card_type'] ?? '')) {
            'メンバー' => 'Member',
            'ライブ' => 'Live',
            'エネルギー' => 'Energy',
            default => '',
        };
    }

    if ($rarity === 'CL') {
        return 50;
    }

    if ($typeEn === 'Energy') {
        return match ($rarity) {
            'PE' => 10,
            'PR' => 10,
            'PR+' => 30,
            'P', 'RE' => 30,
            'PE+' => 50,
            'SRE' => 80,
            'LLE', 'SECE', 'SEC+', 'SECS' => 100,
            default => TCG_STAR_GEMS_PER_DUPE,
        };
    }

    if ($typeEn === 'Live') {
        return match ($rarity) {
            'L' => 10,
            'P', 'R' => 20,
            'R+' => 30,
            'L+' => 50,
            'SECL' => 100,
            default => TCG_STAR_GEMS_PER_DUPE,
        };
    }

    // Member (and unknown types default to member table)
    return match ($rarity) {
        'N' => 10,
        'R', 'P' => 20,
        'R+' => 30,
        'P+' => 50,
        'AR', 'RM' => 80,
        'SEC' => 100,
        default => TCG_STAR_GEMS_PER_DUPE,
    };
}

function tcgDb(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!is_dir(TCG_DATA_DIR)) {
        mkdir(TCG_DATA_DIR, 0755, true);
    }
    $pdo = new PDO('sqlite:' . TCG_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    tcgDbMigrate($pdo);
    return $pdo;
}

function tcgDbMigrate(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_users (
        discord_id TEXT PRIMARY KEY,
        username TEXT NOT NULL DEFAULT "Player",
        avatar_url TEXT,
        starter_deck TEXT,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_collection (
        discord_id TEXT NOT NULL,
        card_no TEXT NOT NULL,
        qty INTEGER NOT NULL DEFAULT 1,
        PRIMARY KEY (discord_id, card_no),
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_daily_state (
        discord_id TEXT PRIMARY KEY,
        last_open_date TEXT,
        packs_opened_today INTEGER NOT NULL DEFAULT 0,
        first_day_bonus_used INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_box_progress (
        discord_id TEXT NOT NULL,
        box_id TEXT NOT NULL,
        packs_in_box INTEGER NOT NULL DEFAULT 0,
        boxes_opened INTEGER NOT NULL DEFAULT 0,
        pe_pity INTEGER NOT NULL DEFAULT 0,
        pplus_pity INTEGER NOT NULL DEFAULT 0,
        sec_pity INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (discord_id, box_id),
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_deck_presets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        discord_id TEXT NOT NULL,
        slot INTEGER NOT NULL,
        name TEXT NOT NULL,
        main_deck TEXT NOT NULL,
        energy_deck TEXT NOT NULL,
        equipped INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL,
        UNIQUE (discord_id, slot),
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_rank (
        discord_id TEXT PRIMARY KEY,
        rating INTEGER NOT NULL DEFAULT 1000,
        wins INTEGER NOT NULL DEFAULT 0,
        losses INTEGER NOT NULL DEFAULT 0,
        draws INTEGER NOT NULL DEFAULT 0,
        games INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL,
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_match_queue (
        discord_id TEXT PRIMARY KEY,
        rating INTEGER NOT NULL DEFAULT 1000,
        joined_at INTEGER NOT NULL,
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_ranked_matches (
        match_id TEXT PRIMARY KEY,
        room_id TEXT NOT NULL,
        p1_id TEXT NOT NULL,
        p2_id TEXT NOT NULL,
        p1_token TEXT NOT NULL,
        p2_token TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "pending",
        created_at INTEGER NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_casual_queue (
        queue_key TEXT PRIMARY KEY,
        discord_id TEXT,
        player_name TEXT NOT NULL,
        join_body TEXT NOT NULL,
        joined_at INTEGER NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_casual_matches (
        queue_key TEXT NOT NULL,
        room_id TEXT NOT NULL,
        player_token TEXT NOT NULL,
        player_id TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        PRIMARY KEY (queue_key, room_id)
    )');

    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_casual_queue_discord
        ON tcg_casual_queue(discord_id) WHERE discord_id IS NOT NULL');

    tcgDbEnsureColumn($db, 'tcg_users', 'banner_card_no', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_users', 'banner_crop', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_users', 'ranked_equipped_starter', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'star_gems', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'dupe_gem_migration_done', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_box_progress', 'rm_pity', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_collection', 'acquired_at', 'INTEGER');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_schema_meta (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');

    tcgDbRunMigrationOnce($db, 'daily_pull_reset_20260622', function (PDO $db): void {
        $today = tcgTodayJst();
        $db->prepare('UPDATE tcg_daily_state SET packs_opened_today = 0 WHERE last_open_date = ?')
            ->execute([$today]);
    });
}

function tcgDbRunMigrationOnce(PDO $db, string $key, callable $fn): void {
    $stmt = $db->prepare('SELECT value FROM tcg_schema_meta WHERE key = ?');
    $stmt->execute([$key]);
    if ($stmt->fetchColumn()) {
        return;
    }
    $fn($db);
    $db->prepare('INSERT INTO tcg_schema_meta (key, value) VALUES (?, ?)')
        ->execute([$key, (string) time()]);
}

function tcgDbEnsureColumn(PDO $db, string $table, string $column, string $definition): void {
    $safeTable = preg_replace('/[^a-z_]/', '', $table);
    $safeCol = preg_replace('/[^a-z_]/', '', $column);
    if ($safeTable !== $table || $safeCol !== $column) {
        throw new InvalidArgumentException('Invalid schema identifier');
    }
    $cols = $db->query('PRAGMA table_info(' . $safeTable . ')')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === $column) {
            return;
        }
    }
    $db->exec('ALTER TABLE ' . $safeTable . ' ADD COLUMN ' . $safeCol . ' ' . $definition);
}

/** Calendar date for daily TCG limits — midnight JST, same as loveliveradio.ca daily claims. */
function tcgTodayJst(): string {
    $tz = new DateTimeZone('Asia/Tokyo');
    return (new DateTime('now', $tz))->format('Y-m-d');
}

/** @deprecated alias — daily reset is JST, not UTC */
function tcgTodayUtc(): string {
    return tcgTodayJst();
}

function tcgEnsureUser(string $discordId, array $profile = []): array {
    $db = tcgDb();
    $now = time();
    $stmt = $db->prepare('SELECT * FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if (!empty($profile['username']) && $profile['username'] !== $row['username']) {
            $db->prepare('UPDATE tcg_users SET username = ?, avatar_url = ?, updated_at = ? WHERE discord_id = ?')
                ->execute([$profile['username'], $profile['avatar_url'] ?? $row['avatar_url'], $now, $discordId]);
            $row['username'] = $profile['username'];
            $row['avatar_url'] = $profile['avatar_url'] ?? $row['avatar_url'];
        }
        return $row;
    }
    $db->prepare('INSERT INTO tcg_users (discord_id, username, avatar_url, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?)')
        ->execute([
            $discordId,
            $profile['username'] ?? 'Player',
            $profile['avatar_url'] ?? null,
            $now,
            $now,
        ]);
    $db->prepare('INSERT OR IGNORE INTO tcg_daily_state (discord_id) VALUES (?)')->execute([$discordId]);
    $db->prepare('INSERT OR IGNORE INTO tcg_rank (discord_id, updated_at) VALUES (?, ?)')
        ->execute([$discordId, $now]);
    $stmt->execute([$discordId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function tcgUpsertCollectionCounts(string $discordId, array $counts, ?int $acquiredAt = null): void {
    if (empty($counts)) {
        return;
    }
    $db = tcgDb();
    $now = $acquiredAt ?? time();
    $stmt = $db->prepare('INSERT INTO tcg_collection (discord_id, card_no, qty, acquired_at) VALUES (?, ?, ?, ?)
        ON CONFLICT(discord_id, card_no) DO UPDATE SET
            qty = qty + excluded.qty,
            acquired_at = excluded.acquired_at');
    foreach ($counts as $no => $qty) {
        $stmt->execute([$discordId, $no, $qty, $now]);
    }
}

function tcgAddCardsToCollection(string $discordId, array $cardNos): void {
    if (empty($cardNos)) {
        return;
    }
    $db = tcgDb();
    $db->beginTransaction();
    try {
        $counts = [];
        foreach ($cardNos as $no) {
            $no = trim((string)$no);
            if ($no === '') {
                continue;
            }
            $counts[$no] = ($counts[$no] ?? 0) + 1;
        }
        tcgUpsertCollectionCounts($discordId, $counts);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function tcgGetCollectionMap(string $discordId): array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT card_no, qty FROM tcg_collection WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[$row['card_no']] = intval($row['qty']);
    }
    return $out;
}

function tcgConsumeCollectionCards(string $discordId, array $requiredCounts): bool {
    $db = tcgDb();
    $owned = tcgGetCollectionMap($discordId);
    foreach ($requiredCounts as $no => $need) {
        if (($owned[$no] ?? 0) < $need) {
            return false;
        }
    }
    return true;
}

/** Wipe collection, decks, rank, boosters, and starter choice; user row is kept. */
function tcgResetAccountProgress(string $discordId): void {
    $db = tcgDb();
    $now = time();
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM tcg_match_queue WHERE discord_id = ?')->execute([$discordId]);
        $db->prepare('DELETE FROM tcg_casual_queue WHERE discord_id = ?')->execute([$discordId]);
        $db->prepare('DELETE FROM tcg_collection WHERE discord_id = ?')->execute([$discordId]);
        $db->prepare('DELETE FROM tcg_deck_presets WHERE discord_id = ?')->execute([$discordId]);
        $db->prepare('DELETE FROM tcg_box_progress WHERE discord_id = ?')->execute([$discordId]);
        $db->prepare('UPDATE tcg_users SET starter_deck = NULL, banner_card_no = NULL, banner_crop = NULL,
            star_gems = 0, dupe_gem_migration_done = 0, updated_at = ? WHERE discord_id = ?')
            ->execute([$now, $discordId]);
        $db->prepare('UPDATE tcg_rank SET rating = 1000, wins = 0, losses = 0, draws = 0, games = 0, updated_at = ?
            WHERE discord_id = ?')->execute([$now, $discordId]);
        $db->prepare('UPDATE tcg_daily_state SET last_open_date = NULL, packs_opened_today = 0, first_day_bonus_used = 0
            WHERE discord_id = ?')->execute([$discordId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function tcgGetStarGems(string $discordId): int {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT star_gems FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $val = $stmt->fetchColumn();
    return $val === false ? 0 : max(0, intval($val));
}

function tcgAddStarGems(string $discordId, int $amount): int {
    if ($amount <= 0) {
        return tcgGetStarGems($discordId);
    }
    $db = tcgDb();
    $db->prepare('UPDATE tcg_users SET star_gems = COALESCE(star_gems, 0) + ?, updated_at = ? WHERE discord_id = ?')
        ->execute([$amount, time(), $discordId]);
    return tcgGetStarGems($discordId);
}

function tcgDeductStarGems(string $discordId, int $amount): int {
    if ($amount <= 0) {
        return tcgGetStarGems($discordId);
    }
    $db = tcgDb();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT star_gems FROM tcg_users WHERE discord_id = ?');
        $stmt->execute([$discordId]);
        $have = max(0, intval($stmt->fetchColumn() ?: 0));
        if ($have < $amount) {
            throw new Exception('Not enough Star Gems');
        }
        $db->prepare('UPDATE tcg_users SET star_gems = star_gems - ?, updated_at = ? WHERE discord_id = ?')
            ->execute([$amount, time(), $discordId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return tcgGetStarGems($discordId);
}

/**
 * Add pulled cards to collection or convert dupes above deck limits into Star Gems.
 *
 * @return array{pulls: list<array>, star_gems_earned: int, star_gems: int}
 */
function tcgApplyBoosterPullWithGems(string $discordId, array $cardNos, array $cardMap): array {
    if (empty($cardNos)) {
        return [
            'pulls' => [],
            'star_gems_earned' => 0,
            'star_gems' => tcgGetStarGems($discordId),
        ];
    }
    $db = tcgDb();
    $owned = tcgGetCollectionMap($discordId);
    $addCounts = [];
    $pulls = [];
    $gemsEarned = 0;

    foreach ($cardNos as $no) {
        $no = trim((string)$no);
        if ($no === '') {
            continue;
        }
        $card = $cardMap[$no] ?? null;
        $max = tcgGetDeckMaxCopies(is_array($card) ? $card : null, $no);
        $have = intval($owned[$no] ?? 0);
        if ($have >= $max) {
            $dupeGems = tcgStarGemsForDupe(is_array($card) ? $card : null, $no);
            $gemsEarned += $dupeGems;
            $pulls[] = [
                'card_no' => $no,
                'converted' => true,
                'star_gems' => $dupeGems,
            ];
        } else {
            $owned[$no] = $have + 1;
            $addCounts[$no] = ($addCounts[$no] ?? 0) + 1;
            $pulls[] = [
                'card_no' => $no,
                'converted' => false,
                'star_gems' => 0,
            ];
        }
    }

    $db->beginTransaction();
    try {
        if (!empty($addCounts)) {
            tcgUpsertCollectionCounts($discordId, $addCounts);
        }
        if ($gemsEarned > 0) {
            $db->prepare('UPDATE tcg_users SET star_gems = COALESCE(star_gems, 0) + ?, updated_at = ? WHERE discord_id = ?')
                ->execute([$gemsEarned, time(), $discordId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return [
        'pulls' => $pulls,
        'star_gems_earned' => $gemsEarned,
        'star_gems' => tcgGetStarGems($discordId),
    ];
}

/**
 * One-time migration: convert collection dupes above deck limits into Star Gems.
 *
 * @return array{migrated: bool, star_gems_gained: int, star_gems: int, cards_converted: int}
 */
function tcgMigrateDuplicateToStarGems(string $discordId, array $cardMap): array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT dupe_gem_migration_done, star_gems FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return [
            'migrated' => false,
            'star_gems_gained' => 0,
            'star_gems' => 0,
            'cards_converted' => 0,
        ];
    }
    if (intval($user['dupe_gem_migration_done'] ?? 0) === 1) {
        return [
            'migrated' => false,
            'star_gems_gained' => 0,
            'star_gems' => max(0, intval($user['star_gems'] ?? 0)),
            'cards_converted' => 0,
        ];
    }

    $owned = tcgGetCollectionMap($discordId);
    $gemsGained = 0;
    $cardsConverted = 0;
    $updates = [];

    foreach ($owned as $no => $qty) {
        $qty = intval($qty);
        if ($qty <= 0) {
            continue;
        }
        $card = $cardMap[$no] ?? null;
        $max = tcgGetDeckMaxCopies(is_array($card) ? $card : null, $no);
        if ($qty > $max) {
            $excess = $qty - $max;
            $dupeGems = tcgStarGemsForDupe(is_array($card) ? $card : null, (string)$no);
            $gemsGained += $excess * $dupeGems;
            $cardsConverted += $excess;
            $updates[$no] = $max;
        }
    }

    $db->beginTransaction();
    try {
        foreach ($updates as $no => $keepQty) {
            $db->prepare('UPDATE tcg_collection SET qty = ? WHERE discord_id = ? AND card_no = ?')
                ->execute([$keepQty, $discordId, $no]);
        }
        if ($gemsGained > 0) {
            $db->prepare('UPDATE tcg_users SET star_gems = COALESCE(star_gems, 0) + ?, updated_at = ? WHERE discord_id = ?')
                ->execute([$gemsGained, time(), $discordId]);
        }
        $db->prepare('UPDATE tcg_users SET dupe_gem_migration_done = 1, updated_at = ? WHERE discord_id = ?')
            ->execute([time(), $discordId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return [
        'migrated' => true,
        'star_gems_gained' => $gemsGained,
        'star_gems' => tcgGetStarGems($discordId),
        'cards_converted' => $cardsConverted,
    ];
}
