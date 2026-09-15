<?php
/**
 * Owner banlist: snapshot + wipe a Discord account, reverse ranked W–L
 * opponents earned against it, restore from snapshot on unban.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/game_mode.php';

function tcgBanEnsureSchema(): void {
    $db = tcgDb();
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
}

/**
 * @return list<string>
 */
function tcgBanDiscordTables(): array {
    return [
        'tcg_users',
        'tcg_collection',
        'tcg_daily_state',
        'tcg_box_progress',
        'tcg_deck_presets',
        'tcg_experiment_presets',
        'tcg_rank',
        'tcg_match_queue',
        'tcg_casual_queue',
        'tcg_replays',
        'tcg_mission_progress',
        'tcg_starter_owned',
        'tcg_play_stats',
        'tcg_play_stat_match_applied',
        'tcg_owned_sleeves',
        'tcg_owned_playmats',
        'tcg_profile_showcase',
        'tcg_coin_grants',
        'tcg_push_tokens',
        'tcg_push_queue_ping',
        'tcg_tournament_start_reminders',
        'tcg_user_notices',
        'tcg_tournament_entrants',
        'tcg_tournament_user_stats',
        'tcg_tournament_h2h',
        'tcg_tournament_ledger',
    ];
}

/**
 * @return array<string,list<string>>
 */
function tcgBanEitherTables(): array {
    return [
        'tcg_ranked_matches' => ['p1_id', 'p2_id'],
        'tcg_casual_matches' => ['player_id'],
        'tcg_pvp_results' => ['p1_id', 'p2_id'],
        'tcg_friends' => ['user_lo', 'user_hi'],
        'tcg_match_invites' => ['from_id', 'to_id'],
        'tcg_profile_reports' => ['reporter_id', 'target_id'],
        'tcg_tournament_h2h' => ['discord_id', 'opponent_discord_id'],
    ];
}

function tcgBanTableExists(PDO $db, string $table): bool {
    if (!preg_match('/^[a-z0-9_]+$/', $table)) {
        return false;
    }
    $st = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $db->quote($table));
    return $st && (bool)$st->fetchColumn();
}

/**
 * @return list<array<string,mixed>>
 */
function tcgBanSelectRows(PDO $db, string $table, string $sql, array $params): array {
    if (!tcgBanTableExists($db, $table)) {
        return [];
    }
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array<string,list<array<string,mixed>>>
 */
function tcgBanSnapshotUser(string $discordId): array {
    $db = tcgDb();
    $out = [];
    foreach (tcgBanDiscordTables() as $table) {
        $rows = tcgBanSelectRows($db, $table, "SELECT * FROM {$table} WHERE discord_id = ?", [$discordId]);
        if ($rows) {
            $out[$table] = $rows;
        }
    }
    foreach (tcgBanEitherTables() as $table => $cols) {
        $parts = [];
        $params = [];
        foreach ($cols as $col) {
            if (!preg_match('/^[a-z0-9_]+$/', $col)) {
                continue;
            }
            $parts[] = "{$col} = ?";
            $params[] = $discordId;
        }
        if (!$parts) {
            continue;
        }
        $rows = tcgBanSelectRows(
            $db,
            $table,
            'SELECT * FROM ' . $table . ' WHERE ' . implode(' OR ', $parts),
            $params
        );
        if ($rows) {
            $out[$table] = $rows;
        }
    }
    return $out;
}

function tcgBanWipeUser(string $discordId): void {
    $db = tcgDb();
    foreach (tcgBanEitherTables() as $table => $cols) {
        if (!tcgBanTableExists($db, $table)) {
            continue;
        }
        $parts = [];
        $params = [];
        foreach ($cols as $col) {
            if (!preg_match('/^[a-z0-9_]+$/', $col)) {
                continue;
            }
            $parts[] = "{$col} = ?";
            $params[] = $discordId;
        }
        if ($parts) {
            try {
                $db->prepare('DELETE FROM ' . $table . ' WHERE ' . implode(' OR ', $parts))->execute($params);
            } catch (Throwable $e) {
                // Table/column mismatch on older Hostinger DBs.
            }
        }
    }
    foreach (array_reverse(tcgBanDiscordTables()) as $table) {
        if (!tcgBanTableExists($db, $table)) {
            continue;
        }
        try {
            $db->prepare("DELETE FROM {$table} WHERE discord_id = ?")->execute([$discordId]);
        } catch (Throwable $e) {
            // Table/column mismatch on older Hostinger DBs.
        }
    }
}

/**
 * @param array<string,list<array<string,mixed>>> $snapshot
 */
function tcgBanRestoreSnapshot(array $snapshot): void {
    $db = tcgDb();
    $order = array_merge(tcgBanDiscordTables(), array_keys(tcgBanEitherTables()));
    foreach ($order as $table) {
        $rows = $snapshot[$table] ?? null;
        if (!is_array($rows) || !$rows || !tcgBanTableExists($db, $table)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !$row) {
                continue;
            }
            $cols = [];
            $vals = [];
            foreach ($row as $k => $v) {
                if (!is_string($k) || !preg_match('/^[a-z0-9_]+$/', $k)) {
                    continue;
                }
                $cols[] = $k;
                $vals[] = $v;
            }
            if (!$cols) {
                continue;
            }
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $db->prepare('INSERT OR REPLACE INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . $ph . ')')
                ->execute($vals);
        }
    }
}

