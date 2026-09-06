<?php
/**
 * Live match spectating for ranked and unranked human PvP.
 * Spectators receive read-only filtered state via get_state (spec_* tokens).
 */

const TCG_SPECTATOR_IDLE_SEC = 120;
const TCG_SPECTATOR_MAX_PER_ROOM = 32;
/** Skip rewriting spectators_*.json on every get_state (presence ping covers liveness). */
const TCG_SPECTATOR_PRESENCE_TOUCH_SEC = 25;
/** Limit purge/count disk scans under spectator stampedes. */
const TCG_SPECTATOR_PURGE_THROTTLE_SEC = 20;
const TCG_SPECTATOR_COUNT_CACHE_SEC = 2;

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

/**
 * Throttled purge — full scans on every get_state stampeded disk under many spectators.
 *
 * @return array<string, mixed>
 */
function tcgPurgeStaleSpectatorsThrottled(string $roomId, ?int $now = null): array {
    $now = $now ?? time();
    if (!isset($GLOBALS['_tcg_spec_purge_at']) || !is_array($GLOBALS['_tcg_spec_purge_at'])) {
        $GLOBALS['_tcg_spec_purge_at'] = [];
    }
    $last = intval($GLOBALS['_tcg_spec_purge_at'][$roomId] ?? 0);
    if ($last > 0 && ($now - $last) < TCG_SPECTATOR_PURGE_THROTTLE_SEC) {
        return tcgReadSpectators($roomId);
    }
    $GLOBALS['_tcg_spec_purge_at'][$roomId] = $now;
    return tcgPurgeStaleSpectators($roomId, $now);
}

function tcgSpectatorTokenValid(string $roomId, string $token): bool {
    if (!tcgIsSpectatorToken($token)) {
        return false;
    }
    $spectators = tcgPurgeStaleSpectatorsThrottled($roomId);
    if (!isset($spectators[$token])) {
        return false;
    }
    $meta = $spectators[$token];
    $last = intval(is_array($meta) ? ($meta['last_seen'] ?? $meta['joined_at'] ?? 0) : 0);
    if ($last > 0 && (time() - $last) >= TCG_SPECTATOR_IDLE_SEC) {
        return false;
    }
    return true;
}

function tcgLiveSpectatorCount(string $roomId): int {
    $now = time();
    if (!isset($GLOBALS['_tcg_spec_count_cache']) || !is_array($GLOBALS['_tcg_spec_count_cache'])) {
        $GLOBALS['_tcg_spec_count_cache'] = [];
    }
    $cached = $GLOBALS['_tcg_spec_count_cache'][$roomId] ?? null;
    if (is_array($cached)
        && intval($cached['t'] ?? 0) > 0
        && ($now - intval($cached['t'])) < TCG_SPECTATOR_COUNT_CACHE_SEC) {
        return intval($cached['n'] ?? 0);
    }
    $n = count(tcgPurgeStaleSpectatorsThrottled($roomId, $now));
    $GLOBALS['_tcg_spec_count_cache'][$roomId] = ['t' => $now, 'n' => $n];
    return $n;
}

function tcgTouchSpectatorPresence(string $roomId, string $token): void {
    if (!tcgIsSpectatorToken($token)) {
        return;
    }
    $now = time();
    $spectators = tcgPurgeStaleSpectatorsThrottled($roomId, $now);
    if (!isset($spectators[$token])) {
        return;
    }
    $meta = $spectators[$token];
    $last = intval(is_array($meta) ? ($meta['last_seen'] ?? 0) : 0);
    // Avoid N spectators × get_state rewriting the same JSON every poll.
    if ($last > 0 && ($now - $last) < TCG_SPECTATOR_PRESENCE_TOUCH_SEC) {
        return;
    }
    $spectators[$token]['last_seen'] = $now;
    tcgWriteSpectators($roomId, $spectators);
    if (isset($GLOBALS['_tcg_spec_count_cache']) && is_array($GLOBALS['_tcg_spec_count_cache'])) {
        unset($GLOBALS['_tcg_spec_count_cache'][$roomId]);
    }
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
    $grace = defined('PRESENCE_DISCONNECT_SEC') ? PRESENCE_DISCONNECT_SEC : 120;

    // Only count seats with a fresh player poll. Redis snapshot games/*.json mtime must
    // NOT mark everyone live — rematch leaves the previous room's snapshot fresh and
    // duplicated the same pair (or same player) in the spectate list.
    $live = 0;
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
        if ($last > 0 && ($now - $last) < $grace) {
            $live++;
        }
    }
    return $live;
}

