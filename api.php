<?php
/**
 * Love Live! Official Card Game (Loveca) — game server API.
 *
 * Authoritative rules engine for PvP, CPU, ranked, casual, tutorial build, and debug
 * harnesses. Persists each match as JSON under tcg/games/; includes effects.php for
 * ability resolution. The browser client (index.html) long-polls get_state and posts
 * discrete actions (play_member, set_live_cards, resolve_prompt, etc.).
 *
 * Turn flow (simplified): setup/mulligan -> coin flip -> Main (both players) ->
 * LIVE Phase (face-down Live storage) -> Performance (Yell, hearts, Live success) ->
 * Live Win/Loss Check -> next turn. Phase names live in $state['phase'].
 *
 * Endpoints:
 *   POST create_room, join_room
 *   GET  get_state (long-poll; response passed through filterStateForPlayer)
 *   POST action (applyAction switch — main game input)
 *   GET  get_cards, preview_random_deck
 *   POST cache_card_image, experiment_deck_*, debug_card_test_start, replay_export, replay_start, replay_goto
 *   POST casual_join|leave|status, ping, cleanup
 *
 * Define TCG_API_LIB_ONLY before require to load rules without HTTP router (CLI/tutorial).
 */

require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/config/errors.php';
require_once __DIR__ . '/config/rate_limit.php';
require_once __DIR__ . '/cards_data.php';
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Hostinger may omit vendor; PSR-4 fallback for Game\Store.
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'LLTCG\\')) {
            return;
        }
        $rel = str_replace('\\', '/', substr($class, 6)) . '.php';
        $path = __DIR__ . '/src/' . $rel;
        if (is_file($path)) {
            require_once $path;
        }
    });
}
tcgDefinePathConstants();

header('Content-Type: application/json');
tcgSendCorsHeaders();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Player-Token, X-Auth-Token, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    tcgSendCorsPreflight('GET, POST, OPTIONS', 'Content-Type, X-Player-Token, X-Auth-Token, Authorization');
    http_response_code(200);
    exit;
}

define('ENERGY_ZONE_MAX', 12);

function tcgRequireAuthLoader(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $path = __DIR__ . '/llr_auth_load.php';
    if (!is_file($path)) {
        throw new Exception(
            'Saved deck presets are unavailable on the server (missing auth loader). '
            . 'Use a Basic deck from the lobby dropdown, or contact the site operator.'
        );
    }
    require_once $path;
    $loaded = true;
}
require_once __DIR__ . '/sleeves.php';
require_once __DIR__ . '/playmats.php';
require_once __DIR__ . '/effects.php';
require_once __DIR__ . '/stamps.php';
require_once __DIR__ . '/cardimg_cache.php';
require_once __DIR__ . '/deckgen.php';
require_once __DIR__ . '/experiment_decks.php';
require_once __DIR__ . '/debug_card_test.php';
require_once __DIR__ . '/replay.php';
require_once __DIR__ . '/casual_matchmaking.php';
require_once __DIR__ . '/spectate.php';
require_once __DIR__ . '/tcg_sync.php';
define('LOCK_TIMEOUT', 5);      // seconds
define('GAME_TIMEOUT', 3600);   // 1 hour inactivity = cleanup
define('POLL_TIMEOUT', 25);     // long-poll seconds
define('PRESENCE_DISCONNECT_SEC', 210); // PvP: forfeit if opponent idle this long (wider under Hostinger load)
define('PRESENCE_NO_SHOW_SEC', 300);    // Ranked: forfeit if opponent never connected
define('PHASE_TIMER_SEC', 60);  // default when room host enables phase timer
define('PHASE_TIMER_MIN', 10);
define('PHASE_TIMER_MAX', 120);

if (!is_dir(GAMES_DIR)) {
    mkdir(GAMES_DIR, 0755, true);
}

// CLI tools (build_tutorial.php) include this file for game logic only.
if (defined('TCG_API_LIB_ONLY')) {
    return;
}

// ─────────────────────────────────────────────
// Router
// ─────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? 'ping';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

/**
 * The match host owns live rooms (Redis game store). Hostinger-only config that
 * leaks into its deploy — e.g. a tracked .htaccess honored under AllowOverride All —
 * must never be able to disable writes on the host serving the rooms.
 */
function tcgIsMatchHost(): bool {
    $store = getenv('TCG_GAME_STORE');
    if ($store === false || $store === '') {
        $store = $_SERVER['TCG_GAME_STORE'] ?? '';
    }
    return strtolower(trim((string)$store)) === 'redis';
}

/**
 * Part 1D: when match-primary cutover is live, Hostinger must not accept room writes.
 * Default allows writes. Disable via:
 * - env TCG_HOSTINGER_MATCH_WRITES=0 (or false/off/no)
 * - marker file MATCH_WRITES_DISABLED next to api.php (Hostinger-friendly, like USE_VPS_API)
 * Both are ignored on the match host itself (see tcgIsMatchHost).
 */
function tcgHostingerMatchWritesEnabled(): bool {
    if (tcgIsMatchHost()) {
        return true;
    }
    if (is_file(__DIR__ . '/MATCH_WRITES_DISABLED')) {
        return false;
    }
    $raw = getenv('TCG_HOSTINGER_MATCH_WRITES');
    if ($raw === false || $raw === '') {
        // Apache SetEnv / .user.ini often only populates $_SERVER
        $raw = $_SERVER['TCG_HOSTINGER_MATCH_WRITES'] ?? '';
    }
    if ($raw === false || $raw === '') {
        return true;
    }
    $v = strtolower(trim((string)$raw));
    return !in_array($v, ['0', 'false', 'off', 'no'], true);
}

function tcgIsHostingerMatchWriteAction(string $action): bool {
    static $blocked = [
        'create_room' => true,
        'join_room' => true,
        'casual_join' => true,
        'casual_leave' => true,
        'action' => true,
        'dry_run_actions' => true,
        'debug_card_test_start' => true,
    ];
    return isset($blocked[$action]);
}

/**
 * Drain-only: Hostinger may still accept action for legacy ranked rooms that were
 * created locally (no match_api=overflow). New ranked rooms live on VPS Redis.
 */
function tcgHostingerRankedActionAllowed(string $action, array $body): bool {
    if ($action !== 'action' && $action !== 'dry_run_actions') {
        return false;
    }
    $roomId = strtoupper(trim((string)($body['room_id'] ?? '')));
    if ($roomId === '') {
        return false;
    }
    try {
        $state = loadGame($roomId);
    } catch (Throwable $e) {
        return false;
    }
    if (!is_array($state) || (($state['mode'] ?? '') !== 'ranked')) {
        return false;
    }
    // VPS-seeded rooms must not be playable on Hostinger.
    if (($state['ranked']['match_api'] ?? '') === 'overflow') {
        return false;
    }
    return true;
}

try {
    if (!tcgHostingerMatchWritesEnabled() && tcgIsHostingerMatchWriteAction($action)
        && !tcgHostingerRankedActionAllowed($action, $body)) {
        http_response_code(503);
        echo json_encode([
            'error' => 'Match writes disabled on this host. Use the VPS match API (stream.loveliveradio.ca/tcg/api).',
            'code' => 'match_writes_disabled',
            'match_api' => 'https://stream.loveliveradio.ca/tcg/api',
        ]);
        return;
    }
    switch ($action) {
        case 'create_room':  echo json_encode(createRoom($body));    break;
        case 'join_room':    echo json_encode(joinRoom($body));       break;
        case 'get_state':    getStatePolling();                        break;
        case 'action':       echo json_encode(handleAction($body));   break;
        case 'dry_run_actions': echo json_encode(handleDryRunActions($body)); break;
        case 'get_cards':    sendCards();                              break;
        case 'preview_random_deck': echo json_encode(previewRandomDeck(
            CARDS_FILE,
            trim((string)($_GET['group'] ?? $body['group'] ?? '')) ?: null
        )); break;
        case 'cache_card_image': echo json_encode(cacheCardImage($body)); break;
        case 'experiment_deck_save': echo json_encode(apiExperimentDeckSave($body)); break;
        case 'experiment_deck_load': echo json_encode(apiExperimentDeckLoad($body)); break;
        case 'experiment_decklog_import': echo json_encode(apiExperimentDecklogImport($body)); break;
        case 'experiment_random_deck': echo json_encode(apiExperimentRandomDeck($body)); break;
        case 'debug_card_test_start': echo json_encode(apiDebugCardTestStart($body)); break;
        case 'replay_export': echo json_encode(apiReplayExport($body)); break;
        case 'replay_start':  echo json_encode(apiReplayStart($body)); break;
        case 'replay_goto':   echo json_encode(apiReplayGoto($body)); break;
        case 'casual_join':  echo json_encode(apiCasualJoin($body)); break;
        case 'casual_leave': echo json_encode(apiCasualLeave($body)); break;
        case 'casual_status': echo json_encode(apiCasualStatus($body)); break;
        case 'spectate_list': echo json_encode(apiSpectateList($body)); break;
        case 'spectate_join': echo json_encode(apiSpectateJoin($body)); break;
        case 'spectate_leave': echo json_encode(apiSpectateLeave($body)); break;
        case 'ping':         echo json_encode(ping($body));            break;
        case 'sync_ticket':  echo json_encode(apiSyncTicket($body));    break;
        case 'seed_ranked_room': echo json_encode(apiSeedRankedRoom($body)); break;
        case 'cleanup':      echo json_encode(cleanupOldGames());      break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    $code = tcgHttpStatusForThrowable($e);
    tcgLogServerFault('api.php:' . $action, $e, $code);
    http_response_code($code);
    echo json_encode(tcgPublicErrorPayload($e, $code));
}

// ─────────────────────────────────────────────
// Card Data
// ─────────────────────────────────────────────

/**
 * The catalog is ~2MB per locale and only changes on deploy, but it shipped with no
 * validators, so every page load and every locale switch re-sent all of it and the
 * CDN marked it DYNAMIC. ETag + max-age lets repeat loads come from cache instead of
 * the PHP pool.
 */
function sendCards(): void {
    $payload = getCards();
    $mtime = file_exists(CARDS_FILE) ? (int)filemtime(CARDS_FILE) : 0;
    $etag = '"cards-' . $mtime . '-' . strlen($payload) . '"';

    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=300, stale-while-revalidate=86400');
    if ($mtime > 0) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    }

    $ifNoneMatch = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    foreach (explode(',', $ifNoneMatch) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && ($candidate === $etag || $candidate === 'W/' . $etag)) {
            http_response_code(304);
            return;
        }
    }
    echo $payload;
}

