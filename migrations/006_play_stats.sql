-- Per-user Stage / Live Success play counters (idol, unit, subunit, live name, card).

CREATE TABLE IF NOT EXISTS tcg_play_stats (
    discord_id TEXT NOT NULL,
    tracker TEXT NOT NULL,
    dim TEXT NOT NULL,
    key TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL,
    PRIMARY KEY (discord_id, tracker, dim, key),
    FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tcg_play_stats_user_tracker
    ON tcg_play_stats(discord_id, tracker);
