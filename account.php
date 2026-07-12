<?php
/**
 * Love Live TCG — Account API (Discord auth, collection, boosters, decks, ranked queue).
 *
 * SQLite-backed player profiles via db.php. Deck presets require llr_auth_load.php.
 * Ranked/casual matchmaking delegates to matchmaking.php / casual_matchmaking.php;
 * active games use api.php room JSON under tcg/games/.
 *
 * Endpoints (action=):
 *   me, pick_starter, collection, booster_boxes, booster_rates, daily_status, open_booster,
 *   deck_list, deck_save, deck_delete, deck_equip, deck_equip_starter, deck_reset_starter, deck_auto_build, reset_account,
 *   ranked_join, ranked_leave, ranked_status, rank_stats, rank_banner_set, rank_flag_set, stamp_favorites_set, active_game, leave_active_game,
 *   replay_save, replay_list, replay_get, replay_start, missions_list, missions_claim, public_profile,
 *   public_leaderboard
 */
require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/config/errors.php';
require_once __DIR__ . '/config/rate_limit.php';
require_once __DIR__ . '/flags.php';
tcgDefinePathConstants();

header('Content-Type: application/json');
tcgSendCorsHeaders();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    tcgSendCorsPreflight('GET, POST, OPTIONS', 'Content-Type, X-Auth-Token, Authorization');
    http_response_code(200);
    exit;
}

define('TCG_MAX_DECK_PRESETS', 10);

require_once __DIR__ . '/llr_auth_load.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booster.php';
require_once __DIR__ . '/stamps.php';
require_once __DIR__ . '/deck_validate.php';
require_once __DIR__ . '/matchmaking.php';
require_once __DIR__ . '/deckgen.php';
require_once __DIR__ . '/missions.php';
if (!defined('TCG_API_LIB_ONLY')) {
    define('TCG_API_LIB_ONLY', true);
}
require_once __DIR__ . '/api.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch ($action) {
        case 'me':                 echo json_encode(tcgApiMe($body)); break;
        case 'pick_starter':       echo json_encode(tcgApiPickStarter($body)); break;
        case 'collection':         echo json_encode(tcgApiCollection($body)); break;
        case 'booster_boxes':      echo json_encode(tcgApiBoosterBoxes()); break;
        case 'booster_rates':      echo json_encode(tcgApiBoosterRates($_GET + $body)); break;
        case 'daily_status':       echo json_encode(tcgApiDailyStatus($body)); break;
        case 'open_booster':       echo json_encode(tcgApiOpenBooster($body)); break;
        case 'deck_list':          echo json_encode(tcgApiDeckList($body)); break;
        case 'deck_save':          echo json_encode(tcgApiDeckSave($body)); break;
        case 'deck_delete':        echo json_encode(tcgApiDeckDelete($body)); break;
        case 'deck_equip':         echo json_encode(tcgApiDeckEquip($body)); break;
        case 'deck_equip_starter': echo json_encode(tcgApiDeckEquipStarter($body)); break;
        case 'deck_reset_starter': echo json_encode(tcgApiDeckResetStarter($body)); break;
        case 'deck_auto_build':    echo json_encode(tcgApiDeckAutoBuild($body)); break;
        case 'reset_account':      echo json_encode(tcgApiResetAccount($body)); break;
        case 'ranked_join':        echo json_encode(tcgApiRankedJoin($body)); break;
        case 'ranked_leave':       echo json_encode(tcgApiRankedLeave($body)); break;
        case 'ranked_status':      echo json_encode(tcgApiRankedStatus($body)); break;
        case 'rank_stats':         echo json_encode(tcgApiRankStats($body)); break;
        case 'rank_banner_set':    echo json_encode(tcgApiRankBannerSet($body)); break;
        case 'rank_flag_set':      echo json_encode(tcgApiRankFlagSet($body)); break;
        case 'stamp_favorites_set': echo json_encode(tcgApiStampFavoritesSet($body)); break;
        case 'active_game':        echo json_encode(tcgApiActiveGame($body)); break;
        case 'leave_active_game':  echo json_encode(tcgApiLeaveActiveGame($body)); break;
        case 'replay_save':        echo json_encode(tcgApiReplaySave($body)); break;
        case 'replay_list':        echo json_encode(tcgApiReplayList($body)); break;
        case 'replay_get':         echo json_encode(tcgApiReplayGet($body)); break;
        case 'replay_start':       echo json_encode(tcgApiReplayStartSaved($body)); break;
        case 'replay_preserve':    echo json_encode(tcgApiReplayPreserve($body)); break;
        case 'missions_list':      echo json_encode(tcgApiMissionsList($body)); break;
        case 'missions_claim':     echo json_encode(tcgApiMissionsClaim($body)); break;
        case 'public_leaderboard': echo json_encode(tcgApiPublicLeaderboard($_GET + $body)); break;
        case 'public_profile':     echo json_encode(tcgApiPublicProfile($_GET + $body)); break;
        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    $code = intval($e->getCode());
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => tcgPublicErrorMessage($e, $code)]);
}

function tcgLoadCardsData(): array {
    if (!file_exists(TCG_CARDS_FILE)) {
        return ['cards' => [], 'starter_decks' => []];
    }
    return json_decode(file_get_contents(TCG_CARDS_FILE), true) ?: ['cards' => [], 'starter_decks' => []];
}

function tcgApiMe(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $profile = tcgAuthUserProfile($uid);
    $user = tcgEnsureUser($uid, $profile);
    $cards = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cards);
    $migration = tcgMigrateDuplicateToStarGems($uid, $cardMap);
    $daily = tcgDailyOpenAllowance($uid);
    require_once __DIR__ . '/ranked_pr_rewards.php';
    $rankedPr = tcgRankedPrDailyAllowance($uid);
    $rank = tcgRankRow($uid);
    $equipped = tcgGetEquippedDeckRow($uid);
    $equippedLoadout = null;
    if ($equipped) {
        $equippedLoadout = (($equipped['source'] ?? '') === 'starter') ? 'starter' : 'preset';
    }
    return [
        'success' => true,
        'user' => [
            'id' => $uid,
            'username' => $user['username'] ?? $profile['username'],
            'avatar_url' => $user['avatar_url'] ?? $profile['avatar_url'],
            'starter_deck' => $user['starter_deck'] ?? null,
            'starter_deck_label' => !empty($user['starter_deck']) ? tcgStarterLabel($user['starter_deck']) : null,
            'needs_starter' => empty($user['starter_deck']),
        ],
        'daily' => $daily,
        'ranked_pr' => $rankedPr,
        'star_gems' => tcgGetStarGems($uid),
        'star_gems_pack_cost' => TCG_STAR_GEMS_PACK_COST,
        'star_gems_box_cost' => TCG_STAR_GEMS_BOX_COST,
        'star_gems_per_dupe' => TCG_STAR_GEMS_PER_DUPE,
        'dupe_migration' => $migration,
        'rank' => tcgFormatRankSummary($rank),
        'banner' => tcgFormatUserBanner($user, $cards),
        'equipped_flag' => tcgFormatEquippedFlag($user['equipped_flag'] ?? null),
        'stamp_favorites' => tcgFormatStampFavorites($user['stamp_favorites'] ?? null),
        'equipped_deck_slot' => ($equippedLoadout === 'preset') ? intval($equipped['slot']) : null,
        'equipped_deck_name' => $equipped ? tcgNormalizeDeckPresetName($equipped['name'] ?? '') : null,
        'equipped_loadout' => $equippedLoadout,
        'starter_options' => tcgStarterDecks(),
        'missions' => tcgMissionSummaryForUser($uid),
    ];
}

