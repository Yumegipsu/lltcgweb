<?php
/**
 * Hostinger ↔ VPS ranked match bridge (seed rooms, Elo apply webhook, resign).
 *
 * Secrets (prefer env; optional defines in gitignored tcg_sync.local.php):
 *   TCG_INTERNAL_MATCH_SECRET
 *   TCG_MATCH_SEED_URL   (Hostinger → VPS seed)
 *   TCG_ELO_APPLY_URL    (VPS → Hostinger Elo)
 */

function tcgMatchBridgeLoadLocalConfig(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $local = __DIR__ . '/tcg_sync.local.php';
    if (is_file($local)) {
        require_once $local;
    }
}

function tcgInternalMatchSecret(): string {
    tcgMatchBridgeLoadLocalConfig();
    if (defined('TCG_INTERNAL_MATCH_SECRET')) {
        return trim((string)TCG_INTERNAL_MATCH_SECRET);
    }
    $env = getenv('TCG_INTERNAL_MATCH_SECRET');
    return is_string($env) ? trim($env) : '';
}

function tcgMatchSeedUrl(): string {
    tcgMatchBridgeLoadLocalConfig();
    if (defined('TCG_MATCH_SEED_URL')) {
        return trim((string)TCG_MATCH_SEED_URL);
    }
    $env = getenv('TCG_MATCH_SEED_URL');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    return 'https://stream.loveliveradio.ca/tcg/api/api.php?action=seed_ranked_room';
}

function tcgEloApplyUrl(): string {
    tcgMatchBridgeLoadLocalConfig();
    if (defined('TCG_ELO_APPLY_URL')) {
        return trim((string)TCG_ELO_APPLY_URL);
    }
    $env = getenv('TCG_ELO_APPLY_URL');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    return 'https://loveliveradio.ca/tcg/account.php?action=ranked_apply_result';
}

/** Hostinger account.php URL for a given action (derived from Elo apply base). */
function tcgHostingerAccountActionUrl(string $action): string {
    $action = trim($action);
    if ($action === '') {
        return '';
    }
    $elo = tcgEloApplyUrl();
    if (preg_match('#^(https?://[^?\s]+)#', $elo, $m)) {
        return $m[1] . '?action=' . rawurlencode($action);
    }
    return 'https://loveliveradio.ca/tcg/account.php?action=' . rawurlencode($action);
}

/**
 * True when match finish / stamp mission rows must be written on Hostinger
 * (VPS SQLite replica is one-way and invisible to the hub).
 */
function tcgMissionShouldWriteOnHostinger(): bool {
    if (function_exists('tcgShouldApplyRankedEloRemotely') && tcgShouldApplyRankedEloRemotely()) {
        return true;
    }
    $store = getenv('TCG_GAME_STORE');
    return is_string($store) && strtolower(trim($store)) === 'redis';
}

/**
 * Slim player slice for Hostinger mission credit (deck purity + CPU checks).
 *
 * @param array<string,mixed>|null $player
 * @return array<string,mixed>
 */
function tcgMissionPlayerSlim(?array $player): array {
    if (!is_array($player)) {
        return [];
    }
    $out = [
        'discord_id' => $player['discord_id'] ?? null,
        'name' => $player['name'] ?? null,
        'deck_choice' => $player['deck_choice'] ?? null,
    ];
    $snap = $player['deck_snapshot'] ?? null;
    if (is_array($snap)) {
        $main = $snap['main_nos'] ?? [];
        $energy = $snap['energy_nos'] ?? [];
        $out['deck_snapshot'] = [
            'main_nos' => is_array($main)
                ? array_values(array_map(static fn($n): string => trim((string)$n), $main))
                : [],
            'energy_nos' => is_array($energy)
                ? array_values(array_map(static fn($n): string => trim((string)$n), $energy))
                : [],
        ];
    }
    return $out;
}

/**
 * @param array<string,mixed>|null $snap
 * @return array{main_nos: list<string>, energy_nos: list<string>}|null
 */
