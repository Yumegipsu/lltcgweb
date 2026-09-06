<?php
/**
 * Tournament Mode v1 — tick, bracket start, room seed, advance, payout.
 */

/** @param array<string,mixed> $body */
function tcgApiTournamentTick(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? $_GET['tournament_id'] ?? '')));
    if ($id === '') {
        $stmt = tcgDb()->query(
            'SELECT id FROM tcg_tournaments WHERE status IN ("open","checkin","running") ORDER BY start_at ASC LIMIT 50'
        );
        $ids = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        $results = [];
        foreach ($ids as $tid) {
            $results[] = tcgTournamentTickOne((string)$tid);
        }
        if (function_exists('tcgPushDispatchTournamentStartReminders')) {
            tcgPushDispatchTournamentStartReminders();
        }
        return ['success' => true, 'ticked' => $results];
    }
    $out = tcgTournamentTickOne($id);
    if (function_exists('tcgPushDispatchTournamentStartReminders')) {
        tcgPushDispatchTournamentStartReminders();
    }
    return $out;
}

/** @return array<string,mixed> */
function tcgTournamentTickOne(string $id): array {
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    $now = time();
    $status = (string)$row['status'];
    $events = [];

    if ($status === 'open') {
        $opens = (int)$row['start_at'] - ((int)$row['checkin_mins'] * 60);
        if ($now >= $opens) {
            tcgDb()->prepare('UPDATE tcg_tournaments SET status = "checkin", updated_at = ? WHERE id = ? AND status = "open"')
                ->execute([$now, $id]);
            $events[] = 'entered_checkin';
            $status = 'checkin';
            $row = tcgTournamentFetch($id) ?: $row;
            tcgTournamentNotifyWebhook('entered_checkin', $row);
        }
    }

    if ($status === 'checkin' && $now >= (int)$row['start_at']) {
        $started = tcgTournamentStartBracket($id);
        $events[] = $started ? 'bracket_started' : 'start_deferred';
        $row = tcgTournamentFetch($id) ?: $row;
        if ($started) {
            tcgTournamentNotifyWebhook('bracket_started', $row);
        }
        $status = (string)$row['status'];
    }

    if ($status === 'running') {
        tcgTournamentApplyRoomResults($id);
        tcgTournamentApplyConnectForfeits($id, $now);
        if (function_exists('tcgTournamentRetryMissingReplays')) {
            tcgTournamentRetryMissingReplays($id);
        }
        tcgTournamentSeedPendingRooms($id);
        tcgTournamentAdvanceCompletedRounds($id);
        if (tcgTournamentTryFinish($id)) {
            $events[] = 'finished';
            $row = tcgTournamentFetch($id) ?: $row;
            tcgTournamentNotifyWebhook('finished', $row);
            $status = 'finished';
        }
        $row = tcgTournamentFetch($id) ?: $row;
        $status = (string)($row['status'] ?? $status);
    } elseif ($status === 'finished' && function_exists('tcgTournamentRetryMissingReplays')) {
        tcgTournamentRetryMissingReplays($id);
    }

    $entrants = tcgTournamentFetchEntrants($id);
    $matches = tcgTournamentFetchMatches($id);
    $replayIndex = function_exists('tcgTournamentReplayIdsByRoom')
        ? tcgTournamentReplayIdsByRoom($id)
        : [];
    return [
        'success' => true,
        'tournament' => tcgTournamentPublicRow($row, [
            'total' => count($entrants),
            'checked_in' => count(array_filter(
                $entrants,
                static fn($e) => in_array((string)$e['status'], ['checked_in', 'playing', 'eliminated'], true)
            )),
        ]),
        'matches' => array_map(
            static fn(array $m) => tcgTournamentPublicMatch($m, $replayIndex),
            $matches
        ),
        'events' => $events,
        'server_now' => $now,
    ];
}

function tcgTournamentStartBracket(string $id): bool {
    $row = tcgTournamentFetch($id);
    if (!$row || (string)$row['status'] !== 'checkin') {
        return false;
    }
    $now = time();
    tcgDb()->prepare(
        'UPDATE tcg_tournament_entrants SET status = "no_show"
         WHERE tournament_id = ? AND status = "registered"'
    )->execute([$id]);

    $stmt = tcgDb()->prepare(
        'SELECT * FROM tcg_tournament_entrants WHERE tournament_id = ? AND status = "checked_in" ORDER BY registered_at ASC'
    );
    $stmt->execute([$id]);
    $checked = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($checked) < (int)$row['min_players']) {
        tcgTournamentCancelAndRefund($id, 'min_players');
        return false;
    }

    $settings = tcgTournamentDecodeSettings($row['settings_json'] ?? '{}');
    // PR pack needs 10 check-ins; otherwise refund host escrow and continue with coins only.
    if (!empty($settings['pr_pack']) && (string)($settings['pr_pack_status'] ?? '') === 'escrowed') {
        if (count($checked) < TCG_TOURNAMENT_PR_PACK_MIN_CHECKINS) {
            tcgTournamentRefundPrPackEscrow($id, $row, 'pr_pack_min_checkin');
            $row = tcgTournamentFetch($id) ?: $row;
            $settings = tcgTournamentDecodeSettings($row['settings_json'] ?? '{}');
        }
    }

    shuffle($checked);
    $playerIds = [];
    foreach ($checked as $i => $e) {
        $seed = $i + 1;
        tcgDb()->prepare(
            'UPDATE tcg_tournament_entrants SET seed = ?, status = "playing" WHERE tournament_id = ? AND discord_id = ?'
        )->execute([$seed, $id, $e['discord_id']]);
        $playerIds[] = (string)$e['discord_id'];
    }

    $format = (string)($settings['format'] ?? 'single_elim');
    $bestOf = (int)($settings['best_of'] ?? 1);

    if ($format === 'swiss' || $format === 'double_elim') {
        $records = [];
        foreach ($playerIds as $pid) {
            $records[$pid] = ['wins' => 0, 'losses' => 0];
        }
        $pairings = tcgTournamentBuildSwissPairings($playerIds, $records, []);
        $side = $format === 'swiss' ? 'swiss' : 'winners';
    } else {
        // single_elim + double_elim_bracket: classic winners R1 tree
        $pairings = tcgTournamentBuildRound1Pairings($playerIds);
        $side = 'winners';
    }

    $created = time();
    foreach ($pairings as $slot => $pair) {
        tcgTournamentInsertMatchRow($id, 1, (int)$slot, $side, $pair, $bestOf, $created);
    }

    // Persist swiss target rounds / classic DE bracket size for finish checks.
    if ($format === 'swiss') {
        $n = count($playerIds);
        $settings['swiss_rounds'] = tcgTournamentSwissRoundCount($n);
        $settings['showed_up'] = $n;
        $settings['playoff_size'] = tcgTournamentSwissPlayoffSize($n);
        $settings['swiss_phase'] = 'swiss';
        tcgDb()->prepare('UPDATE tcg_tournaments SET settings_json = ?, updated_at = ? WHERE id = ?')
            ->execute([tcgTournamentEncodeSettings($settings), $now, $id]);
    } elseif (tcgTournamentIsClassicDoubleElim($format)) {
        $settings['bracket_size'] = tcgTournamentBracketSize(count($playerIds));
        tcgDb()->prepare('UPDATE tcg_tournaments SET settings_json = ?, updated_at = ? WHERE id = ?')
            ->execute([tcgTournamentEncodeSettings($settings), $now, $id]);
    }

    tcgDb()->prepare('UPDATE tcg_tournaments SET status = "running", updated_at = ? WHERE id = ?')
        ->execute([$now, $id]);

    tcgTournamentSeedPendingRooms($id);
    tcgTournamentAdvanceCompletedRounds($id);
    return true;
}

/**
 * @param array{p1:?string,p2:?string,bye:?string} $pair
 */