function tcgApiPickStarter(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $profile = tcgAuthUserProfile($uid);
    $user = tcgEnsureUser($uid, $profile);
    if (!empty($user['starter_deck'])) {
        throw new Exception('Starter deck already chosen');
    }
    $starter = trim((string)($body['starter'] ?? ''));
    $cards = tcgLoadCardsData();
    $result = tcgGrantStarterDeck($uid, $starter, $cards);
    return ['success' => true, 'starter' => $result];
}

function tcgApiCollection(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $db = tcgDb();
    $stmt = $db->prepare('SELECT card_no, qty, acquired_at FROM tcg_collection WHERE discord_id = ? ORDER BY card_no');
    $stmt->execute([$uid]);
    $cards = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cards);
    $list = [];
    $totalCards = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $qty = intval($row['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $totalCards += $qty;
        $list[] = [
            'card_no' => $row['card_no'],
            'qty' => $qty,
            'acquired_at' => intval($row['acquired_at'] ?? 0),
            'card' => $cardMap[$row['card_no']] ?? null,
        ];
    }
    return [
        'success' => true,
        'total_unique' => count($list),
        'total_cards' => $totalCards,
        'collection' => $list,
    ];
}

function tcgApiBoosterBoxes(): array {
    return ['success' => true, 'boxes' => tcgBoosterBoxes()];
}

function tcgApiBoosterRates(array $params): array {
    $boxId = trim((string)($params['box_id'] ?? ''));
    if ($boxId === '') {
        throw new Exception('box_id required', 400);
    }
    $box = tcgBoosterBoxById($boxId);
    if (!$box) {
        throw new Exception('Unknown booster box', 404);
    }
    $cards = tcgLoadCardsData();
    return ['success' => true, 'rates' => tcgComputeBoosterPackRates($box, $cards)];
}

function tcgApiDailyStatus(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    require_once __DIR__ . '/ranked_pr_rewards.php';
    return [
        'success' => true,
        'daily' => tcgDailyOpenAllowance($uid),
        'ranked_pr' => tcgRankedPrDailyAllowance($uid),
        'star_gems' => tcgGetStarGems($uid),
        'star_gems_pack_cost' => TCG_STAR_GEMS_PACK_COST,
        'star_gems_box_cost' => TCG_STAR_GEMS_BOX_COST,
    ];
}

function tcgApiOpenBooster(array $body): array {
    tcgRateLimitForAction('open_booster', $body);
    $uid = tcgRequireAuthUser($body);
    $user = tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    if (empty($user['starter_deck'])) {
        throw new Exception('Choose a starter deck first');
    }
    $boxId = trim((string)($body['box_id'] ?? ''));
    $payment = trim(strtolower((string)($body['payment'] ?? 'daily')));
    $cards = tcgLoadCardsData();
    $result = tcgOpenBoosterPack($uid, $boxId, $cards, $payment);
    $completions = [];
    if ($payment === 'daily') {
        $completions = tcgMissionOnDailyBoostersExhausted($uid);
    }
    $completions = tcgMissionMergeCompletions($completions, tcgMissionCheckCollectionThresholds($uid));
    return tcgMissionAttachCompletions(['success' => true, 'open' => $result], $completions);
}

function tcgGetEquippedDeck(string $uid): ?array {
    return tcgGetEquippedDeckRow($uid);
}

function tcgFormatEquippedLoadout(array $body): array {
    $equipped = tcgGetEquippedDeckRow(tcgRequireAuthUser($body));
    if (!$equipped) {
        return [
            'equipped_deck_slot' => null,
            'equipped_deck_name' => null,
            'equipped_loadout' => null,
        ];
    }
    $loadout = (($equipped['source'] ?? '') === 'starter') ? 'starter' : 'preset';
    return [
        'equipped_deck_slot' => ($loadout === 'preset') ? intval($equipped['slot']) : null,
        'equipped_deck_name' => tcgNormalizeDeckPresetName($equipped['name'] ?? ''),
        'equipped_loadout' => $loadout,
    ];
}

function tcgApiDeckList(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $user = tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $db = tcgDb();
    $stmt = $db->prepare('SELECT id, slot, name, main_deck, energy_deck, equipped, updated_at
        FROM tcg_deck_presets WHERE discord_id = ? ORDER BY slot ASC');
    $stmt->execute([$uid]);
    $decks = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $main = json_decode($row['main_deck'], true) ?: [];
        $energy = json_decode($row['energy_deck'], true) ?: [];
        $decks[] = [
            'id' => intval($row['id']),
            'slot' => intval($row['slot']),
            'name' => tcgNormalizeDeckPresetName($row['name']),
            'main_deck' => $main,
            'energy_deck' => $energy,
            'equipped' => intval($row['equipped']) === 1,
            'updated_at' => intval($row['updated_at']),
            'main_count' => count($main),
            'energy_count' => count($energy),
        ];
    }
    if (empty($decks) && !empty($user['starter_deck'])) {
        $cards = tcgLoadCardsData();
        tcgSaveStarterPreset($uid, $user['starter_deck'], $cards, 1, true);
        return tcgApiDeckList($body);
    }
    if (!empty($user['starter_deck'])) {
        $cards = tcgLoadCardsData();
        if (tcgEnsureStarterPresetSlot1($uid, $user['starter_deck'], $cards)) {
            return tcgApiDeckList($body);
        }
    }
    return ['success' => true, 'decks' => $decks, 'max_slots' => TCG_MAX_DECK_PRESETS];
}