function tcgMissionNormalizeDeckSnapshot(mixed $snap): ?array {
    if (!is_array($snap)) {
        return null;
    }
    $main = $snap['main_nos'] ?? [];
    $energy = $snap['energy_nos'] ?? [];
    if (!is_array($main)) {
        $main = [];
    }
    if (!is_array($energy)) {
        $energy = [];
    }
    return [
        'main_nos' => array_values(array_map(static fn($n): string => trim((string)$n), $main)),
        'energy_nos' => array_values(array_map(static fn($n): string => trim((string)$n), $energy)),
    ];
}

/**
 * Credit daily stamp mission on Hostinger (VPS replica writes are invisible to hub).
 *
 * @return list<array{id: string, i18n_key: string, reward: int}>
 */
function tcgPostMissionStampSentToHostinger(string $discordId): array {
    $discordId = trim($discordId);
    if ($discordId === '') {
        return [];
    }
    $res = tcgMatchBridgeHttpPostJson(
        tcgHostingerAccountActionUrl('mission_stamp_sent'),
        ['discord_id' => $discordId],
        8
    );
    if (!is_array($res) || empty($res['success'])) {
        return [];
    }
    $completions = $res['mission_completions'] ?? null;
    return is_array($completions) ? $completions : [];
}

/**
 * Credit unranked/CPU (and any non-Elo) finish missions on Hostinger.
 * Ranked overflow finishes use ranked_apply_result instead (includes deck snapshots).
 *
 * @param array<string,mixed> $state
 * @return list<array{id: string, i18n_key: string, reward: int}>
 */
function tcgPostMissionGameFinishedToHostinger(array $state): array {
    return tcgPostMissionGameFinishedBundleToHostinger($state)['missions'];
}

/**
 * Post finish missions + coins to Hostinger.
 *
 * @param array<string,mixed> $state
 * @return array{missions: list, coin_grants: list}
 */
function tcgPostMissionGameFinishedBundleToHostinger(array $state): array {
    if (!function_exists('tcgPlayStatDeltasExport') && is_file(__DIR__ . '/play_stats.php')) {
        require_once __DIR__ . '/play_stats.php';
    }
    $payload = [
        'room_id' => (string)($state['room_id'] ?? ''),
        'mode' => (string)($state['mode'] ?? ''),
        'status' => 'finished',
        'winner' => $state['winner'] ?? null,
        'end_reason' => $state['end_reason'] ?? null,
        'resigned_by' => $state['resigned_by'] ?? null,
        'disconnected_player' => $state['disconnected_player'] ?? null,
        'cpu_solo' => !empty($state['cpu_solo']),
        'cpu_difficulty' => (string)($state['cpu_difficulty'] ?? ''),
        'turn' => intval($state['turn'] ?? 0),
        'mission_peaks' => function_exists('missionPeaksExport')
            ? missionPeaksExport($state)
            : (is_array($state['_mission_peaks'] ?? null) ? $state['_mission_peaks'] : []),
        'play_stat_deltas' => function_exists('tcgPlayStatDeltasExport')
            ? tcgPlayStatDeltasExport($state)
            : (is_array($state['_play_stat_deltas'] ?? null) ? $state['_play_stat_deltas'] : []),
        'players' => [
            'p1' => tcgMissionPlayerSlim(is_array($state['players']['p1'] ?? null) ? $state['players']['p1'] : null),
            'p2' => tcgMissionPlayerSlim(is_array($state['players']['p2'] ?? null) ? $state['players']['p2'] : null),
        ],
    ];
    $ranked = $state['ranked'] ?? null;
    if (is_array($ranked)) {
        $payload['ranked'] = [
            'p1_discord_id' => $ranked['p1_discord_id'] ?? null,
            'p2_discord_id' => $ranked['p2_discord_id'] ?? null,
            'game_mode' => $ranked['game_mode'] ?? null,
            'match_api' => $ranked['match_api'] ?? null,
        ];
    }
    $res = tcgMatchBridgeHttpPostJson(
        tcgHostingerAccountActionUrl('mission_game_finished'),
        $payload,
        12
    );
    if (!is_array($res) || empty($res['success'])) {
        return ['missions' => [], 'coin_grants' => []];
    }
    $missions = $res['mission_completions'] ?? [];
    $coins = $res['coin_grants'] ?? [];
    return [
        'missions' => is_array($missions) ? $missions : [],
        'coin_grants' => is_array($coins) ? $coins : [],
    ];
}

