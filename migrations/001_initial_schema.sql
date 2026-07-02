-- Initial SQLite schema for LLTCG accounts (baseline migration).
-- Applied once via src/Db/Migrator.php; existing installs skip via schema_migrations.

CREATE TABLE IF NOT EXISTS tcg_users (
    discord_id TEXT PRIMARY KEY,
    username TEXT NOT NULL DEFAULT "Player",
    avatar_url TEXT,
    starter_deck TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS tcg_collection (
    discord_id TEXT NOT NULL,
    card_no TEXT NOT NULL,
    qty INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (discord_id, card_no),
    FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tcg_daily_state (
    discord_id TEXT PRIMARY KEY,
    last_open_date TEXT,
    packs_opened_today INTEGER NOT NULL DEFAULT 0,
    first_day_bonus_used INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tcg_box_progress (
    discord_id TEXT NOT NULL,
    box_id TEXT NOT NULL,
    packs_in_box INTEGER NOT NULL DEFAULT 0,
    boxes_opened INTEGER NOT NULL DEFAULT 0,
    pe_pity INTEGER NOT NULL DEFAULT 0,
    pplus_pity INTEGER NOT NULL DEFAULT 0,
    sec_pity INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (discord_id, box_id),
    FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tcg_deck_presets (
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
);

CREATE TABLE IF NOT EXISTS tcg_rank (
    discord_id TEXT PRIMARY KEY,
    rating INTEGER NOT NULL DEFAULT 1000,
    wins INTEGER NOT NULL DEFAULT 0,
    losses INTEGER NOT NULL DEFAULT 0,
    draws INTEGER NOT NULL DEFAULT 0,
    games INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL,
    FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tcg_match_queue (
    discord_id TEXT PRIMARY KEY,
    rating INTEGER NOT NULL DEFAULT 1000,
    joined_at INTEGER NOT NULL,
    FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tcg_ranked_matches (
    match_id TEXT PRIMARY KEY,
    room_id TEXT NOT NULL,
    p1_id TEXT NOT NULL,
    p2_id TEXT NOT NULL,
    p1_token TEXT NOT NULL,
    p2_token TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT "pending",
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS tcg_casual_queue (
    queue_key TEXT PRIMARY KEY,
    discord_id TEXT,
    player_name TEXT NOT NULL,
    join_body TEXT NOT NULL,
    joined_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS tcg_casual_matches (
    queue_key TEXT NOT NULL,
    room_id TEXT NOT NULL,
    player_token TEXT NOT NULL,
    player_id TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    PRIMARY KEY (queue_key, room_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_casual_queue_discord
    ON tcg_casual_queue(discord_id) WHERE discord_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS tcg_schema_meta (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