function tcgBanEloDelta(int $winnerRating, int $loserRating): int {
    $expectedW = 1 / (1 + pow(10, ($loserRating - $winnerRating) / 400));
    return (int)round(32 * (1 - $expectedW));
}

/**
 * Reverse ranked W–L (and approximate Elo) opponents got vs this account,
 * and casual unranked_games counts from PvP rows.
 *
 * @return list<array{discord_id:string,game_mode:string,wins:int,losses:int,draws:int,games:int,rating:int,unranked_games?:int}>
 */
function tcgBanComputeAdjustments(string $bannedId): array {
    $db = tcgDb();
    $agg = [];
    $bannedRatings = [];
    $rankSt = null;
    if (tcgBanTableExists($db, 'tcg_rank')) {
        $rankSt = $db->prepare('SELECT rating FROM tcg_rank WHERE discord_id = ? AND game_mode = ?');
    }

    $pvpWinnerByRoom = [];
    if (tcgBanTableExists($db, 'tcg_pvp_results')) {
        $pvpSt = $db->prepare(
            'SELECT room_id, mode, p1_id, p2_id, winner_id FROM tcg_pvp_results
             WHERE p1_id = ? OR p2_id = ?'
        );
        $pvpSt->execute([$bannedId, $bannedId]);
        while ($prow = $pvpSt->fetch(PDO::FETCH_ASSOC)) {
            $rid = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($prow['room_id'] ?? '')) ?? '');
            $win = trim((string)($prow['winner_id'] ?? ''));
            if ($rid !== '' && $win !== '') {
                $pvpWinnerByRoom[$rid] = $win;
            }
            $p1 = (string)($prow['p1_id'] ?? '');
            $p2 = (string)($prow['p2_id'] ?? '');
            $opp = $p1 === $bannedId ? $p2 : $p1;
            if ($opp === '' || $opp === $bannedId) {
                continue;
            }
            $mode = strtolower(trim((string)($prow['mode'] ?? '')));
            // Casual / unranked profile counter (not Elo).
            if ($mode === '' || $mode === 'casual' || $mode === 'unranked' || str_contains($mode, 'casual')) {
                $key = $opp . "\0__unranked__";
                if (!isset($agg[$key])) {
                    $agg[$key] = [
                        'discord_id' => $opp,
                        'game_mode' => TCG_GAME_MODE_STANDARD,
                        'wins' => 0,
                        'losses' => 0,
                        'draws' => 0,
                        'games' => 0,
                        'rating' => 0,
                        'unranked_games' => 0,
                    ];
                }
                $agg[$key]['unranked_games'] = intval($agg[$key]['unranked_games'] ?? 0) + 1;
            }
        }
    }

    if (!tcgBanTableExists($db, 'tcg_ranked_matches')) {
        return array_values(array_filter($agg, static function ($a) {
            return intval($a['wins'] ?? 0) !== 0
                || intval($a['losses'] ?? 0) !== 0
                || intval($a['draws'] ?? 0) !== 0
                || intval($a['games'] ?? 0) !== 0
                || intval($a['rating'] ?? 0) !== 0
                || intval($a['unranked_games'] ?? 0) !== 0;
        }));
    }

    $st = $db->prepare(
        "SELECT room_id, p1_id, p2_id, winner_pid, game_mode FROM tcg_ranked_matches
         WHERE status = 'done' AND (p1_id = ? OR p2_id = ?)"
    );
    $st->execute([$bannedId, $bannedId]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $p1 = (string)$row['p1_id'];
        $p2 = (string)$row['p2_id'];
        $opp = $p1 === $bannedId ? $p2 : $p1;
        if ($opp === '' || $opp === $bannedId) {
            continue;
        }
        $mode = tcgNormalizeGameMode($row['game_mode'] ?? TCG_GAME_MODE_STANDARD);
        $key = $opp . "\0" . $mode;
        if (!isset($agg[$key])) {
            $agg[$key] = [
                'discord_id' => $opp,
                'game_mode' => $mode,
                'wins' => 0,
                'losses' => 0,
                'draws' => 0,
                'games' => 0,
                'rating' => 0,
                'unranked_games' => 0,
            ];
        }
        $wp = (string)($row['winner_pid'] ?? '');
        $bannedWon = ($wp === 'p1' && $p1 === $bannedId) || ($wp === 'p2' && $p2 === $bannedId);
        $oppWon = ($wp === 'p1' && $p1 === $opp) || ($wp === 'p2' && $p2 === $opp);
        if (!$bannedWon && !$oppWon) {
            $rid = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($row['room_id'] ?? '')) ?? '');
            $winId = $rid !== '' ? ($pvpWinnerByRoom[$rid] ?? '') : '';
            if ($winId === $bannedId) {
                $bannedWon = true;
            } elseif ($winId === $opp) {
                $oppWon = true;
            }
        }
        $agg[$key]['games']++;
        if (!$bannedWon && !$oppWon) {
            $agg[$key]['draws']++;
            continue;
        }
        if ($rankSt) {
            if (!isset($bannedRatings[$mode])) {
                $rankSt->execute([$bannedId, $mode]);
                $bannedRatings[$mode] = intval($rankSt->fetchColumn() ?: 1000);
            }
            $rankSt->execute([$opp, $mode]);
            $oppRating = intval($rankSt->fetchColumn() ?: 1000);
            $bRating = $bannedRatings[$mode];
            if ($oppWon) {
                $agg[$key]['wins']++;
                $agg[$key]['rating'] -= tcgBanEloDelta($oppRating, $bRating);
            } else {
                $agg[$key]['losses']++;
                $agg[$key]['rating'] += tcgBanEloDelta($bRating, $oppRating);
            }
        } elseif ($oppWon) {
            $agg[$key]['wins']++;
        } else {
            $agg[$key]['losses']++;
        }
    }
    return array_values($agg);
}