function tcgInternalMatchSecretOk(?string $provided): bool {
    $expected = tcgInternalMatchSecret();
    if ($expected === '' || $provided === null || $provided === '') {
        return false;
    }
    return hash_equals($expected, $provided);
}

function tcgRequestInternalMatchSecret(): string {
    $h = $_SERVER['HTTP_X_TCG_INTERNAL_SECRET'] ?? '';
    if (is_string($h) && $h !== '') {
        return $h;
    }
    return '';
}

function tcgRequireInternalMatchSecret(): void {
    if (!tcgInternalMatchSecretOk(tcgRequestInternalMatchSecret())) {
        throw new Exception('Unauthorized', 401);
    }
}

function tcgShouldApplyRankedEloRemotely(): bool {
    if (tcgInternalMatchSecret() === '' || tcgEloApplyUrl() === '') {
        return false;
    }
    $env = getenv('TCG_ELO_APPLY_REMOTE');
    if (is_string($env) && $env !== '') {
        return in_array(strtolower(trim($env)), ['1', 'true', 'yes'], true);
    }
    // VPS match API uses Redis; Hostinger keeps file store + applies Elo locally.
    $store = getenv('TCG_GAME_STORE');
    return is_string($store) && strtolower(trim($store)) === 'redis';
}

/**
 * POST JSON to URL with internal secret. Returns decoded array or null on failure.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>|null
 */
function tcgMatchBridgeHttpPostJson(string $url, array $payload, int $timeoutSec = 12): ?array {
    $secret = tcgInternalMatchSecret();
    if ($secret === '' || $url === '') {
        return null;
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-TCG-Internal-Secret: ' . $secret,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSec),
            CURLOPT_TIMEOUT => $timeoutSec,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-TCG-Internal-Secret: {$secret}\r\n",
            'content' => $body,
            'timeout' => $timeoutSec,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/** @param array<string,mixed> $state */
function tcgSeedRankedRoomToVps(array $state): bool {
    $url = tcgMatchSeedUrl();
    $res = tcgMatchBridgeHttpPostJson($url, ['state' => $state], 15);
    return is_array($res) && !empty($res['ok']) && empty($res['error']);
}

/**
 * Fetch full room state from VPS get_state (Hostinger tick / migration).
 *
 * @return array<string,mixed>|null
 */
function tcgFetchOverflowRoomState(string $roomId, string $token): ?array {
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId) ?? '');
    $token = trim($token);
    if ($roomId === '' || $token === '') {
        return null;
    }
    if (tcgOverflowProbeUnavailable()) {
        return null;
    }
    $url = tcgOverflowMatchApiBase() . '/api.php?action=get_state'
        . '&room_id=' . rawurlencode($roomId)
        . '&token=' . rawurlencode($token)
        . '&seq=0&poll=0&resume=1';
    $raw = null;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
    }
    if (!is_string($raw) || $code < 200 || $code >= 500) {
        tcgNoteOverflowProbeFailure();
        return null;
    }
    tcgNoteOverflowProbeSuccess();
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !empty($decoded['error'])) {
        return null;
    }
    if (empty($decoded['my_id']) && empty($decoded['status'])) {
        return null;
    }
    return $decoded;
}

/**
 * @param array<string,mixed> $state
 */