function tcgTournamentInsertMatchRow(
    string $tournamentId,
    int $round,
    int $slot,
    string $side,
    array $pair,
    int $bestOf,
    int $created
): void {
    $mid = tcgTournamentMatchNewId();
    $p1 = $pair['p1'] ?? null;
    $p2 = $pair['p2'] ?? null;
    $bye = $pair['bye'] ?? null;
    if ($bye !== null) {
        $metaArr = [
            'best_of' => $bestOf === 3 ? 3 : 1,
            'p1_wins' => 0,
            'p2_wins' => 0,
            'games' => [],
        ];
        if (function_exists('tcgTournamentStatsRecordSeriesResult')) {
            tcgTournamentStatsRecordSeriesResult((string)$bye, '', $metaArr);
        }
        $meta = tcgTournamentEncodeMatchMeta($metaArr);
        tcgDb()->prepare(
            'INSERT INTO tcg_tournament_matches
             (id, tournament_id, round, bracket_slot, bracket_side, p1_discord_id, p2_discord_id, room_id, p1_token, p2_token,
              status, winner_discord_id, connect_deadline_at, meta_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, "done", ?, NULL, ?, ?, ?)'
        )->execute([$mid, $tournamentId, $round, $slot, $side, $bye, $bye, $meta, $created, $created]);
        return;
    }
    $meta = tcgTournamentEncodeMatchMeta([
        'best_of' => $bestOf === 3 ? 3 : 1,
        'p1_wins' => 0,
        'p2_wins' => 0,
        'games' => [],
    ]);
    tcgDb()->prepare(
        'INSERT INTO tcg_tournament_matches
         (id, tournament_id, round, bracket_slot, bracket_side, p1_discord_id, p2_discord_id, room_id, p1_token, p2_token,
          status, winner_discord_id, connect_deadline_at, meta_json, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, "pending", NULL, NULL, ?, ?, ?)'
    )->execute([$mid, $tournamentId, $round, $slot, $side, $p1, $p2, $meta, $created, $created]);
}