function getCards(): string {
    if (!file_exists(CARDS_FILE)) {
        return json_encode(['cards' => [], 'starter_decks' => []]);
    }

    // Optional locale trim: keep English `text` + one locale body (text_jp/es/ko/zh/th/pt).
    // Full multi-locale cards.json is ~4MB; trimming unused oracle text avoids client
    // fetch timeouts that leave G.allCards empty and grey out deck Save.
    $locale = strtolower(trim((string)($_GET['locale'] ?? '')));
    if ($locale === '' || $locale === 'all') {
        $rawAll = file_get_contents(CARDS_FILE);
        return ($rawAll !== false && $rawAll !== '') ? $rawAll : json_encode(['cards' => [], 'starter_decks' => []]);
    }
    if (!in_array($locale, ['en', 'ja', 'es', 'ko', 'zh', 'th', 'pt'], true)) {
        $locale = 'en';
    }

    $cardsMtime = (int)filemtime(CARDS_FILE);
    $cacheFile = rtrim(TCG_DATA_DIR, '/\\') . DIRECTORY_SEPARATOR . 'cards_cache_' . $locale . '.json';
    // Cache hit: never read the 4MB source. Concurrent boots used to stampede the
    // PHP pool by each decoding cards.json before noticing the cache existed.
    if (is_file($cacheFile) && (int)filemtime($cacheFile) >= $cardsMtime) {
        $cached = file_get_contents($cacheFile);
        if ($cached !== false && $cached !== '') {
            return $cached;
        }
    }

    $rebuild = static function () use ($cacheFile, $cardsMtime, $locale): string {
        if (is_file($cacheFile) && (int)filemtime($cacheFile) >= $cardsMtime) {
            $cached = file_get_contents($cacheFile);
            if ($cached !== false && $cached !== '') {
                return $cached;
            }
        }
        $raw = file_get_contents(CARDS_FILE);
        if ($raw === false || $raw === '') {
            return json_encode(['cards' => [], 'starter_decks' => []]);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['cards']) || !is_array($data['cards'])) {
            return $raw;
        }

        $keepKeys = ['text'];
        if ($locale === 'ja') {
            $keepKeys[] = 'text_jp';
        } elseif ($locale !== 'en') {
            $keepKeys[] = 'text_' . $locale;
        }
        $dropKeys = ['text_jp', 'text_es', 'text_ko', 'text_zh', 'text_th', 'text_pt'];

        foreach ($data['cards'] as &$card) {
            if (!is_array($card)) {
                continue;
            }
            foreach ($dropKeys as $k) {
                if (!in_array($k, $keepKeys, true)) {
                    unset($card[$k]);
                }
            }
        }
        unset($card);

        $out = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($out === false) {
            return $raw;
        }

        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $out, LOCK_EX) !== false) {
            @rename($tmp, $cacheFile);
        } else {
            @unlink($tmp);
        }
        return $out;
    };

    $lockPath = $cacheFile . '.lock';
    $lock = @fopen($lockPath, 'c+');
    if ($lock && flock($lock, LOCK_EX)) {
        try {
            return $rebuild();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    return $rebuild();
}

function cacheCardImage(array $body): array {
    tcgRateLimitForAction('cache_card_image', $body);
    $cardNo = trim((string)($body['card_no'] ?? ''));
    if ($cardNo === '') {
        throw new InvalidArgumentException('card_no required');
    }
    $url = lookupCardImageUrl($cardNo);
    if ($url === '') {
        throw new InvalidArgumentException('Unknown card_no or missing image in cards.json');
    }
    return cacheCardImageFromUrl($cardNo, $url);
}

// ─────────────────────────────────────────────
// Room Management
// ─────────────────────────────────────────────
function resolveRoomDeckLists(array $body, array $cards): array {
    require_once __DIR__ . '/game_mode.php';
    if (tcgIsRandomizedGameMode($body['game_mode'] ?? '')) {
        $body['deck'] = 'random';
        unset($body['deck_group'], $body['experiment_password'], $body['experiment_preset'], $body['main_deck'], $body['energy_deck']);
    }
    $deckChoice = (string)($body['deck'] ?? 'nijigasaki');
    if ($deckChoice === 'cpu') {
        $diff = (string)($body['cpu_difficulty'] ?? 'easy');
        $hint = trim((string)($body['cpu_group_hint'] ?? '')) ?: null;
        return resolveCpuDeckLists($cards, $diff, $hint);
    }
    tcgAssertUnrankedDeckForGameMode($body);
    if ($deckChoice === 'experiment' || preg_match('/^experiment:[A-Z0-9]+$/i', $deckChoice)) {
        return resolveExperimentDeckLists($body, $cards);
    }
    $expSlot = 0;
    if ($deckChoice === 'experiment_preset') {
        $expSlot = intval($body['experiment_slot'] ?? $body['deck_slot'] ?? 0);
    } elseif (preg_match('/^experiment_preset:(\d+)$/', $deckChoice, $m)) {
        $expSlot = intval($m[1]);
        $deckChoice = 'experiment_preset';
    }
    if ($deckChoice === 'experiment_preset') {
        assertExperimentAllowedForRoom($body);
        return resolveExperimentPresetDeckLists($body, $cards, $expSlot);
    }
    $slot = 0;
    if ($deckChoice === 'preset') {
        $slot = intval($body['deck_slot'] ?? 0);
    } elseif (preg_match('/^preset:(\d+)$/', $deckChoice, $m)) {
        $slot = intval($m[1]);
        $deckChoice = 'preset';
    }
    if ($deckChoice === 'preset') {
        return resolveAccountPresetDeckLists($body, $cards, $slot);
    }
    $deckGroup = trim((string)($body['deck_group'] ?? '')) ?: null;
    return resolvePlayerDeckLists($cards, $deckChoice, $deckGroup);
}

function resolveAccountPresetDeckLists(array $body, array $cards, int $slot): array {
    if ($slot < 1 || $slot > 10) {
        throw new Exception('Deck preset slot must be 1–10');
    }
    require_once __DIR__ . '/deck_validate.php';

    $main = $body['main_deck'] ?? null;
    $energy = $body['energy_deck'] ?? null;
    // Prefer inline lists so match-primary (VPS) works when presets live on Hostinger SQLite.
    if (is_array($main) && is_array($energy)) {
        require_once __DIR__ . '/booster.php';
        $main = array_values(array_map('strval', $main));
        $energy = array_values(array_map('strval', $energy));
        $cardMap = tcgBuildCardMap($cards);
        // Catalog legality first (no Hostinger collection on VPS).
        $validation = tcgValidateDeckLists($main, $energy, $cardMap, null);
        if (!$validation['valid']) {
            throw new Exception('Preset deck is invalid: ' . implode('; ', $validation['errors']));
        }
        tcgRequireAuthLoader();
        // Auth still required so the seat is tied to a signed-in player.
        tcgRequireAuthUser($body);
        $label = trim((string)($body['deck_label'] ?? ''));
        if ($label === '') {
            $label = 'Deck ' . $slot;
        }
        return [
            'deck_choice' => 'preset:' . $slot,
            'deck_label'  => tcgNormalizeDeckPresetName($label),
            'main_nos'    => $main,
            'energy_nos'  => $energy,
            'sleeve_id'   => tcgNormalizeSleeveId($body['sleeve_id'] ?? ''),
            'playmat_id'  => tcgNormalizePlaymatId($body['playmat_id'] ?? ''),
            'playmat_brightness' => tcgNormalizePlaymatBrightness($body['playmat_brightness'] ?? 1.0),
        ];
    }

    tcgRequireAuthLoader();
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/booster.php';

    $uid = tcgRequireAuthUser($body);
    $db = tcgDb();
    $stmt = $db->prepare('SELECT name, main_deck, energy_deck, sleeve_id, playmat_id, playmat_brightness FROM tcg_deck_presets WHERE discord_id = ? AND slot = ?');
    $stmt->execute([$uid, $slot]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Deck preset #' . $slot . ' not found');
    }
    $main = json_decode($row['main_deck'], true) ?: [];
    $energy = json_decode($row['energy_deck'], true) ?: [];
    $cardMap = tcgBuildCardMap($cards);
    $owned = tcgGetCollectionMap($uid);
    $validation = tcgValidateDeckLists($main, $energy, $cardMap, $owned);
    if (!$validation['valid']) {
        throw new Exception('Preset deck is invalid: ' . implode('; ', $validation['errors']));
    }
    return [
        'deck_choice' => 'preset:' . $slot,
        'deck_label'  => tcgNormalizeDeckPresetName($row['name'] ?? ('Deck ' . $slot)),
        'main_nos'    => $main,
        'energy_nos'  => $energy,
        'sleeve_id'   => tcgNormalizeSleeveId($row['sleeve_id'] ?? ''),
        'playmat_id'  => tcgNormalizePlaymatId($row['playmat_id'] ?? ''),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($row['playmat_brightness'] ?? 1.0),
    ];
}

/** Deck Experiment decks (password / inline lists / account preset) — Free Mode only. */
function resolveExperimentDeckLists(array $body, array $cardsData): array {
    assertExperimentAllowedForRoom($body);

    $password = normalizeExperimentPassword((string)($body['experiment_password'] ?? ''));
    if ($password === '' && preg_match('/^experiment:([A-Z0-9]+)$/i', (string)($body['deck'] ?? ''), $m)) {
        $password = normalizeExperimentPassword($m[1]);
    }

    // Prefer inline lists so match-primary (VPS) works when presets/files live on Hostinger.
    $main = $body['main_deck'] ?? null;
    $energy = $body['energy_deck'] ?? null;
    if (is_array($main) && is_array($energy)) {
        $validated = validateExperimentDeckPayload($main, $energy, $cardsData);
        $label = normalizeExperimentDeckName((string)($body['deck_label'] ?? ''));
        $slot = intval($body['experiment_slot'] ?? $body['deck_slot'] ?? 0);
        $deckChoice = 'experiment';
        if ($slot >= 1 && $slot <= TCG_MAX_EXPERIMENT_PRESETS) {
            $deckChoice = 'experiment_preset:' . $slot;
            if ($label === '' || $label === 'Deck Experiment') {
                $label = 'Experiment ' . $slot;
            }
        } elseif ($password !== '') {
            $deckChoice = 'experiment:' . $password;
        }
        return [
            'deck_choice' => $deckChoice,
            'deck_label'  => $label !== '' ? $label : 'Deck Experiment',
            'main_nos'    => $validated['main'],
            'energy_nos'  => $validated['energy'],
            'sleeve_id'   => tcgNormalizeSleeveId($body['sleeve_id'] ?? ''),
            'playmat_id'  => tcgNormalizePlaymatId($body['playmat_id'] ?? ''),
            'playmat_brightness' => tcgNormalizePlaymatBrightness($body['playmat_brightness'] ?? 1.0),
        ];
    }

    if ($password !== '') {
        return resolveExperimentDeckFromPassword($password, $cardsData);
    }

    throw new Exception('Experiment deck requires experiment_password or main_deck and energy_deck');
}

function createRoom(array $body): array {
    tcgRateLimitForAction('create_room', $body);
    tcgRequireAuthLoader();
    $roomId    = strtoupper(bin2hex(random_bytes(4)));
    $playerToken = generateToken();
    $playerName  = htmlspecialchars($body['name'] ?? 'Player 1', ENT_QUOTES);

    $cards = tcgLoadCardsData();
    $resolved  = resolveRoomDeckLists($body, $cards);

    $mainDeck   = buildDeckForRoom($cards['cards'], $resolved['main_nos'], $body, 'main_order');
    $energyDeck = buildDeckForRoom($cards['cards'], $resolved['energy_nos'], $body, 'energy_order');

    $p1Payload = ['id' => 'p1', 'token' => $playerToken, 'name' => $playerName,
         'deck_choice' => $resolved['deck_choice'], 'deck_label' => $resolved['deck_label'],
         'main_deck' => $mainDeck, 'energy_deck' => $energyDeck,
         'sleeve_id' => tcgNormalizeSleeveId($resolved['sleeve_id'] ?? ($body['sleeve_id'] ?? '')),
         'playmat_id' => tcgNormalizePlaymatId($resolved['playmat_id'] ?? ($body['playmat_id'] ?? '')),
         'playmat_brightness' => tcgNormalizePlaymatBrightness($resolved['playmat_brightness'] ?? ($body['playmat_brightness'] ?? 1.0)),
         'deck_snapshot' => ['main_nos' => $resolved['main_nos'], 'energy_nos' => $resolved['energy_nos']]];
    // CPU seats must never inherit the human auth id (solo join reuses the session).
    $deckChoiceResolved = (string)($resolved['deck_choice'] ?? '');
    $isCpuDeck = (($body['deck'] ?? '') === 'cpu')
        || $deckChoiceResolved === 'cpu'
        || str_starts_with($deckChoiceResolved, 'cpu:');
    $authUid = $isCpuDeck ? null : tcgOptionalAuthUserId($body);
    if ($authUid) {
        $p1Payload['discord_id'] = $authUid;
    }

    $state = initGameState($roomId, $p1Payload);
    $state['phase_timer_cfg'] = parsePhaseTimerConfigFromBody($body);
    require_once __DIR__ . '/game_mode.php';
    $state['game_mode'] = tcgNormalizeGameMode($body['game_mode'] ?? TCG_GAME_MODE_STANDARD);

    saveGame($roomId, $state);

    return tcgSyncAttachMeta([
        'room_id'      => $roomId,
        'player_token' => $playerToken,
        'player_id'    => 'p1',
        'status'       => 'waiting',
        'message'      => "Room $roomId created. Share this code with your opponent!"
    ], $roomId, $playerToken);
}

function joinRoom(array $body): array {
    tcgRateLimitForAction('join_room', $body);
    tcgRequireAuthLoader();
    $roomId      = strtoupper(trim($body['room_id'] ?? ''));
    $playerToken = generateToken();
    $playerName  = htmlspecialchars($body['name'] ?? 'Player 2', ENT_QUOTES);

    if (!$roomId) {
        throw new Exception('Room ID required');
    }

    $state = loadGame($roomId);
    if (!$state) {
        throw new Exception('Room not found');
    }
    if ($state['status'] !== 'waiting') {
        throw new Exception('Room is full or game already started');
    }

    $cards = tcgLoadCardsData();
    // Joiner must match the room's Free/Standard/Starters mode when the host set one.
    require_once __DIR__ . '/game_mode.php';
    if (!empty($state['game_mode'])) {
        $body['game_mode'] = tcgNormalizeGameMode($state['game_mode']);
    } elseif (!isset($body['game_mode'])) {
        $body['game_mode'] = TCG_GAME_MODE_STANDARD;
    } else {
        $body['game_mode'] = tcgNormalizeGameMode($body['game_mode']);
    }
    $resolved  = resolveRoomDeckLists($body, $cards);

    $mainDeck   = buildDeckForRoom($cards['cards'], $resolved['main_nos'], $body, 'main_order');
    $energyDeck = buildDeckForRoom($cards['cards'], $resolved['energy_nos'], $body, 'energy_order');

    $firstPlayer = in_array($body['first_player'] ?? '', ['p1', 'p2'], true)
        ? $body['first_player'] : null;
    $coinFlipWinner = in_array($body['coin_flip_winner'] ?? '', ['p1', 'p2'], true)
        ? $body['coin_flip_winner'] : null;

    $p2Payload = ['id' => 'p2', 'token' => $playerToken, 'name' => $playerName,
         'deck_choice' => $resolved['deck_choice'], 'deck_label' => $resolved['deck_label'],
         'main_deck' => $mainDeck, 'energy_deck' => $energyDeck,
         'sleeve_id' => tcgNormalizeSleeveId($resolved['sleeve_id'] ?? ($body['sleeve_id'] ?? '')),
         'playmat_id' => tcgNormalizePlaymatId($resolved['playmat_id'] ?? ($body['playmat_id'] ?? '')),
         'playmat_brightness' => tcgNormalizePlaymatBrightness($resolved['playmat_brightness'] ?? ($body['playmat_brightness'] ?? 1.0)),
         'deck_snapshot' => ['main_nos' => $resolved['main_nos'], 'energy_nos' => $resolved['energy_nos']]];
    // Solo vs CPU: client joins p2 with deck=cpu using the same auth session as p1.
    // Never copy that discord_id onto the CPU seat or resign→CPU-win grants the CPU deck's missions.
    $deckChoiceResolved = (string)($resolved['deck_choice'] ?? '');
    $isCpuDeck = (($body['deck'] ?? '') === 'cpu')
        || $deckChoiceResolved === 'cpu'
        || str_starts_with($deckChoiceResolved, 'cpu:');
    $authUid = $isCpuDeck ? null : tcgOptionalAuthUserId($body);
    if ($authUid) {
        $p2Payload['discord_id'] = $authUid;
    }

    $state = addSecondPlayer($state,
        $p2Payload,
        $firstPlayer,
        $coinFlipWinner
    );

    if (!empty($body['tutorial_guide'])) {
        $state['tutorial_guide'] = true;
    }

    if (($body['deck'] ?? '') === 'cpu') {
        $cpuDiff = normalizeCpuDifficulty($body['cpu_difficulty'] ?? 'easy');
        $state['cpu_difficulty'] = $cpuDiff;
        $state['cpu_solo'] = true;
        unset($state['players']['p2']['discord_id']);
        $state = addLog($state, 'CPU deck (' . $cpuDiff . '): ' . ($resolved['deck_label'] ?? 'Generated'));
    }

    saveGame($roomId, $state);

    $out = [
        'room_id'      => $roomId,
        'player_token' => $playerToken,
        'player_id'    => 'p2',
        'status'       => 'ready',
        'message'      => 'Joined! Game starting...',
    ];
    if (!empty($state['cpu_difficulty'])) {
        $out['cpu_difficulty'] = normalizeCpuDifficulty($state['cpu_difficulty']);
    }
    return tcgSyncAttachMeta($out, $roomId, $playerToken);
}

// ─────────────────────────────────────────────
// Long Polling State
// ─────────────────────────────────────────────
function filterStateForClient(array $state, string $roomId, string $token): array {
    if (tcgIsSpectatorToken($token)) {
        return filterStateForSpectator($state, $roomId, $token);
    }
    $filtered = filterStateForPlayer($state, $token);
    $filtered['spectator_count'] = tcgLiveSpectatorCount($roomId);
    return $filtered;
}

function liveShowStageIndex(array $state): int {
    $stages = ['reveal', 'live_start', 'performance', 'outcomes', 'judge', 'done'];
    $stage = (string)($state['live_show']['stage'] ?? '');
    $idx = array_search($stage, $stages, true);
    return $idx === false ? count($stages) : intval($idx);
}

function hideLiveJudgeSpoilersFromFilteredState(array &$filtered, array $source): void {
    if (empty($source['live_show']) || liveShowStageIndex($source) >= 4) {
        return;
    }
    $filtered['live_scores_hidden'] = true;
    if (!empty($filtered['log'])) {
        $filtered['log'] = array_values(array_filter(
            $filtered['log'],
            static function ($entry): bool {
                $msg = is_array($entry) ? (string)($entry['msg'] ?? '') : (string)$entry;
                if (str_starts_with($msg, 'Live Scores:')) {
                    return false;
                }
                if ($msg === '=== Live Win/Loss Check Phase ===') {
                    return false;
                }
                if (str_contains($msg, ' wins the Live —')) {
                    return false;
                }
                if ($msg === 'Neither player succeeds — no Live winner this turn.') {
                    return false;
                }
                return true;
            }
        ));
    }
    // Hide printed Live card scores on the mat until the judge beat.
    foreach (['p1', 'p2'] as $pid) {
        if (empty($filtered['players'][$pid]['live_zone']) || !is_array($filtered['players'][$pid]['live_zone'])) {
            continue;
        }
        foreach ($filtered['players'][$pid]['live_zone'] as &$card) {
            if (!is_array($card)) {
                continue;
            }
            if (array_key_exists('score', $card)) {
                $card['score'] = null;
            }
            if (array_key_exists('live_score_bonus', $card)) {
                $card['live_score_bonus'] = 0;
            }
        }
        unset($card);
    }
}

function getStatePolling(): void {
    $roomId      = $_GET['room_id'] ?? '';
    $playerToken = $_GET['token']   ?? $_SERVER['HTTP_X_PLAYER_TOKEN'] ?? '';
    $resumeOnly  = isset($_GET['resume']) && (string)$_GET['resume'] === '1';
    tcgRateLimitForAction(
        $resumeOnly ? 'get_state_resume' : 'get_state',
        ['room_id' => $roomId, 'token' => $playerToken]
    );
    $lastSeq     = intval($_GET['since_seq'] ?? $_GET['seq'] ?? 0);
    $forceFull   = isset($_GET['force']) && (string)$_GET['force'] === '1';

    if (!$roomId || !$playerToken) {
        echo json_encode(['error' => 'room_id and token required']);
        return;
    }

    if (isset($_GET['poll']) && (string)$_GET['poll'] === '0') {
        $state = loadGame($roomId);
        if (!$state) {
            echo json_encode(['error' => 'Room not found']);
            return;
        }
        if (tcgIsSpectatorToken($playerToken)) {
            if (tcgSpectatorTokenValid($roomId, $playerToken)) {
                tcgTouchSpectatorPresence($roomId, $playerToken);
            }
        } else {
            touchPresence($roomId, $playerToken);
        }
        if (tcgIsSpectatorToken($playerToken) && !tcgSpectatorTokenValid($roomId, $playerToken)) {
            echo json_encode(['error' => 'Spectator session expired']);
            return;
        }
        // Refresh reconnect: usually read-only — but live_show stalls must still heal.
        // Spectacle parks on resume=1 during "Checking hearts…"; skipping timeouts there
        // left PvP rooms stuck when one player never acked (and thrashing workers OOMed).
        if ($resumeOnly) {
            $agedLiveShow = !empty($state['live_show'])
                && ($state['live_show']['stage'] ?? '') !== 'done'
                && empty($state['pending_prompt'])
                && (time() - intval($state['live_show']['started_at'] ?? time()) >= (
                    count(liveShowRequiredAckPlayers($state)) >= 2 ? 25 : 90
                ));
            if ($agedLiveShow) {
                try {
                    $healed = withLock($roomId, static function () use ($roomId) {
                        $s = loadGame($roomId);
                        if (!$s) {
                            return null;
                        }
                        if (applyPhaseTimeouts($s)) {
                            saveGame($roomId, $s);
                            return $s;
                        }
                        return null;
                    }, 8.0);
                    if (is_array($healed)) {
                        $state = $healed;
                    }
                } catch (Throwable $e) {
                    // Keep the read-only snapshot if the lock is busy.
                }
            }
            echo json_encode(filterStateForClient($state, $roomId, $playerToken));
            return;
        }

        $curSeq = intval($state['seq'] ?? 0);
        $runSideEffects = $forceFull || tcgPollSideEffectsDue($roomId);
        $mutated = false;
        if ($runSideEffects) {
            if (applyPhaseTimeouts($state)) {
                saveGame($roomId, $state);
                $mutated = true;
            }
            if (applyCoinFlipStalemate($state)) {
                refreshPvpPhaseTimers($state);
                saveGame($roomId, $state);
                $mutated = true;
            }
            if (applyDisconnectForfeits($state, $roomId)) {
                saveGame($roomId, $state);
                maybeApplyRankedFinish($state);
                maybeCreditCasualFinishMissions($state);
                saveGame($roomId, $state);
                $mutated = true;
            }
            maybeRecoverUnappliedRankedFinish($roomId, $state);
            $curSeq = intval($state['seq'] ?? 0);
        }

        // Delayed tournament spectate keys off the delayed snapshot seq, never live seq.
        if (tcgIsSpectatorToken($playerToken)
            && ($state['mode'] ?? '') === 'tournament') {
            require_once __DIR__ . '/tournament_spectate.php';
            if (tcgTournamentStreamDelaySecs($state) > 0) {
                $filtered = filterStateForClient($state, $roomId, $playerToken);
                $viewSeq = intval($filtered['seq'] ?? 0);
                $waiting = !empty($filtered['spectate_stream_waiting']);
                if (!$mutated && !$waiting && $lastSeq > 0 && $lastSeq === $viewSeq) {
                    echo json_encode(['ok' => true, 'unchanged' => true, 'seq' => $viewSeq]);
                    return;
                }
                echo json_encode($filtered);
                return;
            }
        }

        // Client already has this seq and nothing mutated — skip filter/encode.
        // force=1 must NEVER short-circuit: the client uses it when lastSeq was
        // advanced before the board painted (own-turn actions). Returning
        // unchanged there leaves the UI stale until a full page refresh.
        if (!$forceFull && !$mutated && $lastSeq > 0 && $lastSeq === $curSeq) {
            echo json_encode(['ok' => true, 'unchanged' => true, 'seq' => $curSeq]);
            return;
        }

        echo json_encode(filterStateForClient($state, $roomId, $playerToken));
        return;
    }

    $isSpectator = tcgIsSpectatorToken($playerToken);
    if ($isSpectator && !tcgSpectatorTokenValid($roomId, $playerToken)) {
        echo json_encode(['error' => 'Spectator session expired']);
        return;
    }

    $deadline = time() + POLL_TIMEOUT;
    while (time() < $deadline) {
        $state = loadGame($roomId);
        if (!$state) {
            echo json_encode(['error' => 'Room not found']);
            return;
        }
        if (applyPhaseTimeouts($state)) {
            saveGame($roomId, $state);
        }
        if (applyCoinFlipStalemate($state)) {
            refreshPvpPhaseTimers($state);
            saveGame($roomId, $state);
        }
        if (applyDisconnectForfeits($state, $roomId)) {
            saveGame($roomId, $state);
            maybeApplyRankedFinish($state);
            maybeCreditCasualFinishMissions($state);
            saveGame($roomId, $state);
        }
        maybeRecoverUnappliedRankedFinish($roomId, $state);
        $wakeSeq = intval($state['seq'] ?? 0);
        if ($isSpectator && ($state['mode'] ?? '') === 'tournament') {
            require_once __DIR__ . '/tournament_spectate.php';
            if (tcgTournamentStreamDelaySecs($state) > 0) {
                $wakeSeq = tcgTournamentSpectatorViewSeq($roomId, $state);
            }
        }
        if ($wakeSeq > $lastSeq) {
            echo json_encode(filterStateForClient($state, $roomId, $playerToken));
            return;
        }
        if ($isSpectator) {
            tcgTouchSpectatorPresence($roomId, $playerToken);
        } else {
            touchPresence($roomId, $playerToken);
        }
        usleep(800000); // 0.8s
    }
    // Timeout – return current state
    $state = loadGame($roomId);
    if ($state && applyPhaseTimeouts($state)) {
        saveGame($roomId, $state);
    }
    if ($state && applyCoinFlipStalemate($state)) {
        refreshPvpPhaseTimers($state);
        saveGame($roomId, $state);
    }
    if ($state && applyDisconnectForfeits($state, $roomId)) {
        saveGame($roomId, $state);
        maybeApplyRankedFinish($state);
        maybeCreditCasualFinishMissions($state);
        saveGame($roomId, $state);
    }
    if ($state) {
        maybeRecoverUnappliedRankedFinish($roomId, $state);
        echo json_encode(filterStateForClient($state, $roomId, $playerToken));
    }
}

// ─────────────────────────────────────────────
// Action Handler
// ─────────────────────────────────────────────
function handleAction(array $body): array {
    tcgRateLimitForAction('action', $body);
    $roomId = $body['room_id'] ?? '';
    $token  = $body['token']   ?? '';
    $type   = $body['type']    ?? '';
    $data   = $body['data']    ?? [];

    if (!$roomId || !$token || !$type) {
        throw new Exception('room_id, token, and type required');
    }
    if (tcgIsSpectatorToken($token)) {
        throw new Exception('Spectators cannot perform actions');
    }

    // Live presentation / prompt resolves can hold the lock longer; give the waiter more time.
    $lockSec = in_array($type, ['live_show_ack', 'resolve_prompt'], true) ? 12.0 : null;

    return withLock($roomId, function() use ($roomId, $token, $type, $data, $body) {
        $state = loadGame($roomId);
        if (!$state) throw new Exception('Room not found');

        $phaseTimeoutChanged = applyPhaseTimeouts($state);
        if ($phaseTimeoutChanged) {
            saveGame($roomId, $state);
        }
        $stalemateApplied = applyCoinFlipStalemate($state);
        if ($stalemateApplied) {
            refreshPvpPhaseTimers($state);
            saveGame($roomId, $state);
        }
        // Only re-read when a timeout/stalemate write may have raced another worker.
        if ($phaseTimeoutChanged || $stalemateApplied) {
            $state = loadGame($roomId);
            if (!$state) throw new Exception('Room not found');
        }

        if (applyDisconnectForfeits($state, $roomId)) {
            saveGame($roomId, $state);
            maybeApplyRankedFinish($state);
            maybeCreditCasualFinishMissions($state);
            saveGame($roomId, $state);
            $state = loadGame($roomId);
        }

        $playerId = getPlayerIdByToken($state, $token);
        if (!$playerId) throw new Exception('Invalid player token');

        if (($state['mode'] ?? '') === 'replay_view') {
            throw new Exception('Replay viewer — use replay controls, not live actions');
        }
        // A late input that arrives after the third inactivity deadline must not
        // mutate the finished game. Finalize the ranked result and return the new seq.
        if ($phaseTimeoutChanged && ($state['status'] ?? '') === 'finished') {
            maybeApplyRankedFinish($state);
            maybeCreditCasualFinishMissions($state);
            saveGame($roomId, $state);
            $out = ['ok' => true, 'seq' => $state['seq'], 'finished' => true];
            if (($state['mode'] ?? '') === 'ranked') {
                require_once __DIR__ . '/ranked_pr_rewards.php';
                $prReward = tcgRankedPrRewardForPlayer($state, $playerId);
                if ($prReward !== null) {
                    $out['ranked_pr_reward'] = $prReward;
                }
            }
            if (!empty($state['_hostinger_mission_completions']) && is_array($state['_hostinger_mission_completions'])) {
                $out['mission_completions'] = $state['_hostinger_mission_completions'];
                unset($state['_hostinger_mission_completions']);
                saveGame($roomId, $state);
            }
            return $out;
        }

        $prevStatus = $state['status'] ?? '';
        $prevSeq = intval($state['seq'] ?? 0);
        $state = captureReplayBaselineIfNeeded($state);
        $state = applyAction($state, $playerId, $type, $data);
        // Stale/duplicate resolve_prompt / LIVE lock-in: leave game state untouched
        // (no replay record, no save) so the client just resyncs to the current seq.
        if (!empty($state['_resolve_prompt_noop']) || !empty($state['_live_set_noop'])) {
            return ['ok' => true, 'seq' => $state['seq'], 'noop' => true];
        }
        if (intval($state['seq'] ?? 0) > $prevSeq
            && rankedActionShowsPlayerActivity($type)) {
            resetRankedInactivityTimeouts($state, $playerId);
        }
        // Rule action: never leave a seat with an empty main deck while WR has cards.
        $state = refreshEmptyMainDecks($state);
        $state = appendReplayAction($state, $playerId, $type, $data);
        $isResign = ($type === 'resign');
        // Resign must not re-enter ability flush (softlock TypeErrors → HTTP 500).
        if (empty($state['pending_prompt']) && !$isResign) {
            $state = flushAutoOnWaitAbilities($state);
            $state = refreshEmptyMainDecks($state);
        }
        if (!$isResign) {
            refreshPvpPhaseTimers($state);
        }
        $missionCompletions = [];
        $justFinished = $prevStatus !== 'finished' && ($state['status'] ?? '') === 'finished';
        $rankedRemoteMissions = ($state['mode'] ?? '') === 'ranked'
            && (
                (($state['ranked']['match_api'] ?? '') === 'overflow')
                || (function_exists('tcgShouldApplyRankedEloRemotely') && tcgShouldApplyRankedEloRemotely())
            );
        $hostingerMissionWrites = false;
        if (!$rankedRemoteMissions) {
            require_once __DIR__ . '/match_bridge.php';
            $hostingerMissionWrites = function_exists('tcgMissionShouldWriteOnHostinger')
                && tcgMissionShouldWriteOnHostinger();
        }
        if ($justFinished && $isResign) {
            // Persist concede first so ranked/mission failures cannot block resign.
            saveGame($roomId, $state);
            try {
                require_once __DIR__ . '/missions.php';
                tcgMissionBackfillPlayerDiscordFromAuth($state, $playerId, $body);
                require_once __DIR__ . '/ranked_room.php';
                tcgOnGameFinished($state);
                // Overflow ranked: Hostinger webhook owns mission DB writes (VPS replica is one-way).
                if (empty($state['_missions_applied'])) {
                    if ($rankedRemoteMissions) {
                        $missionCompletions = is_array($state['_hostinger_mission_completions'] ?? null)
                            ? $state['_hostinger_mission_completions']
                            : [];
                        unset($state['_hostinger_mission_completions']);
                    } elseif ($hostingerMissionWrites) {
                        // Casual/CPU on match-primary: credit hub missions on Hostinger.
                        $bundle = tcgPostMissionGameFinishedBundleToHostinger($state);
                        $missionCompletions = $bundle['missions'];
                        if (!empty($bundle['coin_grants'])) {
                            $state['_coin_grants'] = $bundle['coin_grants'];
                        }
                    } else {
                        $missionCompletions = tcgMissionOnGameFinished($state);
                        require_once __DIR__ . '/coins.php';
                        $coinGrants = tcgCoinsOnGameFinished($state);
                        if ($coinGrants !== []) {
                            $state['_coin_grants'] = $coinGrants;
                        }
                    }
                    $state['_missions_applied'] = true;
                }
                saveGame($roomId, $state);
            } catch (Throwable $e) {
                // Resign already saved — ranked/mission side effects are best-effort.
            }
        } elseif ($justFinished) {
            require_once __DIR__ . '/missions.php';
            tcgMissionBackfillPlayerDiscordFromAuth($state, $playerId, $body);
            require_once __DIR__ . '/ranked_room.php';
            tcgOnGameFinished($state);
            if (empty($state['_missions_applied'])) {
                if ($rankedRemoteMissions) {
                    $missionCompletions = is_array($state['_hostinger_mission_completions'] ?? null)
                        ? $state['_hostinger_mission_completions']
                        : [];
                    unset($state['_hostinger_mission_completions']);
                    if (!empty($state['_coin_grants']) && is_array($state['_coin_grants'])) {
                        // already stashed by ranked webhook
                    }
                } elseif ($hostingerMissionWrites) {
                    $bundle = tcgPostMissionGameFinishedBundleToHostinger($state);
                    $missionCompletions = $bundle['missions'];
                    if (!empty($bundle['coin_grants'])) {
                        $state['_coin_grants'] = $bundle['coin_grants'];
                    }
                } else {
                    $missionCompletions = tcgMissionOnGameFinished($state);
                    require_once __DIR__ . '/coins.php';
                    $coinGrants = tcgCoinsOnGameFinished($state);
                    if ($coinGrants !== []) {
                        $state['_coin_grants'] = $coinGrants;
                    }
                }
                $state['_missions_applied'] = true;
            }
            saveGame($roomId, $state);
        } else {
            if ($type === 'send_stamp') {
                require_once __DIR__ . '/missions.php';
                $discordId = tcgMissionResolveActingDiscordId($state, $playerId, $body);
                if ($discordId) {
                    $remoteMissions = (($state['ranked']['match_api'] ?? '') === 'overflow')
                        || (function_exists('tcgShouldApplyRankedEloRemotely') && tcgShouldApplyRankedEloRemotely())
                        || (is_string(getenv('TCG_GAME_STORE')) && strtolower(trim((string)getenv('TCG_GAME_STORE'))) === 'redis');
                    if ($remoteMissions) {
                        // Match-primary: stamp actions hit VPS; hub missions live on Hostinger.
                        require_once __DIR__ . '/match_bridge.php';
                        $missionCompletions = tcgPostMissionStampSentToHostinger($discordId);
                    } else {
                        tcgEnsureUser($discordId);
                        $missionCompletions = tcgMissionOnStampSent($discordId);
                    }
                }
            }
            saveGame($roomId, $state);
        }

        $out = ['ok' => true, 'seq' => $state['seq']];
        if (!empty($missionCompletions)) {
            $out['mission_completions'] = $missionCompletions;
        }
        if (!empty($state['_coin_grants']) && is_array($state['_coin_grants'])) {
            foreach ($state['_coin_grants'] as $g) {
                if (($g['pid'] ?? '') === $playerId) {
                    $out['coin_grant'] = [
                        'amount' => intval($g['amount'] ?? 0),
                        'balance' => intval($g['balance'] ?? 0),
                    ];
                    break;
                }
            }
        }
        if (($state['mode'] ?? '') === 'ranked' && ($state['status'] ?? '') === 'finished') {
            require_once __DIR__ . '/ranked_pr_rewards.php';
            $prReward = tcgRankedPrRewardForPlayer($state, $playerId);
            if ($prReward !== null) {
                $out['ranked_pr_reward'] = $prReward;
            }
        }
        return $out;
    }, $lockSec);
}

/**
 * Normalize lobby/CPU difficulty strings. Expert is a valid AI tier (deckgen = Hard).
 */
function normalizeCpuDifficulty(mixed $diff): string {
    $d = is_string($diff) ? $diff : '';
    return in_array($d, ['easy', 'normal', 'hard', 'expert'], true) ? $d : 'easy';
}

/**
 * CPU-solo only: apply action sequences on a cloned state without saving.
 * Used by Expert AI to score short Main-phase plans with real applyAction rules.
 */
function handleDryRunActions(array $body): array {
    tcgRateLimitForAction('dry_run_actions', $body);
    $roomId = $body['room_id'] ?? '';
    $token  = $body['token']   ?? '';
    $sequences = $body['sequences'] ?? null;

    if (!$roomId || !$token) {
        throw new Exception('room_id and token required');
    }
    if (tcgIsSpectatorToken($token)) {
        throw new Exception('Spectators cannot dry-run actions');
    }
    if (!is_array($sequences) || !$sequences) {
        throw new Exception('sequences array required');
    }

    $maxSequences = 16;
    $maxActionsPerSeq = 4;
    if (count($sequences) > $maxSequences) {
        throw new Exception('Too many sequences (max ' . $maxSequences . ')');
    }

    return withLock($roomId, function () use ($roomId, $token, $sequences, $maxActionsPerSeq) {
        $state = loadGame($roomId);
        if (!$state) {
            throw new Exception('Room not found');
        }
        if (!isCpuSoloMatch($state)) {
            throw new Exception('dry_run_actions is only available in CPU solo matches');
        }
        if (($state['mode'] ?? '') === 'replay_view') {
            throw new Exception('Replay viewer — dry-run not available');
        }

        $playerId = getPlayerIdByToken($state, $token);
        if (!$playerId) {
            throw new Exception('Invalid player token');
        }
        // Prefer the CPU seat; allow either seat only when both are CPU (debug) or token is CPU.
        $cpuSeat = null;
        foreach (['p1', 'p2'] as $pid) {
            if (isCpuPlayer($state['players'][$pid] ?? null)) {
                $cpuSeat = $pid;
                break;
            }
        }
        if ($cpuSeat && $playerId !== $cpuSeat) {
            throw new Exception('dry_run_actions must use the CPU seat token');
        }

        $base = json_decode(json_encode($state), true);
        if (!is_array($base)) {
            throw new Exception('Failed to clone game state');
        }

        $results = [];
        foreach ($sequences as $seqIdx => $seq) {
            if (!is_array($seq)) {
                $results[] = ['ok' => false, 'error' => 'sequence must be an array', 'index' => $seqIdx];
                continue;
            }
            if (count($seq) > $maxActionsPerSeq) {
                $results[] = [
                    'ok' => false,
                    'error' => 'Too many actions in sequence (max ' . $maxActionsPerSeq . ')',
                    'index' => $seqIdx,
                ];
                continue;
            }
            $sim = json_decode(json_encode($base), true);
            $stopped = 'done';
            $err = null;
            try {
                foreach ($seq as $stepIdx => $step) {
                    if (!is_array($step)) {
                        throw new Exception('action step must be an object');
                    }
                    $type = (string)($step['type'] ?? '');
                    $data = $step['data'] ?? [];
                    if ($type === '') {
                        throw new Exception('action type required');
                    }
                    if (!is_array($data)) {
                        $data = [];
                    }
                    if (!empty($sim['pending_prompt'])) {
                        $stopped = 'pending_prompt';
                        break;
                    }
                    $ph = (string)($sim['phase'] ?? '');
                    if (($sim['active_player'] ?? '') !== $playerId) {
                        $stopped = 'not_active';
                        break;
                    }
                    if ($type !== 'end_main' && $ph !== 'main_first' && $ph !== 'main_second') {
                        $stopped = 'phase_changed';
                        break;
                    }
                    $sim = applyAction($sim, $playerId, $type, $data);
                    if (!empty($sim['_resolve_prompt_noop'])) {
                        unset($sim['_resolve_prompt_noop']);
                        continue;
                    }
                    $sim = refreshEmptyMainDecks($sim);
                    if (empty($sim['pending_prompt'])) {
                        $sim = flushAutoOnWaitAbilities($sim);
                        $sim = refreshEmptyMainDecks($sim);
                    } else {
                        $stopped = 'pending_prompt';
                        break;
                    }
                    if (($sim['status'] ?? '') === 'finished') {
                        $stopped = 'finished';
                        break;
                    }
                }
            } catch (Throwable $e) {
                $err = $e->getMessage();
                $stopped = 'error';
            }

            $filtered = filterStateForPlayer($sim, $token);
            $results[] = [
                'ok' => $err === null,
                'error' => $err,
                'index' => $seqIdx,
                'stopped' => $stopped,
                'state' => $filtered,
            ];
        }

        return ['ok' => true, 'results' => $results];
    });
}

/**
 * Internal: Hostinger seeds a fully-built ranked room into VPS Redis.
 * Auth: X-TCG-Internal-Secret === TCG_INTERNAL_MATCH_SECRET.
 */
function apiSeedRankedRoom(array $body): array {
    require_once __DIR__ . '/match_bridge.php';
    tcgRequireInternalMatchSecret();
    $state = $body['state'] ?? null;
    if (!is_array($state)) {
        throw new Exception('state required', 400);
    }
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($state['room_id'] ?? '')) ?? '');
    if ($roomId === '') {
        throw new Exception('state.room_id required', 400);
    }
    $state['room_id'] = $roomId;
    $mode = (string)($state['mode'] ?? '');
    if (!in_array($mode, ['ranked', 'tournament'], true)) {
        throw new Exception('state.mode must be ranked or tournament', 400);
    }
    $p1 = $state['players']['p1'] ?? null;
    $p2 = $state['players']['p2'] ?? null;
    if (!is_array($p1) || !is_array($p2) || empty($p1['token']) || empty($p2['token'])) {
        throw new Exception('match state requires both player tokens', 400);
    }
    if ($mode === 'ranked') {
        if (!isset($state['ranked']) || !is_array($state['ranked'])) {
            $state['ranked'] = [];
        }
        $state['ranked']['match_api'] = 'overflow';
    } else {
        if (!isset($state['tournament']) || !is_array($state['tournament'])) {
            $state['tournament'] = [];
        }
        $state['tournament']['match_api'] = 'overflow';
    }
    if (empty($state['seq'])) {
        $state['seq'] = 1;
    }
    $incomingSeq = intval($state['seq'] ?? 1);
    $existing = loadGame($roomId);
    if (is_array($existing)) {
        $existSeq = intval($existing['seq'] ?? 0);
        // Re-migrate / rejoin must not rewind a live overflow room to a stale Hostinger seed.
        if ($existSeq > $incomingSeq) {
            return [
                'ok' => true,
                'room_id' => $roomId,
                'seq' => $existSeq,
                'skipped' => 'existing_ahead',
            ];
        }
    }
    saveGame($roomId, $state);
    return ['ok' => true, 'room_id' => $roomId, 'seq' => $incomingSeq];
}

function ping(array $body): array {
    $roomId = $body['room_id'] ?? '';
    $token  = $body['token']   ?? '';
    if ($roomId && $token) {
        if (tcgIsSpectatorToken($token)) {
            tcgTouchSpectatorPresence($roomId, $token);
        } else {
            touchPresence($roomId, $token);
        }
    }
    return ['ok' => true, 'time' => time()];
}

// ─────────────────────────────────────────────
// Game State Initialization
// ─────────────────────────────────────────────
function initGameState(string $roomId, array $p1): array {
    return [
        'room_id'  => $roomId,
        'status'   => 'waiting',
        'seq'      => 1,
        'turn'     => 1,
        'phase'    => 'waiting',
        'first_player' => null,
        'active_player' => null,
        'log'      => [],
        'players'  => [
            'p1' => initPlayerState($p1),
            'p2' => null,
        ],
    ];
}

function initPlayerState(array $p): array {
    return [
        'id'           => $p['id'],
        'token'        => $p['token'],
        'name'         => $p['name'],
        'deck_choice'  => $p['deck_choice'],
        'deck_label'   => $p['deck_label'] ?? null,
        'deck_snapshot'=> $p['deck_snapshot'] ?? null,
        'discord_id'   => $p['discord_id'] ?? null,
        'sleeve_id'    => tcgNormalizeSleeveId($p['sleeve_id'] ?? ''),
        'playmat_id'   => tcgNormalizePlaymatId($p['playmat_id'] ?? ''),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($p['playmat_brightness'] ?? 1.0),
        'main_deck'    => $p['main_deck'],
        'energy_deck'  => $p['energy_deck'],
        'hand'         => [],
        'energy_zone'  => [],
        'stage'        => ['left' => null, 'center' => null, 'right' => null],
        'live_zone'    => [],
        'success_lives'=> [],
        'waiting_room' => [],
        'score'        => 0,
        'ready_mulligan'=> false,
        'mulligan_redrawn' => null,
    ];
}

function addSecondPlayer(array $state, array $p2, ?string $firstPlayerOverride = null, ?string $coinFlipWinner = null): array {
    $state['players']['p2'] = initPlayerState($p2);
    $state['status']        = 'setup';
    $state['phase']         = 'setup';

    // First player: coin flip winner chooses (see actionChooseFirstPlayer)
    if ($firstPlayerOverride === 'p1' || $firstPlayerOverride === 'p2') {
        $state['first_player']  = $firstPlayerOverride;
        $state['active_player'] = $firstPlayerOverride;
        $state['phase']         = 'setup';
    } else {
        $winner = ($coinFlipWinner === 'p1' || $coinFlipWinner === 'p2')
            ? $coinFlipWinner
            : ((rand(0, 1) === 0) ? 'p1' : 'p2');
        $state['first_player']  = null;
        $state['active_player'] = null;
        $state['coin_flip'] = [
            'winner' => $winner,
            'ready'  => ['p1' => false, 'p2' => false],
            'since'  => time(),
        ];
        $state['phase'] = 'coin_flip';
    }

    // Deal 6 cards to each player
    foreach (['p1','p2'] as $pid) {
        [$drawn, $state['players'][$pid]['main_deck']] =
            drawCards($state['players'][$pid]['main_deck'], 6);
        $state['players'][$pid]['hand'] = $drawn;
        // Deal 3 energy into energy storage (vertical / active)
        [$energy, $state['players'][$pid]['energy_deck']] =
            drawCards($state['players'][$pid]['energy_deck'], 3);
        $state['players'][$pid]['energy_zone'] = array_map(function($c) {
            $c['active'] = true; return $c;
        }, $energy);
    }

    $state = addLog($state, 'Game started! Coin flip — winner chooses who goes first.');
    $state = addLog($state, 'Preparation: each player drew 6 cards and placed 3 Energy in storage.');
    if ($firstPlayerOverride === 'p1' || $firstPlayerOverride === 'p2') {
        $state = addLog($state, 'Preparation — Mulligan: you may replace any number of opening hand cards once.');
    }
    $state['seq']++;
    return $state;
}

