<?php
/**
 * Owner banlist: snapshot + wipe a Discord account, reverse ranked W–L
 * opponents earned against it, rewind current-season points from those
 * games, restore from snapshot on unban.
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
        'tcg_season_rank',
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
                // Stored like wins: ban subtracts Elo the opponent gained.
                $agg[$key]['rating'] += tcgBanEloDelta($oppRating, $bRating);
            } else {
                $agg[$key]['losses']++;
                $agg[$key]['rating'] -= tcgBanEloDelta($bRating, $oppRating);
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
 * @return array{step:int,points:int,wins:int,losses:int,peak:int}
 */
function tcgBanSeasonBlank(): array {
    return ['step' => 0, 'points' => 0, 'wins' => 0, 'losses' => 0, 'peak' => 0];
}

/**
 * @param array{step:int,points:int,wins:int,losses:int,peak:int} $state
 * @return array{step:int,points:int,wins:int,losses:int,peak:int}
 */
function tcgBanSeasonStepState(array $state, int $delta, bool $win): array {
    $moved = tcgSeasonMovePoints((int)$state['step'], (int)$state['points'], $delta, $win);
    $step = (int)$moved['step'];
    return [
        'step' => $step,
        'points' => (int)$moved['points'],
        'wins' => (int)$state['wins'] + ($win ? 1 : 0),
        'losses' => (int)$state['losses'] + ($win ? 0 : 1),
        'peak' => max((int)$state['peak'], $step),
    ];
}

/**
 * UTC month of the active season. Season ids are UTC (2026-10), so every
 * standard game already played this month is in range, not only games after
 * the server's local midnight.
 *
 * @param array{id?:string} $clock
 * @return array{0:int,1:int}
 */
function tcgBanActiveSeasonBounds(array $clock): array {
    $id = (string)($clock['id'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})$/', $id, $m)) {
        return [0, 0];
    }
    $year = (int)$m[1];
    $month = (int)$m[2];
    $tz = new DateTimeZone('UTC');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $year, $month), $tz);
    if (!$start) {
        return [0, 0];
    }
    $end = $start->modify('first day of next month');
    return [$start->getTimestamp(), $end->getTimestamp()];
}

/**
 * Standard ranked games in the open season, oldest first.
 * A match created just before the month boundary still counts when it
 * finished inside the season, which is when the points were applied.
 *
 * @return list<array{room_id:string,p1_id:string,p2_id:string,winner_pid:string,created_at:int,ended_at:int}>
 */
function tcgBanSeasonMatches(int $start, int $end): array {
    $db = tcgDb();
    if (!tcgBanTableExists($db, 'tcg_ranked_matches')) {
        return [];
    }
    $pvpEnded = [];
    $pvpWinner = [];
    if (tcgBanTableExists($db, 'tcg_pvp_results')) {
        $pvp = $db->prepare(
            "SELECT room_id, winner_id, ended_at FROM tcg_pvp_results
             WHERE ended_at >= ? AND ended_at < ? AND lower(mode) = 'ranked'"
        );
        $pvp->execute([$start - 2 * 86400, $end]);
        while ($row = $pvp->fetch(PDO::FETCH_ASSOC)) {
            $rid = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($row['room_id'] ?? '')) ?? '');
            if ($rid === '') {
                continue;
            }
            $pvpEnded[$rid] = intval($row['ended_at'] ?? 0);
            $win = trim((string)($row['winner_id'] ?? ''));
            if ($win !== '') {
                $pvpWinner[$rid] = $win;
            }
        }
    }
    $st = $db->prepare(
        "SELECT room_id, p1_id, p2_id, winner_pid, created_at, game_mode
         FROM tcg_ranked_matches
         WHERE status = 'done' AND created_at >= ? AND created_at < ?"
    );
    $st->execute([$start - 2 * 86400, $end]);
    $rows = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (strtolower(trim((string)($row['game_mode'] ?? ''))) !== TCG_GAME_MODE_STANDARD) {
            continue;
        }
        $rid = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($row['room_id'] ?? '')) ?? '');
        $created = intval($row['created_at'] ?? 0);
        $ended = $pvpEnded[$rid] ?? 0;
        $when = $ended > 0 ? $ended : $created;
        if ($when < $start || $when >= $end) {
            continue;
        }
        $rows[] = [
            'room_id' => $rid,
            'p1_id' => (string)($row['p1_id'] ?? ''),
            'p2_id' => (string)($row['p2_id'] ?? ''),
            'winner_pid' => (string)($row['winner_pid'] ?? ''),
            'created_at' => $created,
            'ended_at' => $when,
            'pvp_winner' => $pvpWinner[$rid] ?? '',
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        $cmp = $a['ended_at'] <=> $b['ended_at'];
        return $cmp !== 0 ? $cmp : strcmp($a['room_id'], $b['room_id']);
    });
    return $rows;
}

/**
 * Rewind the current season for opponents who played this account.
 * Points are removed in reverse match order using the delta from each
 * game. Wins and losses against everyone else stay.
 *
 * @return list<array<string,mixed>>
 */