/**
 * Cheap pre-filter before paying for a full state load.
 *
 * Listing every room with loadGame() made spectate_list take ~30s on Hostinger
 * (thousands of dead games/*.json), which in turn timed out ranked_status.
 * A spectatable room always has a fresh presence file: tcgIsSpectatableHumanGame()
 * requires at least one seat polling within PRESENCE_DISCONNECT_SEC, and presence
 * is file-backed on both the file and Redis stores.
 */
function tcgSpectateRoomHasFreshPresence(string $roomId, ?int $now = null): bool {
    if (!defined('GAMES_DIR')) {
        return true;
    }
    $safe = preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId));
    if ($safe === '') {
        return false;
    }
    $file = GAMES_DIR . 'presence_' . $safe . '.json';
    $mtime = @filemtime($file);
    if ($mtime === false) {
        return false;
    }
    $grace = defined('PRESENCE_DISCONNECT_SEC') ? PRESENCE_DISCONNECT_SEC : 120;
    return (($now ?? time()) - $mtime) < $grace;
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
    if ($roomId !== '' && tcgPvpLivePlayerCount($state, $roomId) < 1) {
        return false;
    }
    return true;
}

/**
 * One seat cannot appear in two spectate rows (rematch ghosts / overlapping rooms).
 * Keep the furthest-along room (seq, then turn) when the same Discord id overlaps.
 *
 * @param list<array<string,mixed>> $matches
 * @return list<array<string,mixed>>
 */