// ─────────────────────────────────────────────
// Action Application (Game Rules Engine)
// ─────────────────────────────────────────────
function applyAction(array $state, string $playerId, string $type, array $data): array {
    // Heal rooms that never flipped setup→playing (softlock skip / Shift+T / client gates).
    if (function_exists('tcgMatchInProgress') && tcgMatchInProgress($state)
        && ($state['status'] ?? '') === 'setup') {
        $state['status'] = 'playing';
    }
    switch ($type) {

        // ── SETUP ──────────────────────────
        case 'ack_coin_flip':
            return actionAckCoinFlip($state, $playerId);

        case 'choose_first_player':
            return actionChooseFirstPlayer($state, $playerId, $data);

        case 'mulligan':
            return actionMulligan($state, $playerId, $data);

        // ── MAIN PHASE ─────────────────────
        case 'play_member':
            return actionPlayMember($state, $playerId, $data);

        case 'activate_ability':
            return actionActivateAbility($state, $playerId, $data);

        case 'resolve_prompt':
            return actionResolvePrompt($state, $playerId, $data);

        case 'anti_softlock_skip':
            return actionAntiSoftlockSkipPrompt($state, $playerId);

        case 'force_own_timeout':
            return actionForceOwnTimeout($state, $playerId);

        case 'live_start_choice':
            return actionLiveStartChoice($state, $playerId, $data);

        case 'end_main':
            return actionEndMain($state, $playerId);

        // ── LIVE PHASE ─────────────────────
        case 'set_live_cards':
            return actionSetLiveCards($state, $playerId, $data);

        case 'end_live_set':
            return actionEndLiveSet($state, $playerId);

        case 'confirm_live':
            return actionConfirmLive($state, $playerId, $data);

        case 'live_show_ack':
            return actionLiveShowAck($state, $playerId, $data);

        // ── MISC ────────────────────────────
        case 'resign':
            unset($state['pending_prompt'], $state['surveil_stash'], $state['_surveil_chain']);
            $state['status'] = 'finished';
            $winner = ($playerId === 'p1') ? 'p2' : 'p1';
            $state['end_reason'] = 'resign';
            $state['resigned_by'] = $playerId;
            $state = addLog($state, $state['players'][$playerId]['name'] . ' resigned. ' .
                            $state['players'][$winner]['name'] . ' wins!');
            $state['winner'] = $winner;
            $state['seq']++;
            return $state;

        case 'request_rematch':
            return actionRequestRematch($state, $playerId);

        case 'send_stamp':
            return actionSendStamp($state, $playerId, $data);

        default:
            throw new Exception("Unknown action: $type");
    }
}

function actionAckCoinFlip(array $state, string $pid): array {
    if (($state['phase'] ?? '') === 'setup') {
        return $state;
    }
    if (($state['phase'] ?? '') !== 'coin_flip') {
        throw new Exception('Not in coin flip phase');
    }
    $flip = &$state['coin_flip'];
    if (empty($flip)) {
        throw new Exception('No coin flip in progress');
    }
    if (!empty($flip['ready'][$pid])) {
        return $state;
    }
    $flip['ready'][$pid] = true;
    if (coinFlipBothReady($state) && empty($flip['both_ready_since'])) {
        $flip['both_ready_since'] = time();
    }
    $state['seq']++;
    return $state;
}

function coinFlipBothReady(array $state): bool {
    $flip = $state['coin_flip'] ?? null;
    if (!$flip) {
        return false;
    }
    return !empty($flip['ready']['p1']) && !empty($flip['ready']['p2']);
}

function actionChooseFirstPlayer(array $state, string $pid, array $data): array {
    if (($state['phase'] ?? '') !== 'coin_flip') {
        throw new Exception('Not in coin flip phase');
    }
    $flip = $state['coin_flip'] ?? null;
    if (!$flip) {
        throw new Exception('No coin flip in progress');
    }
    if (!coinFlipBothReady($state)) {
        throw new Exception('Wait for the coin flip animation to finish');
    }
    $winner = $flip['winner'] ?? null;
    if ($winner !== $pid) {
        throw new Exception('Only the coin flip winner may choose who goes first');
    }
    $choice = $data['first_player'] ?? '';
    if (!in_array($choice, ['p1', 'p2'], true)) {
        throw new Exception('Invalid first player choice');
    }
    $state['first_player'] = $choice;
    $state['active_player'] = $choice;
    $state['phase'] = 'setup';
    unset($state['coin_flip']);

    $winnerName = $state['players'][$winner]['name'] ?? $winner;
    $firstName = $state['players'][$choice]['name'] ?? $choice;
    if ($choice === $winner) {
        $state = addLog($state, '🪙 Coin flip: ' . $winnerName . ' won and chose to go first!');
    } else {
        $state = addLog($state, '🪙 Coin flip: ' . $winnerName . ' won and chose ' . $firstName . ' to go first!');
    }
    $state = addLog($state, 'Preparation — Mulligan: you may replace any number of opening hand cards once.');
    $state['seq']++;
    return $state;
}

function actionMulligan(array $state, string $pid, array $data): array {
    if (($state['phase'] ?? '') !== 'setup') {
        throw new Exception('Not in mulligan phase');
    }
    $p = &$state['players'][$pid];
    if ($p['ready_mulligan']) {
        throw new Exception('Already mulliganed');
    }
    $toReplace = $data['card_ids'] ?? [];
    $redrawn = 0;
    if (!empty($toReplace)) {
        $kept = [];
        $returned = [];
        foreach ($p['hand'] as $c) {
            if (in_array($c['instance_id'], $toReplace, true)) {
                $returned[] = $c;
            } else {
                $kept[] = $c;
            }
        }
        $redrawn = count($returned);
        [$newCards, $p['main_deck']] = drawCards($p['main_deck'], $redrawn);
        $p['main_deck'] = array_merge($p['main_deck'], $returned);
        // Guided tutorial keeps a scripted deck order (shuffle:false on create).
        // Shuffling the remainder after mulligan would randomize Yell and can
        // softlock the Performance watch step when blade hearts never appear.
        if (empty($state['tutorial_guide'])) {
            shuffle($p['main_deck']);
        }
        $p['hand'] = array_merge($kept, $newCards);
    }
    $p['ready_mulligan'] = true;
    $p['mulligan_redrawn'] = $redrawn;
    $pname = $state['players'][$pid]['name'] ?? $pid;
    if ($redrawn > 0) {
        $state = addLog($state, "$pname mulliganed: redrew $redrawn card(s).");
    } else {
        $state = addLog($state, "$pname mulliganed: kept hand.");
    }

    // Check if both players are ready
    $bothReady = true;
    foreach (['p1','p2'] as $id) {
        if (!$state['players'][$id]['ready_mulligan']) {
            $bothReady = false;
            break;
        }
    }
    if ($bothReady) {
        $n1 = intval($state['players']['p1']['mulligan_redrawn'] ?? 0);
        $n2 = intval($state['players']['p2']['mulligan_redrawn'] ?? 0);
        $name1 = $state['players']['p1']['name'] ?? 'p1';
        $name2 = $state['players']['p2']['name'] ?? 'p2';
        $state['mulligan_declare'] = [
            'p1' => $n1,
            'p2' => $n2,
            'seq' => intval($state['seq'] ?? 0) + 1,
        ];
        $state = addLog($state, "Mulligan — $name1 redrew $n1, $name2 redrew $n2.");
        $state = startTurn($state);
    }
    $state['seq']++;
    return $state;
}

function actionPlayMember(array $state, string $pid, array $data): array {
    validateTurn($state, $pid, 'main');
    assertNoPendingPromptForPlayerAction($state, $pid);

    $instanceId  = $data['card_id']  ?? '';
    $targetSlot  = $data['slot']     ?? 'center';
    $batonCardId = $data['baton_id'] ?? null;
    $batonCardId2 = $data['baton_id2'] ?? null;

    $p = &$state['players'][$pid];
    $cardIdx = findInHand($p['hand'], $instanceId);
    if ($cardIdx === false) throw new Exception('Card not in hand');
    $card = $p['hand'][$cardIdx];
    mergeCardCatalogFields($card);
    if ($card['card_type'] !== 'メンバー') throw new Exception('Not a member card');

    $allowsDoubleBaton = false;
    foreach ($card['abilities'] ?? [] as $ab) {
        if (($ab['type'] ?? '') === 'allows_double_baton') {
            $allowsDoubleBaton = true;
            break;
        }
    }

    $occupant = $p['stage'][$targetSlot] ?? null;
    $isBaton = $batonCardId && $occupant;
    $isOverplay = $occupant && !$batonCardId;

    if ($isOverplay && stageSlotBlocksAdditionalEnterThisTurn($occupant, $state)) {
        throw new Exception('Cannot enter this Stage area this turn');
    }
    if ($occupant && stageMemberEnteredThisTurn($occupant, $state)) {
        throw new Exception('Cannot replace a Member that was played this turn');
    }
    if ($batonCardId && (!$occupant || ($occupant['instance_id'] ?? '') !== $batonCardId)) {
        throw new Exception('Invalid Baton Touch target');
    }
    if ($batonCardId && $occupant && hsMemberBatonRestricted($occupant, $card)) {
        throw new Exception('This Member cannot be sent to the Waiting Room via Baton Touch with that Member');
    }
    if ($batonCardId && getEffectiveHandCost($state, $pid, $card) < 1) {
        throw new Exception('Cannot use Baton Touch when play cost is 0');
    }

    if ($isBaton) {
        $cost = computeMemberPlayCostWithBaton($state, $pid, $card, $occupant);
    } else {
        $cost = getEffectiveHandCost($state, $pid, $card);
    }
    // BP07 optional play-cost options (PL!N-bp7-011 shuffle WR Members, LL-bp7-001 discard
    // named set). The client opts in explicitly, so a plain play still pays full cost.
    [$cost, $bp7CostOption] = bp7AdjustHandPlayCost($state, $pid, $card, $cost, [
        'shuffle_wr_members' => !empty($data['bp7_shuffle_wr_members']),
        'discard_named_ids'  => is_array($data['bp7_discard_named_ids'] ?? null)
            ? $data['bp7_discard_named_ids']
            : [],
    ]);
    if ($isBaton && $allowsDoubleBaton && $batonCardId2) {
        foreach ($p['stage'] as $existing2) {
            if (!$existing2 || ($existing2['instance_id'] ?? '') !== $batonCardId2) {
                continue;
            }
            if (stageMemberEnteredThisTurn($existing2, $state)) {
                throw new Exception('Cannot replace a Member that was played this turn');
            }
            if (memberBlocksBaton($existing2)) {
                throw new Exception('This Member cannot be sent to the Waiting Room via Baton Touch');
            }
            $cost = max(0, $cost - getEffectiveStageMemberCost($state, $pid, $existing2));
            break;
        }
    }
    // Replace occupant: Baton Touch (cost reduction) or regular overplay (full cost).
    $anims = [];
    $batonCount = 0;
    $batonGroups = [];
    $batonWrMembers = [];
    $batonTransferredEnergyCards = [];
    // Defer on_leave until after the incoming Member is placed so Position Change
    // (and similar) can see the post-replace Stage, then resume On Enter (#104).
    $onLeavePending = [];
    if ($isOverplay) {
        $existing = $occupant;
        $hostWrIdx = count($p['waiting_room'] ?? []);
        $state = appendCardsToWaitingRoom($state, $pid, [$existing]);
        $p = &$state['players'][$pid];
        $p['stage'][$targetSlot] = null;
        $anims[] = animSpec($existing['instance_id'], 'stage', 'waiting_room', $pid, [
            'slot' => $targetSlot,
        ]);
        $overplaySnap = $existing;
        unset($overplaySnap['stacked_members']);
        $onLeavePending[] = [
            'wr_idx' => $hostWrIdx,
            'member' => $overplaySnap,
            'ctx' => [],
        ];
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' overplayed onto ' . ($existing['name_en'] ?? $existing['name'] ?? 'Member') . '.');
    } elseif ($batonCardId && $occupant && ($occupant['instance_id'] ?? '') === $batonCardId) {
        $existing = $occupant;
        $stackedUnder = count(getMemberStackedEnergyCards($p, $existing));
        if (memberBlocksBaton($existing)) {
            throw new Exception('This Member cannot be sent to the Waiting Room via Baton Touch');
        }
        $batonTransferredEnergyCards = array_merge(
            $batonTransferredEnergyCards,
            detachStackedEnergyForBatonTransfer($existing, $p)
        );
        $hostWrIdx = count($p['waiting_room'] ?? []);
        $state = appendCardsToWaitingRoom($state, $pid, [$existing]);
        $p = &$state['players'][$pid];
        $p['stage'][$targetSlot] = null;
        $anims[] = animSpec($existing['instance_id'], 'stage', 'waiting_room', $pid, [
            'slot' => $targetSlot,
        ]);
        // Keep a member snapshot: empty-deck WR refresh can shuffle the leaving
        // card into main_deck before on_leave resolves (Kaho SD energy activate).
        // wr_idx is the HOST card (stacked Members are appended after it).
        $batonSnap = $existing;
        unset($batonSnap['stacked_members']);
        $onLeavePending[] = [
            'wr_idx' => $hostWrIdx,
            'member' => $batonSnap,
            'ctx' => ['baton_incoming' => $card],
        ];
        $card['baton_from_subunit'] = $existing['subunit'] ?? '';
        $card['baton_from_cost'] = getEffectiveStageMemberCost($state, $pid, $existing);
        $card['baton_from_group'] = $existing['group'] ?? '';
        $card['baton_from_no_ability'] = !cardHasAbilities($existing);
        $card['baton_wr_member_id'] = $existing['instance_id'] ?? '';
        // Snapshot before WR append / possible immediate deck refresh so On Enter can still stack.
        $card['baton_wr_member'] = $batonSnap;
        $batonWrMembers[] = $batonSnap;
        $card['entered_via_baton'] = true;
        $card['entered_turn'] = intval($state['turn'] ?? 1);
        $batonCount = 1;
        if (!empty($existing['group'])) {
            $batonGroups[] = $existing['group'];
        }
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' used Baton Touch! Cost reduced to ' . $cost . '.' .
            ($stackedUnder > 0 ? " ($stackedUnder Energy under replaced Member carried over.)" : ''));
    }
    if ($allowsDoubleBaton && $batonCardId2) {
        foreach ($p['stage'] as $slot => $existing2) {
            if (!$existing2 || ($existing2['instance_id'] ?? '') !== $batonCardId2) continue;
            if (memberBlocksBaton($existing2)) {
                throw new Exception('This Member cannot be sent to the Waiting Room via Baton Touch');
            }
            $batonTransferredEnergyCards = array_merge(
                $batonTransferredEnergyCards,
                detachStackedEnergyForBatonTransfer($existing2, $p)
            );
            $hostWrIdx2 = count($p['waiting_room'] ?? []);
            $state = appendCardsToWaitingRoom($state, $pid, [$existing2]);
            $p = &$state['players'][$pid];
            $p['stage'][$slot] = null;
            $anims[] = animSpec($existing2['instance_id'], 'stage', 'waiting_room', $pid, [
                'slot' => $slot,
            ]);
            $batonSnap2 = $existing2;
            unset($batonSnap2['stacked_members']);
            $onLeavePending[] = [
                'wr_idx' => $hostWrIdx2,
                'member' => $batonSnap2,
                'ctx' => ['baton_incoming' => $card],
            ];
            $batonWrMembers[] = $batonSnap2;
            $batonCount++;
            if (!empty($existing2['group'])) $batonGroups[] = $existing2['group'];
            if ($batonCount === 2) {
                $card['baton_from_group'] = $existing2['group'] ?? ($card['baton_from_group'] ?? '');
            }
            $card['entered_via_baton'] = true;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' used second Baton Touch! Cost reduced to ' . $cost . '.');
            break;
        }
    }
    if ($batonCount > 0) {
        $card['baton_count'] = $batonCount;
        $card['baton_member_groups'] = $batonGroups;
        if (!empty($batonWrMembers)) {
            $card['baton_wr_members'] = $batonWrMembers;
        }
    }

    // Pay energy cost — rested Energy stays in the zone (inactive). Only energy stacked
    // under the replaced Member (Baton) is carried to the new Member.
    $p = &$state['players'][$pid];
    $paidIds = payEnergyCostIds($p, $cost);
    if (count($paidIds) < $cost) {
        throw new Exception('Not enough active energy (need ' . $cost . ', have ' .
            countActiveEnergyInZone($p) . ')');
    }
    if (!empty($batonTransferredEnergyCards)) {
        attachStackedEnergyCardsToMember($card, $batonTransferredEnergyCards);
    }

    // Place member in slot — always from hand in this action (incl. Baton Touch / overplay).
    // Baton does not change the origin: the card still entered from the hand (#69).
    $card['entered_turn'] = intval($state['turn'] ?? 1);
    $card['entered_from_hand'] = true;
    $p['stage'][$targetSlot] = $card;
    if ($bp7CostOption !== null) {
        // Paying the option can remove other hand cards, so re-find the played card.
        $state = bp7ApplyHandPlayCostOption($state, $pid, $card, $bp7CostOption, [
            'discard_named_ids' => is_array($data['bp7_discard_named_ids'] ?? null)
                ? $data['bp7_discard_named_ids']
                : [],
        ]);
        $p = &$state['players'][$pid];
        $reIdx = findInHand($p['hand'], $instanceId);
        $cardIdx = ($reIdx === false) ? $cardIdx : $reIdx;
    }
    if (isset($p['hand'][$cardIdx])) {
        array_splice($p['hand'], $cardIdx, 1);
    }

    $state = addLog($state, $state['players'][$pid]['name'] . ' played ' .
        ($card['name_en'] ?? $card['name']) . ' to ' . $targetSlot . ' area.', 'action', array_merge($anims, [[
            'iid'  => $card['instance_id'],
            'from' => 'hand',
            'to'   => 'stage',
            'pid'  => $pid,
            'slot' => $targetSlot,
            'from_index' => $cardIdx,
        ]]));

    // On Leave after place: Stage includes the replacement for Position Change (#104).
    // BP07 Baton stacks (019 Energy-deck / SP-bp7-001 self) queue during on-leave,
    // so apply them after each leave — not before, when the pending list is still empty.
    $p = &$state['players'][$pid];
    foreach ($onLeavePending as $pending) {
        $wrIdx = intval($pending['wr_idx'] ?? -1);
        $leaving = null;
        if ($wrIdx >= 0 && isset($p['waiting_room'][$wrIdx])) {
            $leaving = $p['waiting_room'][$wrIdx];
        } elseif (!empty($pending['member']) && is_array($pending['member'])) {
            // Deck refresh may have already shuffled the leaving Member out of WR.
            $leaving = $pending['member'];
        }
        if (!$leaving) {
            continue;
        }
        $state = resolveOnLeaveStageAbilities($state, $pid, $leaving, $pending['ctx'] ?? []);
        $state = bp7ApplyPendingBatonStacks($state, $pid, $targetSlot);
        $p = &$state['players'][$pid];
        if ($wrIdx >= 0 && isset($p['waiting_room'][$wrIdx])) {
            $p['waiting_room'][$wrIdx] = $leaving;
        }
        if (!empty($state['pending_prompt'])) {
            break;
        }
    }
    // Refresh stage copy (leave may have mutated via references elsewhere).
    $card = $state['players'][$pid]['stage'][$targetSlot] ?? $card;
    if (!empty($state['pending_prompt'])) {
        // Leave prompt owns the UI — finish On Enter after it resolves (#104).
        $state['_resume_on_enter'] = [
            'pid' => $pid,
            'entered_id' => (string)($card['instance_id'] ?? $instanceId),
            'slot' => $targetSlot,
        ];
    } else {
        $state = resolveOnEnterAbilities($state, $pid, $card, $targetSlot);
    }
    $state = nijiOnMemberEntered($state, $pid, $card);
    $state['seq']++;
    return $state;
}

function actionEndMain(array $state, string $pid): array {
    validateTurn($state, $pid, 'main');
    assertNoPendingPromptForPhaseAdvance($state);
    $first = $state['first_player'];
    $second = ($first === 'p1') ? 'p2' : 'p1';

    if ($state['active_player'] === $first && $state['phase'] === 'main_first') {
        $state = addLog($state, $state['players'][$pid]['name'] . ' — End Main Phase.');
        $state['active_player'] = $second;
        $state = runPlayerTurnPrep($state, $second);
        $state['phase'] = 'main_second';
        $state = addLog($state, possessiveName($state['players'][$second]['name']) . ' turn — Main Phase (Active · Energy · Draw complete).');
    } elseif ($state['active_player'] === $second && $state['phase'] === 'main_second') {
        $state = addLog($state, $state['players'][$pid]['name'] . ' — End Main Phase.');
        $state = clearLiveStorageBeforeLiveSet($state);
        $state['phase'] = 'live_set';
        $state['live_ready'] = ['p1' => false, 'p2' => false];
        $state['active_player'] = $first;
        markCpuLiveSetWait($state, $first);
        $state = addLog($state, '=== LIVE Phase ===');
        $state = addLog($state, 'LIVE Phase: place 0–3 cards (Live or Member) face-down in Live storage (draw 1 per card placed), then end LIVE Phase.');
    }
    refreshPvpPhaseTimers($state);
    $state['seq']++;
    return $state;
}

function isLiveSetPhase(string $phase): bool {
    return $phase === 'live_set';
}

/**
 * Issue #95: Live storage must be empty when a new LIVE Phase starts.
 * Leftovers from a failed drain (or interrupted judge) would otherwise stay in
 * live_zone and get ADDED to by set_live_cards — ghost Lives the player never chose.
 */
function clearLiveStorageBeforeLiveSet(array $state): array {
    // A stuck live_show (e.g. softlocked Live Start) must not survive into the next
    // LIVE Phase — clients would keep presenting the prior round's played Lives.
    if (!empty($state['live_show'])) {
        unset($state['live_show']);
    }
    unset(
        $state['_live_played_snapshot'],
        $state['_live_perf_snapshot'],
        $state['_live_round_success_snapshot'],
        $state['_yell_reveal_snapshot'],
        $state['_yell_blade_snapshot'],
        $state['_stage_hearts_snapshot']
    );
    $anims = [];
    $moved = 0;
    foreach (['p1', 'p2'] as $pid) {
        $zone = $state['players'][$pid]['live_zone'] ?? [];
        if ($zone === []) {
            continue;
        }
        $keep = [];
        foreach ($zone as $li => $lc) {
            if (!$lc) {
                continue;
            }
            // Hime bp2-018 etc.: face-up WR Lives placed during Main must stay.
            if (function_exists('liveZoneCardIsPreplaced') && liveZoneCardIsPreplaced($lc)) {
                $keep[] = $lc;
                continue;
            }
            $anims[] = animSpec($lc['instance_id'] ?? '', 'live', 'waiting_room', $pid, [
                'from_index' => liveZoneSlotOf($lc, $li),
            ]);
            $state['players'][$pid]['waiting_room'][] = $lc;
            $moved++;
        }
        $state['players'][$pid]['live_zone'] = $keep;
    }
    if ($moved > 0) {
        $state = addLog(
            $state,
            "Cleared $moved leftover Live storage card(s) before LIVE Phase.",
            'action',
            $anims
        );
    }
    return $state;
}

function liveSetTurnOrder(array $state): array {
    $first = in_array($state['first_player'] ?? '', ['p1', 'p2'], true)
        ? $state['first_player']
        : 'p1';
    $second = ($first === 'p1') ? 'p2' : 'p1';
    return [$first, $second];
}

function ensureLiveSetReadyState(array &$state): void {
    if (!isset($state['live_ready']) || !is_array($state['live_ready'])) {
        $state['live_ready'] = ['p1' => false, 'p2' => false];
        return;
    }
    foreach (['p1', 'p2'] as $pid) {
        $state['live_ready'][$pid] = !empty($state['live_ready'][$pid]);
    }
}

function currentLiveSetPlayer(array $state): ?string {
    ensureLiveSetReadyState($state);
    $active = $state['active_player'] ?? null;
    if (in_array($active, ['p1', 'p2'], true) && empty($state['live_ready'][$active])) {
        return $active;
    }
    foreach (liveSetTurnOrder($state) as $pid) {
        if (empty($state['live_ready'][$pid])) {
            return $pid;
        }
    }
    return null;
}

function setNextLiveSetPlayer(array &$state): ?string {
    ensureLiveSetReadyState($state);
    foreach (liveSetTurnOrder($state) as $pid) {
        if (empty($state['live_ready'][$pid])) {
            $state['active_player'] = $pid;
            return $pid;
        }
    }
    return null;
}

function liveSetPhaseLog(array $state, string $pid): string {
    $name = $state['players'][$pid]['name'] ?? $pid;
    return possessiveName($name) . ' Live Phase.';
}

// ─────────────────────────────────────────────
// LIVE Phase (live_set) — face-down Live storage
// ─────────────────────────────────────────────
// Each player, in turn order, places 0–3 Live or Member cards; draw 1 per card
// placed. end_live_set advances to the next player, then Performance reveals once both are ready.

function actionSetLiveCards(array $state, string $pid, array $data): array {
    if (($state['phase'] ?? '') !== 'live_set') {
        // Client board lag after End LIVE / Performance advance — treat as already done.
        $state['_live_set_noop'] = true;
        return $state;
    }
    ensureLiveSetReadyState($state);
    if (currentLiveSetPlayer($state) !== $pid) {
        throw new Exception('Not your turn');
    }
    if (!empty($state['live_ready'][$pid])) {
        $state['_live_set_noop'] = true;
        return $state;
    }

    $cardIds = $data['card_ids'] ?? [];
    $p = &$state['players'][$pid];
    $physicalMax = 3;
    $penalty = intval($p['live_set_cap_penalty'] ?? 0);
    $placeMax = max(0, $physicalMax - $penalty);
    $removeIds = $data['remove_ids'] ?? [];
    $anims = [];
    foreach ($removeIds as $rid) {
        if (!is_string($rid) || $rid === '') {
            continue;
        }
        $removed = null;
        $fromSlot = 0;
        $newZone = [];
        foreach ($p['live_zone'] as $li => $c) {
            if (($c['instance_id'] ?? '') === $rid) {
                if (function_exists('liveZoneCardIsPreplaced') && liveZoneCardIsPreplaced($c)) {
                    $newZone[] = $c;
                    continue;
                }
                $removed = $c;
                $fromSlot = liveZoneSlotOf($c, $li);
                continue;
            }
            $newZone[] = $c;
        }
        if (!$removed) {
            continue;
        }
        $p['live_zone'] = $newZone;
        $p['hand'][] = $removed;
        $anims[] = animSpec($rid, 'live', 'hand', $pid, [
            'from_index' => $fromSlot,
            'index'      => count($p['hand']) - 1,
        ]);
    }

    $placedThisSet = 0;
    foreach ($p['live_zone'] as $c) {
        if ($c && !(function_exists('liveZoneCardIsPreplaced') && liveZoneCardIsPreplaced($c))) {
            $placedThisSet++;
        }
    }
    $slotsLeft = min(
        $physicalMax - liveZoneCount($p['live_zone']),
        max(0, $placeMax - $placedThisSet)
    );
    if ($slotsLeft <= 0 && !empty($cardIds)) {
        throw new Exception('Live Card storage is full (max ' . $placeMax . ')');
    }
    $cardIds = array_slice($cardIds, 0, $slotsLeft);

    $added = 0;
    // cannot_live (Rurino bp2-014 etc.): Lives may still be placed in storage;
    // they are dumped to WR as a discard before Performance and never attempt.
    foreach ($cardIds as $cid) {
        $idx = findInHand($p['hand'], $cid);
        if ($idx === false) continue;
        $c = $p['hand'][$idx];
        if (!isLiveStorageEligible($c)) continue;
        $slot = liveZoneFirstEmptySlot($p['live_zone']);
        if ($slot < 0) break;
        $c['revealed'] = false;
        $c['live_slot'] = $slot;
        $p['live_zone'][] = $c;
        array_splice($p['hand'], $idx, 1);
        $anims[] = animSpec($c['instance_id'], 'hand', 'live', $pid, [
            'index' => $slot,
            'from_index' => $idx,
        ]);
        $drawn = drawMainDeckCards($state, $pid, 1);
        $p['hand'] = array_merge($p['hand'], $drawn);
        if (!empty($drawn)) {
            $anims[] = animSpec($drawn[0]['instance_id'], 'main_deck', 'hand', $pid, [
                'index' => count($p['hand']) - 1,
            ]);
        }
        $added++;
    }

    if ($added > 0) {
        $name = $state['players'][$pid]['name'];
        $state = addLog(
            $state,
            "$name placed $added card(s) face-down in storage (" . liveZoneCount($p['live_zone']) . '/3).',
            'action',
            $anims,
            ['owner' => $pid, 'msg_public' => "$name placed card(s) in Live storage."]
        );
    }

    $state['seq']++;
    return $state;
}