function tcgBanComputeSeasonAdjustments(string $bannedId): array {
    $bannedId = trim($bannedId);
    if ($bannedId === '') {
        return [];
    }
    require_once __DIR__ . '/season.php';
    $clock = tcgSeasonClockInfo();
    if (empty($clock['active'])) {
        return [];
    }
    [$start, $end] = tcgBanActiveSeasonBounds($clock);
    if ($start <= 0 || $end <= $start) {
        return [];
    }
    $matches = tcgBanSeasonMatches($start, $end);
    if ($matches === []) {
        return [];
    }

    $live = [];
    $shadow = [];
    $events = [];
    foreach ($matches as $match) {
        $p1 = $match['p1_id'];
        $p2 = $match['p2_id'];
        if ($p1 === '' || $p2 === '' || $p1 === $p2) {
            continue;
        }
        $wp = $match['winner_pid'];
        if ($wp === 'p1') {
            $winner = $p1;
            $loser = $p2;
        } elseif ($wp === 'p2') {
            $winner = $p2;
            $loser = $p1;
        } elseif ($match['pvp_winner'] === $p1 || $match['pvp_winner'] === $p2) {
            $winner = (string)$match['pvp_winner'];
            $loser = $winner === $p1 ? $p2 : $p1;
        } else {
            continue;
        }
        $wState = $live[$winner] ?? tcgBanSeasonBlank();
        $lState = $live[$loser] ?? tcgBanSeasonBlank();
        $base = tcgSeasonPointDelta((int)$wState['step'], (int)$lState['step']);
        $lossDelta = tcgSeasonLossDelta(
            tcgSeasonPointDelta((int)$wState['step'], (int)$lState['step'], null, true),
            (int)$lState['step']
        );
        $vsBanned = $winner === $bannedId || $loser === $bannedId;
        if ($vsBanned) {
            $opp = $winner === $bannedId ? $loser : $winner;
            $events[$opp][] = [
                'won' => $winner === $opp ? 1 : 0,
                'delta' => $winner === $opp ? $base : $lossDelta,
            ];
        } else {
            foreach ([[$winner, $loser, true, $base], [$loser, $winner, false, $lossDelta]] as $side) {
                [$id, $otherId, $won, $delta] = $side;
                $self = $shadow[$id] ?? tcgBanSeasonBlank();
                $otherLive = $live[$otherId] ?? tcgBanSeasonBlank();
                if ($won) {
                    $shadowDelta = tcgSeasonPointDelta((int)$self['step'], (int)$otherLive['step']);
                } else {
                    $shadowBase = tcgSeasonPointDelta((int)$otherLive['step'], (int)$self['step'], null, true);
                    $shadowDelta = tcgSeasonLossDelta($shadowBase, (int)$self['step']);
                }
                $shadow[$id] = tcgBanSeasonStepState($self, $shadowDelta, $won);
            }
        }
        $live[$winner] = tcgBanSeasonStepState($wState, $base, true);
        $live[$loser] = tcgBanSeasonStepState($lState, $lossDelta, false);
    }
    if ($events === []) {
        return [];
    }

    $db = tcgDb();
    if (!tcgBanTableExists($db, 'tcg_season_rank')) {
        return [];
    }
    $rowSt = $db->prepare(
        'SELECT step, points, peak_step, wins, losses, season_id
         FROM tcg_season_rank WHERE discord_id = ? AND game_mode = ?'
    );
    $seasonId = (string)$clock['id'];
    $out = [];
    foreach ($events as $opp => $evs) {
        if ($opp === '' || $opp === $bannedId || $evs === []) {
            continue;
        }
        $rowSt->execute([$opp, TCG_GAME_MODE_STANDARD]);
        $row = $rowSt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (string)($row['season_id'] ?? '') !== $seasonId) {
            continue;
        }
        $step = (int)$row['step'];
        $points = (int)$row['points'];
        $won = 0;
        $lost = 0;
        foreach (array_reverse($evs) as $ev) {
            if (!empty($ev['won'])) {
                $next = tcgSeasonUndoWin($step, $points, (int)$ev['delta']);
                $won++;
            } else {
                $next = tcgSeasonUndoLoss($step, $points, (int)$ev['delta']);
                $lost++;
            }
            $step = (int)$next['step'];
            $points = (int)$next['points'];
        }
        $winsAfter = max(0, (int)$row['wins'] - $won);
        $lossesAfter = max(0, (int)$row['losses'] - $lost);
        $shadowState = $shadow[$opp] ?? tcgBanSeasonBlank();
        if ((int)$shadowState['wins'] === $winsAfter && (int)$shadowState['losses'] === $lossesAfter) {
            $peakAfter = max($step, (int)$shadowState['peak']);
        } else {
            $peakAfter = max((int)$row['peak_step'], $step);
        }
        $out[] = [
            'kind' => 'season',
            'discord_id' => $opp,
            'game_mode' => TCG_GAME_MODE_STANDARD,
            'season_id' => $seasonId,
            'step_before' => (int)$row['step'],
            'points_before' => (int)$row['points'],
            'peak_before' => (int)$row['peak_step'],
            'wins_before' => (int)$row['wins'],
            'losses_before' => (int)$row['losses'],
            'step_after' => $step,
            'points_after' => $points,
            'peak_after' => $peakAfter,
            'wins_after' => $winsAfter,
            'losses_after' => $lossesAfter,
            'events' => $evs,
        ];
    }
    return $out;
}

