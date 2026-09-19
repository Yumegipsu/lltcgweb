-- Time-limited events: points, milestones, ranking rewards (JST windows).
CREATE TABLE IF NOT EXISTS tcg_events (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    banner_url TEXT NOT NULL DEFAULT '',
    starts_at INTEGER NOT NULL,
    ends_at INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'scheduled',
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    ended_processed_at INTEGER
);

CREATE INDEX IF NOT EXISTS idx_tcg_events_status_window
    ON tcg_events(status, starts_at, ends_at);

CREATE TABLE IF NOT EXISTS tcg_event_milestones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT NOT NULL,
    threshold_points INTEGER NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    reward_type TEXT NOT NULL,
    reward_payload TEXT NOT NULL DEFAULT '{}',
    FOREIGN KEY (event_id) REFERENCES tcg_events(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tcg_event_milestones_event
    ON tcg_event_milestones(event_id, threshold_points);

CREATE TABLE IF NOT EXISTS tcg_event_rank_rewards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT NOT NULL,
    rank_from INTEGER NOT NULL,
    rank_to INTEGER NOT NULL,
    reward_type TEXT NOT NULL,
    reward_payload TEXT NOT NULL DEFAULT '{}',
    FOREIGN KEY (event_id) REFERENCES tcg_events(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tcg_event_rank_rewards_event
    ON tcg_event_rank_rewards(event_id, rank_from);

CREATE TABLE IF NOT EXISTS tcg_event_points (
    event_id TEXT NOT NULL,
    discord_id TEXT NOT NULL,
    points INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL,
    PRIMARY KEY (event_id, discord_id),
    FOREIGN KEY (event_id) REFERENCES tcg_events(id) ON DELETE CASCADE,
    FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id)
);

CREATE INDEX IF NOT EXISTS idx_tcg_event_points_lb
    ON tcg_event_points(event_id, points DESC);

CREATE TABLE IF NOT EXISTS tcg_event_point_grants (
    room_id TEXT NOT NULL,
    discord_id TEXT NOT NULL,
    event_id TEXT NOT NULL,
    amount INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    PRIMARY KEY (room_id, discord_id, event_id)
);

CREATE TABLE IF NOT EXISTS tcg_event_milestone_claims (
    event_id TEXT NOT NULL,
    discord_id TEXT NOT NULL,
    milestone_id INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    PRIMARY KEY (event_id, discord_id, milestone_id),
    FOREIGN KEY (event_id) REFERENCES tcg_events(id) ON DELETE CASCADE,
    FOREIGN KEY (milestone_id) REFERENCES tcg_event_milestones(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tcg_event_rank_grants (
    event_id TEXT NOT NULL,
    discord_id TEXT NOT NULL,
    rank_place INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    PRIMARY KEY (event_id, discord_id),
    FOREIGN KEY (event_id) REFERENCES tcg_events(id) ON DELETE CASCADE
);