function actionEndLiveSet(array $state, string $pid): array {
    if (($state['phase'] ?? '') !== 'live_set') {
        // Double-click / heal / stale board after the first lock already advanced.
        $state['_live_set_noop'] = true;
        return $state;
    }
    ensureLiveSetReadyState($state);
    if (currentLiveSetPlayer($state) !== $pid) {
        throw new Exception('Not your turn');
    }
    assertNoPendingPromptForPhaseAdvance($state);
    if (!empty($state['live_ready'][$pid])) {
        $state['_live_set_noop'] = true;
        return $state;
    }

    $name = $state['players'][$pid]['name'];
    $stored = liveZoneCount($state['players'][$pid]['live_zone']);
    if (isset($state['players'][$pid]['live_set_cap_penalty'])) {
        $state['players'][$pid]['live_set_cap_penalty'] = 0;
    }
    $state['live_ready'][$pid] = true;
    $state = addLog(
        $state,
        "$name — locked in LIVE selection ($stored card(s) in storage).",
        'action',
        [],
        ['owner' => $pid, 'msg_public' => "$name — locked in LIVE selection."]
    );

    if (!empty($state['live_ready']['p1']) && !empty($state['live_ready']['p2'])) {
        clearCpuLiveSetWait($state);
        unset($state['live_ready']);
        $state = beginPerformancePhase($state);
    } else {
        $nextPid = setNextLiveSetPlayer($state);
        if ($nextPid) {
            $state = addLog($state, liveSetPhaseLog($state, $nextPid), 'info');
            markCpuLiveSetWait($state, $nextPid);
        } else {
            clearCpuLiveSetWait($state);
        }
    }
    refreshPvpPhaseTimers($state);
    $state['seq']++;
    return $state;
}

function revealAllLiveStorage(array $state): array {
    foreach (['p1', 'p2'] as $pid) {
        $state = revealLiveStorageForPlayer($state, $pid);
    }
    return $state;
}

/** Flip one player's Live storage face-up (per-performer reveal order). */
function revealLiveStorageForPlayer(array $state, string $pid): array {
    $zone = $state['players'][$pid]['live_zone'] ?? [];
    foreach ($zone as $i => $c) {
        if (!$c) {
            continue;
        }
        $zone[$i]['revealed'] = true;
    }
    $state['players'][$pid]['live_zone'] = $zone;
    return $state;
}

/** Public log line naming the Lives this performer just revealed. */
function logPerformerLiveReveal(array $state, string $pid): array {
    $lives = array_values(array_filter(
        $state['players'][$pid]['live_zone'] ?? [],
        fn($c) => isLiveTypeCard($c)
    ));
    if ($lives === []) {
        return $state;
    }
    $labels = array_map(
        fn($c) => '"' . ($c['name_en'] ?? $c['name'] ?? 'Live') . '"',
        $lives
    );
    return addLog(
        $state,
        ($state['players'][$pid]['name'] ?? $pid) .
        ' reveals Live storage: ' . implode(' and ', $labels) . '.',
        'action'
    );
}

/**
 * Reveal one performer's storage, then park on live_show reveal (or start Live Start
 * immediately when there is no presentation cursor).
 */
function beginPerformerRevealThenLiveStart(array $state, string $pid): array {
    $attempting = $state['live_attempt'] ?? [];
    if (!in_array($pid, $attempting, true)) {
        return resolvePerformancePhase($state, $pid);
    }
    $state = revealLiveStorageForPlayer($state, $pid);
    $state = logPerformerLiveReveal($state, $pid);
    $state['_live_start_perf_pid'] = $pid;
    $state['phase'] = 'live_start_effects';
    if (!empty($state['live_show']) && empty($state['tutorial_guide'])) {
        if (!is_array($state['live_show'])) {
            $state['live_show'] = [];
        }
        $state['live_show']['performer'] = $pid;
        return setLiveShowStage($state, 'reveal');
    }
    return beginLiveStartForPerformer($state, $pid);
}

// ─────────────────────────────────────────────
// Performance Phase — Yell, hearts, Live success
// ─────────────────────────────────────────────
// Per-performer reveal → Live Start → Yell (official 8.3), then the next performer.
// Do not flip both players' storage at once — second player must commit to Live Start
// without seeing the opponent's Lives until after the first performer has yelled.

function beginPerformancePhase(array $state): array {
    foreach (['p1', 'p2'] as $banPid) {
        $state = discardCannotLiveStorageToWaitingRoom($state, $banPid);
    }
    unset(
        $state['yell_reveal'],
        $state['live_perf_success'],
        $state['live_round_success'],
        $state['_yell_reveal_snapshot'],
        $state['_yell_blade_snapshot'],
        $state['_live_perf_snapshot'],
        $state['_live_round_success_snapshot'],
        $state['_stage_hearts_snapshot'],
        $state['_deferred_mp_extra_hearts']
    );
    // Official 8.3.4: after reveal, Member bluffs go to WR before Live Start (8.3.8).
    // discardLiveZoneMembersToWaitingRoom runs at Live Start entry (and again at
    // outcomes as a no-op). Zone-count skills already ignore non-Live cards.
    if (!performanceRoundHasLiveCards($state)) {
        return skipEmptyPerformanceRound($state);
    }
    $state = addLog($state, '=== Performance Phase ===');
    // Sequential live_show cursor: park on reveal first so clients/spectators can
    // flip the current performer's storage before Live Start skills and Yell math run.
    if (empty($state['tutorial_guide'])) {
        $state['live_attempt'] = [];
        $first = $state['first_player'] ?? 'p1';
        $second = ($first === 'p1') ? 'p2' : 'p1';
        foreach ([$first, $second] as $pid) {
            if (playerParticipatingInLiveRound($state, $pid)) {
                $state['live_attempt'][] = $pid;
            }
        }
        $state['live_round_success'] = [];
        foreach (['p1', 'p2'] as $pid) {
            if (!in_array($pid, $state['live_attempt'], true)) {
                $state['live_round_success'][$pid] = false;
            }
        }
        $state = initLiveModifiers($state);
        $state['phase'] = 'live_start_effects';
        $revealPid = $state['live_attempt'][0] ?? null;
        $state['live_show'] = [
            'turn' => intval($state['turn'] ?? 1),
            'stage' => 'reveal',
            'performer' => $revealPid,
            'started_at' => time(),
            'stage_seq' => 1,
            'acks' => [],
            'played_lives' => snapshotLiveShowPlayedLives($state),
        ];
        // Drop prior-round carryover so clients cannot hydrate ghost Lives.
        unset(
            $state['_live_played_snapshot'],
            $state['_live_perf_snapshot'],
            $state['_live_round_success_snapshot']
        );
        if ($revealPid) {
            $state = revealLiveStorageForPlayer($state, $revealPid);
            $state = logPerformerLiveReveal($state, $revealPid);
        }
        return $state;
    }
    // Tutorials: reveal both then run Live Start (no live_show presentation cursor).
    $state = revealAllLiveStorage($state);
    return beginLiveStartEffectPhase(
        $state,
        playerParticipatingInLiveRound($state, 'p1'),
        playerParticipatingInLiveRound($state, 'p2')
    );
}

function liveStorageHasAnyCards(array $state): bool {
    foreach (['p1', 'p2'] as $pid) {
        if (!empty($state['players'][$pid]['live_zone'])) {
            return true;
        }
    }
    return false;
}

function performanceRoundHasLiveCards(array $state): bool {
    foreach (['p1', 'p2'] as $pid) {
        if (playerAttemptingLivePerformance($state, $pid)) {
            return true;
        }
    }
    return false;
}

/** True when this player placed any card in Live storage this round (Live or Member bluff). */
function playerParticipatingInLiveRound(array $state, string $pid): bool {
    if (!empty($state['live_modifiers'][$pid]['cannot_live'])) {
        return false;
    }
    foreach ($state['players'][$pid]['live_zone'] ?? [] as $c) {
        if ($c) {
            return true;
        }
    }
    return false;
}

/** True when this player has at least one Live card in Live storage to perform. */
function playerAttemptingLivePerformance(array $state, string $pid): bool {
    if (!playerParticipatingInLiveRound($state, $pid)) {
        return false;
    }
    foreach ($state['players'][$pid]['live_zone'] ?? [] as $c) {
        if ($c && isLiveTypeCard($c)) {
            return true;
        }
    }
    return false;
}

/**
 * Whether Stage/Live [Live Start] skills may resolve for this seat.
 * Member-bluff-only storage must not fire Live Start. Empty storage is allowed
 * so isolated skill unit tests can call resolveLiveStartAbilities without a full
 * Performance setup (real empty seats are already excluded from live_attempt).
 */
function playerShouldResolveLiveStart(array $state, string $pid): bool {
    if (!empty($state['live_modifiers'][$pid]['cannot_live'])) {
        return false;
    }
    $anyStorage = false;
    foreach ($state['players'][$pid]['live_zone'] ?? [] as $c) {
        if (!$c) {
            continue;
        }
        $anyStorage = true;
        if (isLiveTypeCard($c)) {
            return true;
        }
    }
    return !$anyStorage;
}

/** Instance ids of Live cards in storage — frozen for spectacle / Live Judge rows. */
function snapshotLiveShowPlayedLives(array $state): array {
    $out = ['p1' => [], 'p2' => []];
    foreach (['p1', 'p2'] as $pid) {
        foreach ($state['players'][$pid]['live_zone'] ?? [] as $c) {
            if (!$c || !isLiveTypeCard($c)) {
                continue;
            }
            $iid = (string)($c['instance_id'] ?? '');
            if ($iid !== '') {
                $out[$pid][] = $iid;
            }
        }
    }
    return $out;
}

function ensureLiveShowPlayedLives(array $state): array {
    if (empty($state['live_show']) || !is_array($state['live_show'])) {
        return $state;
    }
    $have = $state['live_show']['played_lives'] ?? null;
    if (is_array($have) && (!empty($have['p1']) || !empty($have['p2']))) {
        return $state;
    }
    $snap = snapshotLiveShowPlayedLives($state);
    if ($snap['p1'] !== [] || $snap['p2'] !== []) {
        $state['live_show']['played_lives'] = $snap;
    }
    return $state;
}

/**
 * Ruri bp2-014 etc.: ライブできない — Lives may be set, but they cannot start.
 * Dump that player's Live cards from storage to WR before Performance.
 */
function discardCannotLiveStorageToWaitingRoom(array $state, string $pid): array {
    if (empty($state['live_modifiers'][$pid]['cannot_live'])) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $dump = [];
    $keep = [];
    $anims = [];
    foreach ($p['live_zone'] ?? [] as $li => $c) {
        if (!$c) {
            continue;
        }
        if (!isLiveTypeCard($c)) {
            $keep[] = $c;
            continue;
        }
        $dump[] = $c;
        $anims[] = animSpec($c['instance_id'] ?? '', 'live', 'waiting_room', $pid, [
            'from_index' => liveZoneSlotOf($c, $li),
        ]);
    }
    if ($dump === []) {
        unset($p);
        return $state;
    }
    $p['live_zone'] = $keep;
    $p['waiting_room'] = array_merge($p['waiting_room'] ?? [], $dump);
    unset($p);
    return addLog(
        $state,
        ($state['players'][$pid]['name'] ?? 'Player') .
        ' cannot attempt a Live; Live cards in storage went to the Waiting Room.',
        null,
        $anims
    );
}

/** Yell blade hearts only apply during the current Performance round (not after judge). */
function isInPerformancePhase(array $state): bool {
    return in_array($state['phase'] ?? '', [
        'live_performance_first',
        'live_performance_second',
        'live_success_effects',
        'live_judge',
    ], true);
}

function clearYellRevealState(array $state): array {
    unset($state['yell_reveal']);
    return $state;
}

function skipEmptyPerformanceRound(array $state): array {
    $state = addLog($state, 'No Lives played this turn.');
    $meta = [
        'kind' => 'empty',
        'attempting' => [],
        'success_placed_by' => [],
    ];
    $leftoverAnims = [];
    $state = drainLiveStorageLeftovers($state, $leftoverAnims);
    if (!empty($leftoverAnims)) {
        $state = addLog($state, 'Remaining Live storage sent to Waiting Room.', null, $leftoverAnims);
    }
    if (!empty($state['pending_prompt'])) {
        $state['_pending_live_finalize'] = $meta;
        $state['seq']++;
        return $state;
    }
    return completeLiveRoundTurnAdvance($state, $meta);
}

function actionConfirmLive(array $state, string $pid, array $data): array {
    // This is used for any confirmation step mid-live (future: special abilities)
    $state['seq']++;
    return $state;
}

function setLiveShowStage(array $state, string $stage): array {
    if (empty($state['live_show']) || !is_array($state['live_show'])) {
        $state['live_show'] = [
            'turn' => intval($state['turn'] ?? 1),
            'stage_seq' => 0,
        ];
    }
    $state = ensureLiveShowPlayedLives($state);
    $state['live_show']['stage'] = $stage;
    $state['live_show']['started_at'] = time();
    $state['live_show']['stage_seq'] = intval($state['live_show']['stage_seq'] ?? 0) + 1;
    $state['live_show']['acks'] = [];
    return $state;
}

function liveShowRequiredAckPlayers(array $state): array {
    $required = [];
    foreach (['p1', 'p2'] as $pid) {
        if (empty($state['players'][$pid]) || isCpuPlayer($state['players'][$pid])) {
            continue;
        }
        $required[] = $pid;
    }
    return $required;
}

function liveShowStageFullyAcked(array $state): bool {
    $acks = $state['live_show']['acks'] ?? [];
    foreach (liveShowRequiredAckPlayers($state) as $pid) {
        if (empty($acks[$pid])) {
            return false;
        }
    }
    return true;
}

/**
 * Hold the completed heart/outcome calculation for its own presentation beat.
 * Judge scores and winner movement are intentionally not computed here.
 */
function queueLiveShowOutcomes(array $state): array {
    // Safety net: Member bluffs should already be in WR from Live Start entry
    // (official 8.3.4). Re-run so any path that skipped Live Start still discards.
    foreach (['p1', 'p2'] as $pid) {
        $state = discardLiveZoneMembersToWaitingRoom($state, $pid);
    }
    if (!empty($state['live_show'])) {
        $cur = (string)($state['live_show']['stage'] ?? '');
        // Already on/after outcomes — do not bump stage_seq again.
        if (in_array($cur, ['outcomes', 'judge', 'done'], true)) {
            return $state;
        }
        return setLiveShowStage($state, 'outcomes');
    }
    $state['phase'] = 'live_judge';
    return resolveLiveJudge($state);
}

function advanceLiveShowStage(array $state): array {
    $stage = (string)($state['live_show']['stage'] ?? '');
    if ($stage === 'reveal') {
        $performer = (string)($state['live_show']['performer']
            ?? ($state['live_attempt'][0] ?? ''));
        $attempting = $state['live_attempt'] ?? [];
        $done = $state['_live_start_done'] ?? [];
        $first = (string)($attempting[0] ?? '');
        $state = setLiveShowStage($state, 'live_start');
        // Second (or later) performer's reveal beat → their Live Start only.
        if ($performer !== '' && $first !== '' && $performer !== $first && !empty($done[$first])) {
            return beginLiveStartForPerformer($state, $performer);
        }
        return beginLiveStartEffectPhase(
            $state,
            in_array('p1', $attempting, true),
            in_array('p2', $attempting, true)
        );
    }
    if ($stage === 'live_start') {
        if (!empty($state['pending_prompt'])) {
            return $state;
        }
        // Enter performance before Yell math so mid-prompt resolves keep the cursor
        // here (acks reset) instead of re-firing live_start.
        $state = setLiveShowStage($state, 'performance');
        $state = addLog($state, '=== Live Show ===');
        $first = $state['first_player'] ?? 'p1';
        $yellPid = $state['_live_start_perf_pid'] ?? $first;
        $attempting = $state['live_attempt'] ?? ['p1', 'p2'];
        $state['phase'] = ($yellPid === $first) ? 'live_performance_first' : 'live_performance_second';
        if (in_array($yellPid, $attempting, true)) {
            return resolvePerformancePhase($state, $yellPid);
        }
        return continuePerformanceYellPhase($state, $yellPid);
    }
    if ($stage === 'performance') {
        if (!empty($state['pending_prompt'])) {
            return $state;
        }
        // Official 8.3 interleaves Live Start → Yell per performer. First Yell alone
        // populates yell_reveal — do not treat that as "both done" or the 2nd Live Start
        // / Yell is skipped when clients ack the performance beat early.
        if (empty($state['_perf_yell_both_done'])) {
            return $state;
        }
        // Yell spectacle done — resolve hearts, then park on outcomes.
        return resolvePerformanceHeartsAfterYell($state);
    }
    if ($stage === 'outcomes') {
        $state['phase'] = 'live_judge';
        return resolveLiveJudge($state);
    }
    if ($stage === 'judge') {
        $state = setLiveShowStage($state, 'done');
        return advanceLiveJudgeWinners($state);
    }
    return $state;
}

function actionLiveShowAck(array $state, string $pid, array $data): array {
    // Idempotent: client may ack after the server already cleared live_show
    // (opponent finished judge / timeout advance). Throwing softlocked the UI.
    if (empty($state['live_show']) || !is_array($state['live_show'])) {
        return $state;
    }
    $stageSeq = intval($data['stage_seq'] ?? -1);
    if ($stageSeq !== intval($state['live_show']['stage_seq'] ?? 0)) {
        return $state;
    }
    // Skills must finish before the presentation cursor can advance.
    if (!empty($state['pending_prompt'])) {
        return $state;
    }
    if (!isset($state['live_show']['acks']) || !is_array($state['live_show']['acks'])) {
        $state['live_show']['acks'] = [];
    }
    $state['live_show']['acks'][$pid] = true;
    if (liveShowStageFullyAcked($state)) {
        $state = advanceLiveShowStage($state);
    }
    $state['seq']++;
    return $state;
}

// ─────────────────────────────────────────────
// Game Flow Helpers
// ─────────────────────────────────────────────
function possessiveName(string $name): string {
    if ($name === '') {
        return '';
    }
    $last = substr($name, -1);
    if ($last === 's' || $last === 'S') {
        return $name . "'";
    }
    return $name . "'s";
}

function startTurn(array $state): array {
    unset($state['block_effect_member_activate'], $state['skill_reveals']);
    // Mulligan / coin-flip leave status=setup; softlock skip + Shift+T require playing.
    $state['status'] = 'playing';
    $state['phase'] = 'active_first';
    $first = $state['first_player'];
    $state['active_player'] = $first;
    $state = addLog($state, '--- Turn ' . $state['turn'] . ' ---');
    $state = runPlayerTurnPrep($state, $first);
    $state['phase'] = 'main_first';
    $state = addLog($state, possessiveName($state['players'][$first]['name']) . ' turn — Main Phase (Active · Energy · Draw complete).');
    refreshPvpPhaseTimers($state);
    return $state;
}

function runPlayerTurnPrep(array $state, string $pid): array {
    $name = $state['players'][$pid]['name'];
    $state = addLog($state, "$name — Active Phase: Energy and Members refreshed.");
    $state = doActivePhase($state, $pid);
    $state = doEnergyPhase($state, $pid);
    $state = doDrawPhase($state, $pid);
    return $state;
}

function doActivePhase(array $state, string $pid): array {
    $p = &$state['players'][$pid];
    // Per-turn Nijigasaki activation tracking (Cara Tesoro Live Start, etc.).
    unset($p['_niji_turn_flags'], $p['_effect_source_is_niji']);
    unset($p['succeeded_live_this_turn']);
    // Active Phase: stand all Energy in storage (spent last turn becomes usable again).
    foreach ($p['energy_zone'] as &$e) {
        // PL!SP-bp7-005 puts Energy into Wait "locked": it skips exactly one Active Phase.
        if (!empty($e['skip_activate_next_turn'])) {
            unset($e['skip_activate_next_turn']);
            $e['active'] = false;
            continue;
        }
        $e['active'] = true;
    }
    unset($e);
    foreach ($p['stage'] as &$m) {
        if ($m) {
            if (!empty($m['skip_activate_next_turn'])) {
                $m['skip_activate_next_turn'] = false;
                unset($m['abilities_used']);
                clearMemberPerTurnAutoUses($m);
                continue;
            }
            if (nBp5MemberSkipsActivePhase($m)) {
                unset($m['abilities_used']);
                clearMemberPerTurnAutoUses($m);
                continue;
            }
            if (hsPb1OpponentStageBlockedFromActivate($state, $pid)) {
                unset($m['abilities_used']);
                clearMemberPerTurnAutoUses($m);
                continue;
            }
            if (memberIsInWait($m)) {
                $waitedTurn = intval($m['waited_turn'] ?? 0);
                $waitedBy = (string)($m['waited_active_player'] ?? '');
                $turn = intval($state['turn'] ?? 1);
                // Clear on owner's Active Phase unless self-Waited during their own turn
                // (e.g. On Enter Wait during Main) — that lasts until next turn Active.
                // Legacy rows without waited_active_player keep the old turn-only rule.
                if ($waitedBy === '') {
                    $clearWait = $waitedTurn > 0 && $waitedTurn < $turn;
                } else {
                    $clearWait = ($waitedTurn > 0 && $waitedTurn < $turn) || ($waitedBy !== $pid);
                }
                if ($clearWait) {
                    clearMemberWait($m);
                    unset($m['abilities_used']);
                    clearMemberPerTurnAutoUses($m);
                } else {
                    unset($m['abilities_used']);
                    clearMemberPerTurnAutoUses($m);
                }
                continue;
            }
            $m['active'] = true;
            unset($m['abilities_used']);
            clearMemberPerTurnAutoUses($m);
        }
    }
    unset($m);
    $p['members_entered_this_turn'] = 0;
    foreach ($p['stage'] as &$mbr) {
        if ($mbr) {
            unset($mbr['entered_this_turn'], $mbr['moved_this_turn']);
        }
    }
    unset($mbr);
    return $state;
}

function doEnergyPhase(array $state, string $pid): array {
    $p = &$state['players'][$pid];
    $name = $p['name'];
    $zoneCount = count($p['energy_zone'] ?? []);
    if ($zoneCount >= ENERGY_ZONE_MAX) {
        $state = addLog($state, "$name — Energy Phase: storage full ($zoneCount/" . ENERGY_ZONE_MAX . '), no Energy added.');
        return $state;
    }
    if (empty($p['energy_deck'])) {
        $state = addLog($state, "$name — Energy Phase: no cards left in Energy deck.");
        return $state;
    }
    [$drawn, $p['energy_deck']] = drawCards($p['energy_deck'], 1);
    $anims = [];
    foreach ($drawn as $e) {
        $e['active'] = true;
        $p['energy_zone'][] = $e;
        $anims[] = animSpec($e['instance_id'], 'energy_deck', 'energy', $pid, [
            'index' => count($p['energy_zone']) - 1,
        ]);
    }
    $state = addLog($state, "$name — Energy Phase: placed 1 Energy in storage (" .
        count($p['energy_zone']) . '/' . ENERGY_ZONE_MAX . ').', 'action', $anims);
    return $state;
}

function doDrawPhase(array $state, string $pid): array {
    $p = &$state['players'][$pid];
    $name = $p['name'];
    $drawn = drawMainDeckCards($state, $pid, 1);
    if (!empty($drawn)) {
        $anims = [];
        foreach ($drawn as $c) {
            $p['hand'][] = $c;
            $anims[] = animSpec($c['instance_id'], 'main_deck', 'hand', $pid, [
                'index' => count($p['hand']) - 1,
            ]);
        }
        // drawMainDeckCards already refreshes when the last card is taken; re-check
        // in case the drawn card was the final one and WR still has cards.
        refreshMainDeckFromWaitingRoom($state, $pid);
        $state = addLog($state, "$name — Draw Phase.", 'action', $anims);
    } else {
        $state = addLog($state, "$name — Draw Phase: could not draw (deck and Waiting Room empty).");
    }
    return $state;
}

/** Queue Dia Kurosawa optional Yell retry until both players finish Yell reveal. */
function queueYellRetryOffer(
    array $state,
    string $pid,
    string $slot,
    int $idx,
    array $ab,
    string $mName
): array {
    $state['_yell_retry_offers'] = $state['_yell_retry_offers'] ?? [];
    $state['_yell_retry_offers'][] = [
        'owner'         => $pid,
        'member_slot'   => $slot,
        'ability_index' => $idx,
        'ability'       => $ab,
        'source_name'   => $mName,
    ];
    return $state;
}

function openNextYellRetryPrompt(array $state): array {
    $offers = $state['_yell_retry_offers'] ?? [];
    if (empty($offers)) {
        return finishYellRetryAndHearts($state);
    }
    $offer = array_shift($offers);
    $state['_yell_retry_offers'] = $offers;
    $pid = $offer['owner'];
    $state['pending_prompt'] = [
        'type'          => 'auto_yell_no_live_retry',
        'owner'         => $pid,
        'responder'     => $pid,
        'source_name'   => $offer['source_name'] ?? 'Member',
        'prompt'        => 'Put all cards revealed for Yell into the Waiting Room, lose Blade hearts from that Yell, and perform Yell again?',
        'choices'       => ['yes', 'no'],
        'choice_labels' => ['Yes — Retry Yell', 'No — Keep Yell'],
        'ability'       => $offer['ability'] ?? [],
        'member_slot'   => $offer['member_slot'] ?? '',
        'ability_index' => $offer['ability_index'] ?? 0,
    ];
    $state['phase'] = 'live_success_effects';
    $state['_perf_yell_both_done'] = true;
    $state['_performance_continue'] = $pid;
    return $state;
}

/** Draw Yell cards for a player (shared by initial Yell and retry). */
function drawYellCardsForPlayer(array $state, string $pid): array {
    $p = &$state['players'][$pid];
    $totalBlade = computeYellBladeTotal($state, $pid);
    $state = initLiveModifiers($state);
    $yellReduction = intval($state['live_modifiers'][$pid]['yell_reveal_reduction'] ?? 0);
    $drawBlade = max(0, $totalBlade - $yellReduction);
    $yellCards = [];
    if ($drawBlade > 0) {
        // PL!S-bp7-022 flips this seat's Yell to come off the bottom of the deck.
        $yellCards = drawYellDeckCards($state, $pid, $drawBlade);
    }
    foreach ($yellCards as &$yc) {
        mergeYellCardCatalogFields($yc);
    }
    unset($yc);
    $state = recordYellRevealSnapshot($state, $pid, $yellCards, true);
    return [$state, $yellCards, $totalBlade, $drawBlade, $yellReduction];
}

/** Current yell-reveal pool for a player during Performance. */
function currentPlayerYellCards(array $state, string $pid): array {
    $p = $state['players'][$pid] ?? [];
    return $p['yell_cards'] ?? $state['yell_reveal'][$pid] ?? $state['_last_yell_cards'] ?? [];
}

function recordYellRevealSnapshot(array $state, string $pid, array $cards, bool $replace = false): array {
    if ($replace) {
        $state['_yell_revealed_snapshot'][$pid] = array_values($cards);
        return $state;
    }
    $byId = [];
    foreach ($state['_yell_revealed_snapshot'][$pid] ?? [] as $c) {
        $id = (string)($c['instance_id'] ?? '');
        if ($id !== '') {
            $byId[$id] = $c;
        }
    }
    foreach ($cards as $c) {
        $id = (string)($c['instance_id'] ?? '');
        if ($id !== '') {
            $byId[$id] = $c;
        } else {
            $byId[] = $c;
        }
    }
    $state['_yell_revealed_snapshot'][$pid] = array_values($byId);
    return $state;
}

function snapshotPerformanceLiveScores(array $state): array {
    if (!empty($state['_perf_live_score_snapshot']) && is_array($state['_perf_live_score_snapshot'])) {
        return $state;
    }
    $state['_perf_live_score_snapshot'] = [
        'p1' => getLiveTotalScore($state, 'p1'),
        'p2' => getLiveTotalScore($state, 'p2'),
    ];
    return $state;
}

/** Keep yell_cards, yell_reveal, and _last_yell_cards in sync during Performance. */
function syncPlayerYellPools(array $state, string $pid, array $yellCards): array {
    $state['players'][$pid]['yell_cards'] = $yellCards;
    if (!isset($state['yell_reveal'])) {
        $state['yell_reveal'] = [];
    }
    $state['yell_reveal'][$pid] = $yellCards;
    $state['_last_yell_cards'] = $yellCards;
    $state = recordYellRevealSnapshot($state, $pid, $yellCards, false);
    $state['_last_yell_live_count'] = countYellLiveCards($yellCards);
    $state['_last_yell_live_count_' . $pid] = countYellLiveCards($yellCards);
    return $state;
}

/** Mill selected yell-revealed cards into the Waiting Room and update yell pools. */
function millPlayerYellCardsToWr(array $state, string $pid, array $ids): array {
    if (empty($ids)) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $pool = currentPlayerYellCards($state, $pid);
    $milled = [];
    $remaining = [];
    foreach ($pool as $c) {
        if (in_array($c['instance_id'] ?? '', $ids, true)) {
            $milled[] = $c;
        } else {
            $remaining[] = $c;
        }
    }
    if (empty($milled)) {
        unset($p);
        return $state;
    }
    $p['waiting_room'] = array_merge($p['waiting_room'] ?? [], $milled);
    unset($p);
    $state = syncPlayerYellPools($state, $pid, $remaining);
    return $state;
}