/**
 * Put this season's games vs the banned account back on top of the row as it
 * stands now, so matches played while the ban was active are kept.
 *
 * @param list<array<string,mixed>> $events chronological won/delta pairs
 */
function tcgBanReapplySeasonEvents(string $uid, string $seasonId, array $events): void {
    require_once __DIR__ . '/season.php';
    $db = tcgDb();
    $st = $db->prepare(
        'SELECT step, points, peak_step, wins, losses FROM tcg_season_rank
         WHERE discord_id = ? AND game_mode = ? AND season_id = ?'
    );
    $st->execute([$uid, TCG_GAME_MODE_STANDARD, $seasonId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $step = (int)$row['step'];
    $points = (int)$row['points'];
    $peak = (int)$row['peak_step'];
    $won = 0;
    $lost = 0;
    foreach ($events as $ev) {
        if (!is_array($ev)) {
            continue;
        }
        $win = !empty($ev['won']);
        $moved = tcgSeasonMovePoints($step, $points, (int)($ev['delta'] ?? 0), $win);
        $step = (int)$moved['step'];
        $points = (int)$moved['points'];
        $peak = max($peak, $step);
        if ($win) {
            $won++;
        } else {
            $lost++;
        }
    }
    $db->prepare(
        'UPDATE tcg_season_rank SET
            step = ?,
            points = ?,
            peak_step = ?,
            wins = ?,
            losses = ?,
            updated_at = ?
         WHERE discord_id = ? AND game_mode = ? AND season_id = ?'
    )->execute([
        $step,
        $points,
        $peak,
        (int)$row['wins'] + $won,
        (int)$row['losses'] + $lost,
        time(),
        $uid,
        TCG_GAME_MODE_STANDARD,
        $seasonId,
    ]);
}

function tcgBanApplySeasonAdjustment(array $adjustment, int $sign): void {
    $uid = (string)($adjustment['discord_id'] ?? '');
    $seasonId = (string)($adjustment['season_id'] ?? '');
    if ($uid === '' || $seasonId === '') {
        return;
    }
    $events = $adjustment['events'] ?? null;
    if ($sign >= 0 && is_array($events)) {
        tcgBanReapplySeasonEvents($uid, $seasonId, $events);
        return;
    }
    $prefix = $sign < 0 ? 'after' : 'before';
    $db = tcgDb();
    if (!tcgBanTableExists($db, 'tcg_season_rank')) {
        return;
    }
    $db->prepare(
        'UPDATE tcg_season_rank SET
            step = ?,
            points = ?,
            peak_step = ?,
            wins = ?,
            losses = ?,
            updated_at = ?
         WHERE discord_id = ? AND game_mode = ? AND season_id = ?'
    )->execute([
        intval($adjustment['step_' . $prefix] ?? 0),
        intval($adjustment['points_' . $prefix] ?? 0),
        intval($adjustment['peak_' . $prefix] ?? 0),
        max(0, intval($adjustment['wins_' . $prefix] ?? 0)),
        max(0, intval($adjustment['losses_' . $prefix] ?? 0)),
        time(),
        $uid,
        TCG_GAME_MODE_STANDARD,
        $seasonId,
    ]);
}

/**
 * @param list<array<string,mixed>> $adjustments
 */
function tcgBanRefreshSeasonPink(array $adjustments): void {
    $touched = false;
    foreach ($adjustments as $a) {
        if (($a['kind'] ?? '') === 'season') {
            $touched = true;
            break;
        }
    }
    if (!$touched) {
        return;
    }
    require_once __DIR__ . '/season.php';
    $clock = tcgSeasonClockInfo();
    if (empty($clock['active'])) {
        return;
    }
    tcgSeasonAssignPinkSlots(TCG_GAME_MODE_STANDARD, (string)$clock['id']);
}

/**
 * @param list<array{discord_id:string,game_mode:string,wins:int,losses:int,draws:int,games:int,rating:int,unranked_games?:int}> $adjustments
 * @param int $sign -1 when banning (remove W/L), +1 when unbanning (restore)
 */
function tcgBanApplyAdjustments(array $adjustments, int $sign): void {
    $db = tcgDb();
    $now = time();
    foreach ($adjustments as $a) {
        if (($a['kind'] ?? '') === 'season') {
            tcgBanApplySeasonAdjustment($a, $sign);
            continue;
        }
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
    $adjustments = array_merge(
        tcgBanComputeAdjustments($target),
        tcgBanComputeSeasonAdjustments($target)
    );
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
    tcgBanRefreshSeasonPink($adjustments);
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
    tcgBanRefreshSeasonPink($adjustments);
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
