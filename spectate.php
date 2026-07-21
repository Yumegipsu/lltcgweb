<?php
/**
 * Live match spectating for ranked and unranked human PvP.
 * Spectators receive read-only filtered state via get_state (spec_* tokens).
 */

const TCG_SPECTATOR_IDLE_SEC = 120;
const TCG_SPECTATOR_MAX_PER_ROOM = 32;

/** In-progress matches use status "setup", not "playing" (matches client isActiveGameplay). */
function tcgIsActiveGameplayStatus(array $state): bool {
    $st = $state['status'] ?? '';
    return $st !== '' && !in_array($st, ['waiting', 'ready', 'finished'], true);
}

function tcgIsSpectatorToken(string $token): bool {
    return str_starts_with($token, 'spec_');
}

function tcgSpectatorsFilePath(string $roomId): string {
    $safe = preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId));
    return GAMES_DIR . 'spectators_' . $safe . '.json';
}

function tcgReadSpectators(string $roomId): array {
    $file = tcgSpectatorsFilePath($roomId);
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function tcgWriteSpectators(string $roomId, array $spectators): void {
    $file = tcgSpectatorsFilePath($roomId);
    if (!$spectators) {
        if (is_file($file)) {
            @unlink($file);
        }
        return;
    }
    file_put_contents($file, json_encode($spectators), LOCK_EX);
}

function tcgPurgeStaleSpectators(string $roomId, ?int $now = null): array {
    $now = $now ?? time();
    $spectators = tcgReadSpectators($roomId);
    $changed = false;
    foreach ($spectators as $token => $meta) {
        $last = intval(is_array($meta) ? ($meta['last_seen'] ?? $meta['joined_at'] ?? 0) : 0);
        if ($last <= 0 || ($now - $last) >= TCG_SPECTATOR_IDLE_SEC) {
            unset($spectators[$token]);
            $changed = true;
        }
    }
    if ($changed) {
        tcgWriteSpectators($roomId, $spectators);
    }
    return $spectators;
}

function tcgSpectatorTokenValid(string $roomId, string $token): bool {
    if (!tcgIsSpectatorToken($token)) {
        return false;
    }
    $spectators = tcgPurgeStaleSpectators($roomId);
    return isset($spectators[$token]);
}

function tcgLiveSpectatorCount(string $roomId): int {
    return count(tcgPurgeStaleSpectators($roomId));
}

function tcgTouchSpectatorPresence(string $roomId, string $token): void {
    if (!tcgIsSpectatorToken($token)) {
        return;
    }
    $spectators = tcgPurgeStaleSpectators($roomId);
    if (!isset($spectators[$token])) {
        return;
    }
    $spectators[$token]['last_seen'] = time();
    tcgWriteSpectators($roomId, $spectators);
}

/** Human PvP with at least one player still connected (presence / recent game activity). */
function tcgPvpLivePlayerCount(array $state, string $roomId, ?int $now = null): int {
    if (!tcgIsActiveGameplayStatus($state)) {
        return 0;
    }
    if (($state['mode'] ?? '') === 'replay_view' || !isPvpMatch($state)) {
        return 0;
    }
    if (!function_exists('readPresence')) {
        if (!defined('TCG_API_LIB_ONLY')) {
            define('TCG_API_LIB_ONLY', true);
        }
        require_once __DIR__ . '/api.php';
    }
    $now = $now ?? time();
    $presence = readPresence($roomId);
    $path = gameFile($roomId);
    $gameAge = is_file($path) ? ($now - filemtime($path)) : 0;
    $grace = defined('PRESENCE_DISCONNECT_SEC') ? PRESENCE_DISCONNECT_SEC : 120;
    $noShowSec = (defined('PRESENCE_NO_SHOW_SEC') ? PRESENCE_NO_SHOW_SEC : 300) * 2;

    $live = 0;
    $anyPresenceEver = false;
    foreach (['p1', 'p2'] as $pid) {
        $player = $state['players'][$pid] ?? null;
        if (!$player || isCpuPlayer($player)) {
            continue;
        }
        $token = (string)($player['token'] ?? '');
        if ($token === '') {
            continue;
        }
        $last = intval($presence[$token] ?? 0);
        if ($last > 0) {
            $anyPresenceEver = true;
            if (($now - $last) < $grace) {
                $live++;
            }
        }
    }

    if ($live === 0 && $gameAge < $grace) {
        foreach (['p1', 'p2'] as $pid) {
            $player = $state['players'][$pid] ?? null;
            if ($player && !isCpuPlayer($player)) {
                $live++;
            }
        }
        return $live;
    }

    // Long turns between saves: ranked rows stay pending while the file is still fresh.
    if ($live === 0 && ($state['mode'] ?? '') === 'ranked' && $gameAge < 45 * 60) {
        foreach (['p1', 'p2'] as $pid) {
            $player = $state['players'][$pid] ?? null;
            if ($player && !isCpuPlayer($player)) {
                $live++;
            }
        }
        return $live;
    }

    if ($live === 0 && $gameAge < $noShowSec) {
        foreach (['p1', 'p2'] as $pid) {
            $player = $state['players'][$pid] ?? null;
            if (!$player || isCpuPlayer($player)) {
                continue;
            }
            $token = (string)($player['token'] ?? '');
            $last = intval($presence[$token] ?? 0);
            if ($last > 0 && ($now - $last) < 60) {
                $live++;
            }
        }
    }

    return $live;
}

function tcgIsSpectatableHumanGame(array $state, string $roomId = ''): bool {
    if (!tcgIsActiveGameplayStatus($state)) {
        return false;
    }
    if (($state['mode'] ?? '') === 'replay_view') {
        return false;
    }
    if (empty($state['players']['p2'])) {
        return false;
    }
    $p1 = $state['players']['p1'] ?? null;
    $p2 = $state['players']['p2'] ?? null;
    if (!$p1 || !$p2 || isCpuPlayer($p1) || isCpuPlayer($p2)) {
        return false;
    }
    // Ranked rows are DB-backed; queue stats already treat pending + non-finished as in-game.
    if (($state['mode'] ?? '') === 'ranked' && $roomId !== '') {
        return true;
    }
    if ($roomId !== '' && tcgPvpLivePlayerCount($state, $roomId) < 1) {
        return false;
    }
    return true;
}

function tcgSpectatableMatchRow(string $roomId, array $state, string $category): array {
    return [
        'room_id' => $roomId,
        'category' => $category,
        'p1_name' => (string)($state['players']['p1']['name'] ?? 'Player 1'),
        'p2_name' => (string)($state['players']['p2']['name'] ?? 'Player 2'),
        'turn' => intval($state['turn'] ?? 0),
        'phase' => (string)($state['phase'] ?? ''),
        'spectators' => tcgLiveSpectatorCount($roomId),
    ];
}

function tcgListRankedSpectatableMatches(): array {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/matchmaking.php';

    $matches = [];
    $db = tcgDb();
    $stmt = $db->query('SELECT room_id, created_at, p1_id, p2_id, p1_token, p2_token FROM tcg_ranked_matches WHERE status = "pending"');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row = tcgSanitizeRankedMatchRow($row);
        if (!$row) {
            continue;
        }
        $roomId = (string)($row['room_id'] ?? '');
        if ($roomId === '') {
            continue;
        }
        $path = tcgRankedGameFilePath($roomId);
        if (!is_file($path)) {
            continue;
        }
        $state = json_decode((string)file_get_contents($path), true);
        if (!is_array($state) || ($state['mode'] ?? '') !== 'ranked') {
            continue;
        }
        if (!tcgIsSpectatableHumanGame($state, $roomId)) {
            continue;
        }
        $matches[] = tcgSpectatableMatchRow($roomId, $state, 'ranked');
    }
    return $matches;
}