/**
 * Perform N additional Yell card reveals (merge into current yell pool).
 *
 * 総合ルール 8.3.11: one 「エール」 = move the top deck card once.
 * Card text like 「等しい枚数のエール」/「追加で2枚エール」means N flips, not N×Blade
 * full procedures (which wrongly flipped 30–40 with high Blade + Kurage mill).
 * Stops early if a new auto-yell prompt is queued.
 */
function executeExtraYellDraws(array $state, string $pid, int $count, string $sourceName = 'Member'): array {
    if ($count <= 0) {
        return $state;
    }
    $drawn = drawMainDeckCards($state, $pid, $count);
    foreach ($drawn as &$yc) {
        mergeYellCardCatalogFields($yc);
    }
    unset($yc);
    $prior = array_merge(currentPlayerYellCards($state, $pid), $drawn);
    $state = syncPlayerYellPools($state, $pid, $prior);
    $n = count($drawn);
    if ($n > 0) {
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — Extra Yell [$sourceName]: drew $n card(s).");
    }
    $state = resolveAutoYellAbilities($state, $pid, $prior);
    return $state;
}

/** After yell-phase optional abilities resolve, continue Performance (heart check deferred). */
function continuePerformanceAfterYellAbilities(array $state, string $pid): array {
    if (!empty($state['pending_prompt'])) {
        $state['_performance_continue'] = $pid;
        return $state;
    }
    if (!empty($GLOBALS['TUT_PERF_MANUAL_PHASES'])) {
        return $state;
    }
    if (!empty($state['_perf_yell_both_done'])) {
        return finishYellRetryAndHearts($state);
    }
    return continuePerformanceYellPhase($state, $pid);
}

/** WR prior Yell cards and perform a fresh Yell draw (Blade hearts from prior Yell lost). */
function executeYellRetry(array $state, string $pid, array $prompt): array {
    $p = &$state['players'][$pid];
    $prior = $p['yell_cards'] ?? $state['yell_reveal'][$pid] ?? [];
    if (!empty($prior)) {
        $p['waiting_room'] = array_merge($p['waiting_room'], $prior);
    }
    $p['yell_cards'] = [];
    if (!isset($state['yell_reveal'])) {
        $state['yell_reveal'] = [];
    }
    $state['yell_reveal'][$pid] = [];

    [$state, $yellCards, $totalBlade, $drawBlade, $yellReduction] = drawYellCardsForPlayer($state, $pid);
    $p = &$state['players'][$pid];
    $p['yell_cards'] = $yellCards;
    $state['yell_reveal'][$pid] = $yellCards;

    if ($drawBlade > 0) {
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — Yell retry: drew $drawBlade card(s) for Blade.");
    } elseif ($yellReduction > 0 && $totalBlade > 0) {
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — Yell retry reduced by $yellReduction (drew 0 of $totalBlade Blade).");
    }

    $state['_last_yell_live_count'] = countYellLiveCards($yellCards);
    $state['_last_yell_live_count_' . $pid] = countYellLiveCards($yellCards);
    $state['_last_yell_cards'] = $yellCards;
    $state = resolveAutoYellAbilities($state, $pid, $yellCards);

    $mName = $prompt['source_name'] ?? 'Member';
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$mName] Yell cards to Waiting Room; Yell again (Blade hearts from prior Yell lost).");
    return $state;
}

function finishYellRetryAndHearts(array $state): array {
    unset($state['_yell_retry_offers']);
    $state['_perf_yell_both_done'] = true;
    // Sequential live_show: hold after Yell draws so the client can watch the
    // performance beat before heart checks mutate Live storage. Once hearts have
    // started (_perf_hearts_resolved), always resume them (Live Success prompts).
    if (!empty($state['live_show'])
        && ($state['live_show']['stage'] ?? '') === 'performance'
        && empty($state['_perf_hearts_resolved'])) {
        return $state;
    }
    return resolvePerformanceHeartsAfterYell($state);
}

function resolvePerformanceHeartsAfterYell(array $state): array {
    $state = snapshotPerformanceLiveScores($state);
    $first  = $state['first_player'];
    $second = ($first === 'p1') ? 'p2' : 'p1';
    $attempting = $state['live_attempt'] ?? ['p1', 'p2'];
    $resolved = $state['_perf_hearts_resolved'] ?? [];

    foreach ([$first, $second] as $pid) {
        if (!in_array($pid, $attempting, true)) {
            continue;
        }
        if (!empty($resolved[$pid])) {
            continue;
        }
        $liveCards = array_values(array_filter(
            $state['players'][$pid]['live_zone'] ?? [],
            fn($c) => isLiveTypeCard($c)
        ));
        if (empty($liveCards)) {
            $resolved[$pid] = true;
            continue;
        }
        $state = resolvePerformanceHeartCheck($state, $pid, false);
        if (!empty($state['pending_prompt'])) {
            $state['phase'] = 'live_success_effects';
            $state['_performance_continue'] = $pid;
            $state['_perf_hearts_resolved'] = $resolved;
            return $state;
        }
        $state = flushPendingYellToWr($state, $pid);
        $resolved[$pid] = true;
    }

    unset($state['_perf_hearts_resolved'], $state['_perf_yell_both_done']);
    if (!empty($state['pending_prompt'])) {
        $state['phase'] = 'live_success_effects';
        if (empty($state['_performance_continue'])) {
            $state['_performance_continue'] = $state['pending_prompt']['responder']
                ?? $state['pending_prompt']['owner']
                ?? $first;
        }
        return $state;
    }
    return queueLiveShowOutcomes($state);
}

function continuePerformanceYellPhase(array $state, string $justPlayed): array {
    $first  = $state['first_player'];
    $second = ($first === 'p1') ? 'p2' : 'p1';
    $attempting = $state['live_attempt'] ?? ['p1', 'p2'];

    if ($justPlayed === $first && ($state['phase'] ?? '') === 'live_performance_first') {
        $state['phase'] = 'live_performance_second';
        if (in_array($second, $attempting, true)) {
            // Official 8.3: each performer does Live Start then Yell — not all Live
            // Starts before either Yell (fixes 2nd-player Wait before 1st Yell).
            // Reveal the second performer's Lives first so the first player's score
            // is known before they commit to Live Start costs.
            $done = $state['_live_start_done'] ?? [];
            if (empty($done[$second])) {
                return beginPerformerRevealThenLiveStart($state, $second);
            }
            return resolvePerformancePhase($state, $second);
        }
        return continuePerformanceYellPhase($state, $second);
    }

    if (!empty($state['_yell_retry_offers'])) {
        return openNextYellRetryPrompt($state);
    }
    return finishYellRetryAndHearts($state);
}

/** After reveal, send Member bluffs from Live storage to the Waiting Room (no extra draws — replacements were taken during LIVE placement). */
function discardLiveZoneMembersToWaitingRoom(array $state, string $pid): array {
    $p = &$state['players'][$pid];
    $remaining = [];
    $discarded = [];
    $discardAnims = [];
    foreach ($p['live_zone'] ?? [] as $li => $c) {
        if (!$c) {
            continue;
        }
        if (isLiveTypeCard($c)) {
            $remaining[] = $c;
            continue;
        }
        $discarded[] = $c;
        $discardAnims[] = animSpec($c['instance_id'], 'live', 'waiting_room', $pid, [
            'from_index' => liveZoneSlotOf($c, $li),
        ]);
    }
    if (empty($discarded)) {
        unset($p);
        return $state;
    }
    $p['live_zone'] = $remaining;
    $p['waiting_room'] = array_merge($p['waiting_room'] ?? [], $discarded);
    unset($p);
    return addLog($state, $state['players'][$pid]['name'] .
        ' — ' . count($discarded) . ' non-Live card(s) from storage sent to Waiting Room.',
        null, $discardAnims);
}

/** Run one player's Performance: reveal storage, Yell draw, heart check, success/fail. */
function resolvePerformancePhase(array $state, string $pid, bool $continueAfter = true): array {
    $p = &$state['players'][$pid];

    foreach ($p['live_zone'] as &$c) {
        if ($c) {
            $c['revealed'] = true;
        }
    }
    unset($c);
    $liveCards = array_values(array_filter(
        $p['live_zone'] ?? [],
        fn($c) => $c && isLiveTypeCard($c)
    ));

    if (empty($liveCards)) {
        $state = discardLiveZoneMembersToWaitingRoom($state, $pid);
        $state = addLog($state, $state['players'][$pid]['name'] . ' has no valid Live cards!');
        if ($continueAfter) {
            $state = continuePerformanceYellPhase($state, $pid);
        }
        return $state;
    }

    [$state, $yellCards, $totalBlade, $drawBlade, $yellReduction] = drawYellCardsForPlayer($state, $pid);
    $p = &$state['players'][$pid];
    if ($yellReduction > 0 && $totalBlade > 0) {
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — Yell reduced by $yellReduction (drew $drawBlade of $totalBlade Blade).");
    }
    $p['yell_cards'] = $yellCards;
    if (!isset($state['yell_reveal'])) {
        $state['yell_reveal'] = [];
    }
    $state['yell_reveal'][$pid] = $yellCards;

    if ($drawBlade > 0) {
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — Support LIVE (Yell): drew $drawBlade card(s) for Blade.");
    }

    $state['_last_yell_live_count'] = countYellLiveCards($yellCards);
    $state['_last_yell_live_count_' . $pid] = countYellLiveCards($yellCards);
    $state['_last_yell_cards'] = $yellCards;
    $state = resolveAutoYellAbilities($state, $pid, $yellCards);
    $state = spBp2ApplyDeferredYellLiveStartBonuses($state, $pid, $yellCards);
    if (!empty($state['pending_prompt'])) {
        $state['_performance_continue'] = $pid;
        return $state;
    }

    if ($continueAfter) {
        $state = continuePerformanceYellPhase($state, $pid);
    }
    return $state;
}

/** Heart check, Live success/fail, and success effects for one player (after Yell reveal). */
function resolvePerformanceHeartCheck(array $state, string $pid, bool $continueAfter = true): array {
    $p = &$state['players'][$pid];
    $liveCards = array_values(array_filter(
        $p['live_zone'] ?? [],
        fn($c) => isLiveTypeCard($c)
    ));
    if (empty($liveCards)) {
        if ($continueAfter) {
            $state = continuePerformancePhase($state, $pid);
        }
        return $state;
    }

    $yellCards = $p['yell_cards'] ?? $state['yell_reveal'][$pid] ?? [];
    hydrateYellCardsForPerformance($yellCards);
    applyLiveScoreIfYellHasHeartsInZone($p['live_zone'], $yellCards);
    $liveCards = array_values(array_filter(
        $p['live_zone'] ?? [],
        fn($c) => isLiveTypeCard($c)
    ));
    foreach ($liveCards as &$lc) {
        mergeCardCatalogFields($lc);
    }
    unset($lc);
    $totalBlade = computeYellBladeTotal($state, $pid);

    $state = initLiveModifiers($state);
    if (!empty($state['live_modifiers'][$pid]['yell_blades_to_blue'])) {
        foreach ($yellCards as &$yc) {
            if (empty($yc['blade_hearts'])) {
                continue;
            }
            $yc['blade_hearts'] = array_map(function ($bh) {
                if (is_string($bh)) {
                    return $bh === 'draw' ? $bh : 'blue';
                }
                if (($bh['type'] ?? '') === 'draw') {
                    return $bh;
                }
                return ['type' => 'blue'];
            }, $yc['blade_hearts']);
        }
        unset($yc);
    }
    $yellBladeColor = $state['live_modifiers'][$pid]['yell_blades_to_color'] ?? '';
    if ($yellBladeColor !== '') {
        foreach ($yellCards as &$yc) {
            if (empty($yc['blade_hearts'])) {
                continue;
            }
            $yc['blade_hearts'] = array_map(function ($bh) use ($yellBladeColor) {
                if (is_string($bh)) {
                    return $bh === 'draw' ? $bh : $yellBladeColor;
                }
                if (($bh['type'] ?? '') === 'draw') {
                    return $bh;
                }
                return ['type' => $yellBladeColor];
            }, $yc['blade_hearts']);
        }
        unset($yc);
    }

    // Process blade hearts from yell cards (draw bonus)
    $drawBonus = 0;
    $yellHearts = [];
    $yellResolvePool = collectStageHeartPoolForYellResolve($state, $pid);
    foreach ($yellCards as $yc) {
        $bh = $yc['blade_hearts'] ?? [];
        foreach ($bh as $bh_item) {
            if (is_string($bh_item)) {
                if ($bh_item === 'draw' || $bh_item === 'score') {
                    continue;
                }
                $yellHearts = array_merge(
                    $yellHearts,
                    getHeartIconsFromBladeHeart($bh_item, $yellResolvePool, $liveCards, $state, $pid)
                );
                continue;
            }
            $bhType = $bh_item['type'] ?? '';
            if ($bhType === 'draw' || $bhType === 'score') {
                continue;
            }
            $yellHearts = array_merge(
                $yellHearts,
                getHeartIconsFromBladeHeart($bh_item, $yellResolvePool, $liveCards, $state, $pid)
            );
        }
    }

    $yellWildcard = liveCardsGrantYellHeartsWildcard($liveCards);
    foreach ($liveCards as $lc) {
        mergeCardCatalogFields($lc);
    }
    // Official reminder on draw-icon Lives: after all Yells, draw 1 per DRAW ICON
    // revealed (「エールで出たドロー」). Always counted here — not gated on Live IR.
    // Older catalog encoded that reminder as draw_per_yell_card / draw_per_yell_heart
    // (mistranslating ドロー as "card" / "heart"). Do not extra-draw those.
    $yellDrawIcons = countYellDrawIcons($yellCards);
    $drawBonus += $yellDrawIcons;
    $yellScoreIcons = countYellScoreIcons($yellCards);
    $state['_last_yell_score_icons'] = $yellScoreIcons;
    $state['_last_yell_score_icons_' . $pid] = $yellScoreIcons;
    missionNotePeakScore($state, $pid, 'yell', $yellScoreIcons);
    if ($yellWildcard) {
        $yellHearts = resolveSmartYellWildcardHeartColors(
            $yellHearts,
            $yellResolvePool,
            $liveCards,
            $state,
            $pid
        );
    }

    if ($drawBonus > 0) {
        $bonus = drawMainDeckCards($state, $pid, $drawBonus);
        $p['hand'] = array_merge($p['hand'], $bonus);
        if ($yellDrawIcons > 0) {
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — Drew $yellDrawIcons card(s) from Yell draw icon(s).");
        }
    }

    // Collect all owned hearts (members + yell blade hearts)
    $ownedHearts = [];
    foreach ($p['stage'] as $member) {
        if ($member) {
            foreach (memberPerformanceHeartsFlat($member) as $color) {
                $ownedHearts[] = $color;
            }
        }
    }
    $ownedHearts = array_merge($ownedHearts, $yellHearts);
    $ownedHearts = array_merge($ownedHearts, getBonusHeartsFlat($state, $pid));
    $ownedHearts = array_merge($ownedHearts, getContinuousPerformanceHearts($state, $pid));

    // Each Live card must be paid from one shared heart pool (including Lives that
    // cannot go to Success Live). Hearts consumed by earlier Lives in zone order.
    // Later Lives' colored requirements are reserved so earlier "any" slots do not
    // greedily spend colors still needed downstream (issue #66).
    $successCards = [];
    $failCards    = [];
    $failAnims    = [];
    $remaining    = $ownedHearts;

    $liveRequired = [];
    foreach ($liveCards as $li => $lc) {
        $liveRequired[$li] = plMuseGapApplySuccessLivePassiveReductions($state, $pid, $lc);
        $liveRequired[$li] = applyLiveHeartReductions($liveRequired[$li], $lc);
    }

    foreach ($liveCards as $li => $lc) {
        $required = $liveRequired[$li];
        $reserve = [];
        for ($j = $li + 1, $n = count($liveCards); $j < $n; $j++) {
            $reserve = mergeColoredHeartDemand(
                $reserve,
                coloredHeartDemandFromRequirements($liveRequired[$j] ?? [])
            );
        }
        [$ok, $newRemaining] = checkHearts($remaining, $required, $reserve);
        if ($ok) {
            $successCards[] = $lc;
            $remaining = $newRemaining;
        } else {
            $failCards[] = $lc;
            $failAnims[] = animSpec($lc['instance_id'], 'live', 'waiting_room', $pid, [
                'from_index' => liveZoneSlotOf($lc, $li),
            ]);
        }
    }

    // Per-card heart checks; overall round succeeds only when every Live card passes.
    $liveRoundSuccess = empty($failCards) && !empty($liveCards);
    if (!isset($state['live_perf_success'])) {
        $state['live_perf_success'] = ['p1' => [], 'p2' => []];
    }
    if (!isset($state['live_round_success'])) {
        $state['live_round_success'] = [];
    }
    $state['live_perf_success'][$pid] = array_values(array_map(
        fn($c) => $c['instance_id'],
        $successCards
    ));
    $state['live_round_success'][$pid] = $liveRoundSuccess;

    $p = &$state['players'][$pid];
    $liveCards = array_values(array_filter(
        $p['live_zone'] ?? [],
        fn($c) => $c && isLiveTypeCard($c)
    ));
    $successCards = array_values(array_filter(
        $successCards,
        fn($c) => $c && isLiveTypeCard($c)
    ));
    $failCards = array_values(array_filter(
        $failCards,
        fn($c) => $c && isLiveTypeCard($c)
    ));

    // Member bluffs stay in storage until queueLiveShowOutcomes →
    // discardLiveZoneMembersToWaitingRoom. Rewriting live_zone to success Lives
    // only (or clearing it on fail) used to drop those non-Live cards entirely
    // so they never reached the Waiting Room (#132 / cost-15 bluffs).
    $memberBluffs = array_values(array_filter(
        $p['live_zone'] ?? [],
        fn($c) => $c && !isLiveTypeCard($c)
    ));

    $p['waiting_room'] = array_merge($p['waiting_room'], $failCards);
    if ($liveRoundSuccess) {
        $p['live_zone'] = array_merge($successCards, $memberBluffs);
    } else {
        if (!empty($successCards)) {
            foreach ($successCards as $li => $lc) {
                $failAnims[] = animSpec($lc['instance_id'], 'live', 'waiting_room', $pid, [
                    'from_index' => liveZoneSlotOf($lc, $li),
                ]);
            }
            $p['waiting_room'] = array_merge($p['waiting_room'], $successCards);
        }
        $p['live_zone'] = $memberBluffs;
        $successCards = [];
        $remaining = $ownedHearts;
    }

    // Hold Yell cards until live success effects finish (may add one to hand)
    $p['_pending_yell_wr'] = $yellCards;
    unset($p['yell_cards']);

    $heartStr = implode(', ', $ownedHearts);
    $excessHearts = count($remaining);
    $state['_live_excess_hearts'][$pid] = $excessHearts;
    $state['_live_success_no_excess'][$pid] = ($excessHearts === 0);
    if ($liveRoundSuccess) {
        $state = resolveLiveSuccessAbilities($state, $pid, $successCards, $excessHearts, $remaining, $yellCards);
    }
    $roundNote = (!$liveRoundSuccess && !empty($liveCards))
        ? ' | Round: failed (not all Lives succeeded)'
        : '';
    $state = addLog($state, $state['players'][$pid]['name'] .
        ' performed Live! Blades: ' . $totalBlade .
        ' | Hearts: [' . $heartStr . ']' .
        ' | Live success: ' . count($state['live_perf_success'][$pid]) .
        ' | Failed: ' . count($failCards) . $roundNote, 'action', $failAnims);

    if (!empty($state['pending_prompt'])) {
        $state['phase'] = 'live_success_effects';
        $state['_performance_continue'] = $pid;
        return $state;
    }
    $state = flushPendingYellToWr($state, $pid);
    if ($continueAfter) {
        $state = continuePerformancePhase($state, $pid);
    }
    return $state;
}

function continuePerformancePhase(array $state, string $justPlayed): array {
    $first  = $state['first_player'];
    $second = ($first === 'p1') ? 'p2' : 'p1';
    $attempting = $state['live_attempt'] ?? ['p1', 'p2'];

    if ($justPlayed === $first && $state['phase'] === 'live_performance_first') {
        $state['phase'] = 'live_performance_second';
        if (in_array($second, $attempting, true)) {
            $state = resolvePerformancePhase($state, $second);
        } else {
            $state = continuePerformancePhase($state, $second);
        }
    } else {
        $state = queueLiveShowOutcomes($state);
    }
    return $state;
}

/** True when this player attempted Live and cleared every Live card this round. */
function playerAttemptedLiveRound(array $state, string $pid): bool {
    return in_array($pid, $state['live_attempt'] ?? [], true);
}

function playerLiveRoundSucceeded(array $state, string $pid): bool {
    if (isset($state['live_round_success'][$pid])) {
        return (bool)$state['live_round_success'][$pid];
    }
    return false;
}

// ─────────────────────────────────────────────
// Live Win/Loss Check (live_judge)
// ─────────────────────────────────────────────
// Compare per-player Live success; tie-break on total Live score. Winners may pick
// which successful Live enters Success Live area (pick_judge_success_live prompt).

function resolveLiveJudge(array $state): array {
    $state = flushDeferredLiveSuccessEnergyWaitIfWinning($state);
    $state = addLog($state, '=== Live Win/Loss Check Phase ===');
    $first  = $state['first_player'];
    $second = ($first === 'p1') ? 'p2' : 'p1';
    $firstName = $state['players'][$first]['name'];
    $secondName = $state['players'][$second]['name'];

    $firstOk = playerLiveRoundSucceeded($state, $first);
    $secondOk = playerLiveRoundSucceeded($state, $second);
    $liveWinners = [];
    $isScoreTie = false;
    $blockTieSuccess = false;

    foreach (['p1', 'p2'] as $scorePid) {
        missionNotePeakScore($state, $scorePid, 'live', getLiveTotalScore($state, $scorePid));
    }

    if (!$firstOk && !$secondOk) {
        $state = addLog($state, 'Neither player succeeds — no Live winner this turn.');
    } elseif ($firstOk && !$secondOk) {
        $liveWinners = [$first];
        $state = addLog($state, "$firstName wins the Live — $secondName failed.");
    } elseif (!$firstOk && $secondOk) {
        $liveWinners = [$second];
        $state = addLog($state, "$secondName wins the Live — $firstName failed.");
    } else {
        $scores = [];
        foreach ([$first, $second] as $pid) {
            $zone = $state['players'][$pid]['live_zone'] ?? [];
            $scores[$pid] = empty($zone)
                ? 0
                : sumLiveZoneCardScores($zone) + getLiveScoreBonus($state, $pid);
        }

        $state = addLog($state, 'Live Scores: ' .
            $firstName . ' = ' . ($scores[$first] ?? 0) . ' | ' .
            $secondName . ' = ' . ($scores[$second] ?? 0));

        if (!empty($scores)) {
            $maxScore = max($scores);
            if ($maxScore > 0) {
                $liveWinners = array_keys(array_filter($scores, fn($s) => $s === $maxScore));
            }
        }
        $isScoreTie = count($liveWinners) === 2;
        $blockTieSuccess = !empty($state['live_modifiers']['both']['block_success_live_on_tie'])
            && $isScoreTie;
    }

    $state['_live_judge_ctx'] = [
        'live_winners'      => $liveWinners,
        'block_tie_success' => $blockTieSuccess,
        'is_score_tie'      => $isScoreTie,
        'success_placed_by' => [],
        'winner_index'      => 0,
    ];
    $state['phase'] = 'live_judge';
    if (!empty($state['live_show'])) {
        return setLiveShowStage($state, 'judge');
    }
    return advanceLiveJudgeWinners($state);
}

function liveJudgeEligibleLives(array $zone): array {
    return array_values(array_filter($zone, fn($c) => !liveCardCannotSuccess($c)));
}

function liveJudgeRemoveFromZone(array &$zone, string $instanceId): ?array {
    foreach ($zone as $i => $c) {
        if (($c['instance_id'] ?? '') !== $instanceId) {
            continue;
        }
        $card = $c;
        array_splice($zone, $i, 1);
        return $card;
    }
    return null;
}

function liveJudgePlaceSuccessLive(array $state, string $winnerId, array $toAdd, bool $allowReplace = true): array {
    if ($allowReplace && function_exists('plMuseGapTryOfferReplaceSuccess')) {
        $offered = plMuseGapTryOfferReplaceSuccess($state, $winnerId, $toAdd);
        if ($offered !== null) {
            return $offered;
        }
    }
    $zone = &$state['players'][$winnerId]['live_zone'];
    $fromIdx = liveZoneSlotOf($toAdd, 0);
    $removed = liveJudgeRemoveFromZone($zone, $toAdd['instance_id'] ?? '');
    if (!$removed) {
        throw new Exception('Live card no longer in storage');
    }
    $toAdd = $removed;
    $successIdx = count($state['players'][$winnerId]['success_lives']);
    $state['players'][$winnerId]['success_lives'][] = $toAdd;
    $state['players'][$winnerId]['succeeded_live_this_turn'] = true;
    notifyLiveEnteredSuccess($state, $winnerId, $toAdd);
    $ctx = &$state['_live_judge_ctx'];
    if ($ctx && !in_array($winnerId, $ctx['success_placed_by'] ?? [], true)) {
        $ctx['success_placed_by'][] = $winnerId;
    }
    $winName = $state['players'][$winnerId]['name'];
    $cardName = $toAdd['name_en'] ?? $toAdd['name'];
    $state = addLog($state, $winName .
        ' wins this Live! "' . $cardName . '" added to successes.',
        'good',
        [animSpec($toAdd['instance_id'], 'live', 'success', $winnerId, [
            'index' => $successIdx,
            'from_index' => $fromIdx,
        ])]);
    return $state;
}

function advanceLiveJudgeWinners(array $state): array {
    $ctx = $state['_live_judge_ctx'] ?? null;
    if (!$ctx) {
        return finalizeLiveJudge($state, ['success_placed_by' => []]);
    }

    $liveWinners = $ctx['live_winners'] ?? [];
    $blockTieSuccess = !empty($ctx['block_tie_success']);
    $isScoreTie = !empty($ctx['is_score_tie']);
    $idx = intval($ctx['winner_index'] ?? 0);

    while ($idx < count($liveWinners)) {
        $winnerId = $liveWinners[$idx];
        $ctx['winner_index'] = $idx;
        $state['_live_judge_ctx'] = $ctx;

        if ($blockTieSuccess) {
            $zone = &$state['players'][$winnerId]['live_zone'];
            if (!empty($zone)) {
                $tieAnims = liveZoneDiscardAnims($zone, $winnerId);
                $state['players'][$winnerId]['waiting_room'] =
                    array_merge($state['players'][$winnerId]['waiting_room'], $zone);
                $zone = [];
                $state = addLog($state, $state['players'][$winnerId]['name'] .
                    ' — score tied; Success Live blocked; Live cards sent to Waiting Room.',
                    null, $tieAnims);
            }
            unset($zone);
            $idx++;
            $ctx['winner_index'] = $idx;
            continue;
        }

        if ($isScoreTie && count($state['players'][$winnerId]['success_lives']) >= 2) {
            $zone = &$state['players'][$winnerId]['live_zone'];
            if (!empty($zone)) {
                $capAnims = liveZoneDiscardAnims($zone, $winnerId);
                $state['players'][$winnerId]['waiting_room'] =
                    array_merge($state['players'][$winnerId]['waiting_room'], $zone);
                $zone = [];
                $state = addLog($state, $state['players'][$winnerId]['name'] .
                    ' — score tied, but already has 2 Success Lives; Live cards sent to Waiting Room.',
                    null, $capAnims);
            }
            unset($zone);
            $idx++;
            $ctx['winner_index'] = $idx;
            continue;
        }

        $zone = $state['players'][$winnerId]['live_zone'] ?? [];
        if (empty($zone)) {
            $idx++;
            $ctx['winner_index'] = $idx;
            continue;
        }

        $eligible = liveJudgeEligibleLives($zone);
        if (empty($eligible)) {
            $idx++;
            $ctx['winner_index'] = $idx;
            continue;
        }

        if (count($eligible) > 1) {
            $winName = $state['players'][$winnerId]['name'];
            $state['pending_prompt'] = [
                'type'        => 'pick_judge_success_live',
                'owner'       => $winnerId,
                'responder'   => $winnerId,
                'source_name' => $winName,
                'prompt'      => 'Choose 1 Live card to place in Success Live.',
                'candidates'  => array_map('cardPromptSummary', $eligible),
            ];
            $state['phase'] = 'live_judge';
            $state['_live_judge_ctx'] = $ctx;
            $state = addLog($state, $winName . ' — choose a Live card for Success Live.');
            $state['seq']++;
            return $state;
        }

        $state = liveJudgePlaceSuccessLive($state, $winnerId, $eligible[0]);
        if (!empty($state['pending_prompt'])) {
            $state['phase'] = 'live_judge';
            $state['_live_judge_ctx'] = $ctx;
            $state['seq']++;
            return $state;
        }
        $ctx = $state['_live_judge_ctx'] ?? $ctx;
        $leftInZone = count($state['players'][$winnerId]['live_zone'] ?? []);
        if ($leftInZone > 0) {
            $state = addLog($state, $state['players'][$winnerId]['name'] .
                " — $leftInZone other successful Live(s) in storage cannot be placed (only 1 Success Live per Judge win); sent to Waiting Room.",
                'action');
        }
        $idx++;
        $ctx['winner_index'] = $idx;
        $state['_live_judge_ctx'] = $ctx;
    }

    unset($state['_live_judge_ctx']);
    return finalizeLiveJudge($state, $ctx);
}