/**
 * @param list<array{discord_id:string,game_mode:string,wins:int,losses:int,draws:int,games:int,rating:int,unranked_games?:int}> $adjustments
 * @param int $sign -1 when banning (remove W/L), +1 when unbanning (restore)
 */
function tcgBanApplyAdjustments(array $adjustments, int $sign): void {
    $db = tcgDb();
    $now = time();
    foreach ($adjustments as $a) {
        $uid = (string)($a['discord_id'] ?? '');
        $mode = tcgNormalizeGameMode($a['game_mode'] ?? TCG_GAME_MODE_STANDARD);
        if ($uid === '') {
            continue;
        }
        $w = $sign * intval($a['wins'] ?? 0);
        $l = $sign * intval($a['losses'] ?? 0);
        $d = $sign * intval($a['draws'] ?? 0);
        $g = $sign * intval($a['games'] ?? 0);
        $r = $sign * intval($a['rating'] ?? 0);
        $uq = $sign * intval($a['unranked_games'] ?? 0);
        if ($w !== 0 || $l !== 0 || $d !== 0 || $g !== 0 || $r !== 0) {
            if (tcgBanTableExists($db, 'tcg_rank')) {
                $db->prepare(
                    'UPDATE tcg_rank SET
                        wins = MAX(0, wins + ?),
                        losses = MAX(0, losses + ?),
                        draws = MAX(0, draws + ?),
                        games = MAX(0, games + ?),
                        rating = MAX(100, rating + ?),
                        updated_at = ?
                     WHERE discord_id = ? AND game_mode = ?'
                )->execute([$w, $l, $d, $g, $r, $now, $uid, $mode]);
            }
        }
        if ($uq !== 0 && tcgBanTableExists($db, 'tcg_users')) {
            try {
                $db->prepare(
                    'UPDATE tcg_users SET
                        unranked_games = MAX(0, COALESCE(unranked_games, 0) + ?),
                        updated_at = ?
                     WHERE discord_id = ?'
                )->execute([$uq, $now, $uid]);
            } catch (Throwable $e) {
                // Older DBs without unranked_games.
            }
        }
    }
}

function tcgBanInsertNotice(string $discordId, string $kind, string $reason): void {
    tcgBanEnsureSchema();
    tcgDb()->prepare(
        'INSERT INTO tcg_user_notices (discord_id, kind, reason, created_at) VALUES (?, ?, ?, ?)'
    )->execute([$discordId, $kind, $reason, time()]);
}

/**
 * @return list<array{id:int,kind:string,reason:string,created_at:int}>
 */
function tcgBanPendingNotices(string $discordId): array {
    tcgBanEnsureSchema();
    $st = tcgDb()->prepare(
        'SELECT id, kind, reason, created_at FROM tcg_user_notices
         WHERE discord_id = ? AND acked_at IS NULL ORDER BY id ASC LIMIT 20'
    );
    $st->execute([$discordId]);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $out[] = [
            'id' => intval($row['id']),
            'kind' => (string)$row['kind'],
            'reason' => (string)$row['reason'],
            'created_at' => intval($row['created_at']),
        ];
    }
    return $out;
}