function tcgListCasualSpectatableMatches(): array {
    if (!defined('TCG_API_LIB_ONLY')) {
        define('TCG_API_LIB_ONLY', true);
    }
    require_once __DIR__ . '/api.php';

    $matches = [];
    $seen = [];
    $files = glob(GAMES_DIR . '*.json') ?: [];
    foreach ($files as $file) {
        $base = basename($file);
        if (str_starts_with($base, 'lock_')
            || str_starts_with($base, 'presence_')
            || str_starts_with($base, 'spectators_')) {
            continue;
        }
        $roomId = pathinfo($base, PATHINFO_FILENAME);
        if ($roomId === '' || isset($seen[$roomId])) {
            continue;
        }
        $seen[$roomId] = true;
        $raw = @file_get_contents($file);
        if ($raw === false) {
            continue;
        }
        $state = json_decode($raw, true);
        if (!is_array($state)) {
            continue;
        }
        if (($state['mode'] ?? '') === 'ranked') {
            continue;
        }
        if (!tcgIsSpectatableHumanGame($state, $roomId)) {
            continue;
        }
        $matches[] = tcgSpectatableMatchRow($roomId, $state, 'casual');
    }
    return $matches;
}

function tcgListSpectatableMatches(string $category): array {
    $category = strtolower(trim($category));
    if ($category === 'ranked') {
        $matches = tcgListRankedSpectatableMatches();
    } elseif ($category === 'casual' || $category === 'unranked') {
        $matches = tcgListCasualSpectatableMatches();
    } else {
        throw new Exception('category must be ranked or casual');
    }
    usort($matches, static function (array $a, array $b): int {
        $ta = intval($a['turn'] ?? 0);
        $tb = intval($b['turn'] ?? 0);
        if ($ta !== $tb) {
            return $tb <=> $ta;
        }
        return strcmp((string)($a['room_id'] ?? ''), (string)($b['room_id'] ?? ''));
    });
    return $matches;
}

