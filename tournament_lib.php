<?php
/**
 * Tournament Mode v1 — helpers (feature gate, bracket math, public shapes).
 * Loaded by tournament.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/coins.php';
require_once __DIR__ . '/game_mode.php';
require_once __DIR__ . '/deck_validate.php';

const TCG_TOURNAMENT_CONNECT_SECS = 180;
const TCG_TOURNAMENT_DEFAULT_CHECKIN_MINS = 10;
const TCG_TOURNAMENT_MIN_CHECKIN_MINS = 5;
const TCG_TOURNAMENT_MAX_CHECKIN_MINS = 10;
const TCG_TOURNAMENT_MIN_PLAYERS = 2;
const TCG_TOURNAMENT_MAX_PLAYERS_CAP = 32;
/** Optional host-funded PR pack prize (separate from coin pool). */
const TCG_TOURNAMENT_PR_PACK_COST = 1000;
const TCG_TOURNAMENT_PR_PACK_SIZE = 5;
const TCG_TOURNAMENT_PR_PACK_MIN_CHECKINS = 10;

/**
 * Optional Discord ID allowlist. Empty = open to everyone when tournaments are enabled.
 * Override with TCG_TOURNAMENT_ALLOWLIST (comma-separated). Use "*" / "none" / "-" for open.
 *
 * @return list<string>
 */
function tcgTournamentAllowlist(): array {
    $env = getenv('TCG_TOURNAMENT_ALLOWLIST');
    if (is_string($env)) {
        $env = trim($env);
        if ($env === '*' || $env === 'none' || $env === '-') {
            return [];
        }
        if ($env !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $env)), static fn($id) => $id !== ''));
        }
    }
    return [];
}

/** Public by default; set TCG_TOURNAMENTS_ENABLED=0 to disable. */
function tcgTournamentsEnvEnabled(): bool {
    $env = getenv('TCG_TOURNAMENTS_ENABLED');
    if ($env === false || $env === '') {
        return true;
    }
    $v = strtolower(trim((string)$env));
    return $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on';
}

/** Feature shipped (env open and/or allowlist preview). */
function tcgTournamentsEnabled(): bool {
    if (tcgTournamentsEnvEnabled()) {
        return true;
    }
    return tcgTournamentAllowlist() !== [];
}

/** Per-user gate: allowlist when non-empty; otherwise anyone if env enabled. */
function tcgUserMayUseTournaments(?string $discordId): bool {
    if (!tcgTournamentsEnabled()) {
        return false;
    }
    $list = tcgTournamentAllowlist();
    if ($list === []) {
        return tcgTournamentsEnvEnabled();
    }
    if ($discordId === null || $discordId === '') {
        return false;
    }
    return in_array((string)$discordId, $list, true);
}

function tcgRequireTournamentsEnabled(?string $discordId = null): void {
    if ($discordId !== null) {
        if (!tcgUserMayUseTournaments($discordId)) {
            throw new Exception('Tournaments are disabled', 403);
        }
        return;
    }
    if (!tcgTournamentsEnabled()) {
        throw new Exception('Tournaments are disabled', 403);
    }
}

function tcgTournamentNewId(): string {
    return strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));
}

function tcgTournamentMatchNewId(): string {
    return strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
}

/** @return array<string,mixed> */
function tcgTournamentDecodeSettings(?string $json): array {
    $raw = json_decode((string)$json, true);
    return tcgTournamentNormalizeSettings(is_array($raw) ? $raw : []);
}

/** @param array<string,mixed>|null $settings */
function tcgTournamentEncodeSettings(?array $settings): string {
    return json_encode(tcgTournamentNormalizeSettings($settings ?: []), JSON_UNESCAPED_UNICODE) ?: '{}';
}

/**
 * Deck-construction rules templates allowed for a tournament game mode.
 * Starters / randomized already constrain decks — extra templates don't apply.
 *
 * @return list<string>
 */
function tcgTournamentRulesTemplatesForMode(string $gameMode): array {
    $gameMode = tcgNormalizeGameMode($gameMode);
    if ($gameMode === TCG_GAME_MODE_STANDARD || $gameMode === TCG_GAME_MODE_FREE) {
        return ['standard', 'pauper', 'highlander'];
    }
    return ['standard'];
}

/**
 * Normalize tournament settings_json (Phase 2+ fields).
 * Pass $gameMode so rules_template is clamped to what that mode allows.
 *
 * @param array<string,mixed> $settings
 * @return array<string,mixed>
 */
function tcgTournamentNormalizeSettings(array $settings, ?string $gameMode = null): array {
    $fog = (string)($settings['fog'] ?? 'hidden_hands');
    if ($fog !== 'open_hands') {
        $fog = 'hidden_hands';
    }
    $delay = (int)($settings['stream_delay_secs'] ?? 0);
    if (!in_array($delay, [0, 15, 30, 60], true)) {
        $delay = 0;
    }
    $rules = (string)($settings['rules_template'] ?? 'standard');
    // Legacy: starters_only duplicated game_mode=starters — drop it.
    if ($rules === 'starters_only') {
        $rules = 'standard';
    }
    if (!in_array($rules, ['standard', 'pauper', 'highlander'], true)) {
        $rules = 'standard';
    }
    if ($gameMode !== null) {
        $allowed = tcgTournamentRulesTemplatesForMode($gameMode);
        if (!in_array($rules, $allowed, true)) {
            $rules = 'standard';
        }
    }
    $format = (string)($settings['format'] ?? 'single_elim');
    if (!in_array($format, ['single_elim', 'double_elim', 'double_elim_bracket', 'swiss'], true)) {
        $format = 'single_elim';
    }
    $bestOf = (int)($settings['best_of'] ?? 1);
    if ($bestOf !== 3) {
        $bestOf = 1;
    }
    $out = [
        'connect_secs' => max(30, (int)($settings['connect_secs'] ?? TCG_TOURNAMENT_CONNECT_SECS)),
        'fog' => $fog,
        'stream_delay_secs' => $delay,
        'rules_template' => $rules,
        'format' => $format,
        'best_of' => $bestOf,
    ];
    // Runtime fields set at bracket start / advance — must survive encode/decode.
    if (array_key_exists('swiss_rounds', $settings)) {
        $out['swiss_rounds'] = max(1, min(8, (int)$settings['swiss_rounds']));
    }
    if (array_key_exists('showed_up', $settings)) {
        $out['showed_up'] = max(0, (int)$settings['showed_up']);
    }
    if (array_key_exists('playoff_size', $settings)) {
        $ps = (int)$settings['playoff_size'];
        $out['playoff_size'] = in_array($ps, [2, 4], true) ? $ps : 2;
    }
    if (array_key_exists('swiss_phase', $settings)) {
        $phase = (string)$settings['swiss_phase'];
        $out['swiss_phase'] = in_array($phase, ['swiss', 'playoff'], true) ? $phase : 'swiss';
    }
    if (array_key_exists('bracket_size', $settings)) {
        $out['bracket_size'] = max(2, (int)$settings['bracket_size']);
    }
    // Host-funded PR pack prize (escrowed separately from coin pool).
    $prOn = !empty($settings['pr_pack']) || !empty($settings['pr_pack_prize']);
    if ($prOn || array_key_exists('pr_pack_status', $settings)) {
        $out['pr_pack'] = $prOn ? 1 : 0;
        $st = (string)($settings['pr_pack_status'] ?? ($prOn ? 'escrowed' : 'none'));
        if (!in_array($st, ['escrowed', 'dropped', 'awarded', 'none'], true)) {
            $st = $prOn ? 'escrowed' : 'none';
        }
        $out['pr_pack_status'] = $st;
    }
    return $out;
}