function tcgApiDeckSave(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $slot = intval($body['slot'] ?? 0);
    if ($slot < 1 || $slot > TCG_MAX_DECK_PRESETS) {
        throw new Exception('Deck slot must be 1–' . TCG_MAX_DECK_PRESETS);
    }
    $name = trim((string)($body['name'] ?? 'My Deck'));
    if ($name === '') {
        $name = 'My Deck';
    }
    $main = $body['main_deck'] ?? [];
    $energy = $body['energy_deck'] ?? [];
    if (!is_array($main) || !is_array($energy)) {
        throw new Exception('Invalid deck payload');
    }
    $cards = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cards);
    $owned = tcgGetCollectionMap($uid);
    $validation = tcgValidateDeckLists($main, $energy, $cardMap, $owned);
    if (!$validation['valid']) {
        throw new Exception(implode('; ', $validation['errors']));
    }
    $db = tcgDb();
    $now = time();
    $db->prepare('INSERT INTO tcg_deck_presets (discord_id, slot, name, main_deck, energy_deck, equipped, updated_at)
        VALUES (?, ?, ?, ?, ?, 0, ?)
        ON CONFLICT(discord_id, slot) DO UPDATE SET
            name = excluded.name,
            main_deck = excluded.main_deck,
            energy_deck = excluded.energy_deck,
            updated_at = excluded.updated_at')
        ->execute([
            $uid, $slot, $name,
            json_encode(array_values($main)),
            json_encode(array_values($energy)),
            $now,
        ]);
    return ['success' => true, 'slot' => $slot, 'name' => $name, 'validation' => $validation];
}

function tcgApiDeckDelete(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $slot = intval($body['slot'] ?? 0);
    if ($slot < 1 || $slot > TCG_MAX_DECK_PRESETS) {
        throw new Exception('Invalid deck slot');
    }
    tcgDb()->prepare('DELETE FROM tcg_deck_presets WHERE discord_id = ? AND slot = ?')
        ->execute([$uid, $slot]);
    return ['success' => true, 'deleted_slot' => $slot];
}

function tcgApiDeckEquip(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $slot = intval($body['slot'] ?? 0);
    $db = tcgDb();
    $stmt = $db->prepare('SELECT slot FROM tcg_deck_presets WHERE discord_id = ? AND slot = ?');
    $stmt->execute([$uid, $slot]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('Deck not found');
    }
    $db->prepare('UPDATE tcg_deck_presets SET equipped = 0 WHERE discord_id = ?')->execute([$uid]);
    $db->prepare('UPDATE tcg_deck_presets SET equipped = 1 WHERE discord_id = ? AND slot = ?')
        ->execute([$uid, $slot]);
    tcgClearRankedStarterEquip($uid);
    $equipped = tcgGetEquippedDeckRow($uid);
    return array_merge(
        ['success' => true, 'equipped_slot' => $slot],
        tcgFormatEquippedLoadout($body)
    );
}

function tcgApiDeckEquipStarter(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $user = tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    if (empty($user['starter_deck'])) {
        throw new Exception('No starter deck on this account');
    }
    $cards = tcgLoadCardsData();
    $lists = tcgGetStarterDeckLists($user['starter_deck'], $cards);
    $validation = tcgValidateDeckLists(
        $lists['main_deck'],
        $lists['energy_deck'],
        tcgBuildCardMap($cards),
        tcgGetCollectionMap($uid)
    );
    if (!$validation['valid']) {
        throw new Exception('Starter deck is invalid: ' . implode('; ', $validation['errors']));
    }
    tcgSetRankedStarterEquip($uid);
    return array_merge(['success' => true], tcgFormatEquippedLoadout($body));
}

function tcgApiDeckResetStarter(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $user = tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    if (empty($user['starter_deck'])) {
        throw new Exception('No starter deck on this account');
    }
    $slot = intval($body['slot'] ?? 1);
    if ($slot < 1 || $slot > TCG_MAX_DECK_PRESETS) {
        throw new Exception('Deck slot must be 1–' . TCG_MAX_DECK_PRESETS);
    }
    $cards = tcgLoadCardsData();
    $lists = tcgGetStarterDeckLists($user['starter_deck'], $cards);
    $cardMap = tcgBuildCardMap($cards);
    $owned = tcgGetCollectionMap($uid);
    $validation = tcgValidateDeckLists($lists['main_deck'], $lists['energy_deck'], $cardMap, $owned);
    if (!$validation['valid']) {
        throw new Exception(implode('; ', $validation['errors']));
    }
    tcgWriteDeckPreset($uid, $slot, $lists['name'], $lists['main_deck'], $lists['energy_deck'], null);
    return [
        'success' => true,
        'slot' => $slot,
        'name' => $lists['name'],
        'main_deck' => $lists['main_deck'],
        'energy_deck' => $lists['energy_deck'],
    ];
}

function tcgApiDeckAutoBuild(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $user = tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    if (empty($user['starter_deck'])) {
        throw new Exception('Choose a starter deck first');
    }
    $owned = tcgGetCollectionMap($uid);
    $cards = tcgLoadCardsData();
    $starterLists = tcgGetStarterDeckLists($user['starter_deck'], $cards);
    $groupPref = trim((string)($body['group'] ?? ''));
    if ($groupPref === '') {
        $groupPref = 'mixed';
    }
    $forcedGroup = $groupPref === '' ? null : $groupPref;
    $gen = generateCollectionDeckLists($cards['cards'] ?? [], $owned, $forcedGroup, $starterLists);
    $cardMap = tcgBuildCardMap($cards);
    $validation = tcgValidateDeckLists($gen['main_deck'], $gen['energy_deck'], $cardMap, $owned);
    if (!$validation['valid']) {
        $gen = deckgenStarterBuildResult($starterLists);
        $validation = tcgValidateDeckLists($gen['main_deck'], $gen['energy_deck'], $cardMap, $owned);
    }
    if (!$validation['valid']) {
        throw new Exception('Starter deck validation failed: ' . implode('; ', $validation['errors']));
    }
    return [
        'success' => true,
        'build' => [
            'name' => $gen['name_en'],
            'group' => $gen['group'],
            'summary' => $gen['summary'] ?? '',
            'main_deck' => $gen['main_deck'],
            'energy_deck' => $gen['energy_deck'],
            'members' => $validation['members'],
            'lives' => $validation['lives'],
            'energy_types' => $validation['energy_types'],
        ],
    ];
}

function tcgApiResetAccount(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    tcgResetAccountProgress($uid);
    return ['success' => true, 'reset' => true];
}