function tcgJoinSpectator(string $roomId): array {
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId));
    if ($roomId === '') {
        throw new Exception('room_id required');
    }
    $state = loadGame($roomId);
    if (!$state) {
        throw new Exception('Room not found');
    }
    if (!tcgIsSpectatableHumanGame($state, $roomId)) {
        throw new Exception('This match is not available to spectate');
    }
    $category = (($state['mode'] ?? '') === 'ranked') ? 'ranked' : 'casual';
    $spectators = tcgPurgeStaleSpectators($roomId);
    if (count($spectators) >= TCG_SPECTATOR_MAX_PER_ROOM) {
        throw new Exception('Spectator slots full for this match');
    }
    $token = 'spec_' . bin2hex(random_bytes(16));
    $now = time();
    $spectators[$token] = ['joined_at' => $now, 'last_seen' => $now];
    tcgWriteSpectators($roomId, $spectators);
    return [
        'room_id' => $roomId,
        'spectator_token' => $token,
        'category' => $category,
        'p1_name' => (string)($state['players']['p1']['name'] ?? 'Player 1'),
        'p2_name' => (string)($state['players']['p2']['name'] ?? 'Player 2'),
    ];
}

function tcgLeaveSpectator(string $roomId, string $token): array {
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId));
    if ($roomId === '' || !tcgIsSpectatorToken($token)) {
        throw new Exception('Invalid spectator session');
    }
    $spectators = tcgReadSpectators($roomId);
    unset($spectators[$token]);
    tcgWriteSpectators($roomId, $spectators);
    return ['left' => true];
}