/**
 * Public PR-pack prize summary for list/detail cards.
 *
 * @param array<string,mixed> $settings
 * @param array<string,mixed>|null $results enriched or raw results
 * @return array{enabled:bool,cost:int,pack_size:int,min_checkins:int,status:string,awarded:bool}|null
 */
function tcgTournamentPrPackPublic(array $settings, ?array $results = null): ?array {
    $enabled = !empty($settings['pr_pack']);
    $status = (string)($settings['pr_pack_status'] ?? ($enabled ? 'escrowed' : 'none'));
    $fromResults = is_array($results['pr_pack'] ?? null) ? $results['pr_pack'] : null;
    if (!$enabled && !$fromResults) {
        return null;
    }
    $awarded = !empty($fromResults['awarded']) || $status === 'awarded';
    $dropped = !empty($fromResults['dropped']) || $status === 'dropped';
    if ($awarded) {
        $status = 'awarded';
    } elseif ($dropped) {
        $status = 'dropped';
    }
    return [
        'enabled' => true,
        'cost' => TCG_TOURNAMENT_PR_PACK_COST,
        'pack_size' => (int)($fromResults['pack_size'] ?? TCG_TOURNAMENT_PR_PACK_SIZE),
        'min_checkins' => TCG_TOURNAMENT_PR_PACK_MIN_CHECKINS,
        'status' => $status,
        'awarded' => $awarded,
        'dropped' => $dropped,
    ];
}

/**
 * Refund host PR-pack escrow if still escrowed. Updates settings_json.
 *
 * @param array<string,mixed> $row
 */
function tcgTournamentRefundPrPackEscrow(string $tournamentId, array $row, string $reason): bool {
    $settings = tcgTournamentDecodeSettings($row['settings_json'] ?? '{}');
    if (empty($settings['pr_pack']) || (string)($settings['pr_pack_status'] ?? '') !== 'escrowed') {
        return false;
    }
    $host = (string)($row['host_discord_id'] ?? '');
    if ($host === '') {
        return false;
    }
    $key = 'refund_pr_pack:' . $tournamentId;
    if (!tcgTournamentLedgerWrite($tournamentId, $host, 'refund', TCG_TOURNAMENT_PR_PACK_COST, $key, [
        'reason' => $reason,
        'kind' => 'pr_pack_escrow',
    ])) {
        // Already refunded
        $settings['pr_pack_status'] = 'dropped';
        tcgDb()->prepare('UPDATE tcg_tournaments SET settings_json = ?, updated_at = ? WHERE id = ?')
            ->execute([tcgTournamentEncodeSettings($settings), time(), $tournamentId]);
        return false;
    }
    tcgAddCoins($host, TCG_TOURNAMENT_PR_PACK_COST);
    $settings['pr_pack_status'] = 'dropped';
    tcgDb()->prepare('UPDATE tcg_tournaments SET settings_json = ?, updated_at = ? WHERE id = ?')
        ->execute([tcgTournamentEncodeSettings($settings), time(), $tournamentId]);
    return true;
}

/**
 * Optional Discord incoming webhook (Hostinger env TCG_TOURNAMENT_WEBHOOK_URL).
 *
 * @param array<string,mixed> $row tournament DB row
 */