function tcgPostTournamentApplyResultToHostinger(array &$state): bool {
    $meta = is_array($state['tournament'] ?? null) ? $state['tournament'] : [];
    if (!empty($meta['applied'])) {
        return true;
    }
    $p1 = is_array($state['players']['p1'] ?? null) ? $state['players']['p1'] : [];
    $p2 = is_array($state['players']['p2'] ?? null) ? $state['players']['p2'] : [];
    $winnerPid = $state['winner'] ?? null;
    $winnerDid = '';
    if ($winnerPid === 'p1') {
        $winnerDid = (string)($p1['discord_id'] ?? '');
    } elseif ($winnerPid === 'p2') {
        $winnerDid = (string)($p2['discord_id'] ?? '');
    }
    if ($winnerDid === '') {
        return false;
    }
    $payload = [
        'room_id' => (string)($state['room_id'] ?? ''),
        'match_id' => (string)($meta['match_id'] ?? ''),
        'tournament_id' => (string)($meta['id'] ?? ''),
        'winner_discord_id' => $winnerDid,
        'winner' => $winnerPid,
        'end_reason' => (string)($state['end_reason'] ?? 'game'),
    ];
    $res = tcgMatchBridgeHttpPostJson(tcgHostingerAccountActionUrl('tournament_apply_result'), $payload, 30);
    if (!is_array($res) || empty($res['success'])) {
        return false;
    }
    if (!isset($state['tournament']) || !is_array($state['tournament'])) {
        $state['tournament'] = [];
    }
    $state['tournament']['applied'] = true;
    return true;
}

/**
 * @param array<string,mixed> $state
 */
function tcgPostRankedApplyResultToHostinger(array &$state): bool {
    if (!function_exists('tcgPlayStatDeltasExport') && is_file(__DIR__ . '/play_stats.php')) {
        require_once __DIR__ . '/play_stats.php';
    }
    $ranked = $state['ranked'] ?? [];
    if (!is_array($ranked)) {
        $ranked = [];
    }
    $p1 = is_array($state['players']['p1'] ?? null) ? $state['players']['p1'] : [];
    $p2 = is_array($state['players']['p2'] ?? null) ? $state['players']['p2'] : [];
    $payload = [
        'room_id' => (string)($state['room_id'] ?? ''),
        'winner' => $state['winner'] ?? null,
        'end_reason' => $state['end_reason'] ?? null,
        'p1_discord_id' => (string)($ranked['p1_discord_id'] ?? ''),
        'p2_discord_id' => (string)($ranked['p2_discord_id'] ?? ''),
        'game_mode' => (string)($ranked['game_mode'] ?? 'standard'),
        'resigned_by' => $state['resigned_by'] ?? null,
        'disconnected_player' => $state['disconnected_player'] ?? null,
        // Group-win milestones need deck lists; Elo-only payload previously dropped them.
        'p1_deck_snapshot' => tcgMissionNormalizeDeckSnapshot($p1['deck_snapshot'] ?? null),
        'p2_deck_snapshot' => tcgMissionNormalizeDeckSnapshot($p2['deck_snapshot'] ?? null),
        'p1_deck_choice' => (string)($p1['deck_choice'] ?? ''),
        'p2_deck_choice' => (string)($p2['deck_choice'] ?? ''),
        'p1_name' => (string)($p1['name'] ?? ''),
        'p2_name' => (string)($p2['name'] ?? ''),
        'turn' => intval($state['turn'] ?? 0),
        'mission_peaks' => function_exists('missionPeaksExport')
            ? missionPeaksExport($state)
            : (is_array($state['_mission_peaks'] ?? null) ? $state['_mission_peaks'] : []),
        'play_stat_deltas' => function_exists('tcgPlayStatDeltasExport')
            ? tcgPlayStatDeltasExport($state)
            : (is_array($state['_play_stat_deltas'] ?? null) ? $state['_play_stat_deltas'] : []),
    ];
    $res = tcgMatchBridgeHttpPostJson(tcgEloApplyUrl(), $payload, 30);
    if (!is_array($res) || empty($res['success']) || !empty($res['error'])) {
        return false;
    }
    // Hostinger grants the pack; stash on VPS room so the winner's client can show it.
    $mergedPr = false;
    if (is_array($res['pr_reward'] ?? null)) {
        if (!isset($state['ranked']) || !is_array($state['ranked'])) {
            $state['ranked'] = [];
        }
        require_once __DIR__ . '/ranked_pr_rewards.php';
        $prevEntry = is_array($state['ranked']['pr_reward'] ?? null) ? $state['ranked']['pr_reward'] : [];
        $prevReward = is_array($prevEntry['reward'] ?? null) ? $prevEntry['reward'] : [];
        $hadGrant = tcgRankedPrRewardIsGranted($prevReward);
        $state['ranked']['pr_reward'] = $res['pr_reward'];
        $newReward = is_array($res['pr_reward']['reward'] ?? null) ? $res['pr_reward']['reward'] : [];
        $applied = !empty($res['pr_reward_applied']) || tcgRankedPrRewardShouldPersistApplied($newReward);
        $state['ranked']['pr_reward_applied'] = $applied;
        $mergedPr = !$hadGrant && tcgRankedPrRewardIsGranted($newReward);
    }
    // Late PR merge must bump seq or get_state clients stay on unchanged:true and never
    // see ranked_pr_reward — cards land in collection with no hub popup.
    if ($mergedPr) {
        $state['seq'] = intval($state['seq'] ?? 0) + 1;
    }
    if (!empty($res['mission_completions']) && is_array($res['mission_completions'])) {
        $state['_hostinger_mission_completions'] = $res['mission_completions'];
    }
    if (!empty($res['coin_grants']) && is_array($res['coin_grants'])) {
        $state['_coin_grants'] = $res['coin_grants'];
    }
    return true;
}