function tcgApiRankedJoin(array $body): array {
    tcgRateLimitForAction('ranked_join', $body);
    $uid = tcgRequireAuthUser($body);
    $user = tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    if (empty($user['starter_deck'])) {
        throw new Exception('Choose a starter deck first');
    }
    $equipped = tcgGetEquippedDeck($uid);
    if (!$equipped) {
        throw new Exception('Equip a deck preset for ranked play');
    }
    $main = json_decode($equipped['main_deck'], true) ?: [];
    $energy = json_decode($equipped['energy_deck'], true) ?: [];
    $cards = tcgLoadCardsData();
    $validation = tcgValidateDeckLists($main, $energy, tcgBuildCardMap($cards), tcgGetCollectionMap($uid));
    if (!$validation['valid']) {
        throw new Exception('Equipped deck is invalid: ' . implode('; ', $validation['errors']));
    }
    if (tcgGetActiveRankedGame($uid)) {
        tcgAbandonActiveRankedGame($uid);
    }
    $challengeId = trim((string)($body['challenge_discord_id'] ?? ''));
    if ($challengeId !== '' && $challengeId === $uid) {
        throw new Exception('You cannot challenge yourself', 400);
    }
    $join = tcgQueueJoin($uid);
    if ($challengeId !== '') {
        $match = tcgTryMatchmakeWithChallenge($uid, $challengeId);
        if (!$match) {
            tcgQueueLeave($uid);
            throw new Exception('That player is no longer waiting for a ranked match', 409);
        }
        return ['success' => true, 'queue' => $join, 'match' => $match, 'queue_stats' => tcgQueuePublicStats()];
    }
    $match = tcgTryMatchmake($uid);
    if ($match) {
        return ['success' => true, 'queue' => $join, 'match' => $match, 'queue_stats' => tcgQueuePublicStats()];
    }
    return ['success' => true, 'queue' => $join, 'match' => null, 'queue_stats' => tcgQueuePublicStats()];
}

function tcgApiRankedLeave(array $body): array {
    $uid = tcgRequireAuthUser($body);
    return ['success' => true, 'queue' => tcgQueueLeave($uid)];
}

function tcgApiActiveGame(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $active = tcgGetActiveRankedGame($uid);
    return ['success' => true, 'active' => $active];
}

function tcgApiLeaveActiveGame(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $result = tcgAbandonActiveRankedGame($uid);
    return ['success' => true] + $result;
}

function tcgReplayRowToSummary(array $row): array {
    return [
        'id' => intval($row['id']),
        'room_id' => (string)($row['room_id'] ?? ''),
        'saver_player_id' => (string)($row['saver_player_id'] ?? ''),
        'saver_name' => (string)($row['saver_name'] ?? ''),
        'opponent_name' => (string)($row['opponent_name'] ?? ''),
        'winner' => $row['winner'] ?? null,
        'end_reason' => $row['end_reason'] ?? null,
        'turn' => intval($row['turn'] ?? 0),
        'phase' => (string)($row['phase'] ?? ''),
        'action_count' => intval($row['action_count'] ?? 0),
        'duration_seconds' => intval($row['duration_seconds'] ?? 0),
        'saved_at' => intval($row['saved_at'] ?? 0),
        'preserved' => !empty($row['preserved']),
    ];
}

function tcgReplayOpponentName(array $state, string $playerId): string {
    $opp = ($playerId === 'p1') ? 'p2' : 'p1';
    return (string)($state['players'][$opp]['name'] ?? $opp);
}

function tcgAssertReplaySaveAllowedForAccount(string $uid, array $state, string $playerId): void {
    $ranked = $state['ranked'] ?? null;
    if (!is_array($ranked)) {
        return;
    }
    $expected = $ranked[$playerId . '_discord_id'] ?? null;
    if ($expected !== null && (string)$expected !== $uid) {
        throw new Exception('This ranked replay belongs to a different account', 403);
    }
}

