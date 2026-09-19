<?php
/**
 * SQLite persistence for TCG accounts (Hostinger-friendly).
 *
 * tcg_users, collection, deck presets, booster box pity, ranked ELO (tcg_rank),
 * and matchmaking queue rows. WAL mode; migrations in tcgDbMigrate().
 */
require_once __DIR__ . '/config/paths.php';
tcgDefinePathConstants();

define('TCG_DB_PATH', TCG_DATA_DIR . 'tcg.db');

require_once __DIR__ . '/deck_validate.php';

const TCG_STAR_GEMS_PER_DUPE = 10;
/** Base gem rate: 20 Star Gems per card in a pack (5-card pack = 100, 3-card = 60). */
const TCG_STAR_GEMS_PER_CARD = 20;
/** Default reference costs for a standard 5-card / 10-pack booster box. */
const TCG_STAR_GEMS_PACK_COST = 100;
const TCG_STAR_GEMS_BOX_COST = 1000;

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
    // Hostinger shared hosting: concurrent create_room/casual_join used to fail
    // immediately with "database is locked" while other workers decoded cards.json.
    // ATTR_TIMEOUT is seconds; busy_timeout is milliseconds.
    try {
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 15);
    } catch (Throwable $e) { /* driver may ignore */ }
    $pdo->exec('PRAGMA busy_timeout=15000');
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    tcgDbMigrate($pdo);
    return $pdo;
}

/** True when a PDO/SQLite error is a transient lock contention. */
function tcgDbIsLockedException(Throwable $e): bool {
    $msg = $e->getMessage();
    return str_contains($msg, 'database is locked')
        || str_contains($msg, 'SQLITE_BUSY')
        || str_contains($msg, 'SQLSTATE[HY000]: General error: 5');
}

/**
 * Retry a DB-touching callable on SQLite busy/locked (shared hosting NFS-ish locks).
 *
 * @template T
 * @param callable():T $fn
 * @return T
 */
function tcgDbRetry(callable $fn, int $attempts = 12, int $baseDelayUs = 25000) {
    $delay = $baseDelayUs;
    $last = null;
    for ($i = 0; $i < $attempts; $i++) {
        try {
            return $fn();
        } catch (Throwable $e) {
            $last = $e;
            if (!tcgDbIsLockedException($e) || $i === $attempts - 1) {
                throw $e;
            }
            usleep($delay);
            $delay = min(400000, (int)($delay * 1.6));
        }
    }
    throw $last ?? new RuntimeException('tcgDbRetry failed');
}