function tcgBanAckNotice(string $discordId, int $noticeId): void {
    tcgBanEnsureSchema();
    tcgDb()->prepare(
        'UPDATE tcg_user_notices SET acked_at = ? WHERE id = ? AND discord_id = ? AND acked_at IS NULL'
    )->execute([time(), $noticeId, $discordId]);
}

function tcgBanAccount(string $target, string $actorId, string $reason): array {
    if (function_exists('tcgSocialIsOwner') && tcgSocialIsOwner($target)) {
        throw new Exception('Cannot ban the owner account', 400);
    }
    if ($target === $actorId) {
        throw new Exception('Cannot ban yourself', 400);
    }
    tcgBanEnsureSchema();
    $db = tcgDb();
    $st = $db->prepare('SELECT discord_id FROM tcg_account_bans WHERE discord_id = ? AND restored_at IS NULL');
    $st->execute([$target]);
    if ($st->fetchColumn()) {
        throw new Exception('Account is already banned', 400);
    }
    $userSt = $db->prepare('SELECT username FROM tcg_users WHERE discord_id = ?');
    $userSt->execute([$target]);
    $username = (string)($userSt->fetchColumn() ?: '');
    $snapshot = tcgBanSnapshotUser($target);
    $adjustments = tcgBanComputeAdjustments($target);
    $db->beginTransaction();
    try {
        tcgBanApplyAdjustments($adjustments, -1);
        tcgBanWipeUser($target);
        $db->prepare(
            'INSERT INTO tcg_account_bans
                (discord_id, username, reason, snapshot_json, adjustments_json, banned_by, banned_at, restored_at, restored_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL)
             ON CONFLICT(discord_id) DO UPDATE SET
                username = excluded.username,
                reason = excluded.reason,
                snapshot_json = excluded.snapshot_json,
                adjustments_json = excluded.adjustments_json,
                banned_by = excluded.banned_by,
                banned_at = excluded.banned_at,
                restored_at = NULL,
                restored_by = NULL'
        )->execute([
            $target,
            $username,
            $reason,
            json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            json_encode($adjustments, JSON_UNESCAPED_UNICODE),
            $actorId,
            time(),
        ]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['success' => true, 'banned' => $target, 'adjustments' => count($adjustments)];
}

function tcgUnbanAccount(string $target, string $actorId): array {
    tcgBanEnsureSchema();
    $db = tcgDb();
    $st = $db->prepare('SELECT * FROM tcg_account_bans WHERE discord_id = ? AND restored_at IS NULL');
    $st->execute([$target]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Account is not banned', 400);
    }
    $snapshot = json_decode((string)$row['snapshot_json'], true);
    $adjustments = json_decode((string)$row['adjustments_json'], true);
    if (!is_array($snapshot)) {
        $snapshot = [];
    }
    if (!is_array($adjustments)) {
        $adjustments = [];
    }
    $db->beginTransaction();
    try {
        tcgBanRestoreSnapshot($snapshot);
        tcgBanApplyAdjustments($adjustments, 1);
        $db->prepare('UPDATE tcg_account_bans SET restored_at = ?, restored_by = ? WHERE discord_id = ?')
            ->execute([time(), $actorId, $target]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['success' => true, 'restored' => $target];
}

/**
 * @return list<array<string,mixed>>
 */
function tcgBanListActive(): array {
    tcgBanEnsureSchema();
    $st = tcgDb()->query(
        'SELECT discord_id, username, reason, banned_at, banned_by
         FROM tcg_account_bans WHERE restored_at IS NULL ORDER BY banned_at DESC LIMIT 200'
    );
    return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
}

function tcgBanIsActive(string $discordId): bool {
    $discordId = trim($discordId);
    if ($discordId === '') {
        return false;
    }
    tcgBanEnsureSchema();
    $st = tcgDb()->prepare(
        'SELECT 1 FROM tcg_account_bans WHERE discord_id = ? AND restored_at IS NULL LIMIT 1'
    );
    $st->execute([$discordId]);
    return (bool)$st->fetchColumn();
}

/**
 * SQL fragment: exclude Discord IDs with an active ban (for leaderboard / public lists).
 * Caller must ensure tcg_account_bans exists (tcgBanEnsureSchema).
 */
function tcgBanLeaderboardExcludeSql(string $discordIdExpr = 'r.discord_id'): string {
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $discordIdExpr)) {
        $discordIdExpr = 'r.discord_id';
    }
    return " AND NOT EXISTS (
        SELECT 1 FROM tcg_account_bans b
        WHERE b.discord_id = {$discordIdExpr} AND b.restored_at IS NULL
    )";
}
