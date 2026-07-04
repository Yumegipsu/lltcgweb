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
tcgDefinePathConstants();

header('Content-Type: application/json');
tcgSendCorsHeaders();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Player-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    tcgSendCorsPreflight('GET, POST, OPTIONS', 'Content-Type, X-Player-Token');
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
require_once __DIR__ . '/effects.php';
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
define('PRESENCE_DISCONNECT_SEC', 120); // PvP: forfeit if opponent idle this long
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

try {
    switch ($action) {
        case 'create_room':  echo json_encode(createRoom($body));    break;
        case 'join_room':    echo json_encode(joinRoom($body));       break;
        case 'get_state':    getStatePolling();                        break;
        case 'action':       echo json_encode(handleAction($body));   break;
        case 'get_cards':    echo getCards();                          break;
        case 'preview_random_deck': echo json_encode(previewRandomDeck(
            CARDS_FILE,
            trim((string)($_GET['group'] ?? $body['group'] ?? '')) ?: null
        )); break;
        case 'cache_card_image': echo json_encode(cacheCardImage($body)); break;
        case 'experiment_deck_save': echo json_encode(apiExperimentDeckSave($body)); break;
        case 'experiment_deck_load': echo json_encode(apiExperimentDeckLoad($body)); break;
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
        case 'cleanup':      echo json_encode(cleanupOldGames());      break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Exception $e) {
    $msg = $e->getMessage();
    $serverFault = preg_match('/^(Cannot acquire lock|Lock timeout)/', $msg);
    $code = $serverFault ? 500 : 400;
    http_response_code($code);
    echo json_encode(['error' => tcgPublicErrorMessage($e, $code)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => tcgPublicErrorMessage($e, 500)]);
}

// ─────────────────────────────────────────────
// Card Data
// ─────────────────────────────────────────────
function getCards(): string {
    if (!file_exists(CARDS_FILE)) {
        return json_encode(['cards' => [], 'starter_decks' => []]);
    }
    return file_get_contents(CARDS_FILE);
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
    $deckChoice = (string)($body['deck'] ?? 'nijigasaki');
    if ($deckChoice === 'cpu') {
        $diff = (string)($body['cpu_difficulty'] ?? 'easy');
        $hint = trim((string)($body['cpu_group_hint'] ?? '')) ?: null;
        return resolveCpuDeckLists($cards, $diff, $hint);
    }
    if ($deckChoice === 'experiment' || preg_match('/^experiment:[A-Z0-9]+$/i', $deckChoice)) {
        return resolveExperimentDeckLists($body, $cards);
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
    tcgRequireAuthLoader();
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/deck_validate.php';
    require_once __DIR__ . '/booster.php';

    $uid = tcgRequireAuthUser($body);
    $db = tcgDb();
    $stmt = $db->prepare('SELECT name, main_deck, energy_deck FROM tcg_deck_presets WHERE discord_id = ? AND slot = ?');
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
    ];
}

/** Guest-only custom decks from Deck Experiment (full card pool, unranked only). */
function resolveExperimentDeckLists(array $body, array $cardsData): array {
    assertExperimentGuestOnly($body);

    $password = normalizeExperimentPassword((string)($body['experiment_password'] ?? ''));
    if ($password === '' && preg_match('/^experiment:([A-Z0-9]+)$/i', (string)($body['deck'] ?? ''), $m)) {
        $password = normalizeExperimentPassword($m[1]);
    }

    if ($password !== '') {
        return resolveExperimentDeckFromPassword($password, $cardsData);
    }

    $main = $body['main_deck'] ?? null;
    $energy = $body['energy_deck'] ?? null;
    if (!is_array($main) || !is_array($energy)) {
        throw new Exception('Experiment deck requires experiment_password or main_deck and energy_deck');
    }
    $validated = validateExperimentDeckPayload($main, $energy, $cardsData);

    $label = normalizeExperimentDeckName((string)($body['deck_label'] ?? ''));

    return [
        'deck_choice' => 'experiment',
        'deck_label'  => $label,
        'main_nos'    => $validated['main'],
        'energy_nos'  => $validated['energy'],
    ];
}

function createRoom(array $body): array {
    tcgRateLimitForAction('create_room', $body);
    $roomId    = strtoupper(bin2hex(random_bytes(4)));
    $playerToken = generateToken();
    $playerName  = htmlspecialchars($body['name'] ?? 'Player 1', ENT_QUOTES);

    $cards = json_decode(file_get_contents(CARDS_FILE), true);
    $resolved  = resolveRoomDeckLists($body, $cards);

    $mainDeck   = buildDeckForRoom($cards['cards'], $resolved['main_nos'], $body, 'main_order');
    $energyDeck = buildDeckForRoom($cards['cards'], $resolved['energy_nos'], $body, 'energy_order');

    $state = initGameState(
        $roomId,
        ['id' => 'p1', 'token' => $playerToken, 'name' => $playerName,
         'deck_choice' => $resolved['deck_choice'], 'deck_label' => $resolved['deck_label'],
         'main_deck' => $mainDeck, 'energy_deck' => $energyDeck]
    );
    $state['phase_timer_cfg'] = parsePhaseTimerConfigFromBody($body);

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

    $cards = json_decode(file_get_contents(CARDS_FILE), true);
    $resolved  = resolveRoomDeckLists($body, $cards);

    $mainDeck   = buildDeckForRoom($cards['cards'], $resolved['main_nos'], $body, 'main_order');
    $energyDeck = buildDeckForRoom($cards['cards'], $resolved['energy_nos'], $body, 'energy_order');

    $firstPlayer = in_array($body['first_player'] ?? '', ['p1', 'p2'], true)
        ? $body['first_player'] : null;

    $state = addSecondPlayer($state,
        ['id' => 'p2', 'token' => $playerToken, 'name' => $playerName,
         'deck_choice' => $resolved['deck_choice'], 'deck_label' => $resolved['deck_label'],
         'main_deck' => $mainDeck, 'energy_deck' => $energyDeck],
        $firstPlayer
    );

    if (($body['deck'] ?? '') === 'cpu') {
        $cpuDiff = in_array($body['cpu_difficulty'] ?? '', ['easy', 'normal', 'hard'], true)
            ? $body['cpu_difficulty'] : 'easy';
        $state['cpu_difficulty'] = $cpuDiff;
        $state = addLog($state, 'CPU deck: ' . ($resolved['deck_label'] ?? 'Generated'));
    }

    saveGame($roomId, $state);

    return tcgSyncAttachMeta([
        'room_id'      => $roomId,
        'player_token' => $playerToken,
        'player_id'    => 'p2',
        'status'       => 'ready',
        'message'      => 'Joined! Game starting...'
    ], $roomId, $playerToken);
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

function getStatePolling(): void {
    $roomId      = $_GET['room_id'] ?? '';
    $playerToken = $_GET['token']   ?? $_SERVER['HTTP_X_PLAYER_TOKEN'] ?? '';
    tcgRateLimitForAction('get_state', ['room_id' => $roomId, 'token' => $playerToken]);
    $lastSeq     = intval($_GET['seq'] ?? 0);

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
            saveGame($roomId, $state);
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
            saveGame($roomId, $state);
        }
        if ($state['seq'] > $lastSeq) {
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
        saveGame($roomId, $state);
    }
    if ($state) {
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

    return withLock($roomId, function() use ($roomId, $token, $type, $data) {
        $state = loadGame($roomId);
        if (!$state) throw new Exception('Room not found');

        if (applyPhaseTimeouts($state)) {
            saveGame($roomId, $state);
        }
        if (applyCoinFlipStalemate($state)) {
            refreshPvpPhaseTimers($state);
            saveGame($roomId, $state);
        }
        $state = loadGame($roomId);

        if (applyDisconnectForfeits($state, $roomId)) {
            saveGame($roomId, $state);
            maybeApplyRankedFinish($state);
            saveGame($roomId, $state);
            $state = loadGame($roomId);
        }

        $playerId = getPlayerIdByToken($state, $token);
        if (!$playerId) throw new Exception('Invalid player token');

        if (($state['mode'] ?? '') === 'replay_view') {
            throw new Exception('Replay viewer — use replay controls, not live actions');
        }

        $prevStatus = $state['status'] ?? '';
        $state = captureReplayBaselineIfNeeded($state);
        $state = applyAction($state, $playerId, $type, $data);
        $state = appendReplayAction($state, $playerId, $type, $data);
        if (empty($state['pending_prompt'])) {
            $state = flushAutoOnWaitAbilities($state);
        }
        refreshPvpPhaseTimers($state);
        if ($prevStatus !== 'finished' && ($state['status'] ?? '') === 'finished') {
            require_once __DIR__ . '/ranked_room.php';
            tcgOnGameFinished($state);
        }
        saveGame($roomId, $state);

        return ['ok' => true, 'seq' => $state['seq']];
    });
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
    ];
}

function addSecondPlayer(array $state, array $p2, ?string $firstPlayerOverride = null): array {
    $state['players']['p2'] = initPlayerState($p2);
    $state['status']        = 'setup';
    $state['phase']         = 'setup';

    // First player: coin flip winner chooses (see actionChooseFirstPlayer)
    if ($firstPlayerOverride === 'p1' || $firstPlayerOverride === 'p2') {
        $state['first_player']  = $firstPlayerOverride;
        $state['active_player'] = $firstPlayerOverride;
        $state['phase']         = 'setup';
    } else {
        $winner = (rand(0, 1) === 0) ? 'p1' : 'p2';
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

        // ── MISC ────────────────────────────
        case 'resign':
            $state['status'] = 'finished';
            $winner = ($playerId === 'p1') ? 'p2' : 'p1';
            $state['end_reason'] = 'resign';
            $state['resigned_by'] = $playerId;
            $state = addLog($state, $state['players'][$playerId]['name'] . ' resigned. ' .
                            $state['players'][$winner]['name'] . ' wins!');
            $state['winner'] = $winner;
            $state['seq']++;
            return $state;

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
    if (!empty($toReplace)) {
        $kept = [];
        $returned = [];
        foreach ($p['hand'] as $c) {
            if (in_array($c['instance_id'], $toReplace)) {
                $returned[] = $c;
            } else {
                $kept[] = $c;
            }
        }
        [$newCards, $p['main_deck']] = drawCards($p['main_deck'], count($returned));
        $p['main_deck'] = array_merge($p['main_deck'], $returned);
        shuffle($p['main_deck']);
        $p['hand'] = array_merge($kept, $newCards);
    }
    $p['ready_mulligan'] = true;
    $state = addLog($state, $state['players'][$pid]['name'] . ' completed mulligan.');

    // Check if both players are ready
    $bothReady = true;
    foreach (['p1','p2'] as $id) {
        if (!$state['players'][$id]['ready_mulligan']) {
            $bothReady = false;
            break;
        }
    }
    if ($bothReady) {
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
    $batonTransferredEnergyCards = [];
    if ($isOverplay) {
        $existing = $occupant;
        $p['waiting_room'][] = $existing;
        $p['stage'][$targetSlot] = null;
        $anims[] = animSpec($existing['instance_id'], 'stage', 'waiting_room', $pid, [
            'slot' => $targetSlot,
        ]);
        $state = resolveOnLeaveStageAbilities($state, $pid, $existing, []);
        $wrIdx = count($p['waiting_room']) - 1;
        if ($wrIdx >= 0) {
            $p['waiting_room'][$wrIdx] = $existing;
        }
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
        $p['waiting_room'][] = $existing;
        $p['stage'][$targetSlot] = null;
        $anims[] = animSpec($existing['instance_id'], 'stage', 'waiting_room', $pid, [
            'slot' => $targetSlot,
        ]);
        $state = resolveOnLeaveStageAbilities($state, $pid, $existing, ['baton_incoming' => $card]);
        $wrIdx = count($p['waiting_room']) - 1;
        if ($wrIdx >= 0) {
            $p['waiting_room'][$wrIdx] = $existing;
        }
        $card['baton_from_subunit'] = $existing['subunit'] ?? '';
        $card['baton_from_cost'] = getEffectiveStageMemberCost($state, $pid, $existing);
        $card['baton_from_group'] = $existing['group'] ?? '';
        $card['baton_from_no_ability'] = !cardHasAbilities($existing);
        $card['baton_wr_member_id'] = $existing['instance_id'] ?? '';
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
            $p['waiting_room'][] = $existing2;
            $p['stage'][$slot] = null;
            $anims[] = animSpec($existing2['instance_id'], 'stage', 'waiting_room', $pid, [
                'slot' => $slot,
            ]);
            $state = resolveOnLeaveStageAbilities($state, $pid, $existing2, ['baton_incoming' => $card]);
            $wrIdx2 = count($p['waiting_room']) - 1;
            if ($wrIdx2 >= 0) {
                $p['waiting_room'][$wrIdx2] = $existing2;
            }
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

    // Place member in slot
    $card['entered_turn'] = intval($state['turn'] ?? 1);
    $card['entered_from_hand'] = empty($card['entered_via_baton']);
    $p['stage'][$targetSlot] = $card;
    array_splice($p['hand'], $cardIdx, 1);

    $state = addLog($state, $state['players'][$pid]['name'] . ' played ' .
        ($card['name_en'] ?? $card['name']) . ' to ' . $targetSlot . ' area.', 'action', array_merge($anims, [[
            'iid'  => $card['instance_id'],
            'from' => 'hand',
            'to'   => 'stage',
            'pid'  => $pid,
            'slot' => $targetSlot,
            'from_index' => $cardIdx,
        ]]));
    $state = resolveOnEnterAbilities($state, $pid, $card, $targetSlot);
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
        $state['phase'] = 'live_set';
        $state['live_ready'] = ['p1' => false, 'p2' => false];
        $state['active_player'] = $first;
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
        throw new Exception('Not in LIVE Phase');
    }
    ensureLiveSetReadyState($state);
    if (currentLiveSetPlayer($state) !== $pid) {
        throw new Exception('Not your turn');
    }
    if (!empty($state['live_ready'][$pid])) {
        throw new Exception('Already locked in LIVE selection');
    }

    $cardIds = $data['card_ids'] ?? [];
    $p = &$state['players'][$pid];
    $storageMax = 3;
    $penalty = intval($p['live_set_cap_penalty'] ?? 0);
    if ($penalty > 0) {
        $storageMax = max(0, $storageMax - $penalty);
        $p['live_set_cap_penalty'] = 0;
    }
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

    $slotsLeft = $storageMax - liveZoneCount($p['live_zone']);
    if ($slotsLeft <= 0 && !empty($cardIds)) {
        throw new Exception('Live Card storage is full (max ' . $storageMax . ')');
    }
    $cardIds = array_slice($cardIds, 0, $slotsLeft);

    $added = 0;
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
        throw new Exception('Not in LIVE Phase');
    }
    ensureLiveSetReadyState($state);
    if (currentLiveSetPlayer($state) !== $pid) {
        throw new Exception('Not your turn');
    }
    assertNoPendingPromptForPhaseAdvance($state);
    if (!empty($state['live_ready'][$pid])) {
        throw new Exception('Already locked in LIVE selection');
    }

    $name = $state['players'][$pid]['name'];
    $stored = liveZoneCount($state['players'][$pid]['live_zone']);
    $state['live_ready'][$pid] = true;
    $state = addLog(
        $state,
        "$name — locked in LIVE selection ($stored card(s) in storage).",
        'action',
        [],
        ['owner' => $pid, 'msg_public' => "$name — locked in LIVE selection."]
    );

    if (!empty($state['live_ready']['p1']) && !empty($state['live_ready']['p2'])) {
        unset($state['live_ready']);
        $state = beginPerformancePhase($state);
    } else {
        $nextPid = setNextLiveSetPlayer($state);
        if ($nextPid) {
            $state = addLog($state, liveSetPhaseLog($state, $nextPid), 'info');
        }
    }
    refreshPvpPhaseTimers($state);
    $state['seq']++;
    return $state;
}

function revealAllLiveStorage(array $state): array {
    foreach (['p1', 'p2'] as $pid) {
        foreach ($state['players'][$pid]['live_zone'] as &$c) {
            $c['revealed'] = true;
        }
        unset($c);
    }
    return $state;
}

// ─────────────────────────────────────────────
// Performance Phase — Yell, hearts, Live success
// ─────────────────────────────────────────────
// After simultaneous reveal: live_start_effects queue, then live_performance_first/second
// (resolvePerformancePhase per player), live_success_effects prompts, then Live Judge.

function beginPerformancePhase(array $state): array {
    unset(
        $state['yell_reveal'],
        $state['live_perf_success'],
        $state['live_round_success'],
        $state['_yell_reveal_snapshot'],
        $state['_yell_blade_snapshot'],
        $state['_live_perf_snapshot'],
        $state['_live_round_success_snapshot']
    );
    if (liveStorageHasAnyCards($state)) {
        $state = revealAllLiveStorage($state);
        foreach (['p1', 'p2'] as $pid) {
            $lives = array_values(array_filter(
                $state['players'][$pid]['live_zone'] ?? [],
                fn($c) => isLiveTypeCard($c)
            ));
            if (!empty($lives)) {
                $labels = array_map(
                    fn($c) => '"' . ($c['name_en'] ?? $c['name'] ?? 'Live') . '"',
                    $lives
                );
                $state = addLog(
                    $state,
                    ($state['players'][$pid]['name'] ?? $pid) .
                    ' is performing Live with ' . implode(' and ', $labels) . '.',
                    'action'
                );
            }
        }
        $state = addLog($state, 'Both players reveal Live storage simultaneously.');
    }
    if (!performanceRoundHasLiveCards($state)) {
        return skipEmptyPerformanceRound($state);
    }
    $state = addLog($state, '=== Performance Phase ===');
    return beginLiveStartEffectPhase(
        $state,
        playerAttemptingLivePerformance($state, 'p1'),
        playerAttemptingLivePerformance($state, 'p2')
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

/** True when this player placed at least one Live card in Live storage this round. */
function playerAttemptingLivePerformance(array $state, string $pid): bool {
    foreach ($state['players'][$pid]['live_zone'] ?? [] as $c) {
        if (isLiveTypeCard($c)) {
            return true;
        }
    }
    return false;
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
    $leftoverAnims = [];
    foreach (['p1', 'p2'] as $pid) {
        $p = &$state['players'][$pid];
        if (!empty($p['live_zone'])) {
            $remaining = [];
            foreach ($p['live_zone'] as $lc) {
                $state = sBp6ResolveAutoOnLiveWr($state, $pid, $lc);
                if (!empty($state['pending_prompt'])) {
                    $remaining[] = $lc;
                    continue;
                }
                $leftoverAnims = array_merge($leftoverAnims, liveZoneDiscardAnims([$lc], $pid));
                $p['waiting_room'][] = $lc;
            }
            $p['live_zone'] = $remaining;
        }
        unset($p);
    }
    if (!empty($leftoverAnims)) {
        $state = addLog($state, 'Remaining Live storage sent to Waiting Room.', null, $leftoverAnims);
    }
    unset($state['live_attempt'], $state['live_perf_success'], $state['live_round_success']);
    $state = clearYellRevealState($state);

    foreach (['p1', 'p2'] as $pid) {
        if (count($state['players'][$pid]['success_lives']) >= 3) {
            $state['status'] = 'finished';
            $state['winner'] = $pid;
            $state = addLog($state, '🎉 ' . $state['players'][$pid]['name'] . ' WINS with 3 successful Lives!');
            $state['seq']++;
            return $state;
        }
    }

    $state = clearLiveModifiers($state);
    $state['turn']++;
    $state = addLog($state, '=== Turn ' . $state['turn'] . ' begins ===');
    $state = startTurn($state);
    $state['seq']++;
    return $state;
}

function actionConfirmLive(array $state, string $pid, array $data): array {
    // This is used for any confirmation step mid-live (future: special abilities)
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
    unset($state['block_effect_member_activate']);
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
    $state = addLog($state, "$name — Draw Phase.");
    $state = doDrawPhase($state, $pid);
    return $state;
}

function doActivePhase(array $state, string $pid): array {
    $p = &$state['players'][$pid];
    // Active Phase: stand all Energy in storage (spent last turn becomes usable again).
    foreach ($p['energy_zone'] as &$e) {
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
    $drawn = drawMainDeckCards($state, $pid, 1);
    if (!empty($drawn)) {
        $p['hand'] = array_merge($p['hand'], $drawn);
    } else {
        $state = addLog($state, $p['name'] . ' — Draw Phase: could not draw (deck and Waiting Room empty).');
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
        $yellCards = drawMainDeckCards($state, $pid, $drawBlade);
    }
    foreach ($yellCards as &$yc) {
        mergeYellCardCatalogFields($yc);
    }
    unset($yc);
    return [$state, $yellCards, $totalBlade, $drawBlade, $yellReduction];
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
    $state['phase'] = 'live_judge';
    return resolveLiveJudge($state);
}

function continuePerformanceYellPhase(array $state, string $justPlayed): array {
    $first  = $state['first_player'];
    $second = ($first === 'p1') ? 'p2' : 'p1';
    $attempting = $state['live_attempt'] ?? ['p1', 'p2'];

    if ($justPlayed === $first && ($state['phase'] ?? '') === 'live_performance_first') {
        $state['phase'] = 'live_performance_second';
        if (in_array($second, $attempting, true)) {
            return resolvePerformancePhase($state, $second);
        }
        return continuePerformanceYellPhase($state, $second);
    }

    if (!empty($state['_yell_retry_offers'])) {
        return openNextYellRetryPrompt($state);
    }
    return finishYellRetryAndHearts($state);
}

/** Run one player's Performance: filter non-Lives to WR, Yell draw, heart check, success/fail. */
function resolvePerformancePhase(array $state, string $pid, bool $continueAfter = true): array {
    $p = &$state['players'][$pid];
    
    // Reveal live cards
    $liveCards = [];
    $discarded = [];
    $discardAnims = [];
    foreach ($p['live_zone'] as $li => &$c) {
        $c['revealed'] = true;
        if (isLiveTypeCard($c)) {
            $liveCards[] = $c;
        } else {
            $discarded[] = $c;
            $discardAnims[] = animSpec($c['instance_id'], 'live', 'waiting_room', $pid, [
                'from_index' => liveZoneSlotOf($c, $li),
            ]);
        }
    }
    $p['live_zone'] = $liveCards;
    $p['waiting_room'] = array_merge($p['waiting_room'], $discarded);
    if (!empty($discarded)) {
        $state = addLog($state, $state['players'][$pid]['name'] .
            ' — ' . count($discarded) . ' non-Live card(s) from storage sent to Waiting Room.',
            null, $discardAnims);
    }

    if (empty($liveCards)) {
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
        $state['phase'] = 'live_success_effects';
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
    $yellResolvePool = [];
    foreach ($p['stage'] as $member) {
        if (!$member) {
            continue;
        }
        foreach ($member['hearts'] ?? [] as $hGroup) {
            for ($i = 0; $i < ($hGroup['count'] ?? 1); $i++) {
                $yellResolvePool[] = normalizeHeartColor((string)($hGroup['color'] ?? 'any'));
            }
        }
        foreach ($member['bonus_hearts'] ?? [] as $color) {
            $yellResolvePool[] = normalizeHeartColor((string)$color);
        }
        foreach (hsPb1ApplyContinuousPurpleHeart($member, $state, $pid) as $color) {
            $yellResolvePool[] = normalizeHeartColor((string)$color);
        }
    }
    foreach ($yellCards as $yc) {
        $bh = $yc['blade_hearts'] ?? [];
        foreach ($bh as $bh_item) {
            if (is_string($bh_item)) {
                if ($bh_item === 'draw' || $bh_item === 'score') {
                    continue;
                }
                $yellHearts = array_merge($yellHearts, getHeartIconsFromBladeHeart($bh_item, $yellResolvePool, $liveCards));
                continue;
            }
            $bhType = $bh_item['type'] ?? '';
            if ($bhType === 'draw' || $bhType === 'score') {
                continue;
            }
            $yellHearts = array_merge($yellHearts, getHeartIconsFromBladeHeart($bh_item, $yellResolvePool, $liveCards));
        }
    }

    $yellWildcard = liveCardsGrantYellHeartsWildcard($liveCards);
    $drawPerYellHeart = false;
    $drawPerYellCard = false;
    foreach ($liveCards as $lc) {
        foreach ($lc['abilities'] ?? [] as $ab) {
            if (($ab['trigger'] ?? '') !== 'continuous') continue;
            if (($ab['type'] ?? '') === 'draw_per_yell_heart') $drawPerYellHeart = true;
            if (($ab['type'] ?? '') === 'draw_per_yell_card') $drawPerYellCard = true;
        }
    }
    $yellDrawIcons = countYellDrawIcons($yellCards);
    $drawBonus += $yellDrawIcons;
    $state['_last_yell_score_icons'] = countYellScoreIcons($yellCards);
    if ($yellWildcard) {
        $yellHearts = array_fill(0, count($yellHearts), 'any');
    }
    $yellHasPrintedHearts = false;
    foreach ($yellCards as $yc) {
        if (cardHasPrintedHearts($yc)) {
            $yellHasPrintedHearts = true;
            break;
        }
    }
    if ($yellHasPrintedHearts) {
        foreach ($liveCards as &$lc) {
            foreach ($lc['abilities'] ?? [] as $ab) {
                if (($ab['trigger'] ?? '') !== 'continuous') continue;
                if (($ab['type'] ?? '') !== 'live_score_if_yell_has_hearts') continue;
                $lc['score'] = intval($lc['score'] ?? 0) + intval($ab['amount'] ?? 1);
            }
        }
        unset($lc);
    }
    if ($drawPerYellHeart && count($yellHearts) > 0) {
        $drawBonus += count($yellHearts);
    }
    if ($drawPerYellCard) {
        $drawBonus += count($yellCards);
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
            $treatAs = $member['hearts_treat_as'] ?? null;
            foreach ($member['hearts'] ?? [] as $hGroup) {
                $color = $treatAs ?: normalizeHeartColor((string)($hGroup['color'] ?? 'any'));
                for ($i = 0; $i < ($hGroup['count'] ?? 1); $i++) {
                    $ownedHearts[] = normalizeHeartColor((string)$color);
                }
            }
            foreach ($member['bonus_hearts'] ?? [] as $color) {
                $ownedHearts[] = normalizeHeartColor($treatAs ?: (string)$color);
            }
            foreach (hsPb1ApplyContinuousPurpleHeart($member, $state, $pid) as $color) {
                $ownedHearts[] = normalizeHeartColor((string)$color);
            }
        }
    }
    $ownedHearts = array_merge($ownedHearts, $yellHearts);
    $ownedHearts = array_merge($ownedHearts, getBonusHeartsFlat($state, $pid));
    $ownedHearts = array_merge($ownedHearts, getContinuousPerformanceHearts($state, $pid));

    // Each Live card must be paid from one shared heart pool (including Lives that
    // cannot go to Success Live). Hearts consumed by earlier Lives in zone order.
    $successCards = [];
    $failCards    = [];
    $failAnims    = [];
    $remaining    = $ownedHearts;

    foreach ($liveCards as $li => $lc) {
        $required = $lc['required_hearts'] ?? [];
        $required = plMuseGapApplySuccessLivePassiveReductions($state, $pid, $lc);
        $required = applyLiveHeartReductions($required, $lc);
        [$ok, $newRemaining] = checkHearts($remaining, $required);
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

    $p['waiting_room'] = array_merge($p['waiting_room'], $failCards);
    if ($liveRoundSuccess) {
        $p['live_zone'] = $successCards;
    } else {
        if (!empty($successCards)) {
            foreach ($successCards as $li => $lc) {
                $failAnims[] = animSpec($lc['instance_id'], 'live', 'waiting_room', $pid, [
                    'from_index' => liveZoneSlotOf($lc, $li),
                ]);
            }
            $p['waiting_room'] = array_merge($p['waiting_room'], $successCards);
        }
        $p['live_zone'] = [];
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
        $state['phase'] = 'live_judge';
        $state = resolveLiveJudge($state);
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
    if (!playerAttemptedLiveRound($state, $pid)) {
        return false;
    }
    return !empty($state['players'][$pid]['live_zone']);
}

// ─────────────────────────────────────────────
// Live Win/Loss Check (live_judge)
// ─────────────────────────────────────────────
// Compare per-player Live success; tie-break on total Live score. Winners may pick
// which successful Live enters Success Live area (pick_judge_success_live prompt).

function resolveLiveJudge(array $state): array {
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
                : array_sum(array_column($zone, 'score')) + getLiveScoreBonus($state, $pid);
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

function liveJudgePlaceSuccessLive(array $state, string $winnerId, array $toAdd): array {
    $zone = &$state['players'][$winnerId]['live_zone'];
    $fromIdx = liveZoneSlotOf($toAdd, 0);
    $removed = liveJudgeRemoveFromZone($zone, $toAdd['instance_id'] ?? '');
    if (!$removed) {
        throw new Exception('Live card no longer in storage');
    }
    $toAdd = $removed;
    $successIdx = count($state['players'][$winnerId]['success_lives']);
    $state['players'][$winnerId]['success_lives'][] = $toAdd;
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

function finalizeLiveJudge(array $state, array $ctx): array {
    $attempting = $state['live_attempt'] ?? ['p1', 'p2'];
    $successPlacedBy = $ctx['success_placed_by'] ?? [];

    $leftoverAnims = [];
    foreach (['p1', 'p2'] as $pid) {
        $p = &$state['players'][$pid];
        if (!empty($p['live_zone'])) {
            $remaining = [];
            foreach ($p['live_zone'] as $lc) {
                $state = sBp6ResolveAutoOnLiveWr($state, $pid, $lc);
                if (!empty($state['pending_prompt'])) {
                    $remaining[] = $lc;
                    continue;
                }
                $leftoverAnims = array_merge($leftoverAnims, liveZoneDiscardAnims([$lc], $pid));
                $p['waiting_room'][] = $lc;
            }
            $p['live_zone'] = $remaining;
        }
        unset($p);
    }
    if (!empty($leftoverAnims)) {
        $state = addLog($state, 'Remaining Live storage sent to Waiting Room.', null, $leftoverAnims);
    }
    $state['_live_perf_snapshot'] = $state['live_perf_success'] ?? ['p1' => [], 'p2' => []];
    $state['_live_round_success_snapshot'] = $state['live_round_success'] ?? [];
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

    foreach (['p1', 'p2'] as $pid) {
        if (count($state['players'][$pid]['success_lives']) >= 3) {
            $state['status'] = 'finished';
            $state['winner'] = $pid;
            $state = addLog($state, '🎉 ' . $state['players'][$pid]['name'] . ' WINS with 3 successful Lives!');
            $state['seq']++;
            return $state;
        }
    }

    if (count($successPlacedBy) === 1) {
        $state['first_player'] = $successPlacedBy[0];
    }

    $state = clearLiveModifiers($state);

    $state['turn']++;
    $state = addLog($state, '=== Turn ' . $state['turn'] . ' begins ===');
    $state = startTurn($state);

    $state['seq']++;
    return $state;
}

// ─────────────────────────────────────────────
// Heart Resolution
// ─────────────────────────────────────────────
function isWildcardHeartColor(string $color): bool {
    return in_array($color, ['any', 'wild', 'gray', 'all'], true);
}

function normalizeHeartColor(string $color): string {
    return isWildcardHeartColor($color) ? 'any' : $color;
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

function checkHearts(array $available, array $required): array {
    $available = array_map(fn($h) => normalizeHeartColor((string)$h), $available);
    $pool = $available;
    $wilds = array_filter($pool, fn($h) => isWildcardHeartColor((string)$h));
    $nonWild = array_filter($pool, fn($h) => !isWildcardHeartColor((string)$h));
    $pool = array_values($nonWild);
    $wildCount = count($wilds);

    foreach (sortHeartRequirements($required) as $req) {
        $color = $req['color'];
        $need  = $req['count'] ?? 1;

        if (isWildcardHeartColor($color)) {
            // Any color hearts
            $toRemove = min($need, count($pool));
            array_splice($pool, 0, $toRemove);
            $need -= $toRemove;
            if ($need > 0) {
                if ($wildCount >= $need) { $wildCount -= $need; $need = 0; }
                else return [false, $available];
            }
        } else {
            // Specific color
            $found = array_keys(array_filter($pool, fn($h) => $h === $color));
            $canFill = count($found);
            if ($canFill >= $need) {
                $toRemove = array_slice($found, 0, $need);
                foreach (array_reverse($toRemove) as $idx) {
                    array_splice($pool, $idx, 1);
                }
            } else {
                // Try wilds for remainder
                $needWild = $need - $canFill;
                if ($wildCount >= $needWild) {
                    foreach (array_reverse($found) as $idx) {
                        array_splice($pool, $idx, 1);
                    }
                    $wildCount -= $needWild;
                } else {
                    return [false, $available];
                }
            }
        }
    }

    // Rebuild remaining
    $remaining = array_values($pool);
    for ($i = 0; $i < $wildCount; $i++) $remaining[] = 'any';
    return [true, $remaining];
}

function firstMissingColoredHeartForRequirements(array $pool, array $required): ?string {
    $norm = array_map(fn($h) => normalizeHeartColor((string)$h), $pool);
    $specifics = array_values(array_filter($norm, fn($h) => $h !== 'any'));
    $wildCount = count($norm) - count($specifics);

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
            } elseif ($wildCount > 0) {
                $wildCount--;
            } else {
                return $color;
            }
        }
    }
    return null;
}

/** Pick a color for an ALL blade heart: missing live colors first, then any. */
function resolveAllBladeHeartColor(array $pool, array $liveCards): string {
    foreach ($liveCards as $lc) {
        $required = applyLiveHeartReductions($lc['required_hearts'] ?? [], $lc);
        $missing = firstMissingColoredHeartForRequirements($pool, $required);
        if ($missing !== null) {
            return $missing;
        }
    }
    return 'any';
}

function getHeartIconsFromBladeHeart(string|array $bh, ?array &$resolvePool = null, ?array $liveCards = null): array {
    // Blade hearts may be plain color strings ("red") or objects ({type: "red"} / {type: "draw"})
    $type = is_string($bh) ? $bh : ($bh['type'] ?? $bh['color'] ?? '');
    if ($type === 'draw') return [];
    if ($type === 'all' && $resolvePool !== null && $liveCards !== null) {
        $color = resolveAllBladeHeartColor($resolvePool, $liveCards);
        $resolvePool[] = normalizeHeartColor($color);
        return [$color];
    }
    $heartsMap = [
        'pink'   => 'pink',   'red'    => 'red',
        'yellow' => 'yellow', 'green'  => 'green',
        'blue'   => 'blue',   'purple' => 'purple',
        'any'    => 'any',    'gray'   => 'any', 'wild' => 'any', 'all' => 'any',
    ];
    if (isset($heartsMap[$type])) return [$heartsMap[$type]];
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

/** Block new plays/activations while this player must answer a skill prompt. */
function assertNoPendingPromptForPlayerAction(array $state, string $pid): void {
    $prompt = $state['pending_prompt'] ?? null;
    if (!$prompt) {
        return;
    }
    if (($prompt['responder'] ?? '') === $pid) {
        throw new Exception('Resolve the pending skill prompt before taking another action.');
    }
}

function isCpuPlayer(?array $player): bool {
    if (!$player) {
        return false;
    }
    $name = (string)($player['name'] ?? '');
    return str_contains($name, 'CPU') || str_contains($name, '🤖');
}

function isPvpMatch(array $state): bool {
    $st = $state['status'] ?? '';
    if (in_array($st, ['waiting', 'ready', 'finished'], true)) {
        return false;
    }
    $p1 = $state['players']['p1'] ?? null;
    $p2 = $state['players']['p2'] ?? null;
    if (!$p1 || !$p2) {
        return false;
    }
    return !isCpuPlayer($p1) && !isCpuPlayer($p2);
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

function initPhaseTimer(array &$state): void {
    $cfg = getPhaseTimerCfg($state);
    if (!isset($state['phase_timer']) || !is_array($state['phase_timer'])) {
        $state['phase_timer'] = [
            'enabled' => $cfg['enabled'],
            'duration' => $cfg['duration'],
            'deadlines' => ['p1' => null, 'p2' => null],
        ];
    } else {
        $state['phase_timer']['enabled'] = $cfg['enabled'];
        $state['phase_timer']['duration'] = $cfg['duration'];
    }
}

function setPhaseDeadline(array &$state, string $pid): void {
    if (!in_array($pid, ['p1', 'p2'], true)) {
        return;
    }
    initPhaseTimer($state);
    $state['phase_timer']['deadlines'][$pid] = time() + getPhaseTimerDuration($state);
}

function clearPhaseDeadline(array &$state, string $pid): void {
    if (!isset($state['phase_timer']['deadlines'])) {
        return;
    }
    if (in_array($pid, ['p1', 'p2'], true)) {
        $state['phase_timer']['deadlines'][$pid] = null;
    }
}

function clearAllPhaseDeadlines(array &$state): void {
    if (!isset($state['phase_timer'])) {
        return;
    }
    $state['phase_timer']['deadlines'] = ['p1' => null, 'p2' => null];
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

/** Auto end main / live when PvP phase timers expire. Returns true if state changed. */
function applyPhaseTimeouts(array &$state): bool {
    if (!phaseTimerEnabled($state)) {
        return false;
    }
    initPhaseTimer($state);
    $now = time();
    $changed = false;

    for ($pass = 0; $pass < 6; $pass++) {
        $ph = $state['phase'] ?? '';
        $did = false;

        $prompt = $state['pending_prompt'] ?? null;
        if ($prompt) {
            $responder = $prompt['responder'] ?? '';
            if (in_array($responder, ['p1', 'p2'], true) && playerUsesPhaseTimer($state, $responder)) {
                $dl = $state['phase_timer']['deadlines'][$responder] ?? null;
                if ($dl && $now >= $dl) {
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

    return $changed;
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

    // Hide opponent's hand in human vs human; keep visible for solo CPU (client AI)
    if ($oppId && isset($filtered['players'][$oppId])) {
        $opp = $filtered['players'][$oppId];
        $cpuOpponent = isCpuPlayer($opp);
        $filtered['players'][$oppId]['hand_count'] = count($opp['hand']);
        if (!$cpuOpponent) {
            $filtered['players'][$oppId]['hand']  = [];
        } else {
            $filtered['cpu_solo'] = true;
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

    // Hide own token too (client stores it already)
    if ($myId) {
        $filtered['players'][$myId]['token'] = '';
    }

    $filtered['my_id'] = $myId;
    $filtered['pvp'] = isPvpMatch($state);
    $filtered['mode'] = $state['mode'] ?? null;
    $filtered['phase_timer_cfg'] = getPhaseTimerCfg($state);

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
        $mineStageHearts = aggregateStageHeartsByColor($state['players'][$myId]['stage'] ?? []);
        $oppStageHearts = aggregateStageHeartsByColor($state['players'][$oppId]['stage'] ?? []);
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
        if ($exposePerfCarryover && !empty($state['_yell_blade_snapshot'])) {
            $yellBladeMine = intval($state['_yell_blade_snapshot'][$myId] ?? $yellBladeMine);
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
                'live_score_bonus' => getLiveScoreBonus($state, $myId),
                'active_effects' => collectActiveContinuousEffects($state, $myId),
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
                'live_score_bonus' => getLiveScoreBonus($state, $oppId),
                'active_effects' => collectActiveContinuousEffects($state, $oppId),
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

    if (!empty($state['yell_reveal']) && isInPerformancePhase($state)) {
        $filtered['yell_reveal'] = $state['yell_reveal'];
    } elseif ($exposePerfCarryover && !empty($state['_yell_reveal_snapshot'])) {
        // Keep last round's Yell draws visible until the next Performance (PvP may
        // resolve judge + startTurn before either client polls for spectacle).
        $filtered['yell_reveal'] = $state['_yell_reveal_snapshot'];
    }

    if (!empty($state['live_perf_success'])) {
        $filtered['live_perf_success'] = $state['live_perf_success'];
    }

    if (!empty($state['live_round_success'])) {
        $filtered['live_round_success'] = $state['live_round_success'];
    }

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
// Persistence (File-based)
// ─────────────────────────────────────────────
function gameFile(string $roomId): string {
    return GAMES_DIR . preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId)) . '.json';
}

function loadGame(string $roomId): ?array {
    $file = gameFile($roomId);
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return $data ?: null;
}

function saveGame(string $roomId, array $state): void {
    file_put_contents(gameFile($roomId), json_encode($state), LOCK_EX);
    if (isPvpMatch($state)) {
        tcgSyncNotify($roomId, intval($state['seq'] ?? 0), isset($state['phase']) ? (string)$state['phase'] : null);
    }
}

function withLock(string $roomId, callable $fn): mixed {
    $lockFile = GAMES_DIR . 'lock_' . preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId));
    $lock = fopen($lockFile, 'w');
    if (!$lock) throw new Exception('Cannot acquire lock');
    $deadline = microtime(true) + LOCK_TIMEOUT;
    while (!flock($lock, LOCK_EX | LOCK_NB)) {
        if (microtime(true) > $deadline) {
            fclose($lock);
            throw new Exception('Lock timeout');
        }
        usleep(50000);
    }
    try {
        return $fn();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
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
        $gone = false;
        if ($last > 0 && ($now - $last) >= PRESENCE_DISCONNECT_SEC) {
            $gone = true;
        } elseif ($last === 0 && $gameAge >= $noShowSec) {
            $other = ($pid === 'p1') ? 'p2' : 'p1';
            $otherPlayer = $state['players'][$other] ?? null;
            $otherToken = $otherPlayer['token'] ?? '';
            $otherLast = intval($presence[$otherToken] ?? 0);
            if ($otherLast > 0 && ($now - $otherLast) < 60) {
                $gone = true;
            }
        }
        if (!$gone) {
            continue;
        }

        $winner = ($pid === 'p1') ? 'p2' : 'p1';
        $loserName = $player['name'] ?? $pid;
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