function actionResolvePickJudgeSuccessLive(array $state, string $owner, array $prompt, array $data): array {
    $pickId = $data['card_id'] ?? '';
    if ($pickId === '') {
        throw new Exception('Choose a Live card');
    }
    $ctx = $state['_live_judge_ctx'] ?? null;
    if (!$ctx) {
        throw new Exception('Live Judge is not waiting for a choice');
    }
    $winnerId = $prompt['owner'] ?? $owner;
    $zone = $state['players'][$winnerId]['live_zone'] ?? [];
    $eligibleIds = array_map(
        fn($c) => $c['instance_id'] ?? '',
        liveJudgeEligibleLives($zone)
    );
    if (!in_array($pickId, $eligibleIds, true)) {
        throw new Exception('Invalid Live card');
    }
    $toAdd = null;
    foreach ($zone as $c) {
        if (($c['instance_id'] ?? '') === $pickId) {
            $toAdd = $c;
            break;
        }
    }
    if (!$toAdd) {
        throw new Exception('Live card no longer in storage');
    }

    unset($state['pending_prompt']);
    $state = liveJudgePlaceSuccessLive($state, $winnerId, $toAdd);
    if (!empty($state['pending_prompt'])) {
        $state['phase'] = 'live_judge';
        $state['_live_judge_ctx'] = $ctx;
        $state['seq']++;
        return $state;
    }
    $leftInZone = count($state['players'][$winnerId]['live_zone'] ?? []);
    if ($leftInZone > 0) {
        $state = addLog($state, $state['players'][$winnerId]['name'] .
            " — $leftInZone other successful Live(s) in storage cannot be placed (only 1 Success Live per Judge win); sent to Waiting Room.",
            'action');
    }

    $ctx = $state['_live_judge_ctx'] ?? $ctx;
    $ctx['winner_index'] = intval($ctx['winner_index'] ?? 0) + 1;
    $state['_live_judge_ctx'] = $ctx;
    $state['seq']++;
    return advanceLiveJudgeWinners($state);
}

/**
 * Send leftover Live storage to Waiting Room, pausing when a deck-position prompt opens.
 * Cards still awaiting a prompt stay in live_zone.
 *
 * Important: never keep a long-lived &$players[$pid] across sBp6ResolveAutoOnLiveWr —
 * that call may copy-on-write $state, leaving a stale $p that fails to clear live_zone.
 */
function drainLiveStorageLeftovers(array $state, array &$leftoverAnims): array {
    foreach (['p1', 'p2'] as $pid) {
        $zone = $state['players'][$pid]['live_zone'] ?? [];
        if ($zone === []) {
            continue;
        }
        $remaining = [];
        foreach ($zone as $lc) {
            $state = sBp6ResolveAutoOnLiveWr($state, $pid, $lc);
            if (!empty($state['pending_prompt'])) {
                $remaining[] = $lc;
                continue;
            }
            $leftoverAnims = array_merge($leftoverAnims, liveZoneDiscardAnims([$lc], $pid));
            $state['players'][$pid]['waiting_room'][] = $lc;
        }
        $state['players'][$pid]['live_zone'] = $remaining;
    }
    return $state;
}

/** Complete turn advance after Live leftovers + optional sBp6 prompts are done. */
function completeLiveRoundTurnAdvance(array $state, array $meta): array {
    $kind = $meta['kind'] ?? 'judge';
    $attempting = $meta['attempting'] ?? ['p1', 'p2'];
    $successPlacedBy = $meta['success_placed_by'] ?? [];
    $playedLives = $state['live_show']['played_lives']
        ?? $state['_live_played_snapshot']
        ?? null;
    if (is_array($playedLives) && ($playedLives['p1'] ?? []) === [] && ($playedLives['p2'] ?? []) === []) {
        $playedLives = null;
    }
    unset($state['live_show']);

    if ($kind === 'empty') {
        unset($state['live_attempt'], $state['live_perf_success'], $state['live_round_success']);
        $state['_prev_turn_live_result'] = ['p1' => 'none', 'p2' => 'none'];
        $state = clearYellRevealState($state);
    } else {
        $state['_live_perf_snapshot'] = $state['live_perf_success'] ?? ['p1' => [], 'p2' => []];
        $state['_live_round_success_snapshot'] = $state['live_round_success'] ?? [];
        if (is_array($playedLives)) {
            $state['_live_played_snapshot'] = $playedLives;
        }
        // Stage + Live-Start bonus hearts for spectacle after clearLiveModifiers (#73).
        $state['_stage_hearts_snapshot'] = [
            'p1' => mergeHeartColorCounts(
                aggregateStageHeartsByColor($state['players']['p1']['stage'] ?? []),
                aggregateFlatHeartColors(getBonusHeartsFlat($state, 'p1'))
            ),
            'p2' => mergeHeartColorCounts(
                aggregateStageHeartsByColor($state['players']['p2']['stage'] ?? []),
                aggregateFlatHeartColors(getBonusHeartsFlat($state, 'p2'))
            ),
        ];
        if (!empty($state['yell_reveal'])) {
            $state['_yell_reveal_snapshot'] = $state['yell_reveal'];
        }
        $state['_yell_blade_snapshot'] = [
            'p1' => computeYellBladeTotal($state, 'p1'),
            'p2' => computeYellBladeTotal($state, 'p2'),
        ];
        unset(
            $state['live_attempt'],
            $state['live_perf_success'],
            $state['live_round_success'],
            $state['_live_judge_ctx']
        );
        $state = clearYellRevealState($state);

        $prevResults = [];
        foreach (['p1', 'p2'] as $pid) {
            if (in_array($pid, $attempting, true)) {
                $prevResults[$pid] = in_array($pid, $successPlacedBy, true) ? 'success' : 'failed';
            } else {
                $prevResults[$pid] = 'none';
            }
        }
        $state['_prev_turn_live_result'] = $prevResults;
    }

    $reachers = [];
    foreach (['p1', 'p2'] as $pid) {
        if (count($state['players'][$pid]['success_lives']) >= 3) {
            $reachers[] = $pid;
        }
    }
    if (!empty($reachers)) {
        $matchWinner = $reachers[0];
        // If both somehow hit 3 in one finalize, prefer who placed Success this turn.
        if (count($reachers) > 1 && !empty($successPlacedBy) && is_array($successPlacedBy)) {
            for ($i = count($successPlacedBy) - 1; $i >= 0; $i--) {
                $cand = $successPlacedBy[$i];
                if (in_array($cand, $reachers, true)) {
                    $matchWinner = $cand;
                    break;
                }
            }
        }
        $state['status'] = 'finished';
        $state['winner'] = $matchWinner;
        $state['end_reason'] = $state['end_reason'] ?? 'game';
        $state = addLog($state, '🎉 ' . $state['players'][$matchWinner]['name'] . ' WINS with 3 successful Lives!');
        $state['seq']++;
        return $state;
    }

    if ($kind !== 'empty' && count($successPlacedBy) === 1) {
        $state['first_player'] = $successPlacedBy[0];
    }

    $state = clearLiveModifiers($state);
    $state['turn']++;
    $state = addLog($state, '=== Turn ' . $state['turn'] . ' begins ===');
    $state = startTurn($state);
    $state['seq']++;
    return $state;
}

/**
 * Resume Live leftover drain after sbp6_live_wr_deck_position (or finish turn advance).
 */
function resumePendingLiveFinalize(array $state): array {
    $meta = $state['_pending_live_finalize'] ?? null;
    if (!is_array($meta)) {
        return $state;
    }
    unset($state['_pending_live_finalize']);

    $leftoverAnims = [];
    $state = drainLiveStorageLeftovers($state, $leftoverAnims);
    if (!empty($leftoverAnims)) {
        $state = addLog($state, 'Remaining Live storage sent to Waiting Room.', null, $leftoverAnims);
    }
    if (!empty($state['pending_prompt'])) {
        $state['_pending_live_finalize'] = $meta;
        $state['phase'] = ($meta['kind'] ?? '') === 'empty'
            ? ($state['phase'] ?? 'live_judge')
            : 'live_judge';
        return $state;
    }
    return completeLiveRoundTurnAdvance($state, $meta);
}

function finalizeLiveJudge(array $state, array $ctx): array {
    $attempting = $state['live_attempt'] ?? ['p1', 'p2'];
    $successPlacedBy = $ctx['success_placed_by'] ?? [];
    $meta = [
        'kind' => 'judge',
        'attempting' => $attempting,
        'success_placed_by' => $successPlacedBy,
    ];

    $leftoverAnims = [];
    $state = drainLiveStorageLeftovers($state, $leftoverAnims);
    if (!empty($leftoverAnims)) {
        $state = addLog($state, 'Remaining Live storage sent to Waiting Room.', null, $leftoverAnims);
    }
    // Aqours auto: choose Live deck top/bottom — must not enter Main with leftover faces.
    if (!empty($state['pending_prompt'])) {
        $state['_pending_live_finalize'] = $meta;
        $state['_live_judge_ctx'] = $ctx;
        $state['phase'] = 'live_judge';
        $state['seq']++;
        return $state;
    }
    return completeLiveRoundTurnAdvance($state, $meta);
}

// ─────────────────────────────────────────────
// Heart Resolution
// ─────────────────────────────────────────────
function isWildcardHeartColor(string $color): bool {
    $c = strtolower(trim($color));
    return in_array($c, [
        'any', 'wild', 'gray', 'all',
        // Double colorless blade hearts resolve to two wilds; treat the token as wild too
        // so HUD merges never keep a separate "all2" chip beside "any".
        'all2', 'all_2', 'b_heart07', 'heart07', '',
    ], true);
}

function normalizeHeartColor(string $color): string {
    return isWildcardHeartColor($color) ? 'any' : strtolower(trim($color));
}

function sortHeartRequirements(array $required): array {
    $colored = [];
    $wild = [];
    foreach ($required as $req) {
        $c = normalizeHeartColor((string)($req['color'] ?? 'any'));
        $entry = $req;
        $entry['color'] = $c;
        if ($c === 'any') {
            $wild[] = $entry;
        } else {
            $colored[] = $entry;
        }
    }
    return array_merge($colored, $wild);
}

function liveHeartRequirementsForCheck(array $state, string $pid, array $liveCard): array {
    $required = $liveCard['required_hearts'] ?? $liveCard['hearts'] ?? [];
    $required = plMuseGapApplySuccessLivePassiveReductions($state, $pid, $liveCard);
    return applyLiveHeartReductions($required, $liveCard);
}

function expandHeartRequirementSlots(array $required): array {
    $slots = [];
    foreach (sortHeartRequirements($required) as $req) {
        $color = normalizeHeartColor((string)($req['color'] ?? 'any'));
        $count = intval($req['count'] ?? 1);
        for ($i = 0; $i < $count; $i++) {
            $slots[] = $color;
        }
    }
    return $slots;
}

function heartSlotCandidateIndices(array $pool, string $needColor, array $reservedColored = []): array {
    if ($needColor !== 'any') {
        $indices = [];
        foreach ($pool as $i => $h) {
            if ($h === $needColor) {
                $indices[] = $i;
            }
        }
        foreach ($pool as $i => $h) {
            if (isWildcardHeartColor((string)$h)) {
                $indices[] = $i;
            }
        }
        return $indices;
    }
    // For "any" slots: spend surplus colors (above later Lives' reserved needs)
    // before dipping into colors later Lives still require (issue #66 multi-Live).
    $counts = [];
    foreach ($pool as $h) {
        $counts[$h] = ($counts[$h] ?? 0) + 1;
    }
    $surplusBudget = [];
    foreach ($counts as $color => $have) {
        if (isWildcardHeartColor((string)$color)) {
            continue;
        }
        $surplusBudget[$color] = max(0, intval($have) - intval($reservedColored[$color] ?? 0));
    }
    $surplus = [];
    $reserved = [];
    $wild = [];
    $usedSurplus = [];
    foreach ($pool as $i => $h) {
        if (isWildcardHeartColor((string)$h)) {
            $wild[] = $i;
            continue;
        }
        $used = intval($usedSurplus[$h] ?? 0);
        $budget = intval($surplusBudget[$h] ?? 0);
        if ($used < $budget) {
            $surplus[] = $i;
            $usedSurplus[$h] = $used + 1;
        } else {
            $reserved[] = $i;
        }
    }
    return array_merge($surplus, $wild, $reserved);
}

function coloredHeartDemandFromRequirements(array $required): array {
    $demand = [];
    foreach ($required as $req) {
        $color = normalizeHeartColor((string)($req['color'] ?? 'any'));
        if ($color === 'any' || isWildcardHeartColor($color)) {
            continue;
        }
        $demand[$color] = ($demand[$color] ?? 0) + intval($req['count'] ?? 1);
    }
    return $demand;
}

/** Merge colored heart demands (later Lives' reservation map). */
function mergeColoredHeartDemand(array $a, array $b): array {
    foreach ($b as $color => $n) {
        $a[$color] = ($a[$color] ?? 0) + intval($n);
    }
    return $a;
}

/**
 * Pay colored slots by counts (exact, then wilds). Index-by-index DFS treated
 * identical hearts as distinct and could run for minutes (nginx 504 + lock held)
 * when a late colored slot failed after many wild/"any" prefixes.
 */
function consumeColoredHeartSlotsByCount(array $pool, array $coloredSlots): ?array {
    if (empty($coloredSlots)) {
        return array_values($pool);
    }
    $need = [];
    foreach ($coloredSlots as $color) {
        $need[$color] = ($need[$color] ?? 0) + 1;
    }
    $exactHave = [];
    $wildCount = 0;
    foreach ($pool as $h) {
        if (isWildcardHeartColor((string)$h)) {
            $wildCount++;
        } else {
            $exactHave[$h] = ($exactHave[$h] ?? 0) + 1;
        }
    }
    $exactUsed = [];
    $wildUsed = 0;
    foreach ($need as $color => $n) {
        $have = intval($exactHave[$color] ?? 0);
        $takeExact = min($have, $n);
        $exactUsed[$color] = $takeExact;
        $short = $n - $takeExact;
        if ($short > 0) {
            if ($wildCount - $wildUsed < $short) {
                return null;
            }
            $wildUsed += $short;
        }
    }
    $remaining = [];
    foreach ($pool as $h) {
        if (isWildcardHeartColor((string)$h)) {
            if ($wildUsed > 0) {
                $wildUsed--;
                continue;
            }
            $remaining[] = $h;
            continue;
        }
        $left = intval($exactUsed[$h] ?? 0);
        if ($left > 0) {
            $exactUsed[$h] = $left - 1;
            continue;
        }
        $remaining[] = $h;
    }
    return $remaining;
}

function consumeAnyHeartSlotsGreedy(array $pool, int $anyCount, array $reservedColored = []): ?array {
    if ($anyCount <= 0) {
        return array_values($pool);
    }
    $order = heartSlotCandidateIndices($pool, 'any', $reservedColored);
    if (count($order) < $anyCount) {
        return null;
    }
    $remove = array_fill_keys(array_slice($order, 0, $anyCount), true);
    $remaining = [];
    foreach ($pool as $i => $h) {
        if (isset($remove[$i])) {
            unset($remove[$i]);
            continue;
        }
        $remaining[] = $h;
    }
    return $remaining;
}

function tryConsumeHeartsForRequirementSlots(array $pool, array $slots, array $reservedColored = []): ?array {
    if (empty($slots)) {
        return array_values($pool);
    }
    // Fat Lives (e.g. COMPASS 16 hearts) with a short pool used to DFS-explode for
    // minutes and softlock the match host — "Checking hearts…" forever + lock timeouts.
    if (count($pool) < count($slots)) {
        return null;
    }
    $coloredSlots = [];
    $anyCount = 0;
    foreach ($slots as $slotColor) {
        if ($slotColor === 'any') {
            $anyCount++;
        } else {
            $coloredSlots[] = $slotColor;
        }
    }
    $remaining = consumeColoredHeartSlotsByCount($pool, $coloredSlots);
    if ($remaining === null) {
        return null;
    }
    return consumeAnyHeartSlotsGreedy($remaining, $anyCount, $reservedColored);
}

function checkHearts(array $available, array $required, array $reservedColored = []): array {
    $available = array_values(array_map(fn($h) => normalizeHeartColor((string)$h), $available));
    $remaining = tryConsumeHeartsForRequirementSlots(
        $available,
        expandHeartRequirementSlots($required),
        $reservedColored
    );
    if ($remaining === null) {
        return [false, $available];
    }
    return [true, $remaining];
}

/** First colored live requirement not covered by exact matches (wildcards reserved for checkHearts). */
function firstMissingColoredHeartForRequirements(array $pool, array $required): ?string {
    $specifics = array_values(array_filter(
        array_map(fn($h) => normalizeHeartColor((string)$h), $pool),
        fn($h) => !isWildcardHeartColor((string)$h)
    ));

    foreach (sortHeartRequirements($required) as $req) {
        $color = normalizeHeartColor((string)($req['color'] ?? 'any'));
        if ($color === 'any') {
            continue;
        }
        $need = intval($req['count'] ?? 1);
        for ($i = 0; $i < $need; $i++) {
            $idx = array_search($color, $specifics, true);
            if ($idx !== false) {
                array_splice($specifics, $idx, 1);
            } else {
                return $color;
            }
        }
    }
    return null;
}

/**
 * Consume exact colored matches for one Live's requirements from a working specifics pool.
 * Returns the first unmet colored requirement, or null if all colored slots are covered.
 * Mutates $specifics in place so earlier Lives reserve hearts for later Lives.
 */
function consumeExactColoredHeartRequirements(array &$specifics, array $required): ?string {
    foreach (sortHeartRequirements($required) as $req) {
        $color = normalizeHeartColor((string)($req['color'] ?? 'any'));
        if ($color === 'any') {
            continue;
        }
        $need = intval($req['count'] ?? 1);
        for ($i = 0; $i < $need; $i++) {
            $idx = array_search($color, $specifics, true);
            if ($idx !== false) {
                array_splice($specifics, $idx, 1);
            } else {
                return $color;
            }
        }
    }
    return null;
}

/**
 * Pick a color for an ALL / wildcard blade heart.
 * Colored requirements are prioritized across ALL attempted Lives in zone order —
 * hearts already needed by earlier Lives are reserved so a later Live's pink/red/etc.
 * is not skipped just because the same colors appear in the shared pool.
 */
function resolveAllBladeHeartColor(
    array $pool,
    array $liveCards,
    ?array $state = null,
    ?string $pid = null
): string {
    $specifics = array_values(array_filter(
        array_map(fn($h) => normalizeHeartColor((string)$h), $pool),
        fn($h) => !isWildcardHeartColor((string)$h)
    ));
    foreach ($liveCards as $lc) {
        $required = ($state !== null && $pid !== null)
            ? liveHeartRequirementsForCheck($state, $pid, $lc)
            : applyLiveHeartReductions($lc['required_hearts'] ?? [], $lc);
        $missing = consumeExactColoredHeartRequirements($specifics, $required);
        if ($missing !== null) {
            return $missing;
        }
    }
    return 'any';
}

function resolveSmartYellWildcardHeartColors(
    array $yellHearts,
    array &$resolvePool,
    array $liveCards,
    ?array $state = null,
    ?string $pid = null
): array {
    $resolved = [];
    foreach ($yellHearts as $_) {
        $color = resolveAllBladeHeartColor($resolvePool, $liveCards, $state, $pid);
        $color = normalizeHeartColor($color);
        $resolvePool[] = $color;
        $resolved[] = $color;
    }
    return $resolved;
}

function collectStageHeartPoolForYellResolve(array $state, string $pid): array {
    $pool = [];
    $p = $state['players'][$pid] ?? [];
    foreach ($p['stage'] ?? [] as $member) {
        if (!$member) {
            continue;
        }
        foreach (memberPerformanceHeartsFlat($member) as $color) {
            $pool[] = $color;
        }
    }
    // Match final owned-heart assembly so ALL assignment sees the same colors
    // Performance will actually pay with (bonus + continuous grants).
    $pool = array_merge($pool, getBonusHeartsFlat($state, $pid));
    $pool = array_merge($pool, getContinuousPerformanceHearts($state, $pid));
    return $pool;
}

function getHeartIconsFromBladeHeart(
    string|array $bh,
    ?array &$resolvePool = null,
    ?array $liveCards = null,
    ?array $state = null,
    ?string $pid = null
): array {
    // Blade hearts may be plain color strings ("red") or objects ({type: "red"} / {type: "draw"})
    $type = is_string($bh) ? $bh : ($bh['type'] ?? $bh['color'] ?? '');
    if ($type === 'draw' || $type === 'score') {
        return [];
    }
    if ($resolvePool !== null && $liveCards !== null
        && in_array($type, ['all', 'gray', 'wild', 'any'], true)) {
        $color = resolveAllBladeHeartColor($resolvePool, $liveCards, $state, $pid);
        $resolvePool[] = normalizeHeartColor($color);
        return [$color];
    }
    # Double colorless blade heart (BP07+ / b_heart07): two wild gray/any Yell hearts.
    # Do not treat as ALL blades (icon_b_all) — those are a separate token.
    if ($resolvePool !== null && $liveCards !== null
        && in_array($type, ['all2', 'all_2', 'b_heart07', 'heart07'], true)) {
        $out = [];
        for ($i = 0; $i < 2; $i++) {
            // Same payment path as printed gray/any blade hearts (may fill missing colors).
            $color = resolveAllBladeHeartColor($resolvePool, $liveCards, $state, $pid);
            $resolvePool[] = normalizeHeartColor($color);
            $out[] = $color;
        }
        return $out;
    }
    $heartsMap = [
        'pink'   => 'pink',   'red'    => 'red',
        'yellow' => 'yellow', 'green'  => 'green',
        'blue'   => 'blue',   'purple' => 'purple',
        'any'    => 'any',    'gray'   => 'any', 'wild' => 'any', 'all' => 'any',
        'all2'   => 'any', 'all_2' => 'any', 'b_heart07' => 'any', 'heart07' => 'any',
    ];
    if (isset($heartsMap[$type])) {
        // Fixed-color Yell blades must enter the resolve pool too (#130): later ALL /
        // gray blades prioritize colors still missing after these printed hearts.
        if (in_array($type, ['all2', 'all_2', 'b_heart07', 'heart07'], true)) {
            $pair = [$heartsMap[$type], $heartsMap[$type]];
            if ($resolvePool !== null) {
                foreach ($pair as $c) {
                    $resolvePool[] = normalizeHeartColor($c);
                }
            }
            return $pair;
        }
        $color = $heartsMap[$type];
        if ($resolvePool !== null) {
            $resolvePool[] = normalizeHeartColor($color);
        }
        return [$color];
    }
    return [];
}

// ─────────────────────────────────────────────
// Utility Functions
// ─────────────────────────────────────────────
function buildDeck(array $allCards, array $cardNos): array {
    $cardMap = [];
    foreach ($allCards as $c) {
        $cardMap[$c['card_no']] = $c;
    }
    $deck = [];
    foreach ($cardNos as $no) {
        if (isset($cardMap[$no])) {
            $card = $cardMap[$no];
            $card['instance_id'] = uniqid('card_', true);
            $deck[] = $card;
        }
    }
    return $deck;
}

/** Build deck for a room; optional fixed order when shuffle is false. */
function buildDeckForRoom(array $allCards, array $defaultNos, array $body, string $orderKey): array {
    $nos = $defaultNos;
    if (($body['shuffle'] ?? true) === false && !empty($body[$orderKey]) && is_array($body[$orderKey])) {
        $nos = $body[$orderKey];
    }
    $deck = buildDeck($allCards, $nos);
    if (($body['shuffle'] ?? true) !== false) {
        shuffle($deck);
    }
    return $deck;
}

function drawCards(array $deck, int $count): array {
    $drawn = [];
    for ($i = 0; $i < $count; $i++) {
        if (empty($deck)) break;
        $drawn[] = array_shift($deck);
    }
    return [$drawn, $deck];
}

function findInHand(array $hand, string $instanceId): int|false {
    foreach ($hand as $idx => $card) {
        if (($card['instance_id'] ?? '') === $instanceId) return $idx;
    }
    return false;
}

function getPlayerIdByToken(array $state, string $token): ?string {
    foreach (['p1','p2'] as $pid) {
        if ($state['players'][$pid] && $state['players'][$pid]['token'] === $token) {
            return $pid;
        }
    }
    return null;
}

function validateTurn(array $state, string $pid, string $expectedPhaseKey): void {
    if ($state['active_player'] !== $pid) {
        throw new Exception('Not your turn');
    }
    $validPhases = [
        'main' => ['main_first', 'main_second'],
        'live'  => ['live_set'],
    ];
    $phases = $validPhases[$expectedPhaseKey] ?? [$expectedPhaseKey];
    if (!in_array($state['phase'], $phases)) {
        throw new Exception('Not in correct phase (current: ' . $state['phase'] . ')');
    }
}

/** Block End Main / End LIVE while any skill prompt is still open. */
function assertNoPendingPromptForPhaseAdvance(array $state): void {
    if (!empty($state['pending_prompt'])) {
        throw new Exception('Resolve the pending skill prompt before continuing.');
    }
}

/**
 * Block new plays/activations while any skill prompt is open.
 * Freezing only the responder used to let the active player keep playing
 * during opponent-facing On Enter waits (e.g. Ginko), which then skipped
 * the next Member's On Enter via pending_prompt guards.
 */
function assertNoPendingPromptForPlayerAction(array $state, string $pid): void {
    if (!empty($state['pending_prompt'])) {
        throw new Exception('Resolve the pending skill prompt before taking another action.');
    }
}

function isCpuPlayer(?array $player): bool {
    if (!$player) {
        return false;
    }
    $deckChoice = (string)($player['deck_choice'] ?? '');
    if ($deckChoice === 'cpu' || str_starts_with($deckChoice, 'cpu:')) {
        return true;
    }
    $name = (string)($player['name'] ?? '');
    if ($name === '') {
        return false;
    }
    if (str_contains($name, 'CPU') || str_contains($name, '🤖')) {
        return true;
    }
    return str_starts_with($name, 'COM') || str_starts_with($name, 'COM（');
}

function isHumanVsHumanRoster(array $state): bool {
    $p1 = $state['players']['p1'] ?? null;
    $p2 = $state['players']['p2'] ?? null;
    if (!$p1 || !$p2) {
        return false;
    }
    return !isCpuPlayer($p1) && !isCpuPlayer($p2);
}

function isPvpMatch(array $state): bool {
    $st = $state['status'] ?? '';
    if (in_array($st, ['waiting', 'ready'], true)) {
        return false;
    }
    return isHumanVsHumanRoster($state);
}

function rebuildRematchPlayer(array $old, array $cardsData): array {
    $snapshot = $old['deck_snapshot'] ?? null;
    if (is_array($snapshot) && !empty($snapshot['main_nos']) && !empty($snapshot['energy_nos'])) {
        $mainNos = $snapshot['main_nos'];
        $energyNos = $snapshot['energy_nos'];
    } else {
        $resolved = resolvePlayerDeckLists($cardsData, (string)($old['deck_choice'] ?? 'nijigasaki'), null);
        $mainNos = $resolved['main_nos'];
        $energyNos = $resolved['energy_nos'];
    }
    $body = ['shuffle' => true];
    $mainDeck = buildDeckForRoom($cardsData['cards'], $mainNos, $body, 'main_order');
    $energyDeck = buildDeckForRoom($cardsData['cards'], $energyNos, $body, 'energy_order');
    $out = [
        'id'            => $old['id'],
        'token'         => $old['token'],
        'name'          => $old['name'],
        'deck_choice'   => $old['deck_choice'],
        'deck_label'    => $old['deck_label'] ?? null,
        'main_deck'     => $mainDeck,
        'energy_deck'   => $energyDeck,
        'deck_snapshot' => ['main_nos' => $mainNos, 'energy_nos' => $energyNos],
        'sleeve_id'     => tcgNormalizeSleeveId($old['sleeve_id'] ?? ''),
        'playmat_id'    => tcgNormalizePlaymatId($old['playmat_id'] ?? ''),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($old['playmat_brightness'] ?? 1.0),
    ];
    // Keep signed-in identity so stamp/mission side effects still resolve after rematch.
    if (!empty($old['discord_id'])) {
        $out['discord_id'] = $old['discord_id'];
    }
    return $out;
}