function tcgOverflowMatchApiBase(): string {
    tcgMatchBridgeLoadLocalConfig();
    if (defined('TCG_OVERFLOW_MATCH_API')) {
        return rtrim((string)TCG_OVERFLOW_MATCH_API, '/');
    }
    $env = getenv('TCG_OVERFLOW_MATCH_API');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }
    return 'https://stream.loveliveradio.ca/tcg/api';
}

/**
 * Circuit breaker for interactive overflow lookups (ranked_status / queue stats).
 *
 * A slow or unreachable VPS used to cost one full curl timeout per probe inside a
 * single hub request, which blew past the client's 12s budget ("Request timed out").
 * After a failure, short-circuit further probes for a cooldown window.
 */
function tcgOverflowProbeCooldownFile(): string {
    require_once __DIR__ . '/config/paths.php';
    return tcgPath('data') . 'overflow_probe_cooldown.json';
}

function tcgOverflowProbeUnavailable(): bool {
    static $memo = null;
    if ($memo !== null && $memo > time()) {
        return true;
    }
    $file = tcgOverflowProbeCooldownFile();
    $mtime = @filemtime($file);
    if ($mtime === false) {
        return false;
    }
    return (time() - $mtime) < 30;
}

function tcgNoteOverflowProbeFailure(): void {
    @file_put_contents(tcgOverflowProbeCooldownFile(), (string)time(), LOCK_EX);
}

function tcgNoteOverflowProbeSuccess(): void {
    $file = tcgOverflowProbeCooldownFile();
    if (is_file($file)) {
        @unlink($file);
    }
}

/**
 * Probe VPS ranked room. Returns live|finished|missing|unknown.
 */
