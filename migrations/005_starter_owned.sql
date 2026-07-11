-- Track which starter decks a player has been granted (initial + milestone rewards).

CREATE TABLE IF NOT EXISTS tcg_starter_owned (
    discord_id TEXT NOT NULL,
    starter_key TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT 'initial',
    mission_id TEXT,
    granted_at INTEGER NOT NULL,
    PRIMARY KEY (discord_id, starter_key),
    FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tcg_starter_owned_user
    ON tcg_starter_owned(discord_id);