function tcgReplayLoadOwnedRow(string $uid, int $id): array {
    if ($id <= 0) {
        throw new Exception('replay_id required', 400);
    }
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_replays WHERE id = ? AND discord_id = ?');
    $stmt->execute([$id, $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Replay not found', 404);
    }
    return $row;
}

function tcgReplayPayloadFromRow(array $row): array {
    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
    if (!is_array($payload)) {
        throw new Exception('Saved replay payload is invalid', 500);
    }
    validateReplayFile($payload);
    return $payload;
}

/** Keep at most $keep non-preserved (autosave) rows per user; oldest deleted. */
function tcgReplayTrimAutosaves(string $uid, int $keep = 10): void {
    $keep = max(1, min(50, $keep));
    $db = tcgDb();
    $stmt = $db->prepare('SELECT id FROM tcg_replays
        WHERE discord_id = ? AND COALESCE(preserved, 0) = 0
        ORDER BY saved_at DESC, id DESC');
    $stmt->execute([$uid]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (count($ids) <= $keep) {
        return;
    }
    $drop = array_slice($ids, $keep);
    $del = $db->prepare('DELETE FROM tcg_replays WHERE id = ? AND discord_id = ? AND COALESCE(preserved, 0) = 0');
    foreach ($drop as $id) {
        $del->execute([$id, $uid]);
    }
}

function tcgReplayFindOwnedByRoom(string $uid, string $roomId): ?array {
    if ($roomId === '') {
        return null;
    }
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_replays
        WHERE discord_id = ? AND room_id = ?
        ORDER BY preserved DESC, saved_at DESC, id DESC
        LIMIT 1');
    $stmt->execute([$uid, $roomId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Save finished-match replay.
 * - autosave (default when body.autosave): FIFO last 10 non-preserved
 * - preserve / legacy button: permanent library (preserved=1)
 * Same room_id upserts instead of duplicating.
 */
function tcgApiReplaySave(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $roomId = strtoupper(trim((string)($body['room_id'] ?? '')));
    $token = (string)($body['player_token'] ?? $body['token'] ?? '');
    if ($roomId === '' || $token === '') {
        throw new Exception('room_id and player_token required', 400);
    }
    $wantAutosave = !empty($body['autosave']) || (($body['kind'] ?? '') === 'autosave');
    $wantPreserve = !empty($body['preserve'])
        || !empty($body['permanent'])
        || (($body['kind'] ?? '') === 'library');
    // Legacy clients (no flags) = permanent library save.
    if (!$wantAutosave && !$wantPreserve) {
        $wantPreserve = true;
    }
    if ($wantAutosave) {
        $wantPreserve = false;
    }

    $state = loadGame($roomId);
    if (!$state) {
        throw new Exception('Room not found', 404);
    }
    $playerId = getPlayerIdByToken($state, $token);
    if (!$playerId) {
        throw new Exception('Invalid player token', 403);
    }
    tcgAssertReplaySaveAllowedForAccount($uid, $state, $playerId);
    if (($state['status'] ?? '') !== 'finished') {
        throw new Exception('Replay can only be saved after the match finishes', 400);
    }

    $existing = tcgReplayFindOwnedByRoom($uid, $roomId);
    if ($existing) {
        if ($wantPreserve && empty($existing['preserved'])) {
            tcgDb()->prepare('UPDATE tcg_replays SET preserved = 1 WHERE id = ? AND discord_id = ?')
                ->execute([intval($existing['id']), $uid]);
            $existing = tcgReplayLoadOwnedRow($uid, intval($existing['id']));
        }
        return [
            'success' => true,
            'replay' => tcgReplayRowToSummary($existing),
            'upserted' => true,
        ];
    }

    $payload = buildReplayExportPayload($state, $playerId);
    validateReplayFile($payload);
    if (count($payload['actions'] ?? []) === 0) {
        throw new Exception('No recorded actions yet', 400);
    }
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        throw new Exception('Could not encode replay payload', 500);
    }

    $meta = $payload['meta'] ?? [];
    $db = tcgDb();
    $now = time();
    $preserved = $wantPreserve ? 1 : 0;
    $db->prepare('INSERT INTO tcg_replays (
            discord_id, room_id, saver_player_id, saver_name, opponent_name, winner, end_reason,
            turn, phase, action_count, duration_seconds, payload_json, saved_at, preserved
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $uid,
            (string)($meta['room_id'] ?? $roomId),
            $playerId,
            (string)($meta['saver_name'] ?? ($state['players'][$playerId]['name'] ?? $playerId)),
            tcgReplayOpponentName($state, $playerId),
            $state['winner'] ?? null,
            $state['end_reason'] ?? null,
            intval($meta['turn'] ?? $state['turn'] ?? 0),
            (string)($meta['phase'] ?? $state['phase'] ?? ''),
            count($payload['actions'] ?? []),
            intval($meta['duration_seconds'] ?? 0),
            $payloadJson,
            $now,
            $preserved,
        ]);
    $id = intval($db->lastInsertId());
    if (!$preserved) {
        tcgReplayTrimAutosaves($uid, 10);
    }
    $row = tcgReplayLoadOwnedRow($uid, $id);
    return ['success' => true, 'replay' => tcgReplayRowToSummary($row)];
}

function tcgApiReplayPreserve(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $row = tcgReplayLoadOwnedRow($uid, intval($body['replay_id'] ?? 0));
    if (empty($row['preserved'])) {
        tcgDb()->prepare('UPDATE tcg_replays SET preserved = 1 WHERE id = ? AND discord_id = ?')
            ->execute([intval($row['id']), $uid]);
        $row = tcgReplayLoadOwnedRow($uid, intval($row['id']));
    }
    return ['success' => true, 'replay' => tcgReplayRowToSummary($row)];
}

function tcgApiReplayList(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $limit = max(1, min(200, intval($body['limit'] ?? $_GET['limit'] ?? 100)));
    $stmt = tcgDb()->prepare('SELECT id, room_id, saver_player_id, saver_name, opponent_name, winner, end_reason,
            turn, phase, action_count, duration_seconds, saved_at, preserved
        FROM tcg_replays
        WHERE discord_id = ?
        ORDER BY preserved ASC, saved_at DESC, id DESC
        LIMIT ?');
    $stmt->bindValue(1, $uid, PDO::PARAM_STR);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $summaries = array_map('tcgReplayRowToSummary', $rows);
    $recent = array_values(array_filter($summaries, static fn($r) => empty($r['preserved'])));
    $saved = array_values(array_filter($summaries, static fn($r) => !empty($r['preserved'])));
    return [
        'success' => true,
        'replays' => $summaries,
        'recent' => $recent,
        'saved' => $saved,
    ];
}

function tcgApiReplayGet(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $row = tcgReplayLoadOwnedRow($uid, intval($body['replay_id'] ?? $_GET['replay_id'] ?? 0));
    return [
        'success' => true,
        'replay' => tcgReplayPayloadFromRow($row),
        'summary' => tcgReplayRowToSummary($row),
    ];
}

function tcgApiReplayStartSaved(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $row = tcgReplayLoadOwnedRow($uid, intval($body['replay_id'] ?? 0));
    $payload = tcgReplayPayloadFromRow($row);
    $started = apiReplayStart(['replay' => $payload]);
    return ['success' => true, 'summary' => tcgReplayRowToSummary($row)] + $started;
}

function tcgApiRankedStatus(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $status = tcgQueueStatus($uid);
    if (($status['status'] ?? '') === 'searching') {
        $match = tcgTryMatchmake($uid);
        if ($match) {
            $status = tcgQueueStatus($uid);
        }
    }
    $includeStats = true;
    if (array_key_exists('include_stats', $_GET)) {
        $includeStats = filter_var($_GET['include_stats'], FILTER_VALIDATE_BOOLEAN);
    } elseif (array_key_exists('include_stats', $body)) {
        $includeStats = filter_var($body['include_stats'], FILTER_VALIDATE_BOOLEAN);
    }
    $out = ['success' => true, 'ranked' => $status];
    if ($includeStats) {
        $out['queue_stats'] = tcgQueuePublicStats();
    }
    return $out;
}

function tcgFormatRankSummary(array $rank): array {
    $wins = intval($rank['wins'] ?? 0);
    $losses = intval($rank['losses'] ?? 0);
    $draws = intval($rank['draws'] ?? 0);
    $games = intval($rank['games'] ?? 0);
    $decided = max(1, $wins + $losses);
    return [
        'elo' => intval($rank['rating'] ?? 1000),
        'rating' => intval($rank['rating'] ?? 1000),
        'wins' => $wins,
        'losses' => $losses,
        'draws' => $draws,
        'games' => $games,
        'win_rate' => round(($wins / $decided) * 100, 1),
        'loss_rate' => round(($losses / $decided) * 100, 1),
    ];
}

function tcgParseBannerCrop(?string $json): ?array {
    if (!$json) {
        return null;
    }
    $crop = json_decode($json, true);
    if (!is_array($crop)) {
        return null;
    }
    $x = floatval($crop['x'] ?? -1);
    $y = floatval($crop['y'] ?? -1);
    $w = floatval($crop['w'] ?? 0);
    $h = floatval($crop['h'] ?? 0);
    if ($w <= 0 || $h <= 0 || $x < 0 || $y < 0 || ($x + $w) > 1.001 || ($y + $h) > 1.001) {
        return null;
    }
    return [
        'x' => max(0, min(1, $x)),
        'y' => max(0, min(1, $y)),
        'w' => max(0.01, min(1, $w)),
        'h' => max(0.01, min(1, $h)),
    ];
}

function tcgCardImageMap(array $cardsData): array {
    $map = [];
    foreach ($cardsData['cards'] ?? [] as $card) {
        $no = $card['card_no'] ?? '';
        if ($no) {
            $map[$no] = $card;
        }
    }
    return $map;
}

function tcgFormatUserBanner(?array $user, array $cardsData): ?array {
    if (!$user || empty($user['banner_card_no'])) {
        return null;
    }
    $cardNo = $user['banner_card_no'];
    $card = tcgCardImageMap($cardsData)[$cardNo] ?? null;
    if (!$card || empty($card['image'])) {
        return null;
    }
    $crop = tcgParseBannerCrop($user['banner_crop'] ?? null) ?? ['x' => 0, 'y' => 0.38, 'w' => 1, 'h' => 0.20];
    return [
        'card_no' => $cardNo,
        'name_en' => $card['name_en'] ?? $cardNo,
        'image' => $card['image'],
        'crop' => $crop,
    ];
}

function tcgStampLookup(string $stampId, string $locale): ?array {
    $manifest = tcgLoadStampManifest();
    if (!$manifest) {
        return null;
    }
    $locale = $locale === 'en' ? 'en' : 'ja';
    foreach ($manifest['locales'][$locale] ?? [] as $row) {
        if (($row['id'] ?? '') === $stampId) {
            return $row;
        }
    }
    $other = $locale === 'en' ? 'ja' : 'en';
    foreach ($manifest['locales'][$other] ?? [] as $row) {
        if (($row['id'] ?? '') === $stampId) {
            return $row;
        }
    }
    return null;
}

/** @return list<array{id: string, locale: string, label: string, image: string}> */
function tcgFormatStampProfilePublic(?string $json): array {
    $fav = tcgParseStampFavorites($json);
    $out = [];
    foreach ($fav['profile'] as $id) {
        $row = tcgStampLookup($id, 'ja') ?? tcgStampLookup($id, 'en');
        if (!$row) {
            continue;
        }
        $loc = 'ja';
        $manifest = tcgLoadStampManifest();
        if ($manifest) {
            $jaIds = array_column($manifest['locales']['ja'] ?? [], 'id');
            $loc = in_array($id, $jaIds, true) ? 'ja' : 'en';
        }
        $out[] = [
            'id' => $id,
            'locale' => $loc,
            'label' => (string)($row['label'] ?? $id),
            'image' => 'assets/stamps/' . ltrim((string)($row['image'] ?? ''), '/'),
        ];
    }
    return $out;
}

function tcgApiStampFavoritesSet(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $profile = tcgAuthUserProfile($uid);
    $user = tcgEnsureUser($uid, $profile);
    $raw = $body['favorites'] ?? null;
    if (!is_array($raw)) {
        throw new Exception('favorites object required (ja, en, profile arrays)');
    }
    $favorites = [
        'ja' => tcgSanitizeStampIdList($raw['ja'] ?? [], 'ja', 24),
        'en' => tcgSanitizeStampIdList($raw['en'] ?? [], 'en', 24),
        'profile' => tcgSanitizeStampIdList($raw['profile'] ?? [], 'profile', TCG_STAMP_PROFILE_MAX),
    ];
    $db = tcgDb();
    $now = time();
    $encoded = json_encode($favorites);
    $db->prepare('UPDATE tcg_users SET stamp_favorites = ?, updated_at = ? WHERE discord_id = ?')
        ->execute([$encoded, $now, $uid]);
    $completions = tcgMissionOnStampFavoritesSet($uid, $favorites);
    return tcgMissionAttachCompletions([
        'success' => true,
        'stamp_favorites' => $favorites,
    ], $completions);
}

function tcgApiRankBannerSet(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $profile = tcgAuthUserProfile($uid);
    $user = tcgEnsureUser($uid, $profile);
    $cardNo = trim((string)($body['card_no'] ?? ''));
    if ($cardNo === '') {
        throw new Exception('card_no required');
    }
    $owned = tcgGetCollectionMap($uid);
    if (($owned[$cardNo] ?? 0) <= 0) {
        throw new Exception('You do not own that card');
    }
    $cards = tcgLoadCardsData();
    $card = tcgCardImageMap($cards)[$cardNo] ?? null;
    if (!$card || empty($card['image'])) {
        throw new Exception('Card art not found');
    }
    $cropRaw = $body['crop'] ?? null;
    if (!is_array($cropRaw)) {
        throw new Exception('Invalid crop — use normalized x,y,w,h (0–1)');
    }
    $crop = tcgParseBannerCrop(json_encode($cropRaw));
    $db = tcgDb();
    $now = time();
    $db->prepare('UPDATE tcg_users SET banner_card_no = ?, banner_crop = ?, updated_at = ? WHERE discord_id = ?')
        ->execute([$cardNo, json_encode($crop), $now, $uid]);
    $user['banner_card_no'] = $cardNo;
    $user['banner_crop'] = json_encode($crop);
    $completions = tcgMissionOnProfileBannerSet($uid);
    return tcgMissionAttachCompletions([
        'success' => true,
        'banner' => tcgFormatUserBanner($user, $cards),
    ], $completions);
}

function tcgApiRankFlagSet(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $profile = tcgAuthUserProfile($uid);
    tcgEnsureUser($uid, $profile);
    $flagId = tcgNormalizeEquippedFlag($body['flag_id'] ?? $body['equipped_flag'] ?? null);
    // Explicit clear: allow empty / __none__
    $raw = trim((string)($body['flag_id'] ?? $body['equipped_flag'] ?? ''));
    if ($raw !== '' && $raw !== '__none__' && strcasecmp($raw, 'none') !== 0 && $flagId === '') {
        throw new Exception('Unknown flag');
    }
    $db = tcgDb();
    $now = time();
    $db->prepare('UPDATE tcg_users SET equipped_flag = ?, updated_at = ? WHERE discord_id = ?')
        ->execute([$flagId !== '' ? $flagId : null, $now, $uid]);
    $completions = [];
    if ($flagId !== '') {
        $completions = tcgMissionOnProfileFlagSet($uid);
    }
    return tcgMissionAttachCompletions([
        'success' => true,
        'equipped_flag' => tcgFormatEquippedFlag($flagId),
    ], $completions);
}

function tcgApiRankStats(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $profile = tcgAuthUserProfile($uid);
    $user = tcgEnsureUser($uid, $profile);
    $rank = tcgRankRow($uid);
    $cards = tcgLoadCardsData();
    $db = tcgDb();
    $stmt = $db->query('SELECT r.discord_id, r.rating, r.wins, r.losses, r.draws, r.games,
            u.username, u.avatar_url, u.banner_card_no, u.banner_crop, u.equipped_flag, u.stamp_favorites
        FROM tcg_rank r
        JOIN tcg_users u ON u.discord_id = r.discord_id
        WHERE r.games > 0
        ORDER BY r.rating DESC, r.wins DESC');
    $leaderboard = [];
    $rankNum = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rankNum++;
        $summary = tcgFormatRankSummary($row);
        $leaderboard[] = [
            'rank' => $rankNum,
            'user_id' => $row['discord_id'],
            'username' => $row['username'] ?: 'Player',
            'avatar_url' => $row['avatar_url'] ?? null,
            'elo' => $summary['elo'],
            'wins' => $summary['wins'],
            'losses' => $summary['losses'],
            'draws' => $summary['draws'],
            'games' => $summary['games'],
            'win_rate' => $summary['win_rate'],
            'loss_rate' => $summary['loss_rate'],
            'banner' => tcgFormatUserBanner($row, $cards),
            'equipped_flag' => tcgFormatEquippedFlag($row['equipped_flag'] ?? null),
            'is_you' => $row['discord_id'] === $uid,
        ];
    }
    $yourRank = null;
    foreach ($leaderboard as $entry) {
        if (!empty($entry['is_you'])) {
            $yourRank = $entry['rank'];
            break;
        }
    }
    return [
        'success' => true,
        'you' => array_merge(
            tcgFormatRankSummary($rank),
            [
                'rank' => $yourRank,
                'username' => $user['username'] ?? $profile['username'] ?? 'Player',
                'avatar_url' => $user['avatar_url'] ?? $profile['avatar_url'] ?? null,
                'banner' => tcgFormatUserBanner($user, $cards),
                'equipped_flag' => tcgFormatEquippedFlag($user['equipped_flag'] ?? null),
            ]
        ),
        'leaderboard' => $leaderboard,
    ];
}

/**
 * Attempt to pair the user with another queued player and create a ranked game room.
 */
function tcgTryMatchmake(string $discordId): ?array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT rating FROM tcg_match_queue WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $self = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$self) {
        return null;
    }
    $opp = tcgFindQueueOpponent($discordId, intval($self['rating']));
    if (!$opp) {
        return null;
    }
    $oppId = $opp['discord_id'];
    if ($oppId === $discordId) {
        return null;
    }

    return tcgFinalizeRankedPair($discordId, $oppId);
}

/** Pair specifically with a challenged player who is still in the ranked queue. */
function tcgTryMatchmakeWithChallenge(string $discordId, string $challengeDiscordId): ?array {
    if ($challengeDiscordId === '' || $challengeDiscordId === $discordId) {
        return null;
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT discord_id FROM tcg_match_queue WHERE discord_id = ?');
    $stmt->execute([$challengeDiscordId]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        return null;
    }
    $stmt = $db->prepare('SELECT discord_id FROM tcg_match_queue WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        return null;
    }
    return tcgFinalizeRankedPair($discordId, $challengeDiscordId);
}

function tcgFinalizeRankedPair(string $discordId, string $oppId): ?array {
    require_once __DIR__ . '/ranked_room.php';
    $pair = tcgCreateRankedRoomPair($discordId, $oppId);
    if (!$pair) {
        return null;
    }
    $isP1 = $pair['p1']['discord_id'] === $discordId;
    $side = $isP1 ? $pair['p1'] : $pair['p2'];
    return [
        'status' => 'matched',
        'room_id' => $pair['room_id'],
        'player_token' => $side['token'],
        'player_id' => $side['player_id'],
        'opponent_id' => $isP1 ? $pair['p2']['discord_id'] : $pair['p1']['discord_id'],
        'match_id' => $pair['match_id'],
    ];
}

/** Public ranked top-N for Discord /loveca leaderboard (no auth). */
function tcgApiPublicLeaderboard(array $params): array {
    $limit = intval($params['limit'] ?? 100);
    if ($limit < 1) {
        $limit = 100;
    }
    $limit = min(100, $limit);
    $db = tcgDb();
    $stmt = $db->query(
        'SELECT r.discord_id, r.rating, r.wins, r.losses, r.draws, r.games, u.username
         FROM tcg_rank r
         JOIN tcg_users u ON u.discord_id = r.discord_id
         WHERE r.games > 0
         ORDER BY r.rating DESC, r.wins DESC
         LIMIT ' . $limit
    );
    $leaderboard = [];
    $rankNum = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rankNum++;
        $summary = tcgFormatRankSummary($row);
        $leaderboard[] = [
            'rank' => $rankNum,
            'user_id' => (string)$row['discord_id'],
            'username' => $row['username'] ?: 'Player',
            'elo' => $summary['elo'],
            'wins' => $summary['wins'],
            'losses' => $summary['losses'],
            'loss_rate' => $summary['loss_rate'],
            'games' => $summary['games'],
        ];
    }
    return [
        'success' => true,
        'limit' => $limit,
        'leaderboard' => $leaderboard,
    ];
}

/** Public Loveca profile for Discord /loveca profile (no auth). */
function tcgApiPublicProfile(array $params): array {
    $discordId = trim((string)($params['discord_id'] ?? ''));
    if ($discordId === '' || !preg_match('/^\d{5,32}$/', $discordId)) {
        throw new Exception('discord_id required', 400);
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new Exception('Player not found', 404);
    }

    $cards = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cards);
    $collection = tcgPublicCollectionStats($discordId, $cardMap);
    $packsOpened = tcgPublicPacksOpened($discordId);
    $rank = tcgFormatRankSummary(tcgRankRow($discordId));
    $banner = tcgFormatUserBanner($user, $cards);
    $bannerUrl = null;
    if ($banner && !empty($banner['card_no'])) {
        $crop = $banner['crop'] ?? ['x' => 0, 'y' => 0.38, 'w' => 1, 'h' => 0.20];
        $v = substr(sha1((string)$banner['card_no'] . '|' . json_encode($crop)), 0, 12);
        $bannerUrl = 'https://loveliveradio.ca/tcg/bannerimg.php?discord_id='
            . rawurlencode($discordId) . '&v=' . rawurlencode($v);
    }

    return [
        'success' => true,
        'profile' => [
            'discord_id' => $discordId,
            'username' => (string)($user['username'] ?? 'Player'),
            'avatar_url' => $user['avatar_url'] ?? null,
            'rank' => $rank,
            'ranked_games' => intval($rank['games'] ?? 0),
            'unranked_games' => intval($user['unranked_games'] ?? 0),
            'collection' => $collection,
            'packs_opened' => $packsOpened,
            'banner' => $banner,
            'banner_image_url' => $bannerUrl,
            'equipped_flag' => tcgFormatEquippedFlag($user['equipped_flag'] ?? null),
            'queue' => tcgPublicQueueStatus($discordId),
        ],
    ];
}

function tcgPublicCollectionStats(string $discordId, array $cardMap): array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT card_no, qty FROM tcg_collection WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $totalCards = 0;
    $totalUnique = 0;
    $byRarity = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $qty = intval($row['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $totalUnique++;
        $totalCards += $qty;
        $card = $cardMap[$row['card_no']] ?? null;
        $rarity = strtoupper(trim((string)($card['rarity'] ?? 'UNKNOWN')));
        if ($rarity === '') {
            $rarity = 'UNKNOWN';
        }
        $byRarity[$rarity] = ($byRarity[$rarity] ?? 0) + $qty;
    }
    ksort($byRarity);
    return [
        'total_cards' => $totalCards,
        'total_unique' => $totalUnique,
        'by_rarity' => $byRarity,
    ];
}

function tcgPublicPacksOpened(string $discordId): int {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT box_id, packs_in_box, boxes_opened FROM tcg_box_progress WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $total = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $box = tcgBoosterBoxById((string)($row['box_id'] ?? ''));
        $perBox = $box ? tcgBoxPacksPerBox($box) : TCG_PACKS_PER_BOX;
        $total += intval($row['boxes_opened'] ?? 0) * $perBox + intval($row['packs_in_box'] ?? 0);
    }
    return $total;
}

function tcgPublicSpectateUrl(string $roomId): string {
    return 'https://loveliveradio.ca/tcg/?spectate=' . rawurlencode($roomId);
}

function tcgPublicInMatchStatusFromState(string $discordId, string $roomId, array $state, string $mode): ?array {
    if (!function_exists('tcgIsActiveGameplayStatus')) {
        require_once __DIR__ . '/spectate.php';
    }
    if (!tcgIsActiveGameplayStatus($state)) {
        return null;
    }
    // Profile / spectate is PvP-only — never surface solo CPU (or CPU-tagged) rooms.
    if (!empty($state['cpu_solo']) || !empty($state['cpu_difficulty'])) {
        return null;
    }
    if (($state['mode'] ?? '') === 'replay_view' || ($state['mode'] ?? '') === 'tutorial') {
        return null;
    }
    if (function_exists('isHumanVsHumanRoster') && !isHumanVsHumanRoster($state)) {
        return null;
    }
    $p1 = $state['players']['p1'] ?? null;
    $p2 = $state['players']['p2'] ?? null;
    if (!is_array($p1) || !is_array($p2)) {
        return null;
    }
    $p1Discord = (string)($p1['discord_id'] ?? ($state['ranked']['p1_discord_id'] ?? ''));
    $p2Discord = (string)($p2['discord_id'] ?? ($state['ranked']['p2_discord_id'] ?? ''));
    // Both sides must be real Discord players (friend / casual / ranked PvP).
    if ($p1Discord === '' || $p2Discord === '') {
        return null;
    }
    $opponentName = null;
    if ($p1Discord === $discordId) {
        $opponentName = (string)($p2['name'] ?? 'Opponent');
    } elseif ($p2Discord === $discordId) {
        $opponentName = (string)($p1['name'] ?? 'Opponent');
    } else {
        return null;
    }
    $opponentName = html_entity_decode($opponentName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($opponentName === '') {
        $opponentName = 'Opponent';
    }
    return [
        'status' => 'in_match',
        'mode' => $mode,
        'room_id' => $roomId,
        'opponent_name' => $opponentName,
        'spectate_url' => tcgPublicSpectateUrl($roomId),
    ];
}

function tcgPublicFindCasualMatchForUser(string $discordId): ?array {
    if (!defined('GAMES_DIR')) {
        return null;
    }
    if (!function_exists('tcgIsActiveGameplayStatus')) {
        require_once __DIR__ . '/spectate.php';
    }
    $files = glob(GAMES_DIR . '*.json') ?: [];
    foreach ($files as $file) {
        $base = basename($file);
        if (str_starts_with($base, 'lock_')
            || str_starts_with($base, 'presence_')
            || str_starts_with($base, 'spectators_')) {
            continue;
        }
        $roomId = pathinfo($base, PATHINFO_FILENAME);
        if ($roomId === '') {
            continue;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            continue;
        }
        $state = json_decode($raw, true);
        if (!is_array($state) || ($state['mode'] ?? '') === 'ranked') {
            continue;
        }
        // Skip solo CPU rooms early (full PvP checks run in tcgPublicInMatchStatusFromState).
        if (!empty($state['cpu_solo']) || !empty($state['cpu_difficulty'])) {
            continue;
        }
        $hit = tcgPublicInMatchStatusFromState($discordId, $roomId, $state, 'casual');
        if ($hit) {
            return $hit;
        }
    }
    return null;
}

function tcgPublicQueueStatus(string $discordId): array {
    // Active match first (takes priority over queue searching).
    // Sanitize like ranked_status / active_game so finished rooms do not linger as in_match.
    $db = tcgDb();
    $stmt = $db->prepare(
        'SELECT * FROM tcg_ranked_matches
         WHERE status = "pending" AND (p1_id = ? OR p2_id = ?)
         ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([$discordId, $discordId]);
    $ranked = tcgSanitizeRankedMatchRow($stmt->fetch(PDO::FETCH_ASSOC));
    if ($ranked) {
        $roomId = (string)($ranked['room_id'] ?? '');
        if ($roomId !== '' && function_exists('tcgRankedGameFilePath')) {
            $path = tcgRankedGameFilePath($roomId);
            if (is_file($path)) {
                $state = json_decode((string)file_get_contents($path), true);
                if (is_array($state)) {
                    $hit = tcgPublicInMatchStatusFromState($discordId, $roomId, $state, 'ranked');
                    if ($hit) {
                        return $hit;
                    }
                    // State present but not active gameplay — do not invent in_match.
                    return ['status' => 'idle'];
                }
            }
        }
        // Sanitized pending row with unreadable state: still treat as live for reconnect UX.
        $isP1 = ((string)($ranked['p1_id'] ?? '')) === $discordId;
        $oppId = $isP1 ? (string)($ranked['p2_id'] ?? '') : (string)($ranked['p1_id'] ?? '');
        $oppName = 'Opponent';
        if ($oppId !== '') {
            if (!function_exists('tcgGetUserDisplayName')) {
                require_once __DIR__ . '/ranked_room.php';
            }
            $oppName = tcgGetUserDisplayName($oppId) ?: 'Opponent';
        }
        if ($roomId !== '') {
            return [
                'status' => 'in_match',
                'mode' => 'ranked',
                'room_id' => $roomId,
                'opponent_name' => $oppName,
                'spectate_url' => tcgPublicSpectateUrl($roomId),
            ];
        }
    }

    $casual = tcgPublicFindCasualMatchForUser($discordId);
    if ($casual) {
        return $casual;
    }

    $stmt = $db->prepare('SELECT joined_at FROM tcg_match_queue WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return ['status' => 'searching', 'mode' => 'ranked'];
    }
    $stmt = $db->prepare('SELECT joined_at FROM tcg_casual_queue WHERE discord_id = ? ORDER BY joined_at DESC LIMIT 1');
    $stmt->execute([$discordId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        return ['status' => 'searching', 'mode' => 'casual'];
    }
    return ['status' => 'idle'];
}

/**
 * Called from api.php when ranked room is created.
 */
function tcgGetUserEquippedDeckForGame(string $discordId): ?array {
    $deck = tcgGetEquippedDeck($discordId);
    if (!$deck) {
        return null;
    }
    return [
        'main_deck' => json_decode($deck['main_deck'], true) ?: [],
        'energy_deck' => json_decode($deck['energy_deck'], true) ?: [],
        'deck_label' => tcgNormalizeDeckPresetName($deck['name'] ?? 'Ranked Deck'),
    ];
}