function tcgDedupSpectatableMatchesByPlayers(array $matches): array {
    usort($matches, static function (array $a, array $b): int {
        $sa = intval($a['seq'] ?? 0);
        $sb = intval($b['seq'] ?? 0);
        if ($sa !== $sb) {
            return $sb <=> $sa;
        }
        $ta = intval($a['turn'] ?? 0);
        $tb = intval($b['turn'] ?? 0);
        if ($ta !== $tb) {
            return $tb <=> $ta;
        }
        return strcmp((string)($a['room_id'] ?? ''), (string)($b['room_id'] ?? ''));
    });

    $used = [];
    $out = [];
    foreach ($matches as $row) {
        $ids = [];
        foreach (['p1_discord', 'p2_discord'] as $col) {
            $id = trim((string)($row[$col] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            $n1 = strtolower(trim((string)($row['p1_name'] ?? '')));
            $n2 = strtolower(trim((string)($row['p2_name'] ?? '')));
            if ($n1 !== '') {
                $ids[] = 'n:' . $n1;
            }
            if ($n2 !== '') {
                $ids[] = 'n:' . $n2;
            }
        }
        $overlap = false;
        foreach ($ids as $id) {
            if (isset($used[$id])) {
                $overlap = true;
                break;
            }
        }
        if ($overlap) {
            continue;
        }
        foreach ($ids as $id) {
            $used[$id] = true;
        }
        unset($row['seq'], $row['p1_discord'], $row['p2_discord']);
        $out[] = $row;
    }
    return $out;
}

function tcgSpectatableMatchRow(string $roomId, array $state, string $category): array {
    require_once __DIR__ . '/game_mode.php';
    $p1Discord = (string)($state['players']['p1']['discord_id']
        ?? $state['ranked']['p1_discord_id']
        ?? '');
    $p2Discord = (string)($state['players']['p2']['discord_id']
        ?? $state['ranked']['p2_discord_id']
        ?? '');
    return [
        'room_id' => $roomId,
        'category' => $category,
        'p1_name' => (string)($state['players']['p1']['name'] ?? 'Player 1'),
        'p2_name' => (string)($state['players']['p2']['name'] ?? 'Player 2'),
        'turn' => intval($state['turn'] ?? 0),
        'phase' => (string)($state['phase'] ?? ''),
        // Seats actually polling — hub "in ranked games" must not assume 2 per room.
        'live_players' => tcgPvpLivePlayerCount($state, $roomId),
        'spectators' => tcgLiveSpectatorCount($roomId),
        'seq' => intval($state['seq'] ?? 0),
        'p1_discord' => $p1Discord,
        'p2_discord' => $p2Discord,
        'game_mode' => tcgNormalizeGameMode(
            $state['game_mode']
                ?? $state['ranked']['game_mode']
                ?? TCG_GAME_MODE_STANDARD
        ),
    ];
}

function tcgListActiveRoomIdsForSpectate(): array {
    if (!defined('TCG_API_LIB_ONLY')) {
        define('TCG_API_LIB_ONLY', true);
    }
    require_once __DIR__ . '/api.php';
    $store = tcgResolveGameStore();
    if ($store instanceof \LLTCG\Game\Store\RedisGameStore) {
        return $store->listRoomIds();
    }
    $ids = [];
    $files = glob(GAMES_DIR . '*.json') ?: [];
    foreach ($files as $file) {
        $base = basename($file);
        if (str_starts_with($base, 'lock_')
            || str_starts_with($base, 'presence_')
            || str_starts_with($base, 'spectators_')
            || str_starts_with($base, 'poll_tick_')) {
            continue;
        }
        $roomId = pathinfo($base, PATHINFO_FILENAME);
        if ($roomId !== '') {
            $ids[] = $roomId;
        }
    }
    return $ids;
}

function tcgListRankedSpectatableMatches(): array {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/matchmaking.php';
    if (!defined('TCG_API_LIB_ONLY')) {
        define('TCG_API_LIB_ONLY', true);
    }
    require_once __DIR__ . '/api.php';

    $matches = [];
    $seen = [];

    // Prefer live GameStore (Redis under match-primary) over Hostinger-only file checks.
    foreach (tcgListActiveRoomIdsForSpectate() as $roomId) {
        if (!tcgSpectateRoomHasFreshPresence($roomId)) {
            continue;
        }
        $state = loadGame($roomId);
        if (!is_array($state) || ($state['mode'] ?? '') !== 'ranked') {
            continue;
        }
        if (!tcgIsSpectatableHumanGame($state, $roomId)) {
            continue;
        }
        $seen[$roomId] = true;
        $matches[] = tcgSpectatableMatchRow($roomId, $state, 'ranked');
    }

    // Also include DB-pending ranked rooms that loadGame can still resolve.
    try {
        $db = tcgDb();
        $stmt = $db->query('SELECT room_id FROM tcg_ranked_matches WHERE status = "pending"');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($row['room_id'] ?? '')) ?? '');
            if ($roomId === '' || isset($seen[$roomId])) {
                continue;
            }
            if (!tcgSpectateRoomHasFreshPresence($roomId)) {
                continue;
            }
            $state = loadGame($roomId);
            if (!is_array($state) || ($state['mode'] ?? '') !== 'ranked') {
                continue;
            }
            if (!tcgIsSpectatableHumanGame($state, $roomId)) {
                continue;
            }
            $seen[$roomId] = true;
            $matches[] = tcgSpectatableMatchRow($roomId, $state, 'ranked');
        }
    } catch (\Throwable $e) {
        // SQLite may be absent/stale on match hosts — Redis scan above is enough.
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
    foreach (tcgListActiveRoomIdsForSpectate() as $roomId) {
        if ($roomId === '' || isset($seen[$roomId])) {
            continue;
        }
        $seen[$roomId] = true;
        if (!tcgSpectateRoomHasFreshPresence($roomId)) {
            continue;
        }
        $state = loadGame($roomId);
        if (!is_array($state)) {
            continue;
        }
        $mode = (string)($state['mode'] ?? '');
        if ($mode === 'ranked' || $mode === 'tournament') {
            continue;
        }
        if (!tcgIsSpectatableHumanGame($state, $roomId)) {
            continue;
        }
        $matches[] = tcgSpectatableMatchRow($roomId, $state, 'casual');
    }
    return $matches;
}

/** Tournament rooms live on Hostinger GameStore (not VPS match-primary). */
function tcgListTournamentSpectatableMatches(): array {
    if (!defined('TCG_API_LIB_ONLY')) {
        define('TCG_API_LIB_ONLY', true);
    }
    require_once __DIR__ . '/api.php';

    $matches = [];
    $seen = [];
    foreach (tcgListActiveRoomIdsForSpectate() as $roomId) {
        if ($roomId === '' || isset($seen[$roomId])) {
            continue;
        }
        $seen[$roomId] = true;
        if (!tcgSpectateRoomHasFreshPresence($roomId)) {
            continue;
        }
        $state = loadGame($roomId);
        if (!is_array($state) || ($state['mode'] ?? '') !== 'tournament') {
            continue;
        }
        if (!tcgIsSpectatableHumanGame($state, $roomId)) {
            continue;
        }
        $matches[] = tcgSpectatableMatchRow($roomId, $state, 'tournament');
    }
    return $matches;
}

/** Cache path for a spectate list category (hub polls must not rescan every room). */
function tcgSpectateListCacheFile(string $category): string {
    require_once __DIR__ . '/config/paths.php';
    $safe = preg_replace('/[^a-z]/', '', strtolower($category));
    return tcgPath('data') . 'spectate_list_' . $safe . '.json';
}