function filterStateForSpectator(array $state, string $roomId, string $spectatorToken): array {
    if (!tcgSpectatorTokenValid($roomId, $spectatorToken)) {
        throw new Exception('Spectator session expired');
    }
    tcgTouchSpectatorPresence($roomId, $spectatorToken);

    $filtered = $state;
    foreach (['p1', 'p2'] as $pid) {
        if (!isset($filtered['players'][$pid])) {
            continue;
        }
        $p = $filtered['players'][$pid];
        $filtered['players'][$pid]['hand_count'] = count($p['hand'] ?? []);
        // Spectators see both players' hands (broadcast view); decks stay hidden below.
        $filtered['players'][$pid]['main_deck_count'] = count($p['main_deck'] ?? []);
        $filtered['players'][$pid]['main_deck'] = [];
        $filtered['players'][$pid]['energy_deck_count'] = count($p['energy_deck'] ?? []);
        $filtered['players'][$pid]['energy_deck'] = [];
        $filtered['players'][$pid]['token'] = '';
        // Broadcast view: hands are visible; live storage stays full so card art renders (not card_no '?' stubs).
    }

    // Keep type/responder only — spectators must learn Win/Loss is waiting without
    // seeing pick options (full pending_prompt would spoil Success Live choices).
    if (!empty($state['pending_prompt']) && is_array($state['pending_prompt'])) {
        $pr = $state['pending_prompt'];
        $filtered['pending_prompt_meta'] = [
            'type' => (string)($pr['type'] ?? ''),
            'responder' => (string)($pr['responder'] ?? ''),
        ];
    } else {
        unset($filtered['pending_prompt_meta']);
    }
    unset($filtered['pending_prompt']);
    $filtered['my_id'] = null;
    $filtered['spectator'] = true;
    $filtered['view_as'] = 'p1';
    $filtered['pvp'] = isPvpMatch($state);
    $filtered['mode'] = $state['mode'] ?? null;
    $filtered['phase_timer_cfg'] = getPhaseTimerCfg($state);
    $filtered['spectator_count'] = tcgLiveSpectatorCount($roomId);

    $viewPid = 'p1';
    $oppId = 'p2';
    if (!empty($filtered['log'])) {
        $filtered['log'] = array_map(
            static fn($entry) => filterLogEntryForViewer(
                is_array($entry) ? $entry : ['msg' => (string)$entry],
                $viewPid,
                $filtered
            ),
            $filtered['log']
        );
    }

    $mineStageHearts = aggregateStageHeartsByColor($state['players'][$viewPid]['stage'] ?? []);
    $oppStageHearts = aggregateStageHeartsByColor($state['players'][$oppId]['stage'] ?? []);
    $mineStageHearts = mergeHeartColorCounts(
        $mineStageHearts,
        aggregateFlatHeartColors(getBonusHeartsFlat($state, $viewPid))
    );
    $oppStageHearts = mergeHeartColorCounts(
        $oppStageHearts,
        aggregateFlatHeartColors(getBonusHeartsFlat($state, $oppId))
    );
    $showYellHearts = isInPerformancePhase($state);
    $mineYellHearts = $showYellHearts
        ? aggregateYellHeartsByColor($state['yell_reveal'][$viewPid] ?? [])
        : [];
    $oppYellHearts = $showYellHearts
        ? aggregateYellHeartsByColor($state['yell_reveal'][$oppId] ?? [])
        : [];
    $mineContinuousGrants = $showYellHearts
        ? collectContinuousPerformanceHeartGrants($state, $viewPid) : [];
    $oppContinuousGrants = $showYellHearts
        ? collectContinuousPerformanceHeartGrants($state, $oppId) : [];
    $mineContinuousHearts = aggregateFlatHeartColors(getContinuousPerformanceHearts($state, $viewPid));
    $oppContinuousHearts = aggregateFlatHeartColors(getContinuousPerformanceHearts($state, $oppId));
    $carryPhase = $state['phase'] ?? '';
    $exposePerfCarryover = in_array($carryPhase, [
        'main_first', 'main_second', 'active_first', 'active_second',
        'live_start_effects', 'live_performance_first', 'live_performance_second',
        'live_success_effects', 'live_judge',
    ], true) || ($state['status'] ?? '') === 'finished';
    $yellBladeMine = computeYellBladeTotal($state, $viewPid);
    $yellBladeOpp = computeYellBladeTotal($state, $oppId);
    if ($exposePerfCarryover && !empty($state['_yell_blade_snapshot'])) {
        $yellBladeMine = intval($state['_yell_blade_snapshot'][$viewPid] ?? $yellBladeMine);
        $yellBladeOpp = intval($state['_yell_blade_snapshot'][$oppId] ?? $yellBladeOpp);
    }
    $filtered['stage_board'] = [
        'mine' => [
            'hearts' => mergeHeartColorCounts(
                mergeHeartColorCounts($mineStageHearts, $mineYellHearts),
                $mineContinuousHearts
            ),
            'stage_hearts' => $mineStageHearts,
            'yell_hearts' => $mineYellHearts,
            'continuous_hearts' => $mineContinuousHearts,
            'continuous_heart_grants' => $mineContinuousGrants,
            'yell'   => $yellBladeMine,
            'live_score_bonus' => getLiveScoreBonus($state, $viewPid),
            'active_effects' => collectActiveContinuousEffects($state, $viewPid),
        ],
        'opp' => [
            'hearts' => mergeHeartColorCounts(
                mergeHeartColorCounts($oppStageHearts, $oppYellHearts),
                $oppContinuousHearts
            ),
            'stage_hearts' => $oppStageHearts,
            'yell_hearts' => $oppYellHearts,
            'continuous_hearts' => $oppContinuousHearts,
            'continuous_heart_grants' => $oppContinuousGrants,
            'yell'   => $yellBladeOpp,
            // Omit face-down Live storage — Active effects / bonus text would spoil Lives set.
            'live_score_bonus' => getLiveScoreBonusBreakdown($state, $oppId, true)['total'],
            'active_effects' => collectActiveContinuousEffects($state, $oppId, true),
        ],
    ];

    if (!empty($state['yell_reveal']) && (
        isInPerformancePhase($state)
        || in_array(($state['pending_prompt']['type'] ?? ''), [
            'auto_yell_mill_extra_yell',
            'auto_yell_no_live_retry',
            'pick_yell_member',
            'live_success_pick_yell_live',
            'live_success_pick_yell_deck_top',
            'sbp5_pick_yell_members',
        ], true)
    )) {
        $filtered['yell_reveal'] = $state['yell_reveal'];
    } elseif ($exposePerfCarryover && !empty($state['_yell_reveal_snapshot'])) {
        $filtered['yell_reveal'] = $state['_yell_reveal_snapshot'];
    }
    if (!empty($state['live_perf_success'])) {
        $filtered['live_perf_success'] = $state['live_perf_success'];
    }
    if (!empty($state['live_round_success'])) {
        $filtered['live_round_success'] = $state['live_round_success'];
    }
    // Same as player filter — spectacle gating uses live_attempt when present.
    if (!empty($state['live_attempt']) && isInPerformancePhase($state)) {
        $filtered['live_attempt'] = array_values($state['live_attempt']);
    }
    if ($exposePerfCarryover && !empty($state['_live_perf_snapshot'])) {
        $filtered['_live_perf_snapshot'] = $state['_live_perf_snapshot'];
    }
    if ($exposePerfCarryover && !empty($state['_live_round_success_snapshot'])) {
        $filtered['_live_round_success_snapshot'] = $state['_live_round_success_snapshot'];
    }
    if ($exposePerfCarryover && !empty($state['_yell_blade_snapshot'])) {
        $filtered['_yell_blade_snapshot'] = $state['_yell_blade_snapshot'];
    }
    if (($filtered['phase'] ?? '') === 'live_set') {
        unset(
            $filtered['live_perf_success'],
            $filtered['live_round_success'],
            $filtered['_live_perf_snapshot'],
            $filtered['_live_round_success_snapshot'],
            $filtered['_yell_reveal_snapshot'],
            $filtered['_yell_blade_snapshot'],
            $filtered['yell_reveal']
        );
    }

    return enrichReplayFieldsForClient($filtered, $state);
}

function apiSpectateList(array $body): array {
    $category = (string)($body['category'] ?? $_GET['category'] ?? 'casual');
    return [
        'matches' => tcgListSpectatableMatches($category),
    ];
}

function apiSpectateJoin(array $body): array {
    $roomId = (string)($body['room_id'] ?? '');
    $out = tcgJoinSpectator($roomId);
    return tcgSyncAttachMeta($out, (string)($out['room_id'] ?? ''), (string)($out['spectator_token'] ?? ''));
}

function apiSpectateLeave(array $body): array {
    $roomId = (string)($body['room_id'] ?? '');
    $token = (string)($body['token'] ?? '');
    return tcgLeaveSpectator($roomId, $token);
}