function tcgTournamentCancelAndRefund(string $id, string $reason = 'cancel'): void {
    $row = tcgTournamentFetch($id);
    if (!$row || in_array((string)$row['status'], ['finished', 'cancelled'], true)) {
        return;
    }
    $entrants = tcgTournamentFetchEntrants($id);
    $db = tcgDb();
    $db->beginTransaction();
    try {
        $entryRefunded = 0;
        foreach ($entrants as $e) {
            $paid = (int)($e['paid_coins'] ?? 0);
            $did = (string)$e['discord_id'];
            $key = 'refund_cancel:' . $id . ':' . $did;
            if ($paid > 0 && tcgTournamentLedgerWrite($id, $did, 'refund', $paid, $key, ['reason' => $reason])) {
                tcgAddCoins($did, $paid);
                $entryRefunded += $paid;
            }
        }
        $fresh = tcgTournamentFetch($id);
        $pool = (int)($fresh['prize_pool_coins'] ?? 0);
        $newPool = max(0, $pool - $entryRefunded);
        $hostKey = 'refund_host_pool:' . $id;
        if ($newPool > 0 && tcgTournamentLedgerWrite($id, (string)$row['host_discord_id'], 'refund', $newPool, $hostKey, ['reason' => $reason])) {
            tcgAddCoins((string)$row['host_discord_id'], $newPool);
            $newPool = 0;
        }
        // Refund PR-pack escrow (outside coin pool) before marking cancelled.
        $settings = tcgTournamentDecodeSettings(($fresh ?: $row)['settings_json'] ?? '{}');
        if (!empty($settings['pr_pack']) && (string)($settings['pr_pack_status'] ?? '') === 'escrowed') {
            $prKey = 'refund_pr_pack:' . $id;
            if (tcgTournamentLedgerWrite(
                $id,
                (string)$row['host_discord_id'],
                'refund',
                TCG_TOURNAMENT_PR_PACK_COST,
                $prKey,
                ['reason' => $reason, 'kind' => 'pr_pack_escrow']
            )) {
                tcgAddCoins((string)$row['host_discord_id'], TCG_TOURNAMENT_PR_PACK_COST);
            }
            $settings['pr_pack_status'] = 'dropped';
        }
        $db->prepare(
            'UPDATE tcg_tournaments SET status = "cancelled", prize_pool_coins = ?, settings_json = ?, updated_at = ? WHERE id = ?'
        )->execute([$newPool, tcgTournamentEncodeSettings($settings), time(), $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function tcgTournamentResolveMatch(string $matchId, string $winnerDiscordId, string $reason): void {
    tcgTournamentRecordGameResult($matchId, $winnerDiscordId, $reason);
}

/** Record one game result; Bo3 may keep the series open and reseed the next game. */
function tcgTournamentRecordGameResult(string $matchId, string $winnerDiscordId, string $reason): void {
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_tournament_matches WHERE id = ?');
    $stmt->execute([$matchId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        throw new Exception('Match not found', 404);
    }
    if ((string)$m['status'] === 'done') {
        return;
    }
    $p1 = (string)($m['p1_discord_id'] ?? '');
    $p2 = (string)($m['p2_discord_id'] ?? '');
    if ($winnerDiscordId !== $p1 && $winnerDiscordId !== $p2) {
        throw new Exception('Winner must be a match participant', 400);
    }

    $meta = tcgTournamentDecodeMatchMeta($m['meta_json'] ?? '{}');
    $bestOf = (int)($meta['best_of'] ?? 1);
    if ($bestOf !== 3) {
        $bestOf = 1;
    }
    $roomId = (string)($m['room_id'] ?? '');
    // Avoid double-counting the same finished room in a Bo3 series.
    foreach ($meta['games'] as $g) {
        if (is_array($g) && (string)($g['room_id'] ?? '') === $roomId && $roomId !== '') {
            return;
        }
    }

    if ($winnerDiscordId === $p1) {
        $meta['p1_wins'] = (int)$meta['p1_wins'] + 1;
    } else {
        $meta['p2_wins'] = (int)$meta['p2_wins'] + 1;
    }
    $meta['best_of'] = $bestOf;
    $meta['games'][] = [
        'room_id' => $roomId,
        'winner_discord_id' => $winnerDiscordId,
        'reason' => $reason,
        'at' => time(),
    ];

    // Durable public + personal replays while room tokens are still on the match row.
    if ($roomId !== '' && function_exists('tcgTournamentArchiveFinishedGameReplay')) {
        $gameIdx = count($meta['games']);
        $replayId = null;
        try {
            $replayId = tcgTournamentArchiveFinishedGameReplay(
                $m,
                $roomId,
                $winnerDiscordId,
                $reason,
                $gameIdx
            );
        } catch (Throwable $e) {
            $replayId = null;
        }
        // Second chance before Bo3 clears credentials (overflow finish race).
        if (!$replayId) {
            try {
                $replayId = tcgTournamentArchiveFinishedGameReplay(
                    $m,
                    $roomId,
                    $winnerDiscordId,
                    $reason,
                    $gameIdx
                );
            } catch (Throwable $e) {
                $replayId = null;
            }
        }
        if ($replayId) {
            $meta['games'][count($meta['games']) - 1]['replay_id'] = (int)$replayId;
        }
    }

    $need = (int)ceil($bestOf / 2);
    $seriesOver = $bestOf === 1
        || (int)$meta['p1_wins'] >= $need
        || (int)$meta['p2_wins'] >= $need;

    if (!$seriesOver) {
        // Last-ditch archive while tokens still exist — next seed clears them.
        $lastGame = $meta['games'][count($meta['games']) - 1] ?? null;
        if ($roomId !== '' && is_array($lastGame) && empty($lastGame['replay_id'])
            && function_exists('tcgTournamentArchiveFinishedGameReplay')) {
            try {
                $lateId = tcgTournamentArchiveFinishedGameReplay(
                    $m,
                    $roomId,
                    $winnerDiscordId,
                    $reason,
                    count($meta['games'])
                );
                if ($lateId) {
                    $meta['games'][count($meta['games']) - 1]['replay_id'] = (int)$lateId;
                }
            } catch (Throwable $e) {
                // Fall through — bracket must still advance.
            }
        }
        tcgDb()->prepare(
            'UPDATE tcg_tournament_matches
             SET room_id = NULL, p1_token = NULL, p2_token = NULL, status = "pending",
                 connect_deadline_at = NULL, meta_json = ?, updated_at = ?
             WHERE id = ? AND status IN ("ready","live","pending")'
        )->execute([tcgTournamentEncodeMatchMeta($meta), time(), $matchId]);
        return;
    }

    $seriesWinner = ((int)$meta['p1_wins'] >= (int)$meta['p2_wins']) ? $p1 : $p2;
    if ($bestOf === 1) {
        $seriesWinner = $winnerDiscordId;
    } elseif ((int)$meta['p1_wins'] > (int)$meta['p2_wins']) {
        $seriesWinner = $p1;
    } elseif ((int)$meta['p2_wins'] > (int)$meta['p1_wins']) {
        $seriesWinner = $p2;
    } else {
        $seriesWinner = $winnerDiscordId;
    }

    tcgTournamentFinalizeMatchSeries($m, $seriesWinner, $meta, $reason);
}

/**
 * @param array<string,mixed> $m
 * @param array<string,mixed> $meta
 */
function tcgTournamentFinalizeMatchSeries(array $m, string $winnerDiscordId, array $meta, string $reason): void {
    $matchId = (string)$m['id'];
    $tournamentId = (string)$m['tournament_id'];
    $p1 = (string)($m['p1_discord_id'] ?? '');
    $p2 = (string)($m['p2_discord_id'] ?? '');
    $loser = ($winnerDiscordId === $p1) ? $p2 : $p1;
    $now = time();

    if (function_exists('tcgTournamentStatsRecordSeriesResult')) {
        tcgTournamentStatsRecordSeriesResult($winnerDiscordId, $loser, $meta);
    }

    tcgDb()->prepare(
        'UPDATE tcg_tournament_matches
         SET status = "done", winner_discord_id = ?, meta_json = ?, updated_at = ?
         WHERE id = ?'
    )->execute([$winnerDiscordId, tcgTournamentEncodeMatchMeta($meta), $now, $matchId]);

    $row = tcgTournamentFetch($tournamentId);
    $settings = tcgTournamentDecodeSettings($row['settings_json'] ?? '{}');
    $format = (string)($settings['format'] ?? 'single_elim');

    if ($loser !== '') {
        if ($format === 'swiss') {
            $side = (string)($m['bracket_side'] ?? 'swiss');
            // Swiss stage keeps everyone active; playoff (winners) eliminates.
            if ($side === 'winners') {
                tcgDb()->prepare(
                    'UPDATE tcg_tournament_entrants SET status = "eliminated"
                     WHERE tournament_id = ? AND discord_id = ? AND status = "playing"'
                )->execute([$tournamentId, $loser]);
            }
        } elseif ($format === 'double_elim') {
            $all = tcgTournamentFetchMatches($tournamentId);
            $records = tcgTournamentRecordsFromMatches($all);
            if ((int)($records[$loser]['losses'] ?? 0) >= 2) {
                tcgDb()->prepare(
                    'UPDATE tcg_tournament_entrants SET status = "eliminated"
                     WHERE tournament_id = ? AND discord_id = ? AND status = "playing"'
                )->execute([$tournamentId, $loser]);
            }
        } elseif (tcgTournamentIsClassicDoubleElim($format)) {
            $side = (string)($m['bracket_side'] ?? 'winners');
            // Drop to losers on WB loss; eliminate only from losers, or decisive GF.
            if ($side === 'losers') {
                tcgDb()->prepare(
                    'UPDATE tcg_tournament_entrants SET status = "eliminated"
                     WHERE tournament_id = ? AND discord_id = ? AND status = "playing"'
                )->execute([$tournamentId, $loser]);
            } elseif ($side === 'grand_final') {
                $gfRound = (int)($m['round'] ?? 1);
                $gfP1 = (string)($m['p1_discord_id'] ?? '');
                // GF1 + LB win → bracket reset; keep WB alive for GF2.
                $needsReset = ($gfRound === 1 && $winnerDiscordId !== $gfP1 && $gfP1 !== '');
                if (!$needsReset) {
                    tcgDb()->prepare(
                        'UPDATE tcg_tournament_entrants SET status = "eliminated"
                         WHERE tournament_id = ? AND discord_id = ? AND status = "playing"'
                    )->execute([$tournamentId, $loser]);
                }
            }
        } else {
            tcgDb()->prepare(
                'UPDATE tcg_tournament_entrants SET status = "eliminated"
                 WHERE tournament_id = ? AND discord_id = ? AND status = "playing"'
            )->execute([$tournamentId, $loser]);
        }
    }

    if (!empty($m['room_id'])) {
        tcgTournamentEnsureApi();
        try {
            $state = loadGame((string)$m['room_id']);
            if (is_array($state) && ($state['status'] ?? '') !== 'finished') {
                $state['status'] = 'finished';
                $state['end_reason'] = $reason;
                $winnerPid = null;
                foreach (['p1', 'p2'] as $pid) {
                    if ((string)(($state['players'][$pid]['discord_id'] ?? '')) === $winnerDiscordId) {
                        $winnerPid = $pid;
                        break;
                    }
                }
                if ($winnerPid) {
                    $state['winner'] = $winnerPid;
                }
                saveGame((string)$m['room_id'], $state);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}

function tcgTournamentEnsureApi(): void {
    if (function_exists('loadGame') && function_exists('saveGame') && function_exists('initGameState')) {
        return;
    }
    if (!defined('TCG_API_LIB_ONLY')) {
        define('TCG_API_LIB_ONLY', true);
    }
    require_once __DIR__ . '/api.php';
}

/**
 * @param array<string,mixed> $matchRow
 * @return array<string,mixed>|null
 */
function tcgTournamentLoadRoomState(array $matchRow): ?array {
    $roomId = (string)($matchRow['room_id'] ?? '');
    if ($roomId === '') {
        return null;
    }
    tcgTournamentEnsureApi();
    try {
        $state = loadGame($roomId);
        if (is_array($state) && ($state['mode'] ?? '') === 'tournament') {
            return $state;
        }
    } catch (Throwable $e) {
        // fall through to VPS
    }
    require_once __DIR__ . '/match_bridge.php';
    $p1Token = (string)($matchRow['p1_token'] ?? '');
    $p2Token = (string)($matchRow['p2_token'] ?? '');
    $token = $p1Token !== '' ? $p1Token : $p2Token;
    if ($token === '') {
        return null;
    }
    $remote = tcgFetchOverflowRoomState($roomId, $token);
    return is_array($remote) ? $remote : null;
}

function tcgTournamentApplyRoomResults(string $tournamentId): void {
    tcgTournamentEnsureApi();
    $matches = tcgTournamentFetchMatches($tournamentId);
    foreach ($matches as $m) {
        if (!in_array((string)$m['status'], ['ready', 'live'], true)) {
            continue;
        }
        $roomId = (string)($m['room_id'] ?? '');
        if ($roomId === '') {
            continue;
        }
        $state = tcgTournamentLoadRoomState($m);
        if (!$state || ($state['status'] ?? '') !== 'finished') {
            continue;
        }
        $winnerPid = (string)($state['winner'] ?? '');
        $winnerDid = '';
        if ($winnerPid !== '' && isset($state['players'][$winnerPid]['discord_id'])) {
            $winnerDid = (string)$state['players'][$winnerPid]['discord_id'];
        }
        if ($winnerDid === '') {
            continue;
        }
        tcgTournamentResolveMatch((string)$m['id'], $winnerDid, (string)($state['end_reason'] ?? 'game'));
    }
}

function tcgTournamentApplyConnectForfeits(string $tournamentId, int $now): void {
    tcgTournamentEnsureApi();
    $matches = tcgTournamentFetchMatches($tournamentId);
    foreach ($matches as $m) {
        if ((string)$m['status'] !== 'ready') {
            continue;
        }
        $deadline = (int)($m['connect_deadline_at'] ?? 0);
        if ($deadline <= 0 || $now < $deadline) {
            continue;
        }
        $roomId = (string)($m['room_id'] ?? '');
        $state = $roomId !== '' ? tcgTournamentLoadRoomState($m) : null;
        $p1Connected = false;
        $p2Connected = false;
        if (is_array($state)) {
            if (intval($state['turn'] ?? 0) >= 1 || ($state['status'] ?? '') === 'finished') {
                continue;
            }
            $p1Connected = !empty($state['players']['p1']['connected'])
                || !empty($state['players']['p1']['last_seen']);
            $p2Connected = !empty($state['players']['p2']['connected'])
                || !empty($state['players']['p2']['last_seen']);
        }
        $p1 = (string)($m['p1_discord_id'] ?? '');
        $p2 = (string)($m['p2_discord_id'] ?? '');
        if ($p1Connected && !$p2Connected && $p1 !== '') {
            tcgTournamentResolveMatch((string)$m['id'], $p1, 'connect_forfeit');
        } elseif ($p2Connected && !$p1Connected && $p2 !== '') {
            tcgTournamentResolveMatch((string)$m['id'], $p2, 'connect_forfeit');
        } elseif ($p1 !== '' && $p2 !== '') {
            tcgTournamentResolveMatch((string)$m['id'], $p1, 'connect_forfeit');
        }
    }
}

function tcgTournamentSeedPendingRooms(string $tournamentId): void {
    $row = tcgTournamentFetch($tournamentId);
    if (!$row) {
        return;
    }
    $matches = tcgTournamentFetchMatches($tournamentId);
    foreach ($matches as $m) {
        if ((string)$m['status'] !== 'pending') {
            continue;
        }
        $p1 = (string)($m['p1_discord_id'] ?? '');
        $p2 = (string)($m['p2_discord_id'] ?? '');
        if ($p1 === '' || $p2 === '') {
            continue;
        }
        try {
            tcgTournamentCreateRoomPair($tournamentId, (string)$m['id'], $p1, $p2, (string)$row['game_mode']);
        } catch (Throwable $e) {
            // retry next tick
        }
    }
}

/**
 * @return array{room_id:string,p1_token:string,p2_token:string}|null
 */
function tcgTournamentCreateRoomPair(
    string $tournamentId,
    string $matchId,
    string $p1DiscordId,
    string $p2DiscordId,
    string $gameMode
): ?array {
    tcgTournamentEnsureApi();
    require_once __DIR__ . '/ranked_room.php';
    require_once __DIR__ . '/sleeves.php';
    require_once __DIR__ . '/playmats.php';

    $gameMode = tcgNormalizeGameMode($gameMode);
    $stmt = tcgDb()->prepare(
        'SELECT discord_id, deck_snapshot FROM tcg_tournament_entrants WHERE tournament_id = ? AND discord_id IN (?, ?)'
    );
    $stmt->execute([$tournamentId, $p1DiscordId, $p2DiscordId]);
    $snaps = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $decoded = json_decode((string)($r['deck_snapshot'] ?? '{}'), true);
        if (!is_array($decoded)) {
            return null;
        }
        $snaps[(string)$r['discord_id']] = $decoded;
    }
    if (!isset($snaps[$p1DiscordId], $snaps[$p2DiscordId])) {
        return null;
    }

    $cards = tcgLoadCardsData();
    $allCards = $cards['cards'] ?? [];
    $deck1 = $snaps[$p1DiscordId];
    $deck2 = $snaps[$p2DiscordId];

    $roomId = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
    $p1Token = generateToken();
    $p2Token = generateToken();

    $main1 = buildDeck($allCards, $deck1['main_nos'] ?? []);
    $energy1 = buildDeck($allCards, $deck1['energy_nos'] ?? []);
    shuffle($main1);
    shuffle($energy1);

    $state = initGameState($roomId, [
        'id' => 'p1',
        'token' => $p1Token,
        'name' => tcgGetUserDisplayName($p1DiscordId),
        'deck_choice' => 'tournament',
        'deck_label' => (string)($deck1['name'] ?? 'Tournament Deck'),
        'main_deck' => $main1,
        'energy_deck' => $energy1,
        'discord_id' => $p1DiscordId,
        'sleeve_id' => tcgNormalizeSleeveId($deck1['sleeve_id'] ?? ''),
        'playmat_id' => tcgNormalizePlaymatId($deck1['playmat_id'] ?? ''),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($deck1['playmat_brightness'] ?? 1.0),
        'deck_snapshot' => [
            'main_nos' => $deck1['main_nos'] ?? [],
            'energy_nos' => $deck1['energy_nos'] ?? [],
        ],
    ]);
    $tRow = tcgTournamentFetch($tournamentId);
    $tSettings = tcgTournamentDecodeSettings($tRow['settings_json'] ?? '{}');
    $fogHidden = (($tSettings['fog'] ?? 'hidden_hands') !== 'open_hands');
    $streamDelay = (int)($tSettings['stream_delay_secs'] ?? 0);

    $state['mode'] = 'tournament';
    $state['game_mode'] = $gameMode;
    $state['tournament'] = [
        'id' => $tournamentId,
        'match_id' => $matchId,
        'p1_discord_id' => $p1DiscordId,
        'p2_discord_id' => $p2DiscordId,
        'stream_delay_secs' => $streamDelay,
        'fog' => $fogHidden ? 'hidden_hands' : 'open_hands',
    ];
    $state['spectate_hidden_hands'] = $fogHidden;
    $state['spectate_stream_delay_secs'] = $streamDelay;

    $main2 = buildDeck($allCards, $deck2['main_nos'] ?? []);
    $energy2 = buildDeck($allCards, $deck2['energy_nos'] ?? []);
    shuffle($main2);
    shuffle($energy2);

    $state = addSecondPlayer($state, [
        'id' => 'p2',
        'token' => $p2Token,
        'name' => tcgGetUserDisplayName($p2DiscordId),
        'deck_choice' => 'tournament',
        'deck_label' => (string)($deck2['name'] ?? 'Tournament Deck'),
        'main_deck' => $main2,
        'energy_deck' => $energy2,
        'discord_id' => $p2DiscordId,
        'sleeve_id' => tcgNormalizeSleeveId($deck2['sleeve_id'] ?? ''),
        'playmat_id' => tcgNormalizePlaymatId($deck2['playmat_id'] ?? ''),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($deck2['playmat_brightness'] ?? 1.0),
        'deck_snapshot' => [
            'main_nos' => $deck2['main_nos'] ?? [],
            'energy_nos' => $deck2['energy_nos'] ?? [],
        ],
    ], null);

    $state['phase_timer_cfg'] = ['enabled' => true, 'duration' => defined('PHASE_TIMER_MAX') ? PHASE_TIMER_MAX : 90];
    $state['tournament']['match_api'] = 'overflow';
    require_once __DIR__ . '/match_bridge.php';
    if (!tcgSeedRankedRoomToVps($state)) {
        return null;
    }

    $deadline = time() + TCG_TOURNAMENT_CONNECT_SECS;
    tcgDb()->prepare(
        'UPDATE tcg_tournament_matches
         SET room_id = ?, p1_token = ?, p2_token = ?, status = "ready", connect_deadline_at = ?, updated_at = ?
         WHERE id = ? AND status = "pending"'
    )->execute([$roomId, $p1Token, $p2Token, $deadline, time(), $matchId]);

    return ['room_id' => $roomId, 'p1_token' => $p1Token, 'p2_token' => $p2Token];
}

function tcgTournamentAdvanceCompletedRounds(string $tournamentId): void {
    $row = tcgTournamentFetch($tournamentId);
    if (!$row) {
        return;
    }
    $settings = tcgTournamentDecodeSettings($row['settings_json'] ?? '{}');
    $format = (string)($settings['format'] ?? 'single_elim');
    $bestOf = (int)($settings['best_of'] ?? 1);

    if ($format === 'swiss' || $format === 'double_elim') {
        tcgTournamentAdvanceSwissOrDouble($tournamentId, $format, $settings, $bestOf);
        if ($format === 'swiss') {
            tcgTournamentAdvanceSwissPlayoff($tournamentId, $bestOf);
        }
        tcgTournamentSeedPendingRooms($tournamentId);
        return;
    }
    if (tcgTournamentIsClassicDoubleElim($format)) {
        tcgTournamentAdvanceClassicDoubleElim($tournamentId, $settings, $bestOf);
        tcgTournamentSeedPendingRooms($tournamentId);
        return;
    }

    $matches = tcgTournamentFetchMatches($tournamentId);
    if (!$matches) {
        return;
    }
    $byRound = [];
    $maxRound = 1;
    foreach ($matches as $m) {
        $r = (int)$m['round'];
        $maxRound = max($maxRound, $r);
        $byRound[$r][] = $m;
    }
    for ($r = 1; $r <= $maxRound; $r++) {
        $roundMatches = $byRound[$r] ?? [];
        if (!$roundMatches) {
            continue;
        }
        $allDone = true;
        foreach ($roundMatches as $m) {
            if ((string)$m['status'] !== 'done' || empty($m['winner_discord_id'])) {
                $allDone = false;
                break;
            }
        }
        if (!$allDone || count($roundMatches) === 1) {
            continue;
        }
        $nextRound = $r + 1;
        $nextExisting = $byRound[$nextRound] ?? [];
        usort($roundMatches, static fn($a, $b) => (int)$a['bracket_slot'] <=> (int)$b['bracket_slot']);
        $winners = [];
        foreach ($roundMatches as $m) {
            $winners[] = (string)$m['winner_discord_id'];
        }
        $needed = (int)(count($winners) / 2);
        if ($needed < 1) {
            continue;
        }
        if (count($nextExisting) === 0) {
            $created = time();
            for ($i = 0; $i < $needed; $i++) {
                $wp1 = $winners[$i * 2] ?? null;
                $wp2 = $winners[$i * 2 + 1] ?? null;
                tcgTournamentInsertMatchRow(
                    $tournamentId,
                    $nextRound,
                    $i,
                    'winners',
                    ['p1' => $wp1, 'p2' => $wp2, 'bye' => null],
                    $bestOf,
                    $created
                );
            }
            $all = tcgTournamentFetchMatches($tournamentId);
            $byRound = [];
            foreach ($all as $mm) {
                $byRound[(int)$mm['round']][] = $mm;
            }
            $maxRound = max($maxRound, $nextRound);
        } else {
            usort($nextExisting, static fn($a, $b) => (int)$a['bracket_slot'] <=> (int)$b['bracket_slot']);
            for ($i = 0; $i < $needed; $i++) {
                $wp1 = $winners[$i * 2] ?? null;
                $wp2 = $winners[$i * 2 + 1] ?? null;
                $nm = $nextExisting[$i] ?? null;
                if (!$nm) {
                    continue;
                }
                if ((string)($nm['status'] ?? '') === 'pending'
                    && (empty($nm['p1_discord_id']) || empty($nm['p2_discord_id']))) {
                    tcgDb()->prepare(
                        'UPDATE tcg_tournament_matches SET p1_discord_id = ?, p2_discord_id = ?, updated_at = ? WHERE id = ?'
                    )->execute([$wp1, $wp2, time(), $nm['id']]);
                }
            }
        }
    }
    tcgTournamentSeedPendingRooms($tournamentId);
}

/**
 * Find match by side/round/slot or null.
 * @param list<array<string,mixed>> $matches
 * @return array<string,mixed>|null
 */
function tcgTournamentFindMatchSlot(array $matches, string $side, int $round, int $slot): ?array {
    foreach ($matches as $m) {
        if ((string)($m['bracket_side'] ?? '') === $side
            && (int)($m['round'] ?? 0) === $round
            && (int)($m['bracket_slot'] ?? -1) === $slot) {
            return $m;
        }
    }
    return null;
}

/**
 * Ensure a pending match exists at side/round/slot; place discordId in seat 0 (p1) or 1 (p2).
 *
 * @param list<array<string,mixed>> $matches refreshed list (mutated via re-fetch after insert)
 */
function tcgTournamentPlacePlayerInSlot(
    string $tournamentId,
    string $side,
    int $round,
    int $slot,
    int $seat,
    string $discordId,
    int $bestOf,
    array &$matches
): void {
    $discordId = (string)$discordId;
    if ($discordId === '') {
        return;
    }
    $m = tcgTournamentFindMatchSlot($matches, $side, $round, $slot);
    if (!$m) {
        $created = time();
        tcgTournamentInsertMatchRow(
            $tournamentId,
            $round,
            $slot,
            $side,
            ['p1' => null, 'p2' => null, 'bye' => null],
            $bestOf,
            $created
        );
        // Patch empty insert: InsertMatchRow with nulls still inserts pending with nulls — but our insert requires p1 for bye path. Check insert for null p1/p2.
        $matches = tcgTournamentFetchMatches($tournamentId);
        $m = tcgTournamentFindMatchSlot($matches, $side, $round, $slot);
    }
    if (!$m) {
        return;
    }
    if ((string)($m['status'] ?? '') === 'done') {
        return;
    }
    $p1 = (string)($m['p1_discord_id'] ?? '');
    $p2 = (string)($m['p2_discord_id'] ?? '');
    if ($p1 === $discordId || $p2 === $discordId) {
        return; // already seated
    }
    $col = ($seat === 1) ? 'p2_discord_id' : 'p1_discord_id';
    $cur = ($seat === 1) ? $p2 : $p1;
    if ($cur !== '') {
        // Seat taken by someone else — try other seat if empty
        $other = ($seat === 1) ? 'p1_discord_id' : 'p2_discord_id';
        $otherCur = ($seat === 1) ? $p1 : $p2;
        if ($otherCur !== '') {
            return;
        }
        $col = $other;
    }
    tcgDb()->prepare(
        "UPDATE tcg_tournament_matches SET {$col} = ?, updated_at = ? WHERE id = ?"
    )->execute([$discordId, time(), $m['id']]);
    $matches = tcgTournamentFetchMatches($tournamentId);
}

/**
 * Classic winners/losers double-elim advance (idempotent seat fill + GF reset).
 *
 * @param array<string,mixed> $settings
 */
function tcgTournamentAdvanceClassicDoubleElim(string $tournamentId, array $settings, int $bestOf): void {
    $matches = tcgTournamentFetchMatches($tournamentId);
    if (!$matches) {
        return;
    }
    $bracketSize = (int)($settings['bracket_size'] ?? 0);
    if ($bracketSize < 2) {
        $w1 = 0;
        foreach ($matches as $m) {
            if ((string)($m['bracket_side'] ?? '') === 'winners' && (int)$m['round'] === 1) {
                $w1++;
            }
        }
        $bracketSize = max(2, $w1 * 2);
    }

    foreach ($matches as $m) {
        if ((string)($m['status'] ?? '') !== 'done') {
            continue;
        }
        $winner = (string)($m['winner_discord_id'] ?? '');
        if ($winner === '') {
            continue;
        }
        $side = (string)($m['bracket_side'] ?? 'winners');
        $round = (int)($m['round'] ?? 1);
        $slot = (int)($m['bracket_slot'] ?? 0);
        $p1 = (string)($m['p1_discord_id'] ?? '');
        $p2 = (string)($m['p2_discord_id'] ?? '');
        $loser = ($winner === $p1) ? $p2 : (($winner === $p2) ? $p1 : '');

        if ($side === 'grand_final') {
            // GF1: if LB (p2) wins → create reset GF2; if WB (p1) wins → done.
            if ($round === 1 && $loser !== '') {
                $wbWon = ($winner === $p1);
                if (!$wbWon) {
                    $gf2 = tcgTournamentFindMatchSlot($matches, 'grand_final', 2, 0);
                    if (!$gf2) {
                        $created = time();
                        tcgTournamentInsertMatchRow(
                            $tournamentId,
                            2,
                            0,
                            'grand_final',
                            ['p1' => $p1, 'p2' => $p2, 'bye' => null],
                            $bestOf,
                            $created
                        );
                        $matches = tcgTournamentFetchMatches($tournamentId);
                    } else {
                        // Ensure seats filled
                        tcgTournamentPlacePlayerInSlot($tournamentId, 'grand_final', 2, 0, 0, $p1, $bestOf, $matches);
                        tcgTournamentPlacePlayerInSlot($tournamentId, 'grand_final', 2, 0, 1, $p2, $bestOf, $matches);
                    }
                }
            }
            continue;
        }

        $wDest = tcgTournamentClassicDeWinnerDest($bracketSize, $side, $round, $slot);
        if ($wDest) {
            tcgTournamentPlacePlayerInSlot(
                $tournamentId,
                $wDest['side'],
                $wDest['round'],
                $wDest['slot'],
                $wDest['seat'],
                $winner,
                $bestOf,
                $matches
            );
        }

        if ($side === 'winners' && $loser !== '') {
            $drop = tcgTournamentClassicDeLoserDrop($bracketSize, $round, $slot);
            if ($drop) {
                tcgTournamentPlacePlayerInSlot(
                    $tournamentId,
                    $drop['side'],
                    $drop['round'],
                    $drop['slot'],
                    $drop['seat'],
                    $loser,
                    $bestOf,
                    $matches
                );
            }
        }
    }
}

/**
 * Swiss: fixed round count, then single-elim playoff (top 2 or top 4).
 * Double elim (2 lives): re-pair while 2+ players have &lt;2 losses.
 *
 * @param array<string,mixed> $settings
 */
function tcgTournamentAdvanceSwissOrDouble(
    string $tournamentId,
    string $format,
    array $settings,
    int $bestOf
): void {
    $matches = tcgTournamentFetchMatches($tournamentId);
    if (!$matches) {
        return;
    }

    if ($format === 'swiss') {
        // Only Swiss-side rounds drive Swiss re-pair / playoff seed.
        $swissMatches = array_values(array_filter(
            $matches,
            static fn($m) => (string)($m['bracket_side'] ?? 'swiss') === 'swiss'
        ));
        if (!$swissMatches) {
            return;
        }
        $maxRound = 0;
        foreach ($swissMatches as $m) {
            $maxRound = max($maxRound, (int)$m['round']);
        }
        $roundMatches = array_values(array_filter(
            $swissMatches,
            static fn($m) => (int)$m['round'] === $maxRound
        ));
        if (!$roundMatches) {
            return;
        }
        foreach ($roundMatches as $m) {
            if ((string)$m['status'] !== 'done' || empty($m['winner_discord_id'])) {
                return;
            }
        }
        $nextExisting = array_values(array_filter(
            $swissMatches,
            static fn($m) => (int)$m['round'] === ($maxRound + 1)
        ));
        if ($nextExisting) {
            return;
        }

        $entrants = tcgTournamentFetchEntrants($tournamentId);
        $records = tcgTournamentRecordsFromMatches($swissMatches, 'swiss');
        $active = [];
        foreach ($entrants as $e) {
            if ((string)$e['status'] === 'playing') {
                $active[] = (string)$e['discord_id'];
            }
        }
        $showedUp = (int)($settings['showed_up'] ?? count($active));
        $target = (int)($settings['swiss_rounds'] ?? tcgTournamentSwissRoundCount(max(2, $showedUp)));
        if ($maxRound >= $target) {
            tcgTournamentSeedSwissPlayoff($tournamentId, $settings, $bestOf, $swissMatches, $entrants);
            return;
        }

        $pairings = tcgTournamentBuildSwissPairings(
            $active,
            $records,
            tcgTournamentPriorPairsFromMatches($swissMatches)
        );
        $created = time();
        $nextRound = $maxRound + 1;
        foreach ($pairings as $slot => $pair) {
            tcgTournamentInsertMatchRow($tournamentId, $nextRound, (int)$slot, 'swiss', $pair, $bestOf, $created);
        }
        return;
    }

    // double_elim (2 lives) — original path
    $maxRound = 0;
    foreach ($matches as $m) {
        $maxRound = max($maxRound, (int)$m['round']);
    }
    $roundMatches = array_values(array_filter(
        $matches,
        static fn($m) => (int)$m['round'] === $maxRound
    ));
    if (!$roundMatches) {
        return;
    }
    foreach ($roundMatches as $m) {
        if ((string)$m['status'] !== 'done' || empty($m['winner_discord_id'])) {
            return;
        }
    }

    $nextExisting = array_values(array_filter(
        $matches,
        static fn($m) => (int)$m['round'] === ($maxRound + 1)
    ));
    if ($nextExisting) {
        return;
    }

    $entrants = tcgTournamentFetchEntrants($tournamentId);
    $records = tcgTournamentRecordsFromMatches($matches);
    $active = [];
    foreach ($entrants as $e) {
        if ((string)$e['status'] !== 'playing') {
            continue;
        }
        $pid = (string)$e['discord_id'];
        if ((int)($records[$pid]['losses'] ?? 0) >= 2) {
            continue;
        }
        $active[] = $pid;
    }
    if (count($active) <= 1) {
        return;
    }

    $pairings = tcgTournamentBuildSwissPairings(
        $active,
        $records,
        // Lives DE uses bracket_side=winners for every round — include those pairs.
        tcgTournamentPriorPairsFromMatches($matches, true)
    );
    $created = time();
    $nextRound = $maxRound + 1;
    foreach ($pairings as $slot => $pair) {
        tcgTournamentInsertMatchRow($tournamentId, $nextRound, (int)$slot, 'winners', $pair, $bestOf, $created);
    }
}

/**
 * After Swiss rounds: seed top-2 final or top-4 semis (1v4, 2v3).
 *
 * @param array<string,mixed> $settings
 * @param list<array<string,mixed>> $swissMatches
 * @param list<array<string,mixed>> $entrants
 */
function tcgTournamentSeedSwissPlayoff(
    string $tournamentId,
    array $settings,
    int $bestOf,
    array $swissMatches,
    array $entrants
): void {
    $all = tcgTournamentFetchMatches($tournamentId);
    foreach ($all as $m) {
        if ((string)($m['bracket_side'] ?? '') === 'winners') {
            return; // already seeded
        }
    }

    $records = tcgTournamentRecordsFromMatches($swissMatches, 'swiss');
    $pool = [];
    foreach ($entrants as $e) {
        $st = (string)($e['status'] ?? '');
        if (in_array($st, ['playing', 'eliminated'], true)) {
            // Prefer currently playing; include anyone who competed in Swiss.
            $pool[] = (string)$e['discord_id'];
        }
    }
    // Prefer players still marked playing for the cut.
    $playing = [];
    foreach ($entrants as $e) {
        if ((string)$e['status'] === 'playing') {
            $playing[] = (string)$e['discord_id'];
        }
    }
    if (count($playing) >= 2) {
        $pool = $playing;
    }
    $ranked = tcgTournamentSortBySwissStanding($pool, $records, $swissMatches, 'swiss');
    if (count($ranked) < 2) {
        return;
    }

    $showedUp = (int)($settings['showed_up'] ?? count($ranked));
    $cut = tcgTournamentSwissPlayoffSize($showedUp);
    $cut = min($cut, count($ranked));
    if ($cut < 2) {
        return;
    }
    // If we planned top 4 but only 2–3 remain, fall back to top 2 final.
    if ($cut === 4 && count($ranked) < 4) {
        $cut = 2;
    }

    $seeds = array_slice($ranked, 0, $cut);
    $cutSet = array_fill_keys($seeds, true);
    tcgTournamentEnsureEntrantElimReasonColumn();
    foreach ($entrants as $e) {
        $pid = (string)$e['discord_id'];
        if ((string)$e['status'] === 'playing' && !isset($cutSet[$pid])) {
            $reason = 'swiss_cut';
            $pw = (int)($records[$pid]['wins'] ?? 0);
            $pl = (int)($records[$pid]['losses'] ?? 0);
            foreach ($seeds as $sid) {
                if ((int)($records[$sid]['wins'] ?? 0) === $pw
                    && (int)($records[$sid]['losses'] ?? 0) === $pl) {
                    $reason = 'swiss_omw';
                    break;
                }
            }
            tcgDb()->prepare(
                'UPDATE tcg_tournament_entrants SET status = "eliminated", elim_reason = ?
                 WHERE tournament_id = ? AND discord_id = ? AND status = "playing"'
            )->execute([$reason, $tournamentId, $pid]);
        }
    }

    $created = time();
    if ($cut === 2) {
        tcgTournamentInsertMatchRow(
            $tournamentId,
            1,
            0,
            'winners',
            ['p1' => $seeds[0], 'p2' => $seeds[1], 'bye' => null],
            $bestOf,
            $created
        );
    } else {
        // Semis: 1v4 and 2v3
        tcgTournamentInsertMatchRow(
            $tournamentId,
            1,
            0,
            'winners',
            ['p1' => $seeds[0], 'p2' => $seeds[3], 'bye' => null],
            $bestOf,
            $created
        );
        tcgTournamentInsertMatchRow(
            $tournamentId,
            1,
            1,
            'winners',
            ['p1' => $seeds[1], 'p2' => $seeds[2], 'bye' => null],
            $bestOf,
            $created
        );
    }

    $settings['swiss_phase'] = 'playoff';
    $settings['playoff_size'] = $cut;
    tcgDb()->prepare('UPDATE tcg_tournaments SET settings_json = ?, updated_at = ? WHERE id = ?')
        ->execute([tcgTournamentEncodeSettings($settings), time(), $tournamentId]);
}

/** Advance Swiss playoff winners-bracket (semis → final). */
function tcgTournamentAdvanceSwissPlayoff(string $tournamentId, int $bestOf): void {
    $matches = tcgTournamentFetchMatches($tournamentId);
    $playoff = array_values(array_filter(
        $matches,
        static fn($m) => (string)($m['bracket_side'] ?? '') === 'winners'
    ));
    if (count($playoff) < 2) {
        // Top-2 final is a single match — nothing to advance.
        return;
    }
    $byRound = [];
    $maxRound = 1;
    foreach ($playoff as $m) {
        $r = (int)$m['round'];
        $maxRound = max($maxRound, $r);
        $byRound[$r][] = $m;
    }
    for ($r = 1; $r <= $maxRound; $r++) {
        $roundMatches = $byRound[$r] ?? [];
        if (!$roundMatches) {
            continue;
        }
        $allDone = true;
        foreach ($roundMatches as $m) {
            if ((string)$m['status'] !== 'done' || empty($m['winner_discord_id'])) {
                $allDone = false;
                break;
            }
        }
        if (!$allDone || count($roundMatches) === 1) {
            continue;
        }
        $nextRound = $r + 1;
        $nextExisting = $byRound[$nextRound] ?? [];
        usort($roundMatches, static fn($a, $b) => (int)$a['bracket_slot'] <=> (int)$b['bracket_slot']);
        $winners = [];
        foreach ($roundMatches as $m) {
            $winners[] = (string)$m['winner_discord_id'];
        }
        $needed = (int)(count($winners) / 2);
        if ($needed < 1) {
            continue;
        }
        if (count($nextExisting) === 0) {
            $created = time();
            for ($i = 0; $i < $needed; $i++) {
                $wp1 = $winners[$i * 2] ?? null;
                $wp2 = $winners[$i * 2 + 1] ?? null;
                tcgTournamentInsertMatchRow(
                    $tournamentId,
                    $nextRound,
                    $i,
                    'winners',
                    ['p1' => $wp1, 'p2' => $wp2, 'bye' => null],
                    $bestOf,
                    $created
                );
            }
            $all = tcgTournamentFetchMatches($tournamentId);
            $byRound = [];
            $maxRound = $nextRound;
            foreach ($all as $mm) {
                if ((string)($mm['bracket_side'] ?? '') !== 'winners') {
                    continue;
                }
                $rr = (int)$mm['round'];
                $byRound[$rr][] = $mm;
                $maxRound = max($maxRound, $rr);
            }
        } else {
            usort($nextExisting, static fn($a, $b) => (int)$a['bracket_slot'] <=> (int)$b['bracket_slot']);
            for ($i = 0; $i < $needed; $i++) {
                $wp1 = $winners[$i * 2] ?? null;
                $wp2 = $winners[$i * 2 + 1] ?? null;
                $nm = $nextExisting[$i] ?? null;
                if (!$nm) {
                    continue;
                }
                if ((string)($nm['status'] ?? '') === 'pending'
                    && (empty($nm['p1_discord_id']) || empty($nm['p2_discord_id']))) {
                    tcgDb()->prepare(
                        'UPDATE tcg_tournament_matches SET p1_discord_id = ?, p2_discord_id = ?, updated_at = ? WHERE id = ?'
                    )->execute([$wp1, $wp2, time(), $nm['id']]);
                }
            }
        }
    }
}

function tcgTournamentTryFinish(string $tournamentId): bool {
    $row = tcgTournamentFetch($tournamentId);
    if (!$row || (string)$row['status'] !== 'running') {
        return false;
    }
    $settings = tcgTournamentDecodeSettings($row['settings_json'] ?? '{}');
    $format = (string)($settings['format'] ?? 'single_elim');
    $matches = tcgTournamentFetchMatches($tournamentId);
    if (!$matches) {
        return false;
    }

    $places = [];
    if ($format === 'swiss') {
        $playoff = array_values(array_filter(
            $matches,
            static fn($m) => (string)($m['bracket_side'] ?? '') === 'winners'
        ));
        if (!$playoff) {
            return false; // Swiss not cut yet
        }
        $maxPr = 0;
        foreach ($playoff as $m) {
            $maxPr = max($maxPr, (int)$m['round']);
        }
        $finals = array_values(array_filter(
            $playoff,
            static fn($m) => (int)$m['round'] === $maxPr
        ));
        if (count($finals) !== 1) {
            return false;
        }
        $final = $finals[0];
        if ((string)$final['status'] !== 'done' || empty($final['winner_discord_id'])) {
            return false;
        }
        $winner = (string)$final['winner_discord_id'];
        $p1 = (string)($final['p1_discord_id'] ?? '');
        $p2 = (string)($final['p2_discord_id'] ?? '');
        $runner = ($winner === $p1) ? $p2 : (($winner === $p2) ? $p1 : '');
        if ($winner !== '') {
            $places[] = $winner;
        }
        if ($runner !== '') {
            $places[] = $runner;
        }
        // 3rd: best Swiss record among playoff semi losers (top-4 cut).
        if ((int)($settings['playoff_size'] ?? 0) >= 4) {
            $swissRec = tcgTournamentRecordsFromMatches($matches, 'swiss');
            $semiLosers = [];
            foreach ($playoff as $m) {
                if ((int)$m['round'] !== 1 || (string)$m['status'] !== 'done') {
                    continue;
                }
                $w = (string)($m['winner_discord_id'] ?? '');
                $a = (string)($m['p1_discord_id'] ?? '');
                $b = (string)($m['p2_discord_id'] ?? '');
                $l = ($w === $a) ? $b : (($w === $b) ? $a : '');
                if ($l !== '' && !in_array($l, $places, true)) {
                    $semiLosers[] = $l;
                }
            }
            $semiLosers = tcgTournamentSortBySwissStanding($semiLosers, $swissRec, $matches, 'swiss');
            if (!empty($semiLosers)) {
                $places[] = $semiLosers[0];
            }
        }
    } elseif ($format === 'double_elim') {
        foreach ($matches as $m) {
            if ((string)$m['status'] !== 'done') {
                return false;
            }
        }
        $records = tcgTournamentRecordsFromMatches($matches);
        $alive = [];
        foreach ($records as $pid => $rec) {
            if ((int)($rec['losses'] ?? 0) < 2) {
                $alive[] = $pid;
            }
        }
        // Also include playing entrants with no matches yet — shouldn't happen at end.
        if (count($alive) !== 1) {
            return false;
        }
        $winner = $alive[0];
        $places = [$winner];
        $sorted = array_keys($records);
        usort($sorted, static function ($a, $b) use ($records) {
            $la = (int)($records[$a]['losses'] ?? 0);
            $lb = (int)($records[$b]['losses'] ?? 0);
            if ($la !== $lb) {
                return $la <=> $lb;
            }
            return ((int)($records[$b]['wins'] ?? 0)) <=> ((int)($records[$a]['wins'] ?? 0));
        });
        foreach ($sorted as $pid) {
            if ($pid === $winner) {
                continue;
            }
            $places[] = $pid;
            if (count($places) >= 3) {
                break;
            }
        }
    } elseif (tcgTournamentIsClassicDoubleElim($format)) {
        $gf1 = null;
        $gf2 = null;
        foreach ($matches as $m) {
            if ((string)($m['bracket_side'] ?? '') !== 'grand_final') {
                continue;
            }
            if ((int)$m['round'] === 1) {
                $gf1 = $m;
            }
            if ((int)$m['round'] === 2) {
                $gf2 = $m;
            }
        }
        if (!$gf1 || (string)($gf1['status'] ?? '') !== 'done' || empty($gf1['winner_discord_id'])) {
            return false;
        }
        $gf1Winner = (string)$gf1['winner_discord_id'];
        $gf1P1 = (string)($gf1['p1_discord_id'] ?? '');
        // WB champ is p1 — if they win GF1, no reset required.
        if ($gf1Winner === $gf1P1) {
            $final = $gf1;
        } else {
            // LB won GF1 — need reset GF2 complete.
            if (!$gf2 || (string)($gf2['status'] ?? '') !== 'done' || empty($gf2['winner_discord_id'])) {
                return false;
            }
            $final = $gf2;
        }
        $winner = (string)$final['winner_discord_id'];
        $fp1 = (string)($final['p1_discord_id'] ?? '');
        $fp2 = (string)($final['p2_discord_id'] ?? '');
        $runner = ($winner === $fp1) ? $fp2 : (($winner === $fp2) ? $fp1 : '');
        $places = [];
        if ($winner !== '') {
            $places[] = $winner;
        }
        if ($runner !== '') {
            $places[] = $runner;
        }
        // 3rd: last losers-final loser if present
        $lf = null;
        $maxLr = 0;
        foreach ($matches as $m) {
            if ((string)($m['bracket_side'] ?? '') !== 'losers') {
                continue;
            }
            $maxLr = max($maxLr, (int)$m['round']);
        }
        foreach ($matches as $m) {
            if ((string)($m['bracket_side'] ?? '') === 'losers'
                && (int)$m['round'] === $maxLr
                && (string)($m['status'] ?? '') === 'done') {
                $lf = $m;
                break;
            }
        }
        if ($lf) {
            $lw = (string)($lf['winner_discord_id'] ?? '');
            $lp1 = (string)($lf['p1_discord_id'] ?? '');
            $lp2 = (string)($lf['p2_discord_id'] ?? '');
            $third = ($lw === $lp1) ? $lp2 : (($lw === $lp2) ? $lp1 : '');
            if ($third !== '' && !in_array($third, $places, true)) {
                $places[] = $third;
            }
        }
    } else {
        $maxRound = 0;
        foreach ($matches as $m) {
            $maxRound = max($maxRound, (int)$m['round']);
        }
        $finals = array_values(array_filter($matches, static fn($m) => (int)$m['round'] === $maxRound));
        if (count($finals) !== 1) {
            return false;
        }
        $final = $finals[0];
        if ((string)$final['status'] !== 'done' || empty($final['winner_discord_id'])) {
            return false;
        }
        $winner = (string)$final['winner_discord_id'];
        $p1 = (string)($final['p1_discord_id'] ?? '');
        $p2 = (string)($final['p2_discord_id'] ?? '');
        $runner = ($winner === $p1) ? $p2 : (($winner === $p2) ? $p1 : '');
        if ($winner !== '') {
            $places[] = $winner;
        }
        if ($runner !== '') {
            $places[] = $runner;
        }
    }

    return tcgTournamentPayoutAndFinish($tournamentId, $row, $places);
}

/**
 * Coin pool payout + optional host-funded PR pack for the champion.
 *
 * @param array<string,mixed> $row
 * @param list<string> $places discord ids 1st..nth
 */
function tcgTournamentPayoutAndFinish(string $tournamentId, array $row, array $places): bool {
    $places = array_values(array_filter(array_map('strval', $places), static fn($id) => $id !== ''));
    if (!$places) {
        return false;
    }
    tcgTournamentEnsureResultsColumn();
    $pool = (int)$row['prize_pool_coins'];
    $winner = $places[0];
    $percents = tcgTournamentPrizePercents(count($places));
    $settings = tcgTournamentDecodeSettings($row['settings_json'] ?? '{}');
    $prEscrowed = !empty($settings['pr_pack'])
        && (string)($settings['pr_pack_status'] ?? '') === 'escrowed';
    $prMeta = null;
    if ($prEscrowed) {
        $prMeta = ['awarded' => true, 'dropped' => false, 'pack_size' => TCG_TOURNAMENT_PR_PACK_SIZE];
        $settings['pr_pack_status'] = 'awarded';
    } elseif (!empty($settings['pr_pack']) && (string)($settings['pr_pack_status'] ?? '') === 'dropped') {
        $prMeta = ['awarded' => false, 'dropped' => true, 'pack_size' => TCG_TOURNAMENT_PR_PACK_SIZE];
    }

    $db = tcgDb();
    $db->beginTransaction();
    try {
        $paidOut = 0;
        $placeRows = [];
        foreach ($places as $i => $did) {
            $pct = $percents[$i] ?? 0;
            $amount = (int)floor($pool * $pct / 100);
            if ($i === count($places) - 1) {
                $amount = $pool - $paidOut;
            }
            if ($amount < 0) {
                $amount = 0;
            }
            $placeRows[] = [
                'discord_id' => $did,
                'place' => $i + 1,
                'coins' => $amount,
            ];
            if ($amount <= 0) {
                continue;
            }
            $key = 'payout:' . $tournamentId . ':place' . ($i + 1) . ':' . $did;
            if (tcgTournamentLedgerWrite($tournamentId, $did, 'payout', $amount, $key, ['place' => $i + 1])) {
                tcgAddCoins($did, $amount);
                $paidOut += $amount;
            }
        }
        $finishedAt = time();
        $resultsPayload = tcgTournamentBuildResults($placeRows, $pool, $finishedAt, $prMeta);
        $resultsJson = json_encode($resultsPayload, JSON_UNESCAPED_UNICODE) ?: '{}';
        $db->prepare(
            'UPDATE tcg_tournaments
             SET status = "finished", prize_pool_coins = 0, results_json = ?, settings_json = ?, updated_at = ?
             WHERE id = ?'
        )->execute([$resultsJson, tcgTournamentEncodeSettings($settings), $finishedAt, $tournamentId]);
        $db->prepare(
            'UPDATE tcg_tournament_entrants SET status = "eliminated"
             WHERE tournament_id = ? AND status = "playing" AND discord_id != ?'
        )->execute([$tournamentId, $winner]);
        $db->prepare(
            'UPDATE tcg_tournament_entrants SET status = "winner"
             WHERE tournament_id = ? AND discord_id = ? AND status IN ("playing","eliminated")'
        )->execute([$tournamentId, $winner]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    if ($prEscrowed) {
        try {
            if (!function_exists('tcgGrantPrPackCards')) {
                require_once __DIR__ . '/ranked_pr_rewards.php';
            }
            $pack = tcgGrantPrPackCards($winner, TCG_TOURNAMENT_PR_PACK_SIZE);
            $cardNos = [];
            foreach (($pack['cards'] ?? []) as $c) {
                if (is_array($c) && !empty($c['card_no'])) {
                    $cardNos[] = (string)$c['card_no'];
                }
            }
            tcgTournamentLedgerWrite(
                $tournamentId,
                $winner,
                'pr_pack_payout',
                0,
                'pr_pack_payout:' . $tournamentId . ':' . $winner,
                [
                    'pack_size' => (int)($pack['pack_size'] ?? TCG_TOURNAMENT_PR_PACK_SIZE),
                    'card_nos' => $cardNos,
                    'star_gems_earned' => (int)($pack['star_gems_earned'] ?? 0),
                    'cards' => $pack['cards'] ?? [],
                ]
            );
        } catch (Throwable $e) {
            error_log('tournament PR pack grant failed ' . $tournamentId . ': ' . $e->getMessage());
        }
    }
    return true;
}

/** @param array<string,mixed> $state */
function tcgOnTournamentGameFinished(array &$state): void {
    $meta = is_array($state['tournament'] ?? null) ? $state['tournament'] : [];
    $tid = strtoupper(trim((string)($meta['id'] ?? '')));
    if ($tid === '' || !tcgTournamentsEnabled()) {
        return;
    }
    $remoteApply = (($meta['match_api'] ?? '') === 'overflow')
        || (function_exists('tcgMissionShouldWriteOnHostinger') && tcgMissionShouldWriteOnHostinger());
    if ($remoteApply) {
        require_once __DIR__ . '/match_bridge.php';
        if (!tcgPostTournamentApplyResultToHostinger($state)) {
            return;
        }
        return;
    }
    try {
        tcgTournamentApplyRoomResults($tid);
        tcgTournamentAdvanceCompletedRounds($tid);
        tcgTournamentTryFinish($tid);
    } catch (Throwable $e) {
        // tick retries
    }
}