function tcgDbMigrate(PDO $db): void {
    static $done = false;
    if ($done) {
        return;
    }

    // Fast path: production DBs already have the full bootstrap schema. Re-running
    // dozens of CREATE IF NOT EXISTS / PRAGMA table_info on every new PHP-FPM
    // worker was stacking write locks and breaking casual_join under concurrency.
    $bootstrapped = false;
    try {
        $stmt = $db->query("SELECT 1 FROM tcg_schema_meta WHERE key = 'bootstrap_v2' LIMIT 1");
        $bootstrapped = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $bootstrapped = false;
    }

    if (!$bootstrapped) {
        $alreadyProvisioned = false;
        try {
            $db->query('SELECT 1 FROM tcg_casual_queue LIMIT 1');
            $alreadyProvisioned = true;
        } catch (Throwable $e) {
            $alreadyProvisioned = false;
        }

        if ($alreadyProvisioned) {
            // Existing production DB: mark bootstrap complete without re-locking on CREATE.
            try {
                $db->exec('CREATE TABLE IF NOT EXISTS tcg_schema_meta (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                )');
                $db->prepare('INSERT OR REPLACE INTO tcg_schema_meta (key, value) VALUES (?, ?)')
                    ->execute(['bootstrap_v2', (string)time()]);
            } catch (Throwable $e) { /* ignore */ }
        } else {
            tcgDbMigrateBootstrap($db);
            try {
                $db->prepare('INSERT OR REPLACE INTO tcg_schema_meta (key, value) VALUES (?, ?)')
                    ->execute(['bootstrap_v2', (string)time()]);
            } catch (Throwable $e) { /* ignore */ }
        }
    }

    // Only invoke the SQL migrator when migration files change. Its
    // CREATE TABLE IF NOT EXISTS on every PHP request acquired schema locks
    // and made unrelated account/button requests wait behind matchmaking.
    // Hostinger has no Composer vendor/; multi-statement Migrator::run there
    // can 500 the whole account API — use tcgDbRunMigrationOnce for prod schema.
    $migrationFingerprint = tcgDbMigrationFingerprint();
    $appliedFingerprint = null;
    try {
        $stmt = $db->prepare('SELECT value FROM tcg_schema_meta WHERE key = ?');
        $stmt->execute(['migration_fingerprint']);
        $appliedFingerprint = $stmt->fetchColumn() ?: null;
    } catch (Throwable $e) { /* bootstrap path handles a missing meta table */ }

    if ($migrationFingerprint !== $appliedFingerprint && is_file(__DIR__ . '/vendor/autoload.php')) {
        try {
            require_once __DIR__ . '/vendor/autoload.php';
            if (class_exists(\LLTCG\Db\Migrator::class)) {
                \LLTCG\Db\Migrator::run($db);
            }
            $db->prepare('INSERT OR REPLACE INTO tcg_schema_meta (key, value) VALUES (?, ?)')
                ->execute(['migration_fingerprint', $migrationFingerprint]);
        } catch (Throwable $e) {
            // Do not brick account.php if a SQL migration fails mid-file.
            error_log('tcgDbMigrate Migrator: ' . $e->getMessage());
        }
    }

    tcgDbRunMigrationOnce($db, 'replay_preserved_backfill_20260712', function (PDO $db): void {
        // Pre-feature library rows were all manual saves — keep them forever.
        $db->exec('UPDATE tcg_replays SET preserved = 1 WHERE COALESCE(preserved, 0) = 0');
    });

    tcgDbRunMigrationOnce($db, 'daily_pull_reset_20260622', function (PDO $db): void {
        $today = tcgTodayJst();
        $db->prepare('UPDATE tcg_daily_state SET packs_opened_today = 0 WHERE last_open_date = ?')
            ->execute([$today]);
    });

    // seal_pr was added after bootstrap_v2; comment-only 007_seals.sql never ALTERed prod.
    // Without this, me() → tcgSealBalances SELECT seal_pr → 500 → client "server is busy".
    tcgDbRunMigrationOnce($db, 'seal_pr_column_20260730', function (PDO $db): void {
        tcgDbEnsureColumn($db, 'tcg_users', 'seal_pr', 'INTEGER NOT NULL DEFAULT 0');
    });

    // Per-mode ranked ELO + same-mode queues (Standard / Starters).
    tcgDbRunMigrationOnce($db, 'game_mode_rank_20260731', function (PDO $db): void {
        tcgDbMigrateGameModeRank($db);
    });

    tcgDbRunMigrationOnce($db, 'experiment_presets_20260802', function (PDO $db): void {
        $db->exec('CREATE TABLE IF NOT EXISTS tcg_experiment_presets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            discord_id TEXT NOT NULL,
            slot INTEGER NOT NULL,
            name TEXT NOT NULL,
            main_deck TEXT NOT NULL,
            energy_deck TEXT NOT NULL,
            share_password TEXT,
            updated_at INTEGER NOT NULL,
            UNIQUE (discord_id, slot),
            FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
        )');
    });

    // login_bonus_* was added after bootstrap_v2; 011_login_bonus.sql is docs-only and never ALTERed prod.
    tcgDbRunMigrationOnce($db, 'login_bonus_columns_20260802', function (PDO $db): void {
        tcgDbEnsureColumn($db, 'tcg_daily_state', 'login_bonus_step', 'INTEGER NOT NULL DEFAULT 0');
        tcgDbEnsureColumn($db, 'tcg_daily_state', 'login_bonus_last_date', 'TEXT');
    });

    // Per-preset sleeves. 012_deck_sleeves.sql is docs-only; production already has bootstrap_v2
    // so tcgDbMigrateBootstrap (and its ensureColumn calls) never run.
    tcgDbRunMigrationOnce($db, 'deck_sleeves_20260812', function (PDO $db): void {
        tcgDbEnsureColumn($db, 'tcg_deck_presets', 'sleeve_id', "TEXT NOT NULL DEFAULT ''");
        tcgDbEnsureColumn($db, 'tcg_experiment_presets', 'sleeve_id', "TEXT NOT NULL DEFAULT ''");
    });

    tcgDbRunMigrationOnce($db, 'coins_sleeves_20260812', function (PDO $db): void {
        tcgDbEnsureColumn($db, 'tcg_users', 'coins', 'INTEGER NOT NULL DEFAULT 0');
        tcgDbEnsureColumn($db, 'tcg_users', 'login_days', 'INTEGER NOT NULL DEFAULT 0');
        tcgDbEnsureColumn($db, 'tcg_users', 'login_days_last_date', 'TEXT');
        tcgDbEnsureColumn($db, 'tcg_users', 'login_days_bootstrapped', 'INTEGER NOT NULL DEFAULT 0');
        tcgDbEnsureColumn($db, 'tcg_users', 'free_sleeve_claims', 'INTEGER NOT NULL DEFAULT 0');
        $db->exec('CREATE TABLE IF NOT EXISTS tcg_owned_sleeves (
            discord_id TEXT NOT NULL,
            sleeve_id TEXT NOT NULL,
            acquired_at INTEGER NOT NULL,
            source TEXT NOT NULL DEFAULT "shop",
            equip_intro_seen INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (discord_id, sleeve_id),
            FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS tcg_coin_grants (
            room_id TEXT NOT NULL,
            discord_id TEXT NOT NULL,
            amount INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            PRIMARY KEY (room_id, discord_id),
            FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
        )');
    });

    // Playmat cosmetics (shop + per-deck equip / brightness).
    tcgDbRunMigrationOnce($db, 'playmats_20260816', function (PDO $db): void {
        tcgDbEnsureColumn($db, 'tcg_deck_presets', 'playmat_id', "TEXT NOT NULL DEFAULT ''");
        tcgDbEnsureColumn($db, 'tcg_deck_presets', 'playmat_brightness', 'REAL NOT NULL DEFAULT 1.0');
        tcgDbEnsureColumn($db, 'tcg_experiment_presets', 'playmat_id', "TEXT NOT NULL DEFAULT ''");
        tcgDbEnsureColumn($db, 'tcg_experiment_presets', 'playmat_brightness', 'REAL NOT NULL DEFAULT 1.0');
        $db->exec('CREATE TABLE IF NOT EXISTS tcg_owned_playmats (
            discord_id TEXT NOT NULL,
            playmat_id TEXT NOT NULL,
            acquired_at INTEGER NOT NULL,
            source TEXT NOT NULL DEFAULT "shop",
            PRIMARY KEY (discord_id, playmat_id),
            FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
        )');
    });

    // Tournament Mode tables (017). Hostinger has no Composer vendor/, so SQL migrator
    // used to no-op; keep an explicit once-migration as the production guarantee.
    tcgDbRunMigrationOnce($db, 'tournaments_20260821', function (PDO $db): void {
        tcgDbEnsureTournamentSchema($db);
    });

    tcgDbRunMigrationOnce($db, 'tournaments_phase3_20260821', function (PDO $db): void {
        tcgDbEnsureColumn($db, 'tcg_tournament_matches', 'bracket_side', "TEXT NOT NULL DEFAULT 'winners'");
        tcgDbEnsureColumn($db, 'tcg_tournament_matches', 'meta_json', "TEXT NOT NULL DEFAULT '{}'");
        $db->exec('DROP INDEX IF EXISTS idx_tcg_tournament_matches_slot');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tcg_tournament_matches_slot3
            ON tcg_tournament_matches(tournament_id, bracket_side, round, bracket_slot)');
    });

    tcgDbRunMigrationOnce($db, 'tournament_stats_20260821', function (PDO $db): void {
        require_once __DIR__ . '/tournament_stats.php';
        tcgTournamentStatsEnsureSchema($db);
    });

    tcgDbRunMigrationOnce($db, 'tournament_results_20260830', function (PDO $db): void {
        tcgDbEnsureColumn($db, 'tcg_tournaments', 'results_json', "TEXT NOT NULL DEFAULT ''");
    });

    // Account timezone for tournament scheduling UI (was only in full bootstrap).
    tcgDbRunMigrationOnce($db, 'preferred_timezone_20260821', function (PDO $db): void {
        tcgDbEnsureColumn($db, 'tcg_users', 'preferred_timezone', "TEXT NOT NULL DEFAULT 'Asia/Tokyo'");
    });

    // Profile/friends columns — bootstrap_v2 already ran on Hostinger, so these
    // must be a once-migration (same pattern as preferred_timezone / seal_pr).
    tcgDbRunMigrationOnce($db, 'social_profile_20260823', function (PDO $db): void {
        tcgDbEnsureColumn($db, 'tcg_users', 'friend_code', 'TEXT');
        tcgDbEnsureColumn($db, 'tcg_users', 'bio', 'TEXT');
        tcgDbEnsureColumn($db, 'tcg_users', 'bio_locked', 'INTEGER NOT NULL DEFAULT 0');
        tcgDbEnsureColumn($db, 'tcg_users', 'profile_warnings', 'INTEGER NOT NULL DEFAULT 0');
        tcgDbEnsureColumn($db, 'tcg_users', 'featured_deck_id', 'INTEGER');
        tcgDbEnsureColumn($db, 'tcg_users', 'featured_deck_visibility', "TEXT NOT NULL DEFAULT 'private'");
        tcgDbEnsureColumn($db, 'tcg_users', 'featured_deck_desc', 'TEXT');
        tcgDbEnsureColumn($db, 'tcg_users', 'title_id', 'TEXT');
        $db->exec('CREATE TABLE IF NOT EXISTS tcg_profile_showcase (
            discord_id TEXT NOT NULL,
            slot INTEGER NOT NULL,
            card_no TEXT NOT NULL DEFAULT \'\',
            PRIMARY KEY (discord_id, slot)
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS tcg_friends (
            user_lo TEXT NOT NULL,
            user_hi TEXT NOT NULL,
            requester_id TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            PRIMARY KEY (user_lo, user_hi)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_friends_status ON tcg_friends(status, updated_at)');
        $db->exec('CREATE TABLE IF NOT EXISTS tcg_pvp_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id TEXT NOT NULL UNIQUE,
            mode TEXT NOT NULL,
            p1_id TEXT NOT NULL,
            p2_id TEXT NOT NULL,
            winner_id TEXT,
            ended_at INTEGER NOT NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_pvp_p1 ON tcg_pvp_results(p1_id, ended_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_pvp_p2 ON tcg_pvp_results(p2_id, ended_at)');
        $db->exec('CREATE TABLE IF NOT EXISTS tcg_profile_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reporter_id TEXT NOT NULL,
            target_id TEXT NOT NULL,
            field TEXT NOT NULL,
            snippet TEXT NOT NULL DEFAULT \'\',
            status TEXT NOT NULL DEFAULT \'open\',
            created_at INTEGER NOT NULL
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS tcg_profile_mod_actions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_id TEXT NOT NULL,
            actor_id TEXT NOT NULL,
            action TEXT NOT NULL,
            note TEXT NOT NULL DEFAULT \'\',
            created_at INTEGER NOT NULL
        )');
        try {
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tcg_users_friend_code ON tcg_users(friend_code) WHERE friend_code IS NOT NULL AND friend_code != \'\'');
        } catch (Throwable $e) {
            // Duplicate empties / older SQLite without partial indexes.
        }
    });

    tcgDbRunMigrationOnce($db, 'tournament_start_reminders_20260824', function (PDO $db): void {
        $db->exec('CREATE TABLE IF NOT EXISTS tcg_tournament_start_reminders (
            discord_id TEXT NOT NULL,
            tournament_id TEXT NOT NULL,
            offset_sec INTEGER NOT NULL,
            sent_at INTEGER,
            PRIMARY KEY (discord_id, tournament_id, offset_sec)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_tournament_start_reminders_due
            ON tcg_tournament_start_reminders(sent_at, tournament_id)');
    });

    tcgDbRunMigrationOnce($db, 'account_bans_20260824', function (PDO $db): void {
        $db->exec('CREATE TABLE IF NOT EXISTS tcg_account_bans (
            discord_id TEXT PRIMARY KEY,
            username TEXT NOT NULL DEFAULT \'\',
            reason TEXT NOT NULL DEFAULT \'\',
            snapshot_json TEXT NOT NULL,
            adjustments_json TEXT NOT NULL DEFAULT \'[]\',
            banned_by TEXT NOT NULL,
            banned_at INTEGER NOT NULL,
            restored_at INTEGER,
            restored_by TEXT
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS tcg_user_notices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            discord_id TEXT NOT NULL,
            kind TEXT NOT NULL,
            reason TEXT NOT NULL DEFAULT \'\',
            created_at INTEGER NOT NULL,
            acked_at INTEGER
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_user_notices_pending
            ON tcg_user_notices(discord_id, acked_at)');
    });

    tcgDbRunMigrationOnce($db, 'events_20260919', function (PDO $db): void {
        require_once __DIR__ . '/events.php';
        tcgEventsEnsureSchema($db);
    });

    tcgDbRunMigrationOnce($db, 'scouting_tickets_20260919', function (PDO $db): void {
        tcgDbEnsureColumn($db, 'tcg_users', 'scouting_tickets', 'INTEGER NOT NULL DEFAULT 0');
    });

    $done = true;
}

/** Stable fingerprint used to avoid running the schema migrator per request. */
function tcgDbMigrationFingerprint(): string {
    $parts = [];
    foreach (glob(__DIR__ . '/migrations/*.sql') ?: [] as $file) {
        $parts[] = basename($file) . ':' . (int)filemtime($file) . ':' . (int)filesize($file);
    }
    sort($parts, SORT_STRING);
    return hash('sha256', implode('|', $parts));
}

/** One-time full schema create + column ensures (skipped once bootstrap_v2 is set). */
function tcgDbMigrateBootstrap(PDO $db): void {
    if (is_file(__DIR__ . '/vendor/autoload.php')) {
        try {
            require_once __DIR__ . '/vendor/autoload.php';
            if (class_exists(\LLTCG\Db\Migrator::class)) {
                \LLTCG\Db\Migrator::run($db);
            }
        } catch (Throwable $e) {
            error_log('tcgDbMigrateBootstrap Migrator: ' . $e->getMessage());
        }
    }

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

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_experiment_presets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        discord_id TEXT NOT NULL,
        slot INTEGER NOT NULL,
        name TEXT NOT NULL,
        main_deck TEXT NOT NULL,
        energy_deck TEXT NOT NULL,
        share_password TEXT,
        updated_at INTEGER NOT NULL,
        UNIQUE (discord_id, slot),
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_rank (
        discord_id TEXT NOT NULL,
        game_mode TEXT NOT NULL DEFAULT \'standard\',
        rating INTEGER NOT NULL DEFAULT 1000,
        wins INTEGER NOT NULL DEFAULT 0,
        losses INTEGER NOT NULL DEFAULT 0,
        draws INTEGER NOT NULL DEFAULT 0,
        games INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL,
        PRIMARY KEY (discord_id, game_mode),
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_match_queue (
        discord_id TEXT NOT NULL,
        game_mode TEXT NOT NULL DEFAULT \'standard\',
        rating INTEGER NOT NULL DEFAULT 1000,
        joined_at INTEGER NOT NULL,
        PRIMARY KEY (discord_id, game_mode),
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
        created_at INTEGER NOT NULL,
        game_mode TEXT NOT NULL DEFAULT \'standard\'
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_casual_queue (
        queue_key TEXT PRIMARY KEY,
        discord_id TEXT,
        player_name TEXT NOT NULL,
        join_body TEXT NOT NULL,
        joined_at INTEGER NOT NULL,
        game_mode TEXT NOT NULL DEFAULT \'standard\'
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_casual_matches (
        queue_key TEXT NOT NULL,
        room_id TEXT NOT NULL,
        player_token TEXT NOT NULL,
        player_id TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        PRIMARY KEY (queue_key, room_id)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_replays (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        discord_id TEXT NOT NULL,
        room_id TEXT NOT NULL,
        saver_player_id TEXT NOT NULL,
        saver_name TEXT,
        opponent_name TEXT,
        winner TEXT,
        end_reason TEXT,
        turn INTEGER NOT NULL DEFAULT 0,
        phase TEXT,
        action_count INTEGER NOT NULL DEFAULT 0,
        duration_seconds INTEGER NOT NULL DEFAULT 0,
        payload_json TEXT NOT NULL,
        saved_at INTEGER NOT NULL,
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_replays_user_saved
        ON tcg_replays(discord_id, saved_at DESC)');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_mission_progress (
        discord_id TEXT NOT NULL,
        mission_id TEXT NOT NULL,
        period_key TEXT NOT NULL DEFAULT "",
        completed_at INTEGER,
        claimed_at INTEGER,
        PRIMARY KEY (discord_id, mission_id, period_key),
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_mission_progress_user
        ON tcg_mission_progress(discord_id)');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_starter_owned (
        discord_id TEXT NOT NULL,
        starter_key TEXT NOT NULL,
        source TEXT NOT NULL DEFAULT "initial",
        mission_id TEXT,
        granted_at INTEGER NOT NULL,
        PRIMARY KEY (discord_id, starter_key),
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_starter_owned_user
        ON tcg_starter_owned(discord_id)');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_play_stats (
        discord_id TEXT NOT NULL,
        tracker TEXT NOT NULL,
        dim TEXT NOT NULL,
        key TEXT NOT NULL,
        count INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL,
        PRIMARY KEY (discord_id, tracker, dim, key),
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_play_stats_user_tracker
        ON tcg_play_stats(discord_id, tracker)');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_play_stat_match_applied (
        room_id TEXT NOT NULL PRIMARY KEY,
        applied_at INTEGER NOT NULL
    )');

    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_casual_queue_discord
        ON tcg_casual_queue(discord_id) WHERE discord_id IS NOT NULL');

    tcgDbEnsureColumn($db, 'tcg_deck_presets', 'sleeve_id', "TEXT NOT NULL DEFAULT ''");
    tcgDbEnsureColumn($db, 'tcg_experiment_presets', 'sleeve_id', "TEXT NOT NULL DEFAULT ''");
    tcgDbEnsureColumn($db, 'tcg_users', 'banner_card_no', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_users', 'banner_crop', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_users', 'equipped_flag', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_users', 'stamp_favorites', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_users', 'ranked_equipped_starter', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'ranked_starter_key', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_users', 'star_gems', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'scouting_tickets', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'dupe_gem_migration_done', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'unranked_games', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'sticker_exchanges', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'seal_n', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'seal_r', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'seal_p', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'seal_sec', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'seal_pr', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'coins', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'login_days', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'login_days_last_date', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_users', 'login_days_bootstrapped', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'free_sleeve_claims', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'preferred_timezone', "TEXT NOT NULL DEFAULT 'Asia/Tokyo'");
    tcgDbEnsureColumn($db, 'tcg_users', 'friend_code', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_users', 'bio', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_users', 'bio_locked', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'profile_warnings', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_users', 'featured_deck_id', 'INTEGER');
    tcgDbEnsureColumn($db, 'tcg_users', 'featured_deck_visibility', "TEXT NOT NULL DEFAULT 'private'");
    tcgDbEnsureColumn($db, 'tcg_users', 'featured_deck_desc', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_users', 'title_id', 'TEXT');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_owned_sleeves (
        discord_id TEXT NOT NULL,
        sleeve_id TEXT NOT NULL,
        acquired_at INTEGER NOT NULL,
        source TEXT NOT NULL DEFAULT "shop",
        equip_intro_seen INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (discord_id, sleeve_id),
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_coin_grants (
        room_id TEXT NOT NULL,
        discord_id TEXT NOT NULL,
        amount INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        PRIMARY KEY (room_id, discord_id),
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
    )');
    tcgDbEnsureColumn($db, 'tcg_box_progress', 'rm_pity', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_box_progress', 'live_pity', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_collection', 'acquired_at', 'INTEGER');
    tcgDbEnsureColumn($db, 'tcg_daily_state', 'ranked_pr_date', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_daily_state', 'ranked_pr_today', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_daily_state', 'login_bonus_step', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_daily_state', 'login_bonus_last_date', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_replays', 'preserved', 'INTEGER NOT NULL DEFAULT 0');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_replays_user_autosave
        ON tcg_replays(discord_id, preserved, saved_at DESC)');

    $db->exec('CREATE TABLE IF NOT EXISTS tcg_schema_meta (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');
}

function tcgDbTableHasColumn(PDO $db, string $table, string $column): bool {
    $safeTable = preg_replace('/[^a-z0-9_]/', '', strtolower($table));
    if ($safeTable === '') {
        return false;
    }
    $cols = $db->query('PRAGMA table_info(' . $safeTable . ')')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

/** Migrate legacy single-mode rank/queue tables to per-game_mode schema. */
function tcgDbMigrateGameModeRank(PDO $db): void {
    tcgDbEnsureColumn($db, 'tcg_users', 'ranked_starter_key', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_ranked_matches', 'game_mode', "TEXT NOT NULL DEFAULT 'standard'");
    tcgDbEnsureColumn($db, 'tcg_ranked_matches', 'winner_pid', 'TEXT');
    tcgDbEnsureColumn($db, 'tcg_ranked_matches', 'pr_rewarded', 'INTEGER NOT NULL DEFAULT 0');
    tcgDbEnsureColumn($db, 'tcg_casual_queue', 'game_mode', "TEXT NOT NULL DEFAULT 'standard'");

    if (!tcgDbTableHasColumn($db, 'tcg_rank', 'game_mode')) {
        $db->exec('CREATE TABLE tcg_rank_gm (
            discord_id TEXT NOT NULL,
            game_mode TEXT NOT NULL DEFAULT \'standard\',
            rating INTEGER NOT NULL DEFAULT 1000,
            wins INTEGER NOT NULL DEFAULT 0,
            losses INTEGER NOT NULL DEFAULT 0,
            draws INTEGER NOT NULL DEFAULT 0,
            games INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL,
            PRIMARY KEY (discord_id, game_mode),
            FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
        )');
        $db->exec('INSERT OR IGNORE INTO tcg_rank_gm (discord_id, game_mode, rating, wins, losses, draws, games, updated_at)
            SELECT discord_id, \'standard\', rating, wins, losses, draws, games, updated_at FROM tcg_rank');
        $db->exec('DROP TABLE tcg_rank');
        $db->exec('ALTER TABLE tcg_rank_gm RENAME TO tcg_rank');
    }

    if (!tcgDbTableHasColumn($db, 'tcg_match_queue', 'game_mode')) {
        $db->exec('CREATE TABLE tcg_match_queue_gm (
            discord_id TEXT NOT NULL,
            game_mode TEXT NOT NULL DEFAULT \'standard\',
            rating INTEGER NOT NULL DEFAULT 1000,
            joined_at INTEGER NOT NULL,
            PRIMARY KEY (discord_id, game_mode),
            FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
        )');
        $db->exec('INSERT OR IGNORE INTO tcg_match_queue_gm (discord_id, game_mode, rating, joined_at)
            SELECT discord_id, \'standard\', rating, joined_at FROM tcg_match_queue');
        $db->exec('DROP TABLE tcg_match_queue');
        $db->exec('ALTER TABLE tcg_match_queue_gm RENAME TO tcg_match_queue');
    }
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

/** Create Tournament Mode tables (mirrors migrations/017_tournaments.sql). */
function tcgDbEnsureTournamentSchema(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_tournaments (
        id TEXT PRIMARY KEY,
        host_discord_id TEXT NOT NULL,
        title TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'open\',
        game_mode TEXT NOT NULL DEFAULT \'standard\',
        start_at INTEGER NOT NULL,
        checkin_mins INTEGER NOT NULL DEFAULT 10,
        min_players INTEGER NOT NULL DEFAULT 2,
        max_players INTEGER NOT NULL DEFAULT 16,
        entry_fee_coins INTEGER NOT NULL DEFAULT 0,
        prize_pool_coins INTEGER NOT NULL DEFAULT 0,
        settings_json TEXT NOT NULL DEFAULT \'{}\',
        results_json TEXT NOT NULL DEFAULT \'\',
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,
        FOREIGN KEY (host_discord_id) REFERENCES tcg_users(discord_id)
    )');
    tcgDbEnsureColumn($db, 'tcg_tournaments', 'results_json', "TEXT NOT NULL DEFAULT ''");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_tournaments_status_start
        ON tcg_tournaments(status, start_at)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_tournament_entrants (
        tournament_id TEXT NOT NULL,
        discord_id TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'registered\',
        seed INTEGER,
        deck_snapshot TEXT NOT NULL,
        paid_coins INTEGER NOT NULL DEFAULT 0,
        registered_at INTEGER NOT NULL,
        checked_in_at INTEGER,
        PRIMARY KEY (tournament_id, discord_id),
        FOREIGN KEY (tournament_id) REFERENCES tcg_tournaments(id) ON DELETE CASCADE,
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_tournament_entrants_status
        ON tcg_tournament_entrants(tournament_id, status)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_tournament_matches (
        id TEXT PRIMARY KEY,
        tournament_id TEXT NOT NULL,
        round INTEGER NOT NULL,
        bracket_slot INTEGER NOT NULL,
        bracket_side TEXT NOT NULL DEFAULT \'winners\',
        p1_discord_id TEXT,
        p2_discord_id TEXT,
        room_id TEXT,
        p1_token TEXT,
        p2_token TEXT,
        status TEXT NOT NULL DEFAULT \'pending\',
        winner_discord_id TEXT,
        connect_deadline_at INTEGER,
        meta_json TEXT NOT NULL DEFAULT \'{}\',
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,
        FOREIGN KEY (tournament_id) REFERENCES tcg_tournaments(id) ON DELETE CASCADE
    )');
    tcgDbEnsureColumn($db, 'tcg_tournament_matches', 'bracket_side', "TEXT NOT NULL DEFAULT 'winners'");
    tcgDbEnsureColumn($db, 'tcg_tournament_matches', 'meta_json', "TEXT NOT NULL DEFAULT '{}'");
    $db->exec('DROP INDEX IF EXISTS idx_tcg_tournament_matches_slot');
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tcg_tournament_matches_slot3
        ON tcg_tournament_matches(tournament_id, bracket_side, round, bracket_slot)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_tournament_matches_room
        ON tcg_tournament_matches(room_id)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_tournament_ledger (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tournament_id TEXT NOT NULL,
        discord_id TEXT,
        kind TEXT NOT NULL,
        amount INTEGER NOT NULL,
        idempotency_key TEXT NOT NULL UNIQUE,
        meta_json TEXT NOT NULL DEFAULT \'{}\',
        created_at INTEGER NOT NULL,
        FOREIGN KEY (tournament_id) REFERENCES tcg_tournaments(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_tournament_ledger_tid
        ON tcg_tournament_ledger(tournament_id)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_tournament_start_reminders (
        discord_id TEXT NOT NULL,
        tournament_id TEXT NOT NULL,
        offset_sec INTEGER NOT NULL,
        sent_at INTEGER,
        PRIMARY KEY (discord_id, tournament_id, offset_sec)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_tournament_start_reminders_due
        ON tcg_tournament_start_reminders(sent_at, tournament_id)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_tournament_replays (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tournament_id TEXT NOT NULL,
        match_id TEXT NOT NULL,
        room_id TEXT NOT NULL,
        game_index INTEGER NOT NULL DEFAULT 1,
        winner_discord_id TEXT,
        end_reason TEXT,
        action_count INTEGER NOT NULL DEFAULT 0,
        duration_seconds INTEGER NOT NULL DEFAULT 0,
        payload_json TEXT NOT NULL,
        saved_at INTEGER NOT NULL,
        UNIQUE (tournament_id, room_id),
        FOREIGN KEY (tournament_id) REFERENCES tcg_tournaments(id) ON DELETE CASCADE,
        FOREIGN KEY (match_id) REFERENCES tcg_tournament_matches(id) ON DELETE CASCADE
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_tournament_replays_match
        ON tcg_tournament_replays(match_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_tournament_replays_tournament
        ON tcg_tournament_replays(tournament_id, saved_at DESC)');
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

/** Discord CDN avatar URL from user id + optional avatar hash (login / leaderboard). */
function tcgDiscordAvatarUrl(string $userId, ?string $avatarHash = null): string {
    $hash = is_string($avatarHash) ? trim($avatarHash) : '';
    if ($hash !== '') {
        $ext = str_starts_with($hash, 'a_') ? 'gif' : 'png';
        return 'https://cdn.discordapp.com/avatars/' . rawurlencode($userId) . '/'
            . rawurlencode($hash) . '.' . $ext . '?size=128';
    }
    // Default embed avatar index for users without a custom avatar (new username system).
    $idx = 0;
    if (preg_match('/^\d+$/', $userId)) {
        if (function_exists('gmp_init')) {
            $idx = (int)gmp_intval(gmp_mod(gmp_div_q(gmp_init($userId, 10), '4194304'), '6'));
        } elseif (function_exists('bcdiv')) {
            $idx = (int)bcmod(bcdiv($userId, '4194304', 0), '6');
        } else {
            // 64-bit PHP can hold Discord snowflakes as int.
            $idx = (int)((((int)$userId) >> 22) % 6);
        }
        if ($idx < 0 || $idx > 5) {
            $idx = 0;
        }
    }
    return 'https://cdn.discordapp.com/embed/avatars/' . $idx . '.png';
}

function tcgAssertNotBanned(string $discordId): void {
    $discordId = trim($discordId);
    if ($discordId === '') {
        return;
    }
    try {
        $st = tcgDb()->prepare(
            'SELECT 1 FROM tcg_account_bans WHERE discord_id = ? AND restored_at IS NULL LIMIT 1'
        );
        $st->execute([$discordId]);
        if ($st->fetchColumn()) {
            throw new Exception('This Discord account is banned from Loveca.', 403);
        }
    } catch (Exception $e) {
        if (intval($e->getCode()) === 403) {
            throw $e;
        }
        // Missing table before migration.
    }
}

function tcgEnsureUser(string $discordId, array $profile = []): array {
    if (function_exists('tcgAssertNotBanned')) {
        tcgAssertNotBanned($discordId);
    }
    if (!function_exists('tcgIsPlaceholderUsername') && is_file(__DIR__ . '/auth_profile.php')) {
        require_once __DIR__ . '/auth_profile.php';
    }
    $db = tcgDb();
    $now = time();
    $stmt = $db->prepare('SELECT * FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        // Refresh Discord profile fields on login /me — not only when username changes.
        // Stale avatar CDN hashes 404 after users change/remove their Discord avatar.
        $profileName = array_key_exists('username', $profile) ? trim((string)($profile['username'] ?? '')) : '';
        $wantUser = $profileName !== ''
            && !(function_exists('tcgIsPlaceholderUsername') && tcgIsPlaceholderUsername($profileName));
        $wantAvatar = array_key_exists('avatar_url', $profile);
        $storedName = trim((string)($row['username'] ?? ''));
        if (!$wantUser
            && function_exists('tcgIsPlaceholderUsername')
            && tcgIsPlaceholderUsername($storedName)
            && function_exists('tcgBuildAuthUserProfile')) {
            $rebuilt = tcgBuildAuthUserProfile($discordId);
            $rebuiltName = trim((string)($rebuilt['username'] ?? ''));
            if (!tcgIsPlaceholderUsername($rebuiltName)) {
                $profile = array_merge($profile, $rebuilt);
                $profileName = $rebuiltName;
                $wantUser = true;
            }
        }
        if ($wantUser || $wantAvatar) {
            $username = $wantUser ? $profileName : (string)($row['username'] ?? 'Player');
            $avatar = $wantAvatar ? ($profile['avatar_url'] ?? null) : ($row['avatar_url'] ?? null);
            if ($username !== (string)($row['username'] ?? '')
                || (string)($avatar ?? '') !== (string)($row['avatar_url'] ?? '')) {
                $db->prepare('UPDATE tcg_users SET username = ?, avatar_url = ?, updated_at = ? WHERE discord_id = ?')
                    ->execute([$username, $avatar, $now, $discordId]);
                $row['username'] = $username;
                $row['avatar_url'] = $avatar;
                $row['updated_at'] = $now;
            }
        }
        if (function_exists('tcgSocialEnsureFriendCode')) {
            try {
                tcgSocialEnsureFriendCode($discordId);
                $stmt->execute([$discordId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $row;
            } catch (Throwable $e) {
                // Login must not fail if friend-code assign races a unique index.
            }
        }
        return $row;
    }
    $insertName = trim((string)($profile['username'] ?? ''));
    if ($insertName === ''
        || (function_exists('tcgIsPlaceholderUsername') && tcgIsPlaceholderUsername($insertName))) {
        if (function_exists('tcgBuildAuthUserProfile')) {
            $rebuilt = tcgBuildAuthUserProfile($discordId);
            $rebuiltName = trim((string)($rebuilt['username'] ?? ''));
            if (!function_exists('tcgIsPlaceholderUsername') || !tcgIsPlaceholderUsername($rebuiltName)) {
                $insertName = $rebuiltName;
                if (empty($profile['avatar_url']) && !empty($rebuilt['avatar_url'])) {
                    $profile['avatar_url'] = $rebuilt['avatar_url'];
                }
            }
        }
    }
    if ($insertName === ''
        || (function_exists('tcgIsPlaceholderUsername') && tcgIsPlaceholderUsername($insertName))) {
        $insertName = 'Player';
    }
    $db->prepare('INSERT INTO tcg_users (discord_id, username, avatar_url, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?)')
        ->execute([
            $discordId,
            $insertName,
            $profile['avatar_url'] ?? null,
            $now,
            $now,
        ]);
    $db->prepare('INSERT OR IGNORE INTO tcg_daily_state (discord_id) VALUES (?)')->execute([$discordId]);
    $db->prepare('INSERT OR IGNORE INTO tcg_rank (discord_id, game_mode, updated_at) VALUES (?, ?, ?)')
        ->execute([$discordId, 'standard', $now]);
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (function_exists('tcgSocialEnsureFriendCode')) {
        try {
            tcgSocialEnsureFriendCode($discordId);
            $stmt->execute([$discordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $row;
        } catch (Throwable $e) {
            // Login must not fail if friend-code assign races a unique index.
        }
    }
    return $row;
}

/**
 * Resolve a tcg_users row for Discord /loveca profile lookup.
 * Prefer discord_id; fall back to case-insensitive username (unique Discord handle / display).
 *
 * @param list<string> $usernames
 */
function tcgFindUserForPublicProfile(string $discordId, array $usernames = []): ?array {
    $db = tcgDb();
    if ($discordId !== '' && preg_match('/^\d{5,32}$/', $discordId)) {
        $stmt = $db->prepare('SELECT * FROM tcg_users WHERE discord_id = ?');
        $stmt->execute([$discordId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            return $user;
        }
    }

    $candidates = [];
    foreach ($usernames as $name) {
        $name = trim((string)$name);
        if ($name === '' || strlen($name) < 2) {
            continue;
        }
        $key = strtolower($name);
        if (!isset($candidates[$key])) {
            $candidates[$key] = $name;
        }
    }
    foreach ($candidates as $name) {
        $stmt = $db->prepare('SELECT * FROM tcg_users WHERE lower(username) = lower(?)');
        $stmt->execute([$name]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 1) {
            return $rows[0];
        }
        // Prefer a snowflake-backed row if several share a display-style name.
        $snowflakeRows = array_values(array_filter(
            $rows,
            static fn($r) => (bool)preg_match('/^\d{17,20}$/', (string)($r['discord_id'] ?? ''))
        ));
        if (count($snowflakeRows) === 1) {
            return $snowflakeRows[0];
        }
    }
    return null;
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

/** Sum of all card quantities owned (not unique count). */
function tcgCollectionTotalCards(string $discordId): int {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT COALESCE(SUM(qty), 0) FROM tcg_collection WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    return max(0, intval($stmt->fetchColumn()));
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
        $db->prepare('DELETE FROM tcg_mission_progress WHERE discord_id = ?')->execute([$discordId]);
        $db->prepare('DELETE FROM tcg_play_stats WHERE discord_id = ?')->execute([$discordId]);
        $db->prepare('DELETE FROM tcg_starter_owned WHERE discord_id = ?')->execute([$discordId]);
        $db->prepare('UPDATE tcg_users SET starter_deck = NULL, banner_card_no = NULL, banner_crop = NULL,
            equipped_flag = NULL, stamp_favorites = NULL, star_gems = 0, dupe_gem_migration_done = 0, unranked_games = 0,
            sticker_exchanges = 0,
            seal_n = 0, seal_r = 0, seal_p = 0, seal_sec = 0, seal_pr = 0,
            updated_at = ? WHERE discord_id = ?')
            ->execute([$now, $discordId]);
        $db->prepare('UPDATE tcg_rank SET rating = 1000, wins = 0, losses = 0, draws = 0, games = 0, updated_at = ?
            WHERE discord_id = ?')->execute([$now, $discordId]);
        $db->prepare('UPDATE tcg_users SET ranked_equipped_starter = 0, ranked_starter_key = NULL WHERE discord_id = ?')
            ->execute([$discordId]);
        $db->prepare('UPDATE tcg_daily_state SET last_open_date = NULL, packs_opened_today = 0, first_day_bonus_used = 0,
            ranked_pr_date = NULL, ranked_pr_today = 0,
            login_bonus_step = 0, login_bonus_last_date = NULL
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

function tcgGetScoutingTickets(string $discordId): int {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT scouting_tickets FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $val = $stmt->fetchColumn();
    return $val === false ? 0 : max(0, intval($val));
}

function tcgAddScoutingTickets(string $discordId, int $amount): int {
    if ($amount <= 0) {
        return tcgGetScoutingTickets($discordId);
    }
    $db = tcgDb();
    $db->prepare(
        'UPDATE tcg_users SET scouting_tickets = COALESCE(scouting_tickets, 0) + ?, updated_at = ? WHERE discord_id = ?'
    )->execute([$amount, time(), $discordId]);
    return tcgGetScoutingTickets($discordId);
}

function tcgDeductScoutingTickets(string $discordId, int $amount): int {
    if ($amount <= 0) {
        return tcgGetScoutingTickets($discordId);
    }
    return tcgDbRetry(function () use ($discordId, $amount) {
        $db = tcgDb();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT scouting_tickets FROM tcg_users WHERE discord_id = ?');
            $stmt->execute([$discordId]);
            $have = max(0, intval($stmt->fetchColumn() ?: 0));
            if ($have < $amount) {
                throw new Exception('Not enough Scouting Tickets', 400);
            }
            $db->prepare(
                'UPDATE tcg_users SET scouting_tickets = scouting_tickets - ?, updated_at = ? WHERE discord_id = ?'
            )->execute([$amount, time(), $discordId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return tcgGetScoutingTickets($discordId);
    });
}

function tcgGetUnrankedGames(string $discordId): int {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT unranked_games FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $val = $stmt->fetchColumn();
    return $val === false ? 0 : max(0, intval($val));
}

function tcgIncrementUnrankedGames(string $discordId): int {
    $db = tcgDb();
    $now = time();
    $db->prepare('UPDATE tcg_users SET unranked_games = COALESCE(unranked_games, 0) + 1, updated_at = ? WHERE discord_id = ?')
        ->execute([$now, $discordId]);
    return tcgGetUnrankedGames($discordId);
}

function tcgGetStickerExchanges(string $discordId): int {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT sticker_exchanges FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $val = $stmt->fetchColumn();
    return $val === false ? 0 : max(0, intval($val));
}

function tcgIncrementStickerExchanges(string $discordId): int {
    $db = tcgDb();
    $now = time();
    $db->prepare('UPDATE tcg_users SET sticker_exchanges = COALESCE(sticker_exchanges, 0) + 1, updated_at = ? WHERE discord_id = ?')
        ->execute([$now, $discordId]);
    return tcgGetStickerExchanges($discordId);
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
    return tcgDbRetry(function () use ($discordId, $amount) {
        $db = tcgDb();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT star_gems FROM tcg_users WHERE discord_id = ?');
            $stmt->execute([$discordId]);
            $have = max(0, intval($stmt->fetchColumn() ?: 0));
            if ($have < $amount) {
                throw new Exception('Not enough Star Gems', 400);
            }
            $db->prepare('UPDATE tcg_users SET star_gems = star_gems - ?, updated_at = ? WHERE discord_id = ?')
                ->execute([$amount, time(), $discordId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return tcgGetStarGems($discordId);
    });
}

/** Remove up to $amount Star Gems without going below 0 (no exception if balance is low). */
function tcgSoftClawbackStarGems(string $discordId, int $amount): int {
    if ($amount <= 0) {
        return tcgGetStarGems($discordId);
    }
    $db = tcgDb();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT star_gems FROM tcg_users WHERE discord_id = ?');
        $stmt->execute([$discordId]);
        $have = max(0, intval($stmt->fetchColumn() ?: 0));
        $take = min($have, $amount);
        if ($take > 0) {
            $db->prepare('UPDATE tcg_users SET star_gems = star_gems - ?, updated_at = ? WHERE discord_id = ?')
                ->execute([$take, time(), $discordId]);
        }
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