function startRematchGame(array $state): array {
    if (($state['status'] ?? '') !== 'finished') {
        throw new Exception('Game is not finished');
    }
    if (($state['mode'] ?? '') === 'ranked') {
        throw new Exception('Use ranked queue for a new match');
    }
    if (!isHumanVsHumanRoster($state)) {
        throw new Exception('Rematch only available for player vs player');
    }

    $cards = tcgLoadCardsData();
    $roomId = (string)($state['room_id'] ?? '');
    $phaseTimerCfg = $state['phase_timer_cfg'] ?? null;

    $p1Data = rebuildRematchPlayer($state['players']['p1'], $cards);
    $p2Data = rebuildRematchPlayer($state['players']['p2'], $cards);

    $newState = initGameState($roomId, $p1Data);
    if (is_array($phaseTimerCfg)) {
        $newState['phase_timer_cfg'] = $phaseTimerCfg;
    }
    $newState = addSecondPlayer($newState, $p2Data);
    $newState = addLog($newState, 'Rematch started!', 'info');
    return $newState;
}

function actionRequestRematch(array $state, string $playerId): array {
    if (($state['status'] ?? '') !== 'finished') {
        throw new Exception('Game is not finished');
    }
    if (($state['mode'] ?? '') === 'ranked') {
        throw new Exception('Use ranked queue for a new match');
    }
    if (!isHumanVsHumanRoster($state)) {
        throw new Exception('Rematch only available for player vs player');
    }

    if (!isset($state['rematch']) || !is_array($state['rematch'])) {
        $state['rematch'] = ['p1' => false, 'p2' => false];
    }

    $state['rematch'][$playerId] = true;
    $other = ($playerId === 'p1') ? 'p2' : 'p1';
    if (!empty($state['rematch'][$other])) {
        return startRematchGame($state);
    }

    $state = addLog($state, ($state['players'][$playerId]['name'] ?? 'Player') . ' wants a rematch.', 'info');
    $state['seq']++;
    return $state;
}

function isStampMatchAllowed(array $state, array $data = []): bool {
    if (($state['mode'] ?? '') === 'replay_view') {
        return true;
    }
    if (isHumanVsHumanRoster($state)) {
        return true;
    }
    if (!isCpuSoloMatch($state)) {
        return false;
    }
    return !empty($data['debug_mode']);
}

function actionSendStamp(array $state, string $playerId, array $data): array {
    $status = $state['status'] ?? '';
    if ($status === 'finished' || $status === 'waiting') {
        throw new Exception('Cannot send stamps right now');
    }
    if (!isStampMatchAllowed($state, $data)) {
        throw new Exception('Stamps are only available in player vs player matches');
    }
    $stampId = trim((string)($data['stamp_id'] ?? ''));
    $locale = trim((string)($data['locale'] ?? 'ja'));
    if ($locale !== 'en') {
        $locale = 'ja';
    }
    if (!tcgIsValidStampId($stampId, $locale)) {
        throw new Exception('Invalid stamp');
    }
    $now = time();
    $cooldown = 2;
    $lastAt = intval($state['stamp_last_at'][$playerId] ?? 0);
    $replayView = (($state['mode'] ?? '') === 'replay_view');
    if (!$replayView && $lastAt > 0 && ($now - $lastAt) < $cooldown) {
        throw new Exception('Please wait before sending another stamp');
    }
    if (!isset($state['stamp_pop']) || !is_array($state['stamp_pop'])) {
        $state['stamp_pop'] = [];
    }
    $n = intval($state['stamp_pop'][$playerId]['n'] ?? 0) + 1;
    $state['stamp_pop'][$playerId] = [
        'id' => $stampId,
        'locale' => $locale,
        'n' => $n,
        'at' => $now,
    ];
    if (!isset($state['stamp_last_at']) || !is_array($state['stamp_last_at'])) {
        $state['stamp_last_at'] = [];
    }
    $state['stamp_last_at'][$playerId] = $now;
    $state['seq']++;
    return $state;
}

function isCpuSoloMatch(array $state): bool {
    $st = $state['status'] ?? '';
    if (in_array($st, ['waiting', 'ready', 'finished'], true)) {
        return false;
    }
    $p1 = $state['players']['p1'] ?? null;
    $p2 = $state['players']['p2'] ?? null;
    if (!$p1 || !$p2) {
        return false;
    }
    return isCpuPlayer($p1) xor isCpuPlayer($p2);
}

function parsePhaseTimerConfigFromBody(array $body): array {
    $enabled = filter_var($body['phase_timer_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $duration = intval($body['phase_timer_seconds'] ?? PHASE_TIMER_SEC);
    $duration = max(PHASE_TIMER_MIN, min(PHASE_TIMER_MAX, $duration));
    return ['enabled' => $enabled, 'duration' => $duration];
}

function getPhaseTimerCfg(array $state): array {
    if (($state['mode'] ?? '') === 'ranked') {
        return ['enabled' => true, 'duration' => PHASE_TIMER_MAX];
    }
    $cfg = $state['phase_timer_cfg'] ?? [];
    if (!is_array($cfg)) {
        $cfg = [];
    }
    $duration = intval($cfg['duration'] ?? PHASE_TIMER_SEC);
    $duration = max(PHASE_TIMER_MIN, min(PHASE_TIMER_MAX, $duration));
    return [
        'enabled' => !empty($cfg['enabled']),
        'duration' => $duration,
    ];
}

function phaseTimerEnabled(array $state): bool {
    return getPhaseTimerCfg($state)['enabled'];
}

/** Human-controlled seats only — CPU opponents are driven by the client, not the clock. */
function playerUsesPhaseTimer(array $state, string $pid): bool {
    if (!in_array($pid, ['p1', 'p2'], true)) {
        return false;
    }
    return !isCpuPlayer($state['players'][$pid] ?? null);
}

function getPhaseTimerDuration(array $state): int {
    return getPhaseTimerCfg($state)['duration'];
}

/** Ranked inactivity clocks shorten only after consecutive timer expiries. */
function getPhaseTimerDurationForPlayer(array $state, string $pid): int {
    if (($state['mode'] ?? '') !== 'ranked') {
        return getPhaseTimerDuration($state);
    }
    $timeouts = intval($state['ranked']['inactivity_timeouts'][$pid] ?? 0);
    return match (true) {
        $timeouts >= 2 => 15,
        $timeouts === 1 => 60,
        default => PHASE_TIMER_MAX,
    };
}

function initPhaseTimer(array &$state): void {
    $cfg = getPhaseTimerCfg($state);
    if (!isset($state['phase_timer']) || !is_array($state['phase_timer'])) {
        $state['phase_timer'] = [
            'enabled' => $cfg['enabled'],
            'duration' => $cfg['duration'],
            'deadlines' => ['p1' => null, 'p2' => null],
            'durations' => ['p1' => null, 'p2' => null],
            'window_ids' => ['p1' => null, 'p2' => null],
            'next_window_id' => 0,
        ];
    } else {
        $state['phase_timer']['enabled'] = $cfg['enabled'];
        $state['phase_timer']['duration'] = $cfg['duration'];
    }
    foreach (['deadlines', 'durations', 'window_ids'] as $field) {
        if (!isset($state['phase_timer'][$field]) || !is_array($state['phase_timer'][$field])) {
            $state['phase_timer'][$field] = ['p1' => null, 'p2' => null];
        }
    }
    $state['phase_timer']['next_window_id'] = intval($state['phase_timer']['next_window_id'] ?? 0);
    // Upgrade an already-running timer from an older saved room without resetting it.
    foreach (['p1', 'p2'] as $pid) {
        if (!empty($state['phase_timer']['deadlines'][$pid])
            && empty($state['phase_timer']['window_ids'][$pid])) {
            $state['phase_timer']['next_window_id']++;
            $state['phase_timer']['window_ids'][$pid] = $state['phase_timer']['next_window_id'];
            $state['phase_timer']['durations'][$pid] = getPhaseTimerDurationForPlayer($state, $pid);
        }
    }
}

function setPhaseDeadline(array &$state, string $pid): void {
    if (!in_array($pid, ['p1', 'p2'], true)) {
        return;
    }
    initPhaseTimer($state);
    $duration = getPhaseTimerDurationForPlayer($state, $pid);
    $state['phase_timer']['next_window_id']++;
    $state['phase_timer']['deadlines'][$pid] = time() + $duration;
    $state['phase_timer']['durations'][$pid] = $duration;
    $state['phase_timer']['window_ids'][$pid] = $state['phase_timer']['next_window_id'];
}

function clearPhaseDeadline(array &$state, string $pid): void {
    if (!isset($state['phase_timer']['deadlines'])) {
        return;
    }
    if (in_array($pid, ['p1', 'p2'], true)) {
        $state['phase_timer']['deadlines'][$pid] = null;
        $state['phase_timer']['durations'][$pid] = null;
        $state['phase_timer']['window_ids'][$pid] = null;
    }
}

function clearAllPhaseDeadlines(array &$state): void {
    if (!isset($state['phase_timer'])) {
        return;
    }
    $state['phase_timer']['deadlines'] = ['p1' => null, 'p2' => null];
    $state['phase_timer']['durations'] = ['p1' => null, 'p2' => null];
    $state['phase_timer']['window_ids'] = ['p1' => null, 'p2' => null];
}

function refreshPromptPhaseTimer(array &$state, string $responder): void {
    if (!playerUsesPhaseTimer($state, $responder)) {
        clearPhaseDeadline($state, $responder);
        return;
    }
    initPhaseTimer($state);
    $prompt = $state['pending_prompt'] ?? null;
    $state['phase_timer']['prompt_key'] = promptTimerKey($prompt);
    foreach (['p1', 'p2'] as $pid) {
        if ($pid !== $responder) {
            clearPhaseDeadline($state, $pid);
        }
    }
    // Keep the existing Main/LIVE deadline — do not refresh the clock per prompt step.
    if (empty($state['phase_timer']['deadlines'][$responder])) {
        setPhaseDeadline($state, $responder);
    }
}

/** Assign / clear per-player deadlines when phase or active player changes. */
function refreshPvpPhaseTimers(array &$state): void {
    if (!phaseTimerEnabled($state)) {
        unset($state['phase_timer']);
        return;
    }
    initPhaseTimer($state);
    $ph = $state['phase'] ?? '';
    $prompt = $state['pending_prompt'] ?? null;
    $promptResponder = $prompt['responder'] ?? '';
    $hasOpenPrompt = $prompt && in_array($promptResponder, ['p1', 'p2'], true);

    if ($ph === 'main_first' || $ph === 'main_second') {
        if ($hasOpenPrompt) {
            refreshPromptPhaseTimer($state, $promptResponder);
            return;
        }
        unset($state['phase_timer']['prompt_key'], $state['phase_timer']['live_key'],
            $state['phase_timer']['live_keys']);
        $ap = $state['active_player'] ?? '';
        $turn = intval($state['turn'] ?? 0);
        $mainKey = $ph . '|' . $ap . '|t' . $turn;
        $prevMainKey = $state['phase_timer']['main_key'] ?? '';
        if ($mainKey !== $prevMainKey) {
            clearAllPhaseDeadlines($state);
            $state['phase_timer']['main_key'] = $mainKey;
            if (in_array($ap, ['p1', 'p2'], true) && playerUsesPhaseTimer($state, $ap)) {
                setPhaseDeadline($state, $ap);
            }
            return;
        }
        foreach (['p1', 'p2'] as $pid) {
            if ($pid !== $ap) {
                clearPhaseDeadline($state, $pid);
            }
        }
        if (in_array($ap, ['p1', 'p2'], true) && playerUsesPhaseTimer($state, $ap)
            && empty($state['phase_timer']['deadlines'][$ap])) {
            setPhaseDeadline($state, $ap);
        }
        return;
    }
    if ($ph === 'live_set') {
        if ($hasOpenPrompt) {
            refreshPromptPhaseTimer($state, $promptResponder);
            return;
        }
        unset($state['phase_timer']['prompt_key'], $state['phase_timer']['main_key']);
        unset($state['phase_timer']['live_keys']);
        $turn = intval($state['turn'] ?? 0);
        $ap = currentLiveSetPlayer($state);
        foreach (['p1', 'p2'] as $pid) {
            if ($pid !== $ap) {
                clearPhaseDeadline($state, $pid);
            }
        }
        if (!$ap || !playerUsesPhaseTimer($state, $ap)) {
            return;
        }
        $liveKey = 'live_set|t' . $turn . '|' . $ap;
        $prevLiveKey = $state['phase_timer']['live_key'] ?? '';
        if ($liveKey !== $prevLiveKey || empty($state['phase_timer']['deadlines'][$ap])) {
            $state['phase_timer']['live_key'] = $liveKey;
            setPhaseDeadline($state, $ap);
        }
        return;
    }
    if ($ph === 'setup') {
        unset($state['phase_timer']['prompt_key'], $state['phase_timer']['main_key'],
            $state['phase_timer']['live_key'], $state['phase_timer']['live_keys'],
            $state['phase_timer']['coin_key']);
        $mullKey = 'setup|mulligan';
        $prevMullKey = $state['phase_timer']['mull_key'] ?? '';
        if ($mullKey !== $prevMullKey) {
            clearAllPhaseDeadlines($state);
            $state['phase_timer']['mull_key'] = $mullKey;
        }
        foreach (['p1', 'p2'] as $pid) {
            if (!empty($state['players'][$pid]['ready_mulligan']) || !playerUsesPhaseTimer($state, $pid)) {
                clearPhaseDeadline($state, $pid);
                continue;
            }
            if ($mullKey !== $prevMullKey || empty($state['phase_timer']['deadlines'][$pid])) {
                setPhaseDeadline($state, $pid);
            }
        }
        return;
    }
    if ($ph === 'coin_flip') {
        unset($state['phase_timer']['prompt_key'], $state['phase_timer']['main_key'],
            $state['phase_timer']['live_key'], $state['phase_timer']['live_keys']);
        $flip = $state['coin_flip'] ?? null;
        if (!$flip || !coinFlipBothReady($state)) {
            clearAllPhaseDeadlines($state);
            unset($state['phase_timer']['coin_key']);
            return;
        }
        $winner = $flip['winner'] ?? '';
        foreach (['p1', 'p2'] as $pid) {
            if ($pid !== $winner) {
                clearPhaseDeadline($state, $pid);
            }
        }
        $choiceKey = 'coin_flip|' . $winner;
        $prevKey = $state['phase_timer']['coin_key'] ?? '';
        if ($choiceKey !== $prevKey || empty($state['phase_timer']['deadlines'][$winner])) {
            $state['phase_timer']['coin_key'] = $choiceKey;
            if (in_array($winner, ['p1', 'p2'], true) && playerUsesPhaseTimer($state, $winner)) {
                setPhaseDeadline($state, $winner);
            }
        }
        return;
    }
    if ($hasOpenPrompt) {
        refreshPromptPhaseTimer($state, $promptResponder);
        return;
    }
    unset($state['phase_timer']['prompt_key'], $state['phase_timer']['main_key'],
        $state['phase_timer']['live_key'], $state['phase_timer']['live_keys']);
    clearAllPhaseDeadlines($state);
}

/** Non-gameplay messages must not let an inactive ranked player avoid forfeiture. */
function rankedActionShowsPlayerActivity(string $type): bool {
    return !in_array($type, ['send_stamp', 'request_rematch', 'resign'], true);
}

function resetRankedInactivityTimeouts(array &$state, string $pid): void {
    if (($state['mode'] ?? '') !== 'ranked' || !in_array($pid, ['p1', 'p2'], true)) {
        return;
    }
    $state['ranked']['inactivity_timeouts'][$pid] = 0;
    unset($state['ranked']['last_timeout_window'][$pid]);
}

/**
 * Count one ranked inactivity strike per deadline window.
 * Returns true when the third consecutive strike has ended the game.
 */
function registerRankedInactivityTimeout(array &$state, string $pid): bool {
    if (($state['mode'] ?? '') !== 'ranked' || !in_array($pid, ['p1', 'p2'], true)) {
        return false;
    }
    initPhaseTimer($state);
    $windowId = $state['phase_timer']['window_ids'][$pid] ?? null;
    if ($windowId === null) {
        $windowId = 'deadline:' . intval($state['phase_timer']['deadlines'][$pid] ?? 0);
    }
    if (($state['ranked']['last_timeout_window'][$pid] ?? null) === $windowId) {
        return false;
    }
    $state['ranked']['last_timeout_window'][$pid] = $windowId;
    $count = intval($state['ranked']['inactivity_timeouts'][$pid] ?? 0) + 1;
    $state['ranked']['inactivity_timeouts'][$pid] = $count;
    $name = $state['players'][$pid]['name'] ?? $pid;

    if ($count < 3) {
        $nextDuration = $count === 1 ? 60 : 15;
        $state = addLog(
            $state,
            "$name — inactivity warning $count/3; next action timer is {$nextDuration}s.",
            'info'
        );
        $state['seq']++;
        return false;
    }

    $winner = $pid === 'p1' ? 'p2' : 'p1';
    unset($state['pending_prompt'], $state['surveil_stash'], $state['_surveil_chain']);
    $state['status'] = 'finished';
    $state['winner'] = $winner;
    $state['end_reason'] = 'resign';
    $state['resigned_by'] = $pid;
    $state['ranked']['auto_resigned_for_inactivity'] = $pid;
    clearAllPhaseDeadlines($state);
    $winnerName = $state['players'][$winner]['name'] ?? $winner;
    $state = addLog(
        $state,
        "$name was automatically resigned after three consecutive inactivity timeouts. $winnerName wins!"
    );
    $state['seq']++;
    return true;
}

/** Dismiss an open skill prompt when the phase clock hits zero (skip/no if possible). */
function dismissPendingPromptBeforePhaseTimeout(array $state, string $pid): array {
    $prompt = $state['pending_prompt'] ?? null;
    if (!$prompt || ($prompt['responder'] ?? '') !== $pid) {
        return $state;
    }
    $state = autoResolvePendingPromptForTimeout($state, $pid);
    if (!empty($state['pending_prompt']) && ($state['pending_prompt']['responder'] ?? '') === $pid) {
        $state = forceDismissPendingPromptForPlayer($state, $pid, 'Time expired; dismissed');
    }
    return $state;
}

/**
 * Player-requested shortcut (Shift+T): apply the same resolution as phase-timer
 * expiry for this seat, without counting a ranked inactivity strike.
 * Resolves at most one layer (skill prompt OR phase advance), matching one
 * timer-expiry pass — so skipping a bugged skill does not also end Main.
 */
function actionForceOwnTimeout(array $state, string $pid): array {
    if (!tcgMatchInProgress($state)) {
        throw new Exception('Game is not in progress');
    }
    // Heal legacy rooms that never flipped setup→playing.
    if (($state['status'] ?? '') === 'setup') {
        $state['status'] = 'playing';
    }
    if (!in_array($pid, ['p1', 'p2'], true)) {
        throw new Exception('Invalid player');
    }
    $name = $state['players'][$pid]['name'] ?? $pid;
    $prompt = $state['pending_prompt'] ?? null;

    if ($prompt && ($prompt['responder'] ?? '') === $pid) {
        $seqBefore = intval($state['seq'] ?? 0);
        $state = addLog(
            $state,
            "$name — forced timeout (skipped waiting on skill timer).",
            'info'
        );
        $state = autoResolvePendingPromptForTimeout($state, $pid);
        if (!empty($state['pending_prompt'])
            && ($state['pending_prompt']['responder'] ?? '') === $pid) {
            $state = forceDismissPendingPromptForPlayer($state, $pid, 'Forced timeout; dismissed');
        }
        if (intval($state['seq'] ?? 0) <= $seqBefore) {
            $state['seq'] = $seqBefore + 1;
        }
        return $state;
    }

    $ph = $state['phase'] ?? '';
    if (($ph === 'main_first' || $ph === 'main_second')
        && ($state['active_player'] ?? '') === $pid) {
        $state = addLog($state, "$name — forced Main Phase timeout.", 'info');
        return actionEndMain($state, $pid);
    }
    if ($ph === 'live_set' && currentLiveSetPlayer($state) === $pid) {
        $state = addLog($state, "$name — forced LIVE Phase timeout.", 'info');
        return actionEndLiveSet($state, $pid);
    }
    if ($ph === 'setup' && empty($state['players'][$pid]['ready_mulligan'])) {
        $state = addLog($state, "$name — forced Mulligan timeout (keeping hand).", 'info');
        return actionMulligan($state, $pid, ['card_ids' => []]);
    }
    if ($ph === 'coin_flip') {
        $flip = $state['coin_flip'] ?? null;
        if ($flip && coinFlipBothReady($state) && ($flip['winner'] ?? '') === $pid) {
            $state['first_player'] = $pid;
            $state['active_player'] = $pid;
            $state['phase'] = 'setup';
            unset($state['coin_flip']);
            $state = addLog(
                $state,
                "🪙 Coin flip: $name won — first player chosen (forced timeout)."
            );
            $state = addLog(
                $state,
                'Preparation — Mulligan: you may replace any number of opening hand cards once.'
            );
            $state['seq'] = intval($state['seq'] ?? 0) + 1;
            return $state;
        }
    }

    throw new Exception('Nothing to force-timeout right now');
}

/** Unstick coin flip when a client never acks or the winner never chooses. */
function applyCoinFlipStalemate(array &$state): bool {
    if (($state['phase'] ?? '') !== 'coin_flip') {
        return false;
    }
    $isPvp = isPvpMatch($state);
    $isCpuSolo = isCpuSoloMatch($state);
    if (!$isPvp && !$isCpuSolo) {
        return false;
    }
    $flip = &$state['coin_flip'];
    if (empty($flip)) {
        return false;
    }
    $now = time();
    if (empty($flip['since'])) {
        $flip['since'] = $now;
        return true;
    }

    $changed = false;
    $elapsed = $now - intval($flip['since']);

    if (!coinFlipBothReady($state) && $elapsed >= 12) {
        foreach (['p1', 'p2'] as $pid) {
            if (empty($flip['ready'][$pid])) {
                $flip['ready'][$pid] = true;
            }
        }
        $flip['both_ready_since'] = $now;
        $state = addLog($state, 'Coin flip — continued automatically (player did not respond in time).', 'info');
        $state['seq']++;
        $changed = true;
    }

    if (coinFlipBothReady($state)) {
        if (empty($flip['both_ready_since'])) {
            $flip['both_ready_since'] = $now;
            return true;
        }
        if (phaseTimerEnabled($state)) {
            return $changed;
        }
        // Guided beginner tutorial: never auto-pick first player — the client
        // must show "I'll go first" and wait for the learner.
        if (!empty($state['tutorial_guide'])) {
            return $changed;
        }
        $choiceElapsed = $now - intval($flip['both_ready_since']);
        $choiceTimeout = 35;
        if ($isCpuSolo) {
            $cpuId = isCpuPlayer($state['players']['p1'] ?? null) ? 'p1'
                : (isCpuPlayer($state['players']['p2'] ?? null) ? 'p2' : null);
            if ($cpuId && ($flip['winner'] ?? '') === $cpuId) {
                $choiceTimeout = 4;
            }
        }
        if ($choiceElapsed >= $choiceTimeout) {
            $winner = $flip['winner'] ?? 'p1';
            $state['first_player'] = $winner;
            $state['active_player'] = $winner;
            $state['phase'] = 'setup';
            unset($state['coin_flip']);
            $winnerName = $state['players'][$winner]['name'] ?? $winner;
            $state = addLog($state, '🪙 Coin flip: ' . $winnerName . ' won — first player chosen automatically (time expired).');
            $state = addLog($state, 'Preparation — Mulligan: you may replace any number of opening hand cards once.');
            $state['seq']++;
            $changed = true;
        }
    }

    return $changed;
}

/** Snapshot of everything the live_show cursor can move, to detect no-op advances. */
function liveShowProgressSignature(array $state): string {
    $show = $state['live_show'] ?? [];
    return implode('|', [
        (string)($show['stage'] ?? ''),
        (string)intval($show['stage_seq'] ?? 0),
        (string)($state['phase'] ?? ''),
        empty($state['pending_prompt']) ? '0' : '1',
        empty($state['_perf_yell_both_done']) ? '0' : '1',
        (string)($state['status'] ?? ''),
        (string)count($state['log'] ?? []),
    ]);
}

/**
 * Resume a Performance parked after the Yell step. advanceLiveShowStage() is a
 * no-op while `_perf_yell_both_done` is unset, so a skill that finished without
 * continuing the chain would otherwise leave the room on stage `performance`
 * forever — hearts never checked, no winner, and the room lingers in spectate.
 */
function healStalledLiveShowPerformance(array $state): array {
    if (($state['live_show']['stage'] ?? '') !== 'performance'
        || !empty($state['pending_prompt'])
        || !empty($GLOBALS['TUT_PERF_MANUAL_PHASES'])) {
        return $state;
    }
    $pid = $state['_performance_continue']
        ?? $state['_live_start_perf_pid']
        ?? ($state['first_player'] ?? 'p1');
    if (!is_string($pid) || !isset($state['players'][$pid])) {
        $pid = $state['first_player'] ?? 'p1';
    }
    unset($state['_performance_continue']);
    if (!empty($state['_perf_yell_both_done'])) {
        // finishYellRetryAndHearts() intentionally no-ops while stage=performance and
        // hearts have not started (client spectacle hold). Timeouts / stall heals must
        // actually resolve hearts or rooms softlock on "Checking hearts…".
        return resolvePerformanceHeartsAfterYell($state);
    }
    return continuePerformanceYellPhase($state, $pid);
}

/** Stamp/clear when the CPU seat must lock in during live_set (no phase timer). */
function markCpuLiveSetWait(array &$state, ?string $pid): void {
    if ($pid && isCpuPlayer($state['players'][$pid] ?? null)) {
        $state['live_set_cpu_since'] = time();
        return;
    }
    unset($state['live_set_cpu_since']);
}

function clearCpuLiveSetWait(array &$state): void {
    unset($state['live_set_cpu_since']);
}

/**
 * CPU live_set placement is client-driven. If presentation flags block doCPU after
 * the human locks in, the room used to freeze until resign. Auto lock-in on polls.
 */
function applyCpuStuckLiveSetTimeout(array &$state): bool {
    if (!isCpuSoloMatch($state) || ($state['status'] ?? '') !== 'playing') {
        clearCpuLiveSetWait($state);
        return false;
    }
    if (($state['phase'] ?? '') !== 'live_set' || !empty($state['pending_prompt'])) {
        return false;
    }
    $pid = currentLiveSetPlayer($state);
    if (!$pid || !isCpuPlayer($state['players'][$pid] ?? null)) {
        return false;
    }
    if (!empty($state['live_ready'][$pid])) {
        clearCpuLiveSetWait($state);
        return false;
    }
    $since = intval($state['live_set_cpu_since'] ?? 0);
    if ($since <= 0) {
        markCpuLiveSetWait($state, $pid);
        $state['seq'] = intval($state['seq'] ?? 0) + 1;
        return true;
    }
    if (time() - $since < 20) {
        return false;
    }
    $name = $state['players'][$pid]['name'] ?? $pid;
    clearCpuLiveSetWait($state);
    $state = addLog($state, "$name — LIVE Phase auto lock-in (CPU client timeout).", 'info');
    $state = actionEndLiveSet($state, $pid);
    return true;
}

/**
 * CPU prompts are resolved by the browser AI. If that client dies (background tab,
 * G.animating stuck after Baton, multi-step prompt seq skip), the room freezes
 * forever — phase timers are off for CPU seats. Heal on get_state / action polls.
 */
function applyCpuStuckPromptTimeout(array &$state): bool {
    if (!isCpuSoloMatch($state) || ($state['status'] ?? '') !== 'playing') {
        return false;
    }
    $prompt = $state['pending_prompt'] ?? null;
    if (!is_array($prompt)) {
        return false;
    }
    $responder = (string)($prompt['responder'] ?? '');
    if (!in_array($responder, ['p1', 'p2'], true)
        || !isCpuPlayer($state['players'][$responder] ?? null)) {
        return false;
    }
    $since = intval($prompt['opened_at'] ?? 0);
    if ($since <= 0) {
        $log = $state['action_log'] ?? [];
        $last = $log ? $log[array_key_last($log)] : null;
        $since = intval(is_array($last) ? ($last['ts'] ?? 0) : 0);
        if ($since <= 0) {
            $since = time();
        }
        $state['pending_prompt']['opened_at'] = $since;
    }
    if (time() - $since < 20) {
        return !isset($prompt['opened_at']);
    }
    $seqBefore = intval($state['seq'] ?? 0);
    $beforeKey = (string)($prompt['type'] ?? '') . '|' . (string)($prompt['step'] ?? '');
    if (function_exists('autoResolvePendingPromptForTimeout')) {
        $state = autoResolvePendingPromptForTimeout($state, $responder);
    }
    $after = $state['pending_prompt'] ?? null;
    if (is_array($after) && ($after['responder'] ?? '') === $responder) {
        $afterKey = (string)($after['type'] ?? '') . '|' . (string)($after['step'] ?? '');
        if ($afterKey === $beforeKey) {
            if (function_exists('forceDismissPendingPromptForPlayer')) {
                $state = forceDismissPendingPromptForPlayer(
                    $state,
                    $responder,
                    'CPU prompt timed out'
                );
            }
        } else {
            $state['pending_prompt']['opened_at'] = time();
        }
    }
    return intval($state['seq'] ?? 0) > $seqBefore || empty($state['pending_prompt']);
}

/** Auto end main / live when PvP phase timers expire. Returns true if state changed. */
function applyPhaseTimeouts(array &$state): bool {
    $cpuLiveSetChanged = applyCpuStuckLiveSetTimeout($state);
    $cpuPromptChanged = applyCpuStuckPromptTimeout($state);
    if (!empty($state['live_show'])
        && ($state['live_show']['stage'] ?? '') !== 'done'
        && empty($state['pending_prompt'])
        && time() - intval($state['live_show']['started_at'] ?? time()) >= (
            // Solo/CPU: give the human time to finish watching; PvP keeps a tighter sync.
            count(liveShowRequiredAckPlayers($state)) >= 2 ? 25 : 90
        )) {
        $before = liveShowProgressSignature($state);
        $state = advanceLiveShowStage($state);
        if (liveShowProgressSignature($state) === $before) {
            $state = healStalledLiveShowPerformance($state);
        }
        if (liveShowProgressSignature($state) !== $before) {
            $state['seq'] = intval($state['seq'] ?? 0) + 1;
            return true;
        }
        // Nothing left to advance: re-arm the window instead of bumping seq every
        // poll (that starved the phase timers below and spammed clients forever).
        $state['live_show']['started_at'] = time();
        return true;
    }
    if (!phaseTimerEnabled($state)) {
        return $cpuLiveSetChanged || $cpuPromptChanged;
    }
    initPhaseTimer($state);
    $now = time();
    $changed = false;

    for ($pass = 0; $pass < 6; $pass++) {
        if (($state['status'] ?? '') === 'finished') {
            break;
        }
        $ph = $state['phase'] ?? '';
        $did = false;

        $prompt = $state['pending_prompt'] ?? null;
        if ($prompt) {
            $responder = $prompt['responder'] ?? '';
            if (in_array($responder, ['p1', 'p2'], true) && playerUsesPhaseTimer($state, $responder)) {
                $dl = $state['phase_timer']['deadlines'][$responder] ?? null;
                if ($dl && $now >= $dl) {
                    if (registerRankedInactivityTimeout($state, $responder)) {
                        return true;
                    }
                    $state = autoResolvePendingPromptForTimeout($state, $responder);
                    if (!empty($state['pending_prompt'])
                        && ($state['pending_prompt']['responder'] ?? '') === $responder) {
                        $state = dismissPendingPromptBeforePhaseTimeout($state, $responder);
                    }
                    refreshPvpPhaseTimers($state);
                    $changed = $did = true;
                    continue;
                }
            }
        }

        if ($ph === 'main_first' || $ph === 'main_second') {
            $ap = $state['active_player'] ?? '';
            if (!playerUsesPhaseTimer($state, $ap)) {
                break;
            }
            $dl = $state['phase_timer']['deadlines'][$ap] ?? null;
            if (!$ap || !$dl || $now < $dl) {
                break;
            }
            if (registerRankedInactivityTimeout($state, $ap)) {
                return true;
            }
            $name = $state['players'][$ap]['name'] ?? $ap;
            $state = addLog($state, "$name — Main Phase time expired (auto end).", 'info');
            $state = dismissPendingPromptBeforePhaseTimeout($state, $ap);
            $state = actionEndMain($state, $ap);
            $changed = $did = true;
        } elseif ($ph === 'live_set') {
            $pid = currentLiveSetPlayer($state);
            if (!$pid || !playerUsesPhaseTimer($state, $pid)) {
                break;
            }
            $dl = $state['phase_timer']['deadlines'][$pid] ?? null;
            if (!$dl || $now < $dl) {
                break;
            }
            if (registerRankedInactivityTimeout($state, $pid)) {
                return true;
            }
            $name = $state['players'][$pid]['name'] ?? $pid;
            $state = addLog($state, "$name — LIVE Phase time expired (auto lock-in).", 'info');
            $state = dismissPendingPromptBeforePhaseTimeout($state, $pid);
            $state = actionEndLiveSet($state, $pid);
            $changed = $did = true;
        } elseif ($ph === 'coin_flip') {
            $flip = $state['coin_flip'] ?? null;
            if (!$flip || !coinFlipBothReady($state)) {
                break;
            }
            $winner = $flip['winner'] ?? '';
            if (!in_array($winner, ['p1', 'p2'], true) || !playerUsesPhaseTimer($state, $winner)) {
                break;
            }
            $dl = $state['phase_timer']['deadlines'][$winner] ?? null;
            if (!$dl || $now < $dl) {
                break;
            }
            if (registerRankedInactivityTimeout($state, $winner)) {
                return true;
            }
            $winnerName = $state['players'][$winner]['name'] ?? $winner;
            $state['first_player'] = $winner;
            $state['active_player'] = $winner;
            $state['phase'] = 'setup';
            unset($state['coin_flip']);
            $state = addLog($state, '🪙 Coin flip: ' . $winnerName . ' won — first player chosen automatically (time expired).');
            $state = addLog($state, 'Preparation — Mulligan: you may replace any number of opening hand cards once.');
            $state['seq']++;
            refreshPvpPhaseTimers($state);
            $changed = $did = true;
        } elseif ($ph === 'setup') {
            foreach (['p1', 'p2'] as $pid) {
                if (!playerUsesPhaseTimer($state, $pid)) {
                    continue;
                }
                if (!empty($state['players'][$pid]['ready_mulligan'])) {
                    continue;
                }
                $dl = $state['phase_timer']['deadlines'][$pid] ?? null;
                if (!$dl || $now < $dl) {
                    continue;
                }
                if (registerRankedInactivityTimeout($state, $pid)) {
                    return true;
                }
                $name = $state['players'][$pid]['name'] ?? $pid;
                $state = addLog($state, "$name — Mulligan time expired (keeping hand).", 'info');
                $state = actionMulligan($state, $pid, ['card_ids' => []]);
                $changed = $did = true;
            }
        }

        if (!$did) {
            break;
        }
    }

    return $changed || $cpuPromptChanged;
}

// ─────────────────────────────────────────────
// get_state filtering (per-player view)
// ─────────────────────────────────────────────

/**
 * Strip secrets and enrich UI fields before JSON reaches a client.
 * Hides opponent hand/deck in human PvP; keeps CPU hand for solo AI. Redacts unrevealed
 * opponent Live storage. Filters log lines via msg_public for hidden effect details.
 * Exposes stage_board hearts/yell and carries yell_reveal / perf snapshots across
 * batched poll updates so the client can run Performance spectacle after judge.
 */
function filterStateForPlayer(array $state, string $token): array {
    $myId    = getPlayerIdByToken($state, $token);
    $oppId   = $myId ? (($myId === 'p1') ? 'p2' : 'p1') : null;
    $filtered = $state;
    // Break lingering &$state['players'][$id] references (tests and some resolvers)
    // so opponent-hand redaction cannot wipe the real match.
    if (isset($state['players']) && is_array($state['players'])) {
        $filtered['players'] = [];
        foreach ($state['players'] as $pid => $player) {
            $filtered['players'][$pid] = is_array($player) ? array_replace([], $player) : $player;
        }
    }

    // Hide opponent's hand in human vs human; keep visible for solo CPU (client AI)
    if ($oppId && isset($filtered['players'][$oppId])) {
        $opp = $filtered['players'][$oppId];
        $cpuOpponent = isCpuPlayer($opp);
        $filtered['players'][$oppId]['hand_count'] = count($opp['hand']);
        if (!$cpuOpponent) {
            $filtered['players'][$oppId]['hand']  = [];
        } else {
            $filtered['cpu_solo'] = true;
            if (!empty($state['cpu_difficulty'])) {
                $filtered['cpu_difficulty'] = normalizeCpuDifficulty($state['cpu_difficulty']);
            }
        }
        // Deck contents are secret — counts only (Waiting Room stays public, face-up in UI)
        $filtered['players'][$oppId]['main_deck_count'] = count($opp['main_deck'] ?? []);
        $filtered['players'][$oppId]['main_deck'] = [];
        $filtered['players'][$oppId]['energy_deck_count'] = count($opp['energy_deck'] ?? []);
        $filtered['players'][$oppId]['energy_deck'] = [];
        $filtered['players'][$oppId]['token'] = '';
        foreach ($filtered['players'][$oppId]['live_zone'] as &$lc) {
            if (!$cpuOpponent && !($lc['revealed'] ?? false)) {
                $lc = ['instance_id' => $lc['instance_id'], 'revealed' => false, 'card_no' => '?'];
            }
        }
        unset($lc);
    }

    $prompt = $filtered['pending_prompt'] ?? null;
    if (is_array($prompt)) {
        $responder = (string)($prompt['responder'] ?? $prompt['owner'] ?? '');
        if ($myId && $responder !== $myId && pendingPromptIsPrivateLook($prompt)) {
            $filtered['pending_prompt'] = [
                'type' => 'wait_look',
                'owner' => $prompt['owner'] ?? $responder,
                'responder' => $responder,
                'source_name' => $prompt['source_name'] ?? '',
                'prompt' => 'Opponent is looking at cards…',
            ];
            unset($filtered['surveil_stash']);
        } elseif ($myId && $responder !== $myId) {
            unset($filtered['surveil_stash']);
        }
    } else {
        unset($filtered['surveil_stash']);
    }

    // Hide own token too (client stores it already)
    if ($myId) {
        $filtered['players'][$myId]['token'] = '';
    }

    $filtered['my_id'] = $myId;
    $filtered['pvp'] = isPvpMatch($state);
    $filtered['mode'] = $state['mode'] ?? null;
    $filtered['phase_timer_cfg'] = getPhaseTimerCfg($state);

    if (($state['status'] ?? '') === 'finished' && $myId && $oppId && isHumanVsHumanRoster($state)) {
        $rematch = is_array($state['rematch'] ?? null) ? $state['rematch'] : [];
        $filtered['rematch'] = [
            'mine' => !empty($rematch[$myId]),
            'opp'  => !empty($rematch[$oppId]),
        ];
    }

    hideLiveJudgeSpoilersFromFilteredState($filtered, $state);
    if ($myId && !empty($filtered['log'])) {
        $filtered['log'] = array_map(
            fn($entry) => filterLogEntryForViewer(
                is_array($entry) ? $entry : ['msg' => (string)$entry],
                $myId,
                $filtered
            ),
            $filtered['log']
        );
    }

    if (!empty($filtered['pending_prompt'])) {
        $filtered['pending_prompt'] = enrichSelfActivationPrompt($filtered, $filtered['pending_prompt']);
    }

    if ($myId && $oppId) {
        $carryPhase = $state['phase'] ?? '';
        $exposePerfCarryover = in_array($carryPhase, [
            'main_first', 'main_second', 'active_first', 'active_second',
            'live_start_effects', 'live_performance_first', 'live_performance_second',
            'live_success_effects', 'live_judge',
        ], true) || ($state['status'] ?? '') === 'finished';
        $mineStage = is_array($state['players'][$myId] ?? null)
            ? ($state['players'][$myId]['stage'] ?? [])
            : [];
        $oppStage = is_array($state['players'][$oppId] ?? null)
            ? ($state['players'][$oppId]['stage'] ?? [])
            : [];
        $mineStageHearts = aggregateStageHeartsByColor(is_array($mineStage) ? $mineStage : []);
        $oppStageHearts = aggregateStageHeartsByColor(is_array($oppStage) ? $oppStage : []);
        // Live-start modifier hearts (Eli/Kotori choose_heart_per_success, etc.)
        $mineStageHearts = mergeHeartColorCounts(
            $mineStageHearts,
            aggregateFlatHeartColors(getBonusHeartsFlat($state, $myId))
        );
        $oppStageHearts = mergeHeartColorCounts(
            $oppStageHearts,
            aggregateFlatHeartColors(getBonusHeartsFlat($state, $oppId))
        );
        // Performance snapshot is for spectacle only — do NOT overwrite stage_board
        // stage_hearts during Main/Active (that left the HUD stuck on last Live's totals).
        $minePerfStageHearts = ($exposePerfCarryover && !empty($state['_stage_hearts_snapshot'][$myId]))
            ? $state['_stage_hearts_snapshot'][$myId]
            : null;
        $oppPerfStageHearts = ($exposePerfCarryover && !empty($state['_stage_hearts_snapshot'][$oppId]))
            ? $state['_stage_hearts_snapshot'][$oppId]
            : null;
        $showYellHearts = isInPerformancePhase($state);
        $mineYellHearts = $showYellHearts
            ? aggregateYellHeartsByColor($state['yell_reveal'][$myId] ?? [])
            : [];
        $oppYellHearts = $showYellHearts
            ? aggregateYellHeartsByColor($state['yell_reveal'][$oppId] ?? [])
            : [];
        $mineContinuousGrants = $showYellHearts
            ? collectContinuousPerformanceHeartGrants($state, $myId) : [];
        $oppContinuousGrants = $showYellHearts
            ? collectContinuousPerformanceHeartGrants($state, $oppId) : [];
        $mineContinuousHearts = aggregateFlatHeartColors(getContinuousPerformanceHearts($state, $myId));
        $oppContinuousHearts = aggregateFlatHeartColors(getContinuousPerformanceHearts($state, $oppId));
        $yellBladeMine = computeYellBladeTotal($state, $myId);
        $yellBladeOpp = computeYellBladeTotal($state, $oppId);
        $yellBladeMinePerf = null;
        $yellBladeOppPerf = null;
        if ($exposePerfCarryover && !empty($state['_yell_blade_snapshot'])) {
            $yellBladeMinePerf = intval($state['_yell_blade_snapshot'][$myId] ?? $yellBladeMine);
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
                    ? 0 : getLiveScoreBonus($state, $myId),
                'active_effects' => collectActiveContinuousEffects($state, $myId),
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
    }

    if (!isset($exposePerfCarryover)) {
        $carryPhase = $state['phase'] ?? '';
        $exposePerfCarryover = in_array($carryPhase, [
            'main_first', 'main_second', 'active_first', 'active_second',
            'live_start_effects', 'live_performance_first', 'live_performance_second',
            'live_success_effects', 'live_judge',
        ], true) || ($state['status'] ?? '') === 'finished';
    }

    // Expose live Yell draws whenever present — including mid-round Live Start for
    // the 2nd performer (phase live_start_effects) after the 1st Yell. Gating on
    // isInPerformancePhase hid cards and left the spectacle animating empty rails.
    if (!empty($state['yell_reveal'])) {
        $filtered['yell_reveal'] = $state['yell_reveal'];
    } elseif ($exposePerfCarryover && !empty($state['_yell_reveal_snapshot'])) {
        // Keep last round's Yell draws visible until the next Performance (PvP may
        // resolve judge + startTurn before either client polls for spectacle).
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
        unset($filtered['_stage_hearts_snapshot']);
    }

    if (!empty($state['stamp_pop']) && is_array($state['stamp_pop'])) {
        $filtered['stamp_pop'] = $state['stamp_pop'];
    }

    if ($myId && ($state['status'] ?? '') === 'finished') {
        require_once __DIR__ . '/ranked_pr_rewards.php';
        $prReward = tcgRankedPrRewardForPlayer($state, $myId);
        if ($prReward !== null) {
            $filtered['ranked_pr_reward'] = $prReward;
        } else {
            // Game files must never leak a prior seat's top-level reward onto the opponent.
            unset($filtered['ranked_pr_reward']);
        }
        // Nested winner payload includes their daily allowance — hide from everyone else.
        if (isset($filtered['ranked']) && is_array($filtered['ranked'])) {
            $nested = $filtered['ranked']['pr_reward'] ?? null;
            if (is_array($nested) && (string)($nested['player_id'] ?? '') !== $myId) {
                unset($filtered['ranked']['pr_reward']);
            }
        }
    }

    return enrichReplayFieldsForClient($filtered, $state);
}

function inferLogOwnerPid(array $state, string $msg): ?string {
    if (!preg_match('/^(.+?) — \[/u', $msg, $m)) {
        return null;
    }
    $name = trim($m[1]);
    foreach (['p1', 'p2'] as $pid) {
        if (!empty($state['players'][$pid]) && ($state['players'][$pid]['name'] ?? '') === $name) {
            return $pid;
        }
    }
    return null;
}

function filterLogEntryForViewer(array $entry, string $myId, array $state): array {
    $owner = $entry['owner'] ?? inferLogOwnerPid($state, $entry['msg'] ?? '');
    if ($owner === null || $owner === $myId) {
        unset($entry['owner'], $entry['msg_public']);
        return $entry;
    }
    if (!empty($entry['msg_public'])) {
        $entry['msg'] = $entry['msg_public'];
    } elseif (preg_match('/^(.+? — \[[^\]]+\] )(.+)$/u', $entry['msg'] ?? '', $m)) {
        $redacted = redactEffectDetailForOpponent($m[2]);
        if ($redacted !== $m[2]) {
            $entry['msg'] = $m[1] . $redacted;
        }
    }
    unset($entry['owner'], $entry['msg_public']);
    return $entry;
}

function inferLogKind(string $message): string {
    if (preg_match('/^===|^---|^Game started|^Each player drew/', $message)) {
        return 'phase';
    }
    if (str_contains($message, ' — [') || str_contains($message, ' — [')) {
        return 'effect';
    }
    if (str_contains($message, 'played ') || str_contains($message, 'Baton Touch')
        || str_contains($message, 'performed Live') || str_contains($message, 'set ')
        || str_contains($message, 'drew ') || str_contains($message, 'Resign')) {
        return 'action';
    }
    if (str_contains($message, 'WIN') || str_contains($message, '🎉')
        || str_contains($message, 'success') || str_contains($message, 'Success Live')) {
        return 'good';
    }
    if (str_contains($message, 'fail') || str_contains($message, 'resign')) {
        return 'warn';
    }
    return 'info';
}

function addLog(array $state, string $message, ?string $kind = null, array $anim = [], array $opts = []): array {
    $entry = [
        'msg'  => $message,
        'ts'   => time(),
        'kind' => $kind ?? inferLogKind($message),
    ];
    if (!empty($anim)) {
        $entry['anim'] = $anim;
    }
    if (!empty($opts['owner']) && !empty($opts['msg_public']) && $opts['msg_public'] !== $message) {
        $entry['owner'] = $opts['owner'];
        $entry['msg_public'] = $opts['msg_public'];
    }
    $state['log'][] = $entry;
    if (count($state['log']) > 500) {
        $state['log'] = array_slice($state['log'], -500);
    }
    return $state;
}

function generateToken(): string {
    return bin2hex(random_bytes(16));
}

// ─────────────────────────────────────────────
// Persistence (file flock or Redis via GameStore)
// ─────────────────────────────────────────────

function tcgResolveGameStore(): \LLTCG\Game\Store\GameStoreInterface {
    if (isset($GLOBALS['__tcg_game_store'])
        && $GLOBALS['__tcg_game_store'] instanceof \LLTCG\Game\Store\GameStoreInterface) {
        return $GLOBALS['__tcg_game_store'];
    }
    $GLOBALS['__tcg_game_store'] = \LLTCG\Game\Store\GameStoreFactory::fromEnv();
    return $GLOBALS['__tcg_game_store'];
}

/** @internal tests may inject a store */
function tcgSetGameStore(?\LLTCG\Game\Store\GameStoreInterface $store): void {
    $GLOBALS['__tcg_game_store'] = $store;
}

function gameFile(string $roomId): string {
    // Legacy helper for presence/side paths — room body may live in Redis.
    return GAMES_DIR . preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId)) . '.json';
}

function loadGame(string $roomId): ?array {
    return tcgResolveGameStore()->load($roomId);
}

function saveGame(string $roomId, array $state): void {
    tcgResolveGameStore()->save($roomId, $state);
    if (($state['mode'] ?? '') === 'tournament') {
        // Spectate delay ring writes file I/O — defer until the room lock is released
        // so peer actions (placement / End LIVE) are not blocked under contention.
        if (!empty($GLOBALS['__tcg_sync_defer'])) {
            if (!isset($GLOBALS['__tcg_delay_pending']) || !is_array($GLOBALS['__tcg_delay_pending'])) {
                $GLOBALS['__tcg_delay_pending'] = [];
            }
            $GLOBALS['__tcg_delay_pending'][$roomId] = $state;
        } else {
            require_once __DIR__ . '/tournament_spectate.php';
            tcgTournamentRecordDelayedSnapshot($roomId, $state);
        }
    }
    if (!isPvpMatch($state)) {
        return;
    }
    $seq = intval($state['seq'] ?? 0);
    $phase = isset($state['phase']) ? (string)$state['phase'] : null;
    // Defer sync curl until the room flock is released so Live acks don't block peers.
    if (!empty($GLOBALS['__tcg_sync_defer'])) {
        if (!isset($GLOBALS['__tcg_sync_pending']) || !is_array($GLOBALS['__tcg_sync_pending'])) {
            $GLOBALS['__tcg_sync_pending'] = [];
        }
        $GLOBALS['__tcg_sync_pending'][$roomId] = ['seq' => $seq, 'phase' => $phase];
        return;
    }
    tcgSyncNotify($roomId, $seq, $phase);
}

function withLock(string $roomId, callable $fn, ?float $timeoutSec = null): mixed {
    $GLOBALS['__tcg_sync_defer'] = intval($GLOBALS['__tcg_sync_defer'] ?? 0) + 1;
    try {
        return tcgResolveGameStore()->withLock($roomId, $fn, $timeoutSec);
    } finally {
        $GLOBALS['__tcg_sync_defer'] = max(0, intval($GLOBALS['__tcg_sync_defer'] ?? 1) - 1);
        if (intval($GLOBALS['__tcg_sync_defer'] ?? 0) === 0) {
            $delayPending = $GLOBALS['__tcg_delay_pending'] ?? [];
            $GLOBALS['__tcg_delay_pending'] = [];
            if (is_array($delayPending) && $delayPending) {
                require_once __DIR__ . '/tournament_spectate.php';
                foreach ($delayPending as $rid => $snap) {
                    if (!is_array($snap)) {
                        continue;
                    }
                    try {
                        tcgTournamentRecordDelayedSnapshot((string)$rid, $snap);
                    } catch (Throwable $e) {
                        // Spectate delay must never fail the player action that already committed.
                    }
                }
            }
            $pending = $GLOBALS['__tcg_sync_pending'] ?? [];
            $GLOBALS['__tcg_sync_pending'] = [];
            if (is_array($pending)) {
                foreach ($pending as $rid => $info) {
                    if (!is_array($info)) {
                        continue;
                    }
                    tcgSyncNotify(
                        (string)$rid,
                        intval($info['seq'] ?? 0),
                        isset($info['phase']) ? (string)$info['phase'] : null
                    );
                }
            }
        }
    }
}

function touchPresence(string $roomId, string $token): void {
    $file = GAMES_DIR . 'presence_' . preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId)) . '.json';
    $data = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: [];
    }
    $data[$token] = time();
    file_put_contents($file, json_encode($data));
}