function tcgTournamentNotifyWebhook(string $event, array $row): void {
    $url = getenv('TCG_TOURNAMENT_WEBHOOK_URL');
    if (!is_string($url) || trim($url) === '') {
        return;
    }
    $url = trim($url);
    if (!preg_match('#^https://(discord(?:app)?\.com|canary\.discord\.com)/api/webhooks/#i', $url)) {
        return;
    }
    $title = (string)($row['title'] ?? 'Tournament');
    $id = (string)($row['id'] ?? '');
    $start = (int)($row['start_at'] ?? 0);
    $link = 'https://loveliveradio.ca/tcg/';
    $lines = [
        'entered_checkin' => '**Check-in open** — ' . $title,
        'bracket_started' => '**Bracket started** — ' . $title,
        'finished' => '**Tournament finished** — ' . $title,
    ];
    $content = $lines[$event] ?? ('**Tournament update** (' . $event . ') — ' . $title);
    if ($id !== '') {
        $content .= "\nID `" . $id . "`";
    }
    if ($event === 'finished') {
        $results = tcgTournamentResultsForRow($row);
        $winner = is_array($results) ? ($results['winner'] ?? null) : null;
        if (is_array($winner)) {
            $wName = (string)($winner['username'] ?? 'Player');
            $wCoins = (int)($winner['coins'] ?? 0);
            $content .= "\nWinner: **" . $wName . "**";
            if ($wCoins > 0) {
                $content .= ' (+' . $wCoins . ' Coins)';
            }
            if (!empty($results['pr_pack']['awarded'])) {
                $content .= ' + PR Pack ×' . (int)($results['pr_pack']['pack_size'] ?? TCG_TOURNAMENT_PR_PACK_SIZE);
            }
            $pool = (int)($results['prize_pool_total'] ?? 0);
            if ($pool > 0) {
                $content .= "\nPrize pool: " . $pool . ' Coins';
            }
        }
    } elseif ($start > 0) {
        $content .= "\nStarts <t:" . $start . ":f>";
    }
    $content .= "\n" . $link;
    $payload = json_encode([
        'content' => $content,
        'allowed_mentions' => ['parse' => []],
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 3,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents($url, false, $ctx);
}

/** Sum live spectators across seeded tournament rooms. */
function tcgTournamentSpectatorCount(string $tournamentId): int {
    if (!function_exists('tcgLiveSpectatorCount')) {
        require_once __DIR__ . '/spectate.php';
    }
    $n = 0;
    foreach (tcgTournamentFetchMatches($tournamentId) as $m) {
        $rid = trim((string)($m['room_id'] ?? ''));
        if ($rid === '') {
            continue;
        }
        $n += tcgLiveSpectatorCount($rid);
    }
    return $n;
}

/**
 * Enforce rules_template against a locked deck snapshot.
 *
 * @param array<string,mixed> $snap
 * @throws Exception
 */
function tcgTournamentAssertRulesTemplate(string $template, array $snap, string $gameMode): void {
    $template = (string)$template;
    if ($template === 'starters_only') {
        // Legacy alias — starters are enforced by game_mode=starters.
        $template = 'standard';
    }
    $allowed = tcgTournamentRulesTemplatesForMode($gameMode);
    if (!in_array($template, $allowed, true)) {
        $template = 'standard';
    }
    if ($template === '' || $template === 'standard') {
        return;
    }
    require_once __DIR__ . '/cards_data.php';
    require_once __DIR__ . '/deck_validate.php';
    $main = is_array($snap['main_nos'] ?? null) ? $snap['main_nos'] : [];
    $energy = is_array($snap['energy_nos'] ?? null) ? $snap['energy_nos'] : [];
    $allNos = array_merge($main, $energy);
    if ($template === 'highlander') {
        $counts = [];
        foreach ($allNos as $no) {
            $no = (string)$no;
            $counts[$no] = ($counts[$no] ?? 0) + 1;
            if ($counts[$no] > 1) {
                throw new Exception('Highlander: only 1 copy of each card allowed (' . $no . ')', 400);
            }
        }
        return;
    }
    $cards = tcgLoadCardsData();
    $map = tcgBuildCardMap($cards);
    foreach ($allNos as $no) {
        $no = (string)$no;
        $card = $map[$no] ?? null;
        if (!$card) {
            throw new Exception('Unknown card in deck: ' . $no, 400);
        }
        $rarity = strtoupper(trim((string)($card['rarity'] ?? $card['rarity_en'] ?? '')));
        if ($template === 'pauper') {
            // Allow N / R / C / U / CL; reject SR+ / SEC / etc.
            if ($rarity !== '' && !in_array($rarity, ['N', 'R', 'C', 'U', 'CL'], true)) {
                throw new Exception('Pauper: card ' . $no . ' rarity ' . $rarity . ' not allowed', 400);
            }
        }
    }
}

function tcgTournamentBracketSize(int $n): int {
    $n = max(2, $n);
    $p = 1;
    while ($p < $n) {
        $p <<= 1;
    }
    return $p;
}

/**
 * @param list<string> $playerIds
 * @return list<array{p1:?string,p2:?string,bye:?string}>
 */
function tcgTournamentBuildRound1Pairings(array $playerIds): array {
    $playerIds = array_values(array_filter(array_map('strval', $playerIds), static fn($id) => $id !== ''));
    $n = count($playerIds);
    if ($n < 1) {
        return [];
    }
    $size = tcgTournamentBracketSize($n);
    $slots = array_fill(0, $size, null);
    for ($i = 0; $i < $n; $i++) {
        $slots[$i] = $playerIds[$i];
    }
    $pairings = [];
    for ($i = 0; $i < $size / 2; $i++) {
        $a = $slots[$i] ?? null;
        $b = $slots[$size - 1 - $i] ?? null;
        $bye = null;
        if ($a !== null && $b === null) {
            $bye = $a;
        } elseif ($b !== null && $a === null) {
            $bye = $b;
        }
        $pairings[] = ['p1' => $a, 'p2' => $b, 'bye' => $bye];
    }
    return $pairings;
}

/** @return list<int> */
function tcgTournamentPrizePercents(int $places): array {
    if ($places <= 1) {
        return [100];
    }
    if ($places === 2) {
        return [70, 30];
    }
    return [50, 30, 20];
}

function tcgTournamentAssertHost(array $row, string $uid): void {
    if ((string)($row['host_discord_id'] ?? '') !== $uid) {
        throw new Exception('Host only', 403);
    }
}

function tcgTournamentEnsureResultsColumn(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    tcgDbEnsureColumn(tcgDb(), 'tcg_tournaments', 'results_json', "TEXT NOT NULL DEFAULT ''");
}

/** Swiss cut note: swiss_omw | swiss_cut | null */
function tcgTournamentEnsureEntrantElimReasonColumn(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    tcgDbEnsureColumn(tcgDb(), 'tcg_tournament_entrants', 'elim_reason', "TEXT NOT NULL DEFAULT ''");
}

/**
 * @return array{prize_pool_total:int,finished_at:int,places:list<array{discord_id:string,place:int,coins:int}>}|null
 */
function tcgTournamentDecodeResults(mixed $raw): ?array {
    if (is_array($raw)) {
        $data = $raw;
    } else {
        $s = trim((string)$raw);
        if ($s === '') {
            return null;
        }
        $data = json_decode($s, true);
    }
    if (!is_array($data) || empty($data['places']) || !is_array($data['places'])) {
        return null;
    }
    $places = [];
    foreach ($data['places'] as $p) {
        if (!is_array($p)) {
            continue;
        }
        $did = trim((string)($p['discord_id'] ?? ''));
        $place = (int)($p['place'] ?? 0);
        if ($did === '' || $place < 1) {
            continue;
        }
        $places[] = [
            'discord_id' => $did,
            'place' => $place,
            'coins' => max(0, (int)($p['coins'] ?? 0)),
        ];
    }
    if ($places === []) {
        return null;
    }
    usort($places, static fn($a, $b) => $a['place'] <=> $b['place']);
    $out = [
        'prize_pool_total' => max(0, (int)($data['prize_pool_total'] ?? 0)),
        'finished_at' => max(0, (int)($data['finished_at'] ?? 0)),
        'places' => $places,
    ];
    if (is_array($data['pr_pack'] ?? null)) {
        $out['pr_pack'] = [
            'awarded' => !empty($data['pr_pack']['awarded']),
            'dropped' => !empty($data['pr_pack']['dropped']),
            'pack_size' => max(0, (int)($data['pr_pack']['pack_size'] ?? TCG_TOURNAMENT_PR_PACK_SIZE)),
        ];
    }
    return $out;
}

/**
 * @param list<array{discord_id:string,place:int,coins:int}> $places
 * @param array{awarded?:bool,dropped?:bool,pack_size?:int}|null $prPack
 * @return array{prize_pool_total:int,finished_at:int,places:list<array{discord_id:string,place:int,coins:int}>,pr_pack?:array{awarded:bool,dropped:bool,pack_size:int>}
 */
function tcgTournamentBuildResults(array $places, int $prizePoolTotal, ?int $finishedAt = null, ?array $prPack = null): array {
    $clean = [];
    foreach ($places as $p) {
        $did = trim((string)($p['discord_id'] ?? ''));
        $place = (int)($p['place'] ?? 0);
        if ($did === '' || $place < 1) {
            continue;
        }
        $clean[] = [
            'discord_id' => $did,
            'place' => $place,
            'coins' => max(0, (int)($p['coins'] ?? 0)),
        ];
    }
    usort($clean, static fn($a, $b) => $a['place'] <=> $b['place']);
    $out = [
        'prize_pool_total' => max(0, $prizePoolTotal),
        'finished_at' => $finishedAt ?? time(),
        'places' => $clean,
    ];
    if (is_array($prPack)) {
        $out['pr_pack'] = [
            'awarded' => !empty($prPack['awarded']),
            'dropped' => !empty($prPack['dropped']),
            'pack_size' => max(0, (int)($prPack['pack_size'] ?? TCG_TOURNAMENT_PR_PACK_SIZE)),
        ];
    }
    return $out;
}

/**
 * @param array{prize_pool_total:int,finished_at:int,places:list<array{discord_id:string,place:int,coins:int}>}|null $results
 * @return array{prize_pool_total:int,finished_at:int,winner:?array{discord_id:string,username:string,avatar_url:?string,place:int,coins:int},places:list<array{discord_id:string,username:string,avatar_url:?string,place:int,coins:int}>}|null
 */
function tcgTournamentEnrichResults(?array $results): ?array {
    if ($results === null || empty($results['places'])) {
        return null;
    }
    $ids = [];
    foreach ($results['places'] as $p) {
        $ids[] = (string)$p['discord_id'];
    }
    $ids = array_values(array_unique(array_filter($ids)));
    $users = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = tcgDb()->prepare(
            "SELECT discord_id, username, avatar_url FROM tcg_users WHERE discord_id IN ($placeholders)"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
            $users[(string)$u['discord_id']] = $u;
        }
    }
    $places = [];
    foreach ($results['places'] as $p) {
        $did = (string)$p['discord_id'];
        $u = $users[$did] ?? [];
        $places[] = [
            'discord_id' => $did,
            'username' => (string)($u['username'] ?? 'Player'),
            'avatar_url' => $u['avatar_url'] ?? null,
            'place' => (int)$p['place'],
            'coins' => (int)$p['coins'],
        ];
    }
    $winner = $places[0] ?? null;
    $out = [
        'prize_pool_total' => (int)$results['prize_pool_total'],
        'finished_at' => (int)$results['finished_at'],
        'winner' => $winner,
        'places' => $places,
    ];
    if (is_array($results['pr_pack'] ?? null)) {
        $out['pr_pack'] = [
            'awarded' => !empty($results['pr_pack']['awarded']),
            'dropped' => !empty($results['pr_pack']['dropped']),
            'pack_size' => max(0, (int)($results['pr_pack']['pack_size'] ?? TCG_TOURNAMENT_PR_PACK_SIZE)),
        ];
    }
    return $out;
}

/**
 * Finished events used to leave the champion as status "playing".
 * Normalize champion → winner and any leftover playing → eliminated.
 */
function tcgTournamentRepairFinishedEntrantStatuses(string $tournamentId, ?array $row = null): void {
    $tournamentId = strtoupper(trim($tournamentId));
    if ($tournamentId === '') {
        return;
    }
    $row = $row ?? tcgTournamentFetch($tournamentId);
    if (!$row || (string)($row['status'] ?? '') !== 'finished') {
        return;
    }
    $results = tcgTournamentResultsForRow($row);
    $winnerId = '';
    if (is_array($results) && is_array($results['winner'] ?? null)) {
        $winnerId = trim((string)($results['winner']['discord_id'] ?? ''));
    }
    if ($winnerId === '' && is_array($results) && !empty($results['places'][0]['discord_id'])) {
        $winnerId = trim((string)$results['places'][0]['discord_id']);
    }
    try {
        if ($winnerId !== '') {
            tcgDb()->prepare(
                'UPDATE tcg_tournament_entrants SET status = "winner"
                 WHERE tournament_id = ? AND discord_id = ?
                   AND status IN ("playing","eliminated")'
            )->execute([$tournamentId, $winnerId]);
        }
        tcgDb()->prepare(
            'UPDATE tcg_tournament_entrants SET status = "eliminated"
             WHERE tournament_id = ? AND status = "playing"'
        )->execute([$tournamentId]);
    } catch (Throwable $e) {
        // best-effort repair
    }
}

/** Reconstruct + persist results for finished events that predate results_json. */
function tcgTournamentBackfillResultsFromLedger(string $tournamentId): ?array {
    tcgTournamentEnsureResultsColumn();
    $row = tcgTournamentFetch($tournamentId);
    if (!$row || (string)($row['status'] ?? '') !== 'finished') {
        return null;
    }
    $existing = tcgTournamentDecodeResults($row['results_json'] ?? '');
    if ($existing) {
        return $existing;
    }
    $stmt = tcgDb()->prepare(
        'SELECT discord_id, amount, meta_json FROM tcg_tournament_ledger
         WHERE tournament_id = ? AND kind = ? ORDER BY created_at ASC'
    );
    $stmt->execute([$tournamentId, 'payout']);
    $places = [];
    $total = 0;
    $finishedAt = (int)($row['updated_at'] ?? time());
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $led) {
        $meta = json_decode((string)($led['meta_json'] ?? '{}'), true);
        $place = is_array($meta) ? (int)($meta['place'] ?? 0) : 0;
        $did = trim((string)($led['discord_id'] ?? ''));
        $amount = max(0, (int)($led['amount'] ?? 0));
        if ($did === '' || $place < 1) {
            continue;
        }
        $places[] = ['discord_id' => $did, 'place' => $place, 'coins' => $amount];
        $total += $amount;
    }
    if ($places === []) {
        return null;
    }
    $payload = tcgTournamentBuildResults($places, $total, $finishedAt);
    try {
        tcgDb()->prepare('UPDATE tcg_tournaments SET results_json = ? WHERE id = ?')
            ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}', $tournamentId]);
    } catch (Throwable $e) {
        // Still return decoded payload for this response.
    }
    return $payload;
}