function tcgListSpectatableMatches(string $category): array {
    $category = strtolower(trim($category));
    if ($category === 'unranked') {
        $category = 'casual';
    }
    if ($category !== 'ranked' && $category !== 'casual' && $category !== 'tournament') {
        throw new Exception('category must be ranked, casual, or tournament');
    }
    $cacheFile = tcgSpectateListCacheFile($category);
    $cacheAge = is_file($cacheFile) ? (time() - (int)@filemtime($cacheFile)) : null;
    if ($cacheAge !== null && $cacheAge < 5) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['matches']) && is_array($cached['matches'])) {
            return $cached['matches'];
        }
    }
    if ($category === 'ranked') {
        $matches = tcgListRankedSpectatableMatches();
    } elseif ($category === 'tournament') {
        $matches = tcgListTournamentSpectatableMatches();
    } else {
        $matches = tcgListCasualSpectatableMatches();
    }
    $matches = tcgDedupSpectatableMatchesByPlayers($matches);
    usort($matches, static function (array $a, array $b): int {
        $ta = intval($a['turn'] ?? 0);
        $tb = intval($b['turn'] ?? 0);
        if ($ta !== $tb) {
            return $tb <=> $ta;
        }
        return strcmp((string)($a['room_id'] ?? ''), (string)($b['room_id'] ?? ''));
    });
    @file_put_contents($cacheFile, json_encode(['matches' => $matches]), LOCK_EX);
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
    $category = (($state['mode'] ?? '') === 'ranked') ? 'ranked'
        : ((($state['mode'] ?? '') === 'tournament') ? 'tournament' : 'casual');
    $spectators = tcgPurgeStaleSpectators($roomId);
    if (count($spectators) >= TCG_SPECTATOR_MAX_PER_ROOM) {
        throw new Exception('Spectator slots full for this match');
    }
    $token = 'spec_' . bin2hex(random_bytes(16));
    $now = time();
    $spectators[$token] = ['joined_at' => $now, 'last_seen' => $now];
    tcgWriteSpectators($roomId, $spectators);
    $hiddenHands = !empty($state['spectate_hidden_hands']);
    if (($state['mode'] ?? '') === 'tournament' && !array_key_exists('spectate_hidden_hands', $state)) {
        $hiddenHands = true;
    }
    return [
        'room_id' => $roomId,
        'spectator_token' => $token,
        'category' => $category,
        'p1_name' => (string)($state['players']['p1']['name'] ?? 'Player 1'),
        'p2_name' => (string)($state['players']['p2']['name'] ?? 'Player 2'),
        'spectate_hidden_hands' => $hiddenHands,
        'mode' => (string)($state['mode'] ?? ''),
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

    if (($state['mode'] ?? '') === 'tournament') {
        require_once __DIR__ . '/tournament_spectate.php';
        $delayed = tcgTournamentApplyStreamDelay($roomId, $state);
        $state = $delayed['state'];
    }

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
    hideLiveJudgeSpoilersFromFilteredState($filtered, $state);
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

    $carryPhase = $state['phase'] ?? '';
    $exposePerfCarryover = in_array($carryPhase, [
        'main_first', 'main_second', 'active_first', 'active_second',
        'live_start_effects', 'live_performance_first', 'live_performance_second',
        'live_success_effects', 'live_judge',
    ], true) || ($state['status'] ?? '') === 'finished';
    $mineStage = is_array($state['players'][$viewPid] ?? null)
        ? ($state['players'][$viewPid]['stage'] ?? [])
        : [];
    $oppStage = is_array($state['players'][$oppId] ?? null)
        ? ($state['players'][$oppId]['stage'] ?? [])
        : [];
    $mineStageHearts = aggregateStageHeartsByColor(is_array($mineStage) ? $mineStage : []);
    $oppStageHearts = aggregateStageHeartsByColor(is_array($oppStage) ? $oppStage : []);
    $mineStageHearts = mergeHeartColorCounts(
        $mineStageHearts,
        aggregateFlatHeartColors(getBonusHeartsFlat($state, $viewPid))
    );
    $oppStageHearts = mergeHeartColorCounts(
        $oppStageHearts,
        aggregateFlatHeartColors(getBonusHeartsFlat($state, $oppId))
    );
    // Snapshot is spectacle-only — keep stage_board.stage_hearts as live HUD totals.
    $minePerfStageHearts = ($exposePerfCarryover && !empty($state['_stage_hearts_snapshot'][$viewPid]))
        ? $state['_stage_hearts_snapshot'][$viewPid]
        : null;
    $oppPerfStageHearts = ($exposePerfCarryover && !empty($state['_stage_hearts_snapshot'][$oppId]))
        ? $state['_stage_hearts_snapshot'][$oppId]
        : null;
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
    $yellBladeMine = computeYellBladeTotal($state, $viewPid);
    $yellBladeOpp = computeYellBladeTotal($state, $oppId);
    $yellBladeMinePerf = null;
    $yellBladeOppPerf = null;
    if ($exposePerfCarryover && !empty($state['_yell_blade_snapshot'])) {
        $yellBladeMinePerf = intval($state['_yell_blade_snapshot'][$viewPid] ?? $yellBladeMine);
        $yellBladeOppPerf = intval($state['_yell_blade_snapshot'][$oppId] ?? $yellBladeOpp);
    }
    $filtered['stage_board'] = [
        'mine' => [
            'hearts' => mergeHeartColorCounts(
                mergeHeartColorCounts($mineStageHearts, $mineYellHearts),
                $mineContinuousHearts
            ),
            'stage_hearts' => $mineStageHearts,
            'perf_stage_hearts' => $minePerfStageHearts,
            'yell_hearts' => $mineYellHearts,
            'continuous_hearts' => $mineContinuousHearts,
            'continuous_heart_grants' => $mineContinuousGrants,
            'yell'   => $yellBladeMine,
            'perf_yell' => $yellBladeMinePerf,
            'live_score_bonus' => !empty($filtered['live_scores_hidden'])
                ? 0 : getLiveScoreBonus($state, $viewPid),
            'active_effects' => collectActiveContinuousEffects($state, $viewPid),
        ],
        'opp' => [
            'hearts' => mergeHeartColorCounts(
                mergeHeartColorCounts($oppStageHearts, $oppYellHearts),
                $oppContinuousHearts
            ),
            'stage_hearts' => $oppStageHearts,
            'perf_stage_hearts' => $oppPerfStageHearts,
            'yell_hearts' => $oppYellHearts,
            'continuous_hearts' => $oppContinuousHearts,
            'continuous_heart_grants' => $oppContinuousGrants,
            'yell'   => $yellBladeOpp,
            'perf_yell' => $yellBladeOppPerf,
            // Omit face-down Live storage — Active effects / bonus text would spoil Lives set.
            'live_score_bonus' => !empty($filtered['live_scores_hidden'])
                ? 0 : getLiveScoreBonusBreakdown($state, $oppId, true)['total'],
            'active_effects' => collectActiveContinuousEffects($state, $oppId, true),
        ],
    ];

    if (!empty($state['yell_reveal'])) {
        $filtered['yell_reveal'] = $state['yell_reveal'];
    } elseif ($exposePerfCarryover && !empty($state['_yell_reveal_snapshot'])) {
        $filtered['yell_reveal'] = $state['_yell_reveal_snapshot'];
    }
    if (!empty($state['live_show']) && !empty($state['_perf_yell_both_done'])) {
        $filtered['_perf_yell_both_done'] = true;
    }
    if (!empty($state['live_perf_success'])) {
        $filtered['live_perf_success'] = $state['live_perf_success'];
    }
    if (!empty($state['live_round_success'])) {
        $filtered['live_round_success'] = $state['live_round_success'];
    }
    // Same as player filter — spectacle gating uses live_attempt when present.
    if (!empty($state['live_attempt']) && (
        isInPerformancePhase($state)
        || ($state['phase'] ?? '') === 'live_start_effects'
        || !empty($state['live_show'])
    )) {
        $filtered['live_attempt'] = array_values($state['live_attempt']);
    }
    if ($exposePerfCarryover && !empty($state['_live_perf_snapshot'])) {
        $filtered['_live_perf_snapshot'] = $state['_live_perf_snapshot'];
    }
    if ($exposePerfCarryover && !empty($state['_live_played_snapshot'])) {
        $filtered['_live_played_snapshot'] = $state['_live_played_snapshot'];
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
            $filtered['_live_played_snapshot'],
            $filtered['_live_round_success_snapshot'],
            $filtered['_yell_reveal_snapshot'],
            $filtered['_yell_blade_snapshot'],
            $filtered['yell_reveal']
        );
    }

    // Never expose the winner's ranked PR pack payload to spectators — the client
    // would treat G.playerId (view-as seat) as the winner and queue a false pack popup.
    unset($filtered['ranked_pr_reward']);
    if (isset($filtered['ranked']) && is_array($filtered['ranked'])) {
        unset($filtered['ranked']['pr_reward']);
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