/**
 * Throttle timeout/disconnect side-effects on poll=0 so unchanged seq can short-circuit.
 * Still runs at least once per second per room so phase clocks stay accurate.
 */
function tcgPollSideEffectsDue(string $roomId): bool {
    $safe = preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId));
    if ($safe === '') {
        return true;
    }
    $file = GAMES_DIR . 'poll_tick_' . $safe . '.json';
    $now = time();
    $last = 0;
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        $last = intval(is_array($j) ? ($j['t'] ?? 0) : 0);
    }
    if ($last > 0 && ($now - $last) < 1) {
        return false;
    }
    @file_put_contents($file, json_encode(['t' => $now]), LOCK_EX);
    return true;
}

function readPresence(string $roomId): array {
    $file = GAMES_DIR . 'presence_' . preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId)) . '.json';
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/** Forfeit PvP matches when an opponent stops polling / never joins. */
function applyDisconnectForfeits(array &$state, string $roomId): bool {
    if (($state['status'] ?? '') === 'finished') {
        return false;
    }
    if (($state['mode'] ?? '') === 'replay_view') {
        return false;
    }

    if (!isPvpMatch($state)) {
        return false;
    }

    $presence = readPresence($roomId);
    $now = time();
    $path = gameFile($roomId);
    $gameAge = is_file($path) ? ($now - filemtime($path)) : 0;
    $isRanked = ($state['mode'] ?? '') === 'ranked';
    $noShowSec = $isRanked ? PRESENCE_NO_SHOW_SEC : PRESENCE_NO_SHOW_SEC * 2;
    $grace = PRESENCE_DISCONNECT_SEC;

    // Shared outage: if every human seat is stale, do not mass-forfeit (Hostinger CPU blip).
    $humanSeats = [];
    foreach (['p1', 'p2'] as $pid) {
        $player = $state['players'][$pid] ?? null;
        if (!$player || isCpuPlayer($player)) {
            continue;
        }
        $token = $player['token'] ?? '';
        if ($token === '') {
            continue;
        }
        $last = intval($presence[$token] ?? 0);
        $stale = ($last > 0 && ($now - $last) >= $grace)
            || ($last === 0 && $gameAge >= $noShowSec);
        $humanSeats[$pid] = [
            'token' => $token,
            'last' => $last,
            'stale' => $stale,
            'name' => $player['name'] ?? $pid,
        ];
    }
    if (count($humanSeats) >= 2) {
        $allStale = true;
        foreach ($humanSeats as $seat) {
            if (!$seat['stale']) {
                $allStale = false;
                break;
            }
        }
        if ($allStale) {
            return false;
        }
    }

    foreach ($humanSeats as $pid => $seat) {
        $token = $seat['token'];
        $last = $seat['last'];
        $gone = false;
        if ($last > 0 && ($now - $last) >= $grace) {
            $gone = true;
        } elseif ($last === 0 && $gameAge >= $noShowSec) {
            $other = ($pid === 'p1') ? 'p2' : 'p1';
            $otherSeat = $humanSeats[$other] ?? null;
            $otherLast = $otherSeat ? intval($otherSeat['last']) : 0;
            if ($otherLast > 0 && ($now - $otherLast) < 60) {
                $gone = true;
            }
        }
        if (!$gone) {
            continue;
        }

        $winner = ($pid === 'p1') ? 'p2' : 'p1';
        $loserName = $seat['name'];
        $winnerName = $state['players'][$winner]['name'] ?? $winner;
        $state['status'] = 'finished';
        $state['winner'] = $winner;
        $state['end_reason'] = 'disconnect';
        $state['disconnected_player'] = $pid;
        $state = addLog($state, "$loserName disconnected. $winnerName wins!", 'info');
        $state['seq']++;
        return true;
    }
    return false;
}

function maybeApplyRankedFinish(array &$state): void {
    if (($state['mode'] ?? '') !== 'ranked' || ($state['status'] ?? '') !== 'finished') {
        return;
    }
    require_once __DIR__ . '/ranked_room.php';
    tcgOnGameFinished($state);
}

/**
 * Casual/CPU finishes that land via disconnect/timeout (get_state) never hit handleAction's
 * justFinished path — credit Hostinger missions once when match-primary owns the room.
 */
function maybeCreditCasualFinishMissions(array &$state): void {
    if (($state['status'] ?? '') !== 'finished') {
        return;
    }
    if (($state['mode'] ?? '') === 'ranked') {
        return;
    }
    if (!empty($state['_missions_applied'])) {
        return;
    }
    require_once __DIR__ . '/match_bridge.php';
    if (!tcgMissionShouldWriteOnHostinger()) {
        return;
    }
    $bundle = tcgPostMissionGameFinishedBundleToHostinger($state);
    $state['_missions_applied'] = true;
    if ($bundle['missions'] !== []) {
        $state['_hostinger_mission_completions'] = $bundle['missions'];
    }
    if (!empty($bundle['coin_grants'])) {
        $state['_coin_grants'] = $bundle['coin_grants'];
    }
}

/** Poll recovery: apply ELO if a finished ranked room never got ranked.applied. */
function maybeRecoverUnappliedRankedFinish(string $roomId, array &$state): void {
    if (($state['mode'] ?? '') !== 'ranked' || ($state['status'] ?? '') !== 'finished') {
        return;
    }
    $ranked = is_array($state['ranked'] ?? null) ? $state['ranked'] : [];
    $needsElo = empty($ranked['applied']);
    require_once __DIR__ . '/ranked_pr_rewards.php';
    $needsPr = tcgRankedPrRewardNeedsHostingerRetry($state);
    if (!$needsElo && !$needsPr) {
        return;
    }
    $seqBefore = intval($state['seq'] ?? 0);
    maybeApplyRankedFinish($state);
    if (intval($state['seq'] ?? 0) !== $seqBefore
        || ($needsElo && !empty($state['ranked']['applied']))
        || ($needsPr && !tcgRankedPrRewardNeedsHostingerRetry($state))) {
        saveGame($roomId, $state);
    }
}

function cleanupOldGames(): array {
    $files = glob(GAMES_DIR . '*.json');
    $cleaned = 0;
    foreach ($files as $f) {
        if (filemtime($f) < time() - GAME_TIMEOUT) {
            unlink($f);
            $cleaned++;
        }
    }
    return ['cleaned' => $cleaned];
}