/** @param array<string,mixed> $row */
function tcgTournamentResultsForRow(array $row): ?array {
    tcgTournamentEnsureResultsColumn();
    $decoded = tcgTournamentDecodeResults($row['results_json'] ?? '');
    if (!$decoded && (string)($row['status'] ?? '') === 'finished') {
        $decoded = tcgTournamentBackfillResultsFromLedger((string)($row['id'] ?? ''));
    }
    return tcgTournamentEnrichResults($decoded);
}

/**
 * Career summary + recent placements for social profile.
 *
 * @return array{
 *   match_wins:int,match_losses:int,coins_earned:int,events_played:int,
 *   placements:list<array{tournament_id:string,title:string,place:int,coins:int,finished_at:int,prize_pool_total:int}>
 * }
 */
function tcgTournamentProfileSummary(string $discordId, int $placementLimit = 8): array {
    $discordId = trim($discordId);
    $empty = [
        'match_wins' => 0,
        'match_losses' => 0,
        'coins_earned' => 0,
        'events_played' => 0,
        'placements' => [],
    ];
    if ($discordId === '') {
        return $empty;
    }
    tcgTournamentEnsureResultsColumn();
    $stats = function_exists('tcgTournamentStatsSummaryForUser')
        ? tcgTournamentStatsSummaryForUser($discordId, 0)
        : ['match_wins' => 0, 'match_losses' => 0, 'coins_earned' => 0];
    $played = 0;
    try {
        $c = tcgDb()->prepare(
            'SELECT COUNT(*) FROM tcg_tournament_entrants e
             INNER JOIN tcg_tournaments t ON t.id = e.tournament_id
             WHERE e.discord_id = ? AND t.status = ?'
        );
        $c->execute([$discordId, 'finished']);
        $played = (int)$c->fetchColumn();
    } catch (Throwable $e) {
        $played = 0;
    }
    $placementLimit = max(0, min(20, $placementLimit));
    $placements = [];
    if ($placementLimit > 0) {
        try {
            $stmt = tcgDb()->prepare(
                'SELECT t.id, t.title, t.updated_at, t.results_json
                 FROM tcg_tournament_entrants e
                 INNER JOIN tcg_tournaments t ON t.id = e.tournament_id
                 WHERE e.discord_id = ? AND t.status = ?
                 ORDER BY t.updated_at DESC
                 LIMIT ?'
            );
            $stmt->execute([$discordId, 'finished', $placementLimit * 3]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $trow) {
                $results = tcgTournamentResultsForRow($trow);
                if (!$results) {
                    continue;
                }
                $mine = null;
                foreach ($results['places'] as $p) {
                    if ((string)$p['discord_id'] === $discordId) {
                        $mine = $p;
                        break;
                    }
                }
                if (!$mine) {
                    continue;
                }
                $placements[] = [
                    'tournament_id' => (string)$trow['id'],
                    'title' => (string)($trow['title'] ?? 'Tournament'),
                    'place' => (int)$mine['place'],
                    'coins' => (int)$mine['coins'],
                    'finished_at' => (int)($results['finished_at'] ?: ($trow['updated_at'] ?? 0)),
                    'prize_pool_total' => (int)$results['prize_pool_total'],
                ];
                if (count($placements) >= $placementLimit) {
                    break;
                }
            }
        } catch (Throwable $e) {
            $placements = [];
        }
    }
    return [
        'match_wins' => (int)($stats['match_wins'] ?? 0),
        'match_losses' => (int)($stats['match_losses'] ?? 0),
        'coins_earned' => (int)($stats['coins_earned'] ?? 0),
        'events_played' => $played,
        'placements' => $placements,
    ];
}