function tcgProbeOverflowRankedRoom(string $roomId, string $token): string {
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId) ?? '');
    $token = trim($token);
    if ($roomId === '' || $token === '') {
        return 'unknown';
    }
    // Same room is probed by queue status and queue stats in one request.
    static $memo = [];
    $memoKey = $roomId . '|' . substr(hash('sha256', $token), 0, 12);
    if (isset($memo[$memoKey])) {
        return $memo[$memoKey];
    }
    if (tcgOverflowProbeUnavailable()) {
        // 'unknown' keeps pending rows intact — never clears a match on a blip.
        return 'unknown';
    }
    $url = tcgOverflowMatchApiBase() . '/api.php?action=get_state'
        . '&room_id=' . rawurlencode($roomId)
        . '&token=' . rawurlencode($token)
        . '&seq=0&poll=0&resume=1';
    $raw = null;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return 'unknown';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
    }
    if (!is_string($raw) || $code < 200 || $code >= 500) {
        tcgNoteOverflowProbeFailure();
        return $memo[$memoKey] = 'unknown';
    }
    tcgNoteOverflowProbeSuccess();
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $memo[$memoKey] = 'unknown';
    }
    if (!empty($decoded['error']) && preg_match('/room not found/i', (string)$decoded['error'])) {
        return $memo[$memoKey] = 'missing';
    }
    if (($decoded['status'] ?? '') === 'finished') {
        return $memo[$memoKey] = 'finished';
    }
    if (!empty($decoded['my_id']) || in_array((string)($decoded['mode'] ?? ''), ['ranked', 'tournament'], true)) {
        return $memo[$memoKey] = 'live';
    }
    return $memo[$memoKey] = 'unknown';
}

/**
 * Live ranked player count from the VPS spectate list (presence-accurate).
 * Returns null when the overflow host cannot be reached.
 */
function tcgFetchOverflowRankedLivePlayerCount(?string $gameMode = null): ?int {
    require_once __DIR__ . '/game_mode.php';
    $gameMode = tcgNormalizeGameMode($gameMode ?? TCG_GAME_MODE_STANDARD);
    if (tcgOverflowProbeUnavailable()) {
        return null;
    }
    $url = tcgOverflowMatchApiBase() . '/api.php?action=spectate_list';
    $payload = json_encode(['category' => 'ranked'], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return null;
    }
    $raw = null;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 4,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
    }
    if (!is_string($raw) || $code < 200 || $code >= 300) {
        tcgNoteOverflowProbeFailure();
        return null;
    }
    tcgNoteOverflowProbeSuccess();
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['matches']) || !is_array($decoded['matches'])) {
        return null;
    }
    $inGame = 0;
    foreach ($decoded['matches'] as $m) {
        if (!is_array($m)) {
            continue;
        }
        $mMode = tcgNormalizeGameMode($m['game_mode'] ?? TCG_GAME_MODE_STANDARD);
        if ($mMode !== $gameMode) {
            continue;
        }
        // Count seats that are actually polling; a room with one disconnected
        // player must not report two people in ranked games. Older match hosts
        // do not send live_players — fall back to the 1v1 assumption.
        if (array_key_exists('live_players', $m)) {
            $inGame += max(0, min(2, intval($m['live_players'])));
            continue;
        }
        $inGame += 2;
    }
    return $inGame;
}

/**
 * POST JSON to the overflow match API without the internal secret
 * (player-token gated actions like replay_export).
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>|null
 */
function tcgOverflowHttpPostJson(string $url, array $payload, int $timeoutSec = 30): ?array {
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSec),
            CURLOPT_TIMEOUT => $timeoutSec,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => $timeoutSec,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Pull a finished-match replay from the VPS match API (match-primary rooms).
 *
 * @return array<string,mixed>|null Replay file payload
 */
function tcgReplayExportErrorIsRetryable(Throwable $e): bool {
    $code = intval($e->getCode());
    if ($code === 503) {
        return true;
    }
    $msg = strtolower($e->getMessage());
    return str_contains($msg, 'not finished')
        || str_contains($msg, 'no recorded actions')
        || str_contains($msg, 'room not found')
        || str_contains($msg, 'not ready')
        || str_contains($msg, 'match host')
        || str_contains($msg, 'export not ready');
}