/** @return array<string,mixed>|null */
function tcgTournamentFetch(string $id): ?array {
    tcgTournamentEnsureResultsColumn();
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_tournaments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** @return list<array<string,mixed>> */
function tcgTournamentFetchEntrants(string $tournamentId): array {
    $stmt = tcgDb()->prepare(
        'SELECT e.*, u.username, u.avatar_url
         FROM tcg_tournament_entrants e
         LEFT JOIN tcg_users u ON u.discord_id = e.discord_id
         WHERE e.tournament_id = ?
         ORDER BY CASE WHEN e.seed IS NULL THEN 9999 ELSE e.seed END, e.registered_at ASC'
    );
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string,mixed>> */
function tcgTournamentFetchMatches(string $tournamentId): array {
    $stmt = tcgDb()->prepare(
        'SELECT * FROM tcg_tournament_matches WHERE tournament_id = ?
         ORDER BY round ASC, bracket_slot ASC'
    );
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tcgTournamentLedgerWrite(
    string $tournamentId,
    ?string $discordId,
    string $kind,
    int $amount,
    string $idempotencyKey,
    array $meta = []
): bool {
    $db = tcgDb();
    try {
        $db->prepare(
            'INSERT INTO tcg_tournament_ledger
             (tournament_id, discord_id, kind, amount, idempotency_key, meta_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $tournamentId,
            $discordId,
            $kind,
            $amount,
            $idempotencyKey,
            json_encode($meta, JSON_UNESCAPED_UNICODE) ?: '{}',
            time(),
        ]);
        if (function_exists('tcgTournamentStatsOnLedger')) {
            tcgTournamentStatsOnLedger($kind, $discordId, $amount);
        }
        return true;
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'unique')) {
            return false;
        }
        throw $e;
    }
}

/**
 * @param array{slot?:int,starter?:string,experiment_slot?:int,experiment_password?:string}|null $choice
 * @return array{name:string,main_nos:list<string>,energy_nos:list<string>,sleeve_id:string,playmat_id:string,playmat_brightness:float,source:string,starter_key:string}
 */
function tcgTournamentDeckSnapshotForUser(string $discordId, string $gameMode, ?array $choice = null): array {
    require_once __DIR__ . '/ranked_room.php';
    require_once __DIR__ . '/booster.php';
    $gameMode = tcgNormalizeGameMode($gameMode);
    if (tcgIsRandomizedGameMode($gameMode)) {
        $cards = tcgLoadCardsData();
        $allCards = $cards['cards'] ?? [];
        $cardMap = tcgBuildCardMap($cards);
        $deck = tcgGenerateValidatedRandomDeckLists($allCards, $cardMap);
        if (!$deck) {
            throw new Exception('Could not generate a legal random deck', 400);
        }
        return [
            'name' => (string)$deck['name'],
            'main_nos' => $deck['main_nos'],
            'energy_nos' => $deck['energy_nos'],
            'sleeve_id' => '',
            'playmat_id' => '',
            'playmat_brightness' => 1.0,
            'source' => 'random',
            'starter_key' => '',
        ];
    }

    if (tcgIsFreeGameMode($gameMode)) {
        require_once __DIR__ . '/experiment_decks.php';
        $cards = tcgLoadCardsData();
        $expSlot = isset($choice['experiment_slot']) ? (int)$choice['experiment_slot'] : 0;
        $expPw = normalizeExperimentPassword((string)($choice['experiment_password'] ?? ''));
        $acctSlot = isset($choice['slot']) ? (int)$choice['slot'] : 0;

        if ($expPw !== '') {
            $resolved = resolveExperimentDeckFromPassword($expPw, $cards);
            return [
                'name' => (string)($resolved['deck_label'] ?? 'Experiment Deck'),
                'main_nos' => array_values(array_map('strval', $resolved['main_nos'] ?? [])),
                'energy_nos' => array_values(array_map('strval', $resolved['energy_nos'] ?? [])),
                'sleeve_id' => tcgNormalizeSleeveId($resolved['sleeve_id'] ?? ''),
                'playmat_id' => '',
                'playmat_brightness' => 1.0,
                'source' => 'experiment',
                'starter_key' => '',
            ];
        }
        if ($expSlot >= 1) {
            $db = tcgDb();
            $stmt = $db->prepare(
                'SELECT name, main_deck, energy_deck, sleeve_id, playmat_id, playmat_brightness
                 FROM tcg_experiment_presets WHERE discord_id = ? AND slot = ?'
            );
            $stmt->execute([$discordId, $expSlot]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Experiment deck not found', 404);
            }
            $main = json_decode((string)($row['main_deck'] ?? '[]'), true) ?: [];
            $energy = json_decode((string)($row['energy_deck'] ?? '[]'), true) ?: [];
            $validated = validateExperimentDeckPayload($main, $energy, $cards);
            return [
                'name' => normalizeExperimentDeckName((string)($row['name'] ?? ('Experiment ' . $expSlot))),
                'main_nos' => $validated['main'],
                'energy_nos' => $validated['energy'],
                'sleeve_id' => tcgNormalizeSleeveId($row['sleeve_id'] ?? ''),
                'playmat_id' => tcgNormalizePlaymatId($row['playmat_id'] ?? ''),
                'playmat_brightness' => tcgNormalizePlaymatBrightness($row['playmat_brightness'] ?? 1.0),
                'source' => 'experiment_preset',
                'starter_key' => '',
            ];
        }
        if ($acctSlot > 0) {
            $db = tcgDb();
            $stmt = $db->prepare(
                'SELECT name, main_deck, energy_deck, sleeve_id, playmat_id, playmat_brightness
                 FROM tcg_deck_presets WHERE discord_id = ? AND slot = ?'
            );
            $stmt->execute([$discordId, $acctSlot]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Deck not found', 404);
            }
            $main = array_values(array_map('strval', json_decode((string)($row['main_deck'] ?? '[]'), true) ?: []));
            $energy = array_values(array_map('strval', json_decode((string)($row['energy_deck'] ?? '[]'), true) ?: []));
            $cardMap = tcgBuildCardMap($cards);
            $owned = tcgGetCollectionMap($discordId);
            $v = tcgValidateDeckLists($main, $energy, $cardMap, $owned);
            if (!$v['valid']) {
                throw new Exception('Equipped deck is not legal: ' . implode('; ', $v['errors'] ?? ['invalid']), 400);
            }
            return [
                'name' => tcgNormalizeDeckPresetName($row['name'] ?? 'Tournament Deck'),
                'main_nos' => $main,
                'energy_nos' => $energy,
                'sleeve_id' => tcgNormalizeSleeveId($row['sleeve_id'] ?? ''),
                'playmat_id' => tcgNormalizePlaymatId($row['playmat_id'] ?? ''),
                'playmat_brightness' => tcgNormalizePlaymatBrightness($row['playmat_brightness'] ?? 1.0),
                'source' => 'preset',
                'starter_key' => '',
            ];
        }
        throw new Exception('Free tournaments need a Deck Experiment deck (preset or password) or a saved account deck', 400);
    }

    if (is_array($choice)) {
        $slot = isset($choice['slot']) ? (int)$choice['slot'] : 0;
        $starter = trim((string)($choice['starter'] ?? ''));
        if ($starter !== '') {
            if (!in_array($starter, tcgOwnedStarterKeys($discordId), true)) {
                throw new Exception('You do not own that starter deck', 400);
            }
            if ($gameMode === TCG_GAME_MODE_STARTERS || $gameMode === 'starters') {
                // ok
            }
            tcgSetRankedStarterEquip($discordId, $starter);
        } elseif ($slot > 0) {
            if ($gameMode === TCG_GAME_MODE_STARTERS || $gameMode === 'starters') {
                throw new Exception('Starters mode requires a starter deck', 400);
            }
            $db = tcgDb();
            $stmt = $db->prepare('SELECT slot FROM tcg_deck_presets WHERE discord_id = ? AND slot = ?');
            $stmt->execute([$discordId, $slot]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception('Deck not found', 404);
            }
            $db->prepare('UPDATE tcg_deck_presets SET equipped = 0 WHERE discord_id = ?')->execute([$discordId]);
            $db->prepare('UPDATE tcg_deck_presets SET equipped = 1 WHERE discord_id = ? AND slot = ?')
                ->execute([$discordId, $slot]);
            tcgClearRankedStarterEquip($discordId);
        }
    }

    $row = tcgGetEquippedDeckRow($discordId);
    if (!$row) {
        throw new Exception('Equip a deck before registering', 400);
    }
    if ($gameMode === TCG_GAME_MODE_STARTERS || $gameMode === 'starters') {
        if (($row['source'] ?? '') !== 'starter') {
            throw new Exception('Starters mode requires an equipped starter deck', 400);
        }
        $starterKey = trim((string)($row['starter_key'] ?? ''));
        if ($starterKey === '' || !in_array($starterKey, tcgOwnedStarterKeys($discordId), true)) {
            throw new Exception('Invalid starter deck', 400);
        }
    }

    $main = json_decode($row['main_deck'] ?? '[]', true) ?: [];
    $energy = json_decode($row['energy_deck'] ?? '[]', true) ?: [];
    $main = array_values(array_map('strval', $main));
    $energy = array_values(array_map('strval', $energy));
    $cards = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cards);
    $ownedCheck = (($row['source'] ?? '') === 'starter') ? null : tcgGetCollectionMap($discordId);
    $v = tcgValidateDeckLists($main, $energy, $cardMap, $ownedCheck);
    if (!$v['valid']) {
        tcgUnequipIllegalEquippedLoadout($discordId);
        throw new Exception('Equipped deck is not legal: ' . implode('; ', $v['errors'] ?? ['invalid']), 400);
    }

    return [
        'name' => tcgNormalizeDeckPresetName($row['name'] ?? 'Tournament Deck'),
        'main_nos' => $main,
        'energy_nos' => $energy,
        'sleeve_id' => tcgNormalizeSleeveId($row['sleeve_id'] ?? ''),
        'playmat_id' => tcgNormalizePlaymatId($row['playmat_id'] ?? ''),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($row['playmat_brightness'] ?? 1.0),
        'source' => (string)($row['source'] ?? 'preset'),
        'starter_key' => (string)($row['starter_key'] ?? ''),
    ];
}