function tcgFetchOverflowReplayExport(string $roomId, string $token): ?array {
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId) ?? '');
    $token = trim($token);
    if ($roomId === '' || $token === '') {
        return null;
    }
    $testCounter = getenv('TCG_TEST_OVERFLOW_COUNTER');
    $testPayload = getenv('TCG_TEST_OVERFLOW_PAYLOAD');
    if (is_string($testCounter) && $testCounter !== ''
        && is_string($testPayload) && $testPayload !== '' && is_file($testPayload)) {
        $count = is_file($testCounter) ? (int)file_get_contents($testCounter) : 0;
        file_put_contents($testCounter, (string)($count + 1));
        $failUntil = max(0, (int)(getenv('TCG_TEST_OVERFLOW_FAIL_UNTIL') ?: '0'));
        if ($count < $failUntil) {
            throw new Exception('Replay not finished yet', 503);
        }
        $decoded = json_decode((string)file_get_contents($testPayload), true);
        if (!is_array($decoded)) {
            throw new Exception('Mock payload invalid', 500);
        }
        return $decoded;
    }
    $url = tcgOverflowMatchApiBase() . '/api.php?action=replay_export';
    $res = tcgOverflowHttpPostJson($url, [
        'room_id' => $roomId,
        'token' => $token,
    ], 45);
    if (!is_array($res)) {
        throw new Exception('Match export not ready (host unreachable)', 503);
    }
    if (!empty($res['error'])) {
        $errMsg = (string)$res['error'];
        $retryable = str_contains(strtolower($errMsg), 'not finished')
            || str_contains(strtolower($errMsg), 'no recorded actions')
            || str_contains(strtolower($errMsg), 'room not found');
        throw new Exception($errMsg, $retryable ? 503 : 404);
    }
    if (empty($res['replay']) || !is_array($res['replay'])) {
        throw new Exception('Match export not ready yet', 503);
    }
    return $res['replay'];
}

/**
 * Hostinger → VPS replay_export with backoff (autosave often races finish).
 *
 * @param array<int,int> $delaysMs
 * @return array<string,mixed>
 */
function tcgFetchOverflowReplayExportWithRetry(
    string $roomId,
    string $token,
    array $delaysMs = [0, 400, 1200, 3000, 6000]
): array {
    $lastErr = null;
    foreach (array_values($delaysMs) as $i => $delay) {
        if ($i > 0 && $delay > 0) {
            usleep($delay * 1000);
        }
        try {
            $payload = tcgFetchOverflowReplayExport($roomId, $token);
            if (is_array($payload)) {
                return $payload;
            }
            $lastErr = new Exception('Match export not ready yet', 503);
        } catch (Throwable $e) {
            $lastErr = $e;
            if (!tcgReplayExportErrorIsRetryable($e)) {
                throw $e;
            }
        }
    }
    if ($lastErr instanceof Throwable) {
        throw $lastErr;
    }
    throw new Exception('Room not found', 404);
}

/**
 * After Hostinger SQLite archive: drop VPS disk snapshot (Redis room keeps TTL).
 * Best-effort — failures leave files for cleanup cron / api.php?action=cleanup.
 */
function tcgNotifyOverflowDeleteGameSnapshot(string $roomId): void {
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId) ?? '');
    if ($roomId === '') {
        return;
    }
    // Local file-store Hostinger: unlink directly when this process owns GAMES_DIR.
    $store = getenv('TCG_GAME_STORE');
    $isRedis = is_string($store) && strtolower(trim($store)) === 'redis';
    if (!$isRedis && defined('GAMES_DIR') && function_exists('deleteGameSnapshot')) {
        try {
            deleteGameSnapshot($roomId);
        } catch (Throwable $e) {
            // ignore
        }
    }
    $url = tcgOverflowMatchApiBase() . '/api.php?action=delete_game_snapshot';
    try {
        tcgMatchBridgeHttpPostJson($url, ['room_id' => $roomId], 8);
    } catch (Throwable $e) {
        // best-effort
    }
}

function tcgResignRankedRoomOnVps(string $roomId, string $token): bool {
    $url = tcgOverflowMatchApiBase() . '/api.php?action=action';
    $payload = json_encode([
        'room_id' => $roomId,
        'token' => $token,
        'type' => 'resign',
        'data' => [],
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return false;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $code < 200 || $code >= 300) {
            return false;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) && (isset($decoded['ok']) || isset($decoded['seq']) || empty($decoded['error']));
    }
    return false;
}