/**
 * Decks the user can lock in for a tournament game mode.
 * @return array{success:bool,game_mode:string,randomized:bool,decks:list<array<string,mixed>>,needs_deck_builder:bool}
 */
function tcgTournamentEligibleDecksForUser(string $discordId, string $gameMode): array {
    require_once __DIR__ . '/booster.php';
    $gameMode = tcgNormalizeGameMode($gameMode);
    if (tcgIsRandomizedGameMode($gameMode)) {
        return [
            'success' => true,
            'game_mode' => $gameMode,
            'randomized' => true,
            'free' => false,
            'decks' => [],
            'needs_deck_builder' => false,
        ];
    }

    $decks = [];
    $cards = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cards);

    if (tcgIsFreeGameMode($gameMode)) {
        require_once __DIR__ . '/experiment_decks.php';
        $stmt = tcgDb()->prepare(
            'SELECT slot, name, main_deck, energy_deck FROM tcg_experiment_presets
             WHERE discord_id = ? ORDER BY slot ASC'
        );
        $stmt->execute([$discordId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $main = json_decode($row['main_deck'] ?? '[]', true) ?: [];
            $energy = json_decode($row['energy_deck'] ?? '[]', true) ?: [];
            try {
                validateExperimentDeckPayload($main, $energy, $cards);
            } catch (Throwable $e) {
                continue;
            }
            $decks[] = [
                'type' => 'experiment_preset',
                'slot' => (int)$row['slot'],
                'name' => normalizeExperimentDeckName((string)($row['name'] ?? ('Experiment ' . $row['slot']))),
            ];
        }
        $owned = tcgGetCollectionMap($discordId);
        $stmt = tcgDb()->prepare(
            'SELECT slot, name, main_deck, energy_deck, equipped FROM tcg_deck_presets
             WHERE discord_id = ? ORDER BY slot ASC'
        );
        $stmt->execute([$discordId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $main = json_decode($row['main_deck'] ?? '[]', true) ?: [];
            $energy = json_decode($row['energy_deck'] ?? '[]', true) ?: [];
            $v = tcgValidateDeckLists($main, $energy, $cardMap, $owned);
            if (!$v['valid']) {
                continue;
            }
            $decks[] = [
                'type' => 'preset',
                'slot' => (int)$row['slot'],
                'name' => tcgNormalizeDeckPresetName($row['name'] ?? 'Deck'),
                'equipped' => (int)($row['equipped'] ?? 0) === 1,
            ];
        }
        return [
            'success' => true,
            'game_mode' => $gameMode,
            'randomized' => false,
            'free' => true,
            'decks' => $decks,
            'needs_deck_builder' => count($decks) === 0,
            'allows_experiment_password' => true,
        ];
    }

    if ($gameMode === TCG_GAME_MODE_STARTERS || $gameMode === 'starters') {
        foreach (tcgOwnedStarterKeys($discordId) as $key) {
            $lists = tcgGetStarterDeckLists($key, $cards);
            $v = tcgValidateDeckLists($lists['main_deck'], $lists['energy_deck'], $cardMap, null);
            if (!$v['valid']) {
                continue;
            }
            $decks[] = [
                'type' => 'starter',
                'starter' => $key,
                'label' => tcgStarterLabel($key),
                'name' => (string)($lists['name'] ?? tcgStarterLabel($key)),
            ];
        }
    } else {
        $owned = tcgGetCollectionMap($discordId);
        $stmt = tcgDb()->prepare(
            'SELECT slot, name, main_deck, energy_deck, equipped FROM tcg_deck_presets
             WHERE discord_id = ? ORDER BY slot ASC'
        );
        $stmt->execute([$discordId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $main = json_decode($row['main_deck'] ?? '[]', true) ?: [];
            $energy = json_decode($row['energy_deck'] ?? '[]', true) ?: [];
            $v = tcgValidateDeckLists($main, $energy, $cardMap, $owned);
            if (!$v['valid']) {
                continue;
            }
            $decks[] = [
                'type' => 'preset',
                'slot' => (int)$row['slot'],
                'name' => tcgNormalizeDeckPresetName($row['name'] ?? 'Deck'),
                'equipped' => (int)($row['equipped'] ?? 0) === 1,
            ];
        }
        foreach (tcgOwnedStarterKeys($discordId) as $key) {
            $lists = tcgGetStarterDeckLists($key, $cards);
            $v = tcgValidateDeckLists($lists['main_deck'], $lists['energy_deck'], $cardMap, null);
            if (!$v['valid']) {
                continue;
            }
            $decks[] = [
                'type' => 'starter',
                'starter' => $key,
                'label' => tcgStarterLabel($key),
                'name' => (string)($lists['name'] ?? tcgStarterLabel($key)),
            ];
        }
    }

    return [
        'success' => true,
        'game_mode' => $gameMode,
        'randomized' => false,
        'free' => false,
        'decks' => $decks,
        'needs_deck_builder' => count($decks) === 0,
    ];
}

/** @param array<string,mixed> $row */
function tcgTournamentPublicRow(array $row, ?array $counts = null): array {
    $settings = tcgTournamentDecodeSettings($row['settings_json'] ?? '{}');
    $out = [
        'id' => (string)$row['id'],
        'host_discord_id' => (string)$row['host_discord_id'],
        'title' => (string)$row['title'],
        'status' => (string)$row['status'],
        'game_mode' => tcgNormalizeGameMode($row['game_mode'] ?? 'standard'),
        'start_at' => (int)$row['start_at'],
        'checkin_mins' => (int)$row['checkin_mins'],
        'min_players' => (int)$row['min_players'],
        'max_players' => (int)$row['max_players'],
        'entry_fee_coins' => (int)$row['entry_fee_coins'],
        'prize_pool_coins' => (int)$row['prize_pool_coins'],
        'settings' => $settings,
        'created_at' => (int)$row['created_at'],
        'updated_at' => (int)$row['updated_at'],
    ];
    if ($counts !== null) {
        $out['entrant_count'] = (int)($counts['total'] ?? 0);
        $out['checked_in_count'] = (int)($counts['checked_in'] ?? 0);
    }
    $results = tcgTournamentResultsForRow($row);
    if ($results !== null) {
        $out['results'] = $results;
        // Prefer stored prize total after finish (live pool is zeroed on payout).
        if ((string)$row['status'] === 'finished' && (int)$out['prize_pool_coins'] === 0) {
            $out['prize_pool_total'] = (int)$results['prize_pool_total'];
        }
    }
    $pr = tcgTournamentPrPackPublic($settings, is_array($results) ? $results : null);
    if ($pr !== null) {
        $out['pr_pack'] = $pr;
    }
    return $out;
}

/** @param array<string,mixed> $e */
function tcgTournamentPublicEntrant(array $e, bool $includeDeck = false): array {
    $out = [
        'discord_id' => (string)$e['discord_id'],
        'username' => (string)($e['username'] ?? 'Player'),
        'avatar_url' => $e['avatar_url'] ?? null,
        'status' => (string)$e['status'],
        'seed' => isset($e['seed']) && $e['seed'] !== null ? (int)$e['seed'] : null,
        'paid_coins' => (int)($e['paid_coins'] ?? 0),
        'registered_at' => (int)($e['registered_at'] ?? 0),
        'checked_in_at' => isset($e['checked_in_at']) && $e['checked_in_at'] !== null
            ? (int)$e['checked_in_at'] : null,
    ];
    $reason = trim((string)($e['elim_reason'] ?? ''));
    if ($reason !== '') {
        $out['elim_reason'] = $reason;
    }
    if ($includeDeck) {
        $snap = json_decode((string)($e['deck_snapshot'] ?? '{}'), true);
        $out['deck_snapshot'] = is_array($snap) ? $snap : null;
    }
    return $out;
}

/** @param array<string,mixed> $m
 * @param array<string,int>|null $roomToReplayId optional room_id => replay id map
 */
function tcgTournamentPublicMatch(array $m, ?array $roomToReplayId = null): array {
    $meta = function_exists('tcgTournamentDecodeMatchMeta')
        ? tcgTournamentDecodeMatchMeta($m['meta_json'] ?? '{}')
        : ['p1_wins' => 0, 'p2_wins' => 0, 'best_of' => 1, 'games' => []];
    $gamesOut = [];
    foreach (($meta['games'] ?? []) as $g) {
        if (!is_array($g)) {
            continue;
        }
        $rid = isset($g['room_id']) && $g['room_id'] !== '' ? (string)$g['room_id'] : null;
        $replayId = isset($g['replay_id']) ? (int)$g['replay_id'] : 0;
        $gamesOut[] = [
            'room_id' => $rid,
            'winner_discord_id' => isset($g['winner_discord_id']) && $g['winner_discord_id'] !== ''
                ? (string)$g['winner_discord_id'] : null,
            'replay_id' => $replayId > 0 ? $replayId : null,
            'game_index' => isset($g['game_index']) ? (int)$g['game_index'] : null,
            'at' => isset($g['at']) ? (int)$g['at'] : null,
        ];
    }
    // Preserve positional game index when meta omitted it (Watch G1 / G2 labels).
    foreach ($gamesOut as $i => &$gRow) {
        if (empty($gRow['game_index'])) {
            $gRow['game_index'] = $i + 1;
        }
    }
    unset($gRow);
    if ($roomToReplayId === null
        && function_exists('tcgTournamentReplayIdsByRoom')
        && ($m['tournament_id'] ?? '') !== '') {
        $roomToReplayId = tcgTournamentReplayIdsByRoom((string)$m['tournament_id']);
    }
    if (is_array($roomToReplayId) && $roomToReplayId !== []
        && function_exists('tcgTournamentHydratePublicGames')) {
        $gamesOut = tcgTournamentHydratePublicGames($gamesOut, $roomToReplayId);
    }
    return [
        'id' => (string)$m['id'],
        'tournament_id' => (string)$m['tournament_id'],
        'round' => (int)$m['round'],
        'bracket_slot' => (int)$m['bracket_slot'],
        'bracket_side' => (string)($m['bracket_side'] ?? 'winners'),
        'p1_discord_id' => $m['p1_discord_id'] !== null ? (string)$m['p1_discord_id'] : null,
        'p2_discord_id' => $m['p2_discord_id'] !== null ? (string)$m['p2_discord_id'] : null,
        'room_id' => $m['room_id'] !== null && $m['room_id'] !== '' ? (string)$m['room_id'] : null,
        'status' => (string)$m['status'],
        'winner_discord_id' => $m['winner_discord_id'] !== null ? (string)$m['winner_discord_id'] : null,
        'connect_deadline_at' => isset($m['connect_deadline_at']) && $m['connect_deadline_at'] !== null
            ? (int)$m['connect_deadline_at'] : null,
        'best_of' => (int)($meta['best_of'] ?? 1),
        'p1_wins' => (int)($meta['p1_wins'] ?? 0),
        'p2_wins' => (int)($meta['p2_wins'] ?? 0),
        'games' => $gamesOut,
    ];
}

/**
 * Pick the bulletin hero tournament: busiest running event, else check-in/open.
 *
 * @param list<array<string,mixed>> $list Public tournament rows with entrant_count.
 * @return array<string,mixed>|null
 */
function tcgTournamentPickBulletinFeatured(array $list): ?array {
    if ($list === []) {
        return null;
    }
    $rankStatus = static function (string $status): int {
        return match ($status) {
            'running' => 0,
            'checkin' => 1,
            'open' => 2,
            default => 9,
        };
    };
    $best = null;
    $bestKey = null;
    foreach ($list as $row) {
        $status = (string)($row['status'] ?? '');
        $sr = $rankStatus($status);
        if ($sr >= 9) {
            continue;
        }
        $players = (int)($row['entrant_count'] ?? 0);
        $start = (int)($row['start_at'] ?? 0);
        // Prefer higher status priority, then more players, then sooner start.
        $key = sprintf('%d-%06d-%010d', $sr, 999999 - $players, $start);
        if ($bestKey === null || $key < $bestKey) {
            $bestKey = $key;
            $best = $row;
        }
    }
    return $best;
}

/**
 * Human-readable current round / phase for the bulletin hero.
 *
 * @param list<array<string,mixed>> $matches Raw or public match rows.
 * @return array{phase:string,summary:string,round:?int,side:?string,live_matches:int,ready_matches:int}
 */
function tcgTournamentBulletinProgress(string $status, array $settings, array $matches): array {
    $format = (string)($settings['format'] ?? 'single_elim');
    if ($status === 'open') {
        return [
            'phase' => 'open',
            'summary' => 'Registration open',
            'round' => null,
            'side' => null,
            'live_matches' => 0,
            'ready_matches' => 0,
        ];
    }
    if ($status === 'checkin') {
        return [
            'phase' => 'checkin',
            'summary' => 'Check-in open',
            'round' => null,
            'side' => null,
            'live_matches' => 0,
            'ready_matches' => 0,
        ];
    }
    $live = 0;
    $ready = 0;
    $focus = null;
    $focusPri = 99;
    foreach ($matches as $m) {
        $st = (string)($m['status'] ?? '');
        if ($st === 'live') {
            $live++;
        } elseif ($st === 'ready') {
            $ready++;
        }
        if (!in_array($st, ['pending', 'ready', 'live'], true)) {
            continue;
        }
        $pri = match ($st) {
            'live' => 0,
            'ready' => 1,
            default => 2,
        };
        $round = (int)($m['round'] ?? 1);
        $side = (string)($m['bracket_side'] ?? 'winners');
        $candPri = ($pri * 1000) + $round;
        if ($side === 'losers') {
            $candPri += 50;
        } elseif ($side === 'grand_final') {
            $candPri += 80;
        }
        if ($candPri < $focusPri) {
            $focusPri = $candPri;
            $focus = $m;
        }
    }
    if ($focus === null) {
        return [
            'phase' => 'running',
            'summary' => $matches === [] ? 'Bracket starting' : 'Wrapping up',
            'round' => null,
            'side' => null,
            'live_matches' => $live,
            'ready_matches' => $ready,
        ];
    }
    $side = (string)($focus['bracket_side'] ?? 'winners');
    $round = (int)($focus['round'] ?? 1);
    $same = array_values(array_filter(
        $matches,
        static function ($m) use ($side, $round) {
            return (string)($m['bracket_side'] ?? 'winners') === $side
                && (int)($m['round'] ?? 0) === $round
                && in_array((string)($m['status'] ?? ''), ['pending', 'ready', 'live'], true);
        }
    ));
    $slotCount = max(1, count($same));
    $summary = tcgTournamentBulletinRoundLabel($side, $round, $slotCount, $format);
    if ($live > 0) {
        $summary .= ' · ' . $live . ' live';
    } elseif ($ready > 0) {
        $summary .= ' · ' . $ready . ' ready';
    }
    return [
        'phase' => 'running',
        'summary' => $summary,
        'round' => $round,
        'side' => $side,
        'live_matches' => $live,
        'ready_matches' => $ready,
    ];
}

function tcgTournamentBulletinRoundLabel(string $side, int $round, int $slotCount, string $format): string {
    if ($side === 'swiss') {
        return 'Swiss · Round ' . max(1, $round);
    }
    if ($side === 'losers') {
        return $slotCount === 1 ? 'Losers Final' : ('Losers · R' . max(1, $round));
    }
    if ($side === 'grand_final') {
        return $round >= 2 ? 'Grand Final (Reset)' : 'Grand Final';
    }
    if ($slotCount === 1) {
        return $format === 'swiss' ? 'Final' : 'Winners Final';
    }
    if ($slotCount === 2) {
        return 'Semifinals';
    }
    return 'Round of ' . ($slotCount * 2);
}

