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
 *   deck_list, deck_save, deck_set_sleeve, deck_delete, deck_equip, deck_equip_starter, deck_reset_starter, deck_auto_build, deck_import_decklog, reset_account,
 *   ranked_join, ranked_leave, ranked_status, ranked_apply_result, mission_stamp_sent, mission_game_finished, rank_stats, rank_banner_set, rank_flag_set, stamp_favorites_set, active_game, leave_active_game,
 *   replay_save, replay_list, replay_get, replay_start, missions_list, missions_claim, login_bonus_status, login_bonus_claim, public_profile,
 *   public_leaderboard, sticker_shop_catalog, sticker_shop_cards, convert_to_seal, convert_to_seals_batch, sticker_buy,
 *   presence_action_mint, presence_action_redeem,
 *   tournament_* (local-flagged: list/get/create/update/cancel/deposit/register/checkin/tick/…)
 */
require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/config/errors.php';
require_once __DIR__ . '/config/rate_limit.php';
require_once __DIR__ . '/flags.php';
require_once __DIR__ . '/cards_data.php';
tcgDefinePathConstants();

header('Content-Type: application/json');
tcgSendCorsHeaders();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization, X-TCG-Internal-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    tcgSendCorsPreflight('GET, POST, OPTIONS', 'Content-Type, X-Auth-Token, Authorization, X-TCG-Internal-Secret');
    http_response_code(200);
    exit;
}

define('TCG_MAX_DECK_PRESETS', 10);

require_once __DIR__ . '/llr_auth_load.php';
require_once __DIR__ . '/sleeves.php';
require_once __DIR__ . '/playmats.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booster.php';
require_once __DIR__ . '/seals.php';
require_once __DIR__ . '/coins.php';
require_once __DIR__ . '/sleeve_shop.php';
require_once __DIR__ . '/playmat_shop.php';
require_once __DIR__ . '/stamps.php';
require_once __DIR__ . '/deck_validate.php';
require_once __DIR__ . '/matchmaking.php';
require_once __DIR__ . '/deckgen.php';
require_once __DIR__ . '/missions.php';
require_once __DIR__ . '/login_bonus.php';
require_once __DIR__ . '/presence_actions.php';
require_once __DIR__ . '/tournament.php';
require_once __DIR__ . '/social.php';
require_once __DIR__ . '/push.php';
if (is_file(__DIR__ . '/local_dev_auth.php')) {
    require_once __DIR__ . '/local_dev_auth.php';
}
if (!defined('TCG_API_LIB_ONLY')) {
    define('TCG_API_LIB_ONLY', true);
}
require_once __DIR__ . '/api.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

if (!(defined('TCG_ACCOUNT_LIB_ONLY') && TCG_ACCOUNT_LIB_ONLY)) {
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
        case 'deck_set_sleeve':    echo json_encode(tcgApiDeckSetSleeve($body)); break;
        case 'deck_set_playmat':   echo json_encode(tcgApiDeckSetPlaymat($body)); break;
        case 'experiment_preset_list':   echo json_encode(tcgApiExperimentPresetList($body)); break;
        case 'experiment_preset_save':   echo json_encode(tcgApiExperimentPresetSave($body)); break;
        case 'experiment_preset_set_sleeve': echo json_encode(tcgApiExperimentPresetSetSleeve($body)); break;
        case 'experiment_preset_set_playmat': echo json_encode(tcgApiExperimentPresetSetPlaymat($body)); break;
        case 'experiment_preset_delete': echo json_encode(tcgApiExperimentPresetDelete($body)); break;
        case 'experiment_preset_get':    echo json_encode(tcgApiExperimentPresetGet($body)); break;
        case 'deck_delete':        echo json_encode(tcgApiDeckDelete($body)); break;
        case 'deck_equip':         echo json_encode(tcgApiDeckEquip($body)); break;
        case 'deck_equip_starter': echo json_encode(tcgApiDeckEquipStarter($body)); break;
        case 'deck_reset_starter': echo json_encode(tcgApiDeckResetStarter($body)); break;
        case 'deck_auto_build':    echo json_encode(tcgApiDeckAutoBuild($body)); break;
        case 'deck_import_decklog': echo json_encode(tcgApiDeckImportDecklog($body)); break;
        case 'reset_account':      echo json_encode(tcgApiResetAccount($body)); break;
        case 'ranked_join':        echo json_encode(tcgApiRankedJoin($body)); break;
        case 'ranked_leave':       echo json_encode(tcgApiRankedLeave($body)); break;
        case 'ranked_status':      echo json_encode(tcgApiRankedStatus($body)); break;
        case 'ranked_apply_result': echo json_encode(tcgApiRankedApplyResult($body)); break;
        case 'mission_stamp_sent': echo json_encode(tcgApiMissionStampSent($body)); break;
        case 'mission_game_finished': echo json_encode(tcgApiMissionGameFinished($body)); break;
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
        case 'login_bonus_status': echo json_encode(tcgApiLoginBonusStatus($body)); break;
        case 'login_bonus_claim':  echo json_encode(tcgApiLoginBonusClaim($body)); break;
        case 'public_leaderboard': echo json_encode(tcgApiPublicLeaderboard($_GET + $body)); break;
        case 'public_profile':     echo json_encode(tcgApiPublicProfile($_GET + $body)); break;
        case 'repair_placeholder_usernames': echo json_encode(tcgApiRepairPlaceholderUsernames($body)); break;
        case 'sticker_shop_catalog': echo json_encode(tcgApiStickerShopCatalog($body)); break;
        case 'sticker_shop_cards': echo json_encode(tcgApiStickerShopCards($body)); break;
        case 'convert_to_seal':    echo json_encode(tcgApiConvertToSeal($body)); break;
        case 'convert_to_seals_batch': echo json_encode(tcgApiConvertToSealsBatch($body)); break;
        case 'sticker_buy':        echo json_encode(tcgApiStickerBuy($body)); break;
        case 'sleeve_shop_catalog': echo json_encode(tcgApiSleeveShopCatalog($body)); break;
        case 'sleeve_buy':         echo json_encode(tcgApiSleeveBuy($body)); break;
        case 'sleeve_claim_free':  echo json_encode(tcgApiSleeveClaimFree($body)); break;
        case 'sleeve_equip_intro_seen': echo json_encode(tcgApiSleeveEquipIntroSeen($body)); break;
        case 'owned_sleeves':      echo json_encode(tcgApiOwnedSleeves($body)); break;
        case 'playmat_shop_catalog': echo json_encode(tcgApiPlaymatShopCatalog($body)); break;
        case 'playmat_buy':        echo json_encode(tcgApiPlaymatBuy($body)); break;
        case 'owned_playmats':     echo json_encode(tcgApiOwnedPlaymats($body)); break;
        case 'presence_action_mint': echo json_encode(tcgApiPresenceActionMint($body)); break;
        case 'presence_action_redeem': echo json_encode(tcgApiPresenceActionRedeem($body)); break;
        case 'tournament_enabled':   echo json_encode(tcgApiTournamentEnabled($body)); break;
        case 'tournament_hub':       echo json_encode(tcgApiTournamentHub($body)); break;
        case 'tournament_list':      echo json_encode(tcgApiTournamentList($body)); break;
        case 'tournament_get':       echo json_encode(tcgApiTournamentGet($body)); break;
        case 'tournament_create':    echo json_encode(tcgApiTournamentCreate($body)); break;
        case 'tournament_update':    echo json_encode(tcgApiTournamentUpdate($body)); break;
        case 'tournament_deposit_prize': echo json_encode(tcgApiTournamentDepositPrize($body)); break;
        case 'tournament_register':  echo json_encode(tcgApiTournamentRegister($body)); break;
        case 'tournament_unregister': echo json_encode(tcgApiTournamentUnregister($body)); break;
        case 'tournament_checkin':   echo json_encode(tcgApiTournamentCheckin($body)); break;
        case 'tournament_kick':      echo json_encode(tcgApiTournamentKick($body)); break;
        case 'tournament_dq':        echo json_encode(tcgApiTournamentDq($body)); break;
        case 'tournament_force_result': echo json_encode(tcgApiTournamentForceResult($body)); break;
        case 'tournament_cancel':    echo json_encode(tcgApiTournamentCancel($body)); break;
        case 'tournament_tick':      echo json_encode(tcgApiTournamentTick($body)); break;
        case 'tournament_start_reminders_get': echo json_encode(tcgApiTournamentStartRemindersGet($body)); break;
        case 'tournament_start_reminders_set': echo json_encode(tcgApiTournamentStartRemindersSet($body)); break;
        case 'tournament_report':    echo json_encode(tcgApiTournamentReport($body)); break;
        case 'tournament_join_match': echo json_encode(tcgApiTournamentJoinMatch($body)); break;
        case 'tournament_apply_result': echo json_encode(tcgApiTournamentApplyResult($body)); break;
        case 'tournament_eligible_decks': echo json_encode(tcgApiTournamentEligibleDecks($body)); break;
        case 'tournament_replay_get': echo json_encode(tcgApiTournamentReplayGet($body)); break;
        case 'tournament_replay_start': echo json_encode(tcgApiTournamentReplayStart($body)); break;
        case 'timezone_set':          echo json_encode(tcgApiTimezoneSet($body)); break;
        case 'social_profile':        echo json_encode(tcgApiSocialGetProfile($body)); break;
        case 'social_stats':          echo json_encode(tcgApiSocialGetStats($body)); break;
        case 'social_save_profile':   echo json_encode(tcgApiSocialSaveProfile($body)); break;
        case 'social_friends':        echo json_encode(tcgApiSocialFriends($body)); break;
        case 'social_friend_add':     echo json_encode(tcgApiSocialFriendAdd($body)); break;
        case 'social_friend_accept':  echo json_encode(tcgApiSocialFriendRespond($body, true)); break;
        case 'social_friend_decline': echo json_encode(tcgApiSocialFriendRespond($body, false)); break;
        case 'social_friend_remove':  echo json_encode(tcgApiSocialFriendRemove($body)); break;
        case 'social_report':         echo json_encode(tcgApiSocialReport($body)); break;
        case 'social_mod_inbox':      echo json_encode(tcgApiSocialModInbox($body)); break;
        case 'social_mod_action':     echo json_encode(tcgApiSocialModAction($body)); break;
        case 'social_ban_list':       echo json_encode(tcgApiSocialBanList($body)); break;
        case 'social_ban_unban':      echo json_encode(tcgApiSocialBanUnban($body)); break;
        case 'social_notice_ack':     echo json_encode(tcgApiSocialNoticeAck($body)); break;
        case 'push_register':         echo json_encode(tcgApiPushRegister($body)); break;
        case 'push_unregister':       echo json_encode(tcgApiPushUnregister($body)); break;
        case 'push_test':             echo json_encode(tcgApiPushTest($body)); break;
        case 'match_invite':          echo json_encode(tcgApiMatchInvite($body)); break;
        case 'match_invites_pending': echo json_encode(tcgApiMatchInvitesPending($body)); break;
        case 'match_invite_accept':   echo json_encode(tcgApiMatchInviteRespond($body, true)); break;
        case 'match_invite_decline':  echo json_encode(tcgApiMatchInviteRespond($body, false)); break;
        case 'local_dev_status':
            if (!function_exists('tcgApiLocalDevStatus')) {
                throw new Exception('Local fake auth unavailable', 404);
            }
            echo json_encode(tcgApiLocalDevStatus($body));
            break;
        case 'local_dev_login':
            if (!function_exists('tcgApiLocalDevLogin')) {
                throw new Exception('Local fake auth unavailable', 404);
            }
            echo json_encode(tcgApiLocalDevLogin($body));
            break;
        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    $code = tcgHttpStatusForThrowable($e);
    tcgLogServerFault('account.php:' . $action, $e, $code);
    $payload = tcgPublicErrorPayload($e, $code);
    $payload['success'] = false;
    http_response_code($code);
    echo json_encode($payload);
}
}

function tcgApiMe(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $profile = tcgAuthUserProfile($uid);
    $user = tcgEnsureUser($uid, $profile);
    require_once __DIR__ . '/sleeve_shop.php';
    $loginDays = tcgTouchLoginDays($uid);
    require_once __DIR__ . '/missions.php';
    tcgMissionCheckLoginDays($uid);
    $cards = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cards);
    $migration = tcgMigrateDuplicateToStarGems($uid, $cardMap);
    $daily = tcgDailyOpenAllowance($uid);
    require_once __DIR__ . '/ranked_pr_rewards.php';
    $rankedPr = tcgRankedPrDailyAllowance($uid);
    $rank = tcgRankRow($uid);
    tcgUnequipIllegalEquippedLoadout($uid);
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
            'friend_code' => (function () use ($uid, $user) {
                try {
                    return tcgSocialEnsureFriendCode($uid);
                } catch (Throwable $e) {
                    return (string)($user['friend_code'] ?? '');
                }
            })(),
            'is_social_mod' => tcgSocialIsOwner($uid),
        ],
        'daily' => $daily,
        'ranked_pr' => $rankedPr,
        'star_gems' => tcgGetStarGems($uid),
        'coins' => tcgGetCoins($uid),
        'login_days' => $loginDays,
        'free_sleeve_claims' => tcgGetFreeSleeveClaims($uid),
        'owned_sleeves' => tcgOwnedSleeveIds($uid),
        'sleeves_need_intro' => tcgOwnedSleevesNeedingIntro($uid),
        'sleeve_shop_price' => TCG_SLEEVE_SHOP_PRICE,
        'owned_playmats' => tcgOwnedPlaymatIds($uid),
        'playmat_shop_price' => TCG_PLAYMAT_SHOP_PRICE,
        'star_gems_per_card' => TCG_STAR_GEMS_PER_CARD,
        'star_gems_pack_cost' => TCG_STAR_GEMS_PACK_COST,
        'star_gems_box_cost' => TCG_STAR_GEMS_BOX_COST,
        'star_gems_per_dupe' => TCG_STAR_GEMS_PER_DUPE,
        'seals' => tcgSealBalances($uid),
        'seal_buy_costs' => TCG_SEAL_BUY_COST,
        'owned_starters' => tcgOwnedStarterKeys($uid),
        'dupe_migration' => $migration,
        'rank' => tcgFormatRankSummary($rank),
        'banner' => tcgFormatUserBanner($user, $cards),
        'equipped_flag' => tcgFormatEquippedFlag($user['equipped_flag'] ?? null),
        'stamp_favorites' => tcgFormatStampFavorites($user['stamp_favorites'] ?? null),
        'equipped_deck_slot' => ($equippedLoadout === 'preset') ? intval($equipped['slot']) : null,
        'equipped_deck_name' => $equipped ? tcgNormalizeDeckPresetName($equipped['name'] ?? '') : null,
        'equipped_loadout' => $equippedLoadout,
        'equipped_starter_key' => ($equippedLoadout === 'starter') ? ($equipped['starter_key'] ?? null) : null,
        'preferred_timezone' => tcgNormalizePreferredTimezone($user['preferred_timezone'] ?? null),
        'starter_options' => tcgStarterDecks(),
        'missions' => tcgMissionSummaryForUser($uid),
        'tournament_enabled' => tcgUserMayUseTournaments($uid),
        'notices' => function_exists('tcgBanPendingNotices') ? tcgBanPendingNotices($uid) : [],
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
        'seals' => tcgSealBalances($uid),
    ];
}

function tcgApiBoosterBoxes(): array {
    $boxes = array_map('tcgEnrichBoosterBoxPublic', tcgBoosterBoxes());
    return [
        'success' => true,
        'boxes' => $boxes,
        'star_gems_per_card' => TCG_STAR_GEMS_PER_CARD,
        'star_gems_pack_cost' => TCG_STAR_GEMS_PACK_COST,
        'star_gems_box_cost' => TCG_STAR_GEMS_BOX_COST,
    ];
}

function tcgApiStickerShopCatalog(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    return [
        'success' => true,
        'products' => tcgStickerShopCatalog($uid),
        'seals' => tcgSealBalances($uid),
        'seal_buy_costs' => TCG_SEAL_BUY_COST,
        'owned_starters' => tcgOwnedStarterKeys($uid),
    ];
}

function tcgApiStickerShopCards(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $productId = trim((string)($body['product_id'] ?? $_GET['product_id'] ?? ''));
    if ($productId === '') {
        throw new Exception('product_id required', 400);
    }
    if (!tcgStickerShopProductAllowedForUser($uid, $productId)) {
        throw new Exception('Product not available', 403);
    }
    $cardsData = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cardsData);
    $owned = tcgGetCollectionMap($uid);
    $seals = tcgSealBalances($uid);
    $list = [];
    foreach (tcgStickerShopProductCardNos($productId, $cardsData) as $no) {
        $card = $cardMap[$no] ?? null;
        if (!$card || !tcgCardPurchasableWithSeal($card)) {
            continue;
        }
        $tier = tcgSealTierForCard($card);
        if ($tier === null) {
            continue;
        }
        $cost = tcgSealBuyCostForTier($tier);
        $qty = intval($owned[$no] ?? 0);
        $max = tcgGetDeckMaxCopies($card, $no);
        $list[] = [
            'card_no' => $no,
            'card' => tcgStickerShopCardSummary($card),
            'seal_tier' => $tier,
            'cost' => $cost,
            'owned_qty' => $qty,
            'max_copies' => $max,
            'can_buy' => $qty < $max && ($seals[strtolower($tier)] ?? 0) >= $cost,
        ];
    }
    usort($list, static function ($a, $b) {
        $ta = $a['seal_tier'] ?? '';
        $tb = $b['seal_tier'] ?? '';
        if ($ta !== $tb) {
            $order = ['N' => 0, 'R' => 1, 'P' => 2, 'SEC' => 3];
            return ($order[$ta] ?? 9) <=> ($order[$tb] ?? 9);
        }
        return strcmp($a['card_no'], $b['card_no']);
    });
    $payload = [
        'success' => true,
        'product_id' => $productId,
        'cards' => $list,
        'seals' => $seals,
        'seal_buy_costs' => TCG_SEAL_BUY_COST,
    ];
    if (json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) === false) {
        throw new Exception('Could not encode sticker shop cards', 500);
    }
    return $payload;
}

function tcgApiConvertToSeal(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $cardNo = trim((string)($body['card_no'] ?? ''));
    $qty = max(1, intval($body['qty'] ?? 1));
    if ($cardNo === '') {
        throw new Exception('card_no required', 400);
    }
    $cardsData = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cardsData);
    return tcgConvertCardsToSeals($uid, $cardNo, $qty, $cardMap, $cardsData);
}

function tcgApiConvertToSealsBatch(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $items = $body['items'] ?? null;
    if (!is_array($items)) {
        throw new Exception('items required', 400);
    }
    $cardsData = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cardsData);
    return tcgConvertCardsToSealsBatch($uid, $items, $cardMap, $cardsData);
}

function tcgApiStickerBuy(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $cardNo = trim((string)($body['card_no'] ?? ''));
    if ($cardNo === '') {
        throw new Exception('card_no required', 400);
    }
    $productId = trim((string)($body['product_id'] ?? ''));
    $cardsData = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cardsData);
    $result = tcgStickerBuyCard($uid, $cardNo, $cardMap, $cardsData, $productId !== '' ? $productId : null);
    require_once __DIR__ . '/missions.php';
    $completions = tcgMissionCheckStickerExchangeThresholds($uid);
    return tcgMissionAttachCompletions($result, $completions);
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
        'star_gems_per_card' => TCG_STAR_GEMS_PER_CARD,
        'star_gems_pack_cost' => TCG_STAR_GEMS_PACK_COST,
        'star_gems_box_cost' => TCG_STAR_GEMS_BOX_COST,
    ];
}

function tcgApiOpenBooster(array $body): array {
    tcgRateLimitForAction('open_booster', $body);
    $uid = tcgRequireAuthUser($body);
    $user = tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    if (empty($user['starter_deck'])) {
        throw new Exception('Choose a starter deck first', 400);
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
            'equipped_starter_key' => null,
        ];
    }
    $loadout = (($equipped['source'] ?? '') === 'starter') ? 'starter' : 'preset';
    return [
        'equipped_deck_slot' => ($loadout === 'preset') ? intval($equipped['slot']) : null,
        'equipped_deck_name' => tcgNormalizeDeckPresetName($equipped['name'] ?? ''),
        'equipped_loadout' => $loadout,
        'equipped_starter_key' => ($loadout === 'starter') ? ($equipped['starter_key'] ?? null) : null,
    ];
}

function tcgApiDeckList(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $user = tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    tcgUnequipIllegalEquippedLoadout($uid);
    $db = tcgDb();
    $stmt = $db->prepare('SELECT id, slot, name, main_deck, energy_deck, sleeve_id, playmat_id, playmat_brightness, equipped, updated_at
        FROM tcg_deck_presets WHERE discord_id = ? ORDER BY slot ASC');
    $stmt->execute([$uid]);
    $decks = [];
    $cardMap = tcgBuildCardMap(tcgLoadCardsData());
    $owned = tcgGetCollectionMap($uid);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $main = json_decode($row['main_deck'], true) ?: [];
        $energy = json_decode($row['energy_deck'], true) ?: [];
        $legal = tcgValidateDeckLists($main, $energy, $cardMap, $owned)['valid'];
        $decks[] = [
            'id' => intval($row['id']),
            'slot' => intval($row['slot']),
            'name' => tcgNormalizeDeckPresetName($row['name']),
            'main_deck' => $main,
            'energy_deck' => $energy,
            'sleeve_id' => tcgNormalizeSleeveId($row['sleeve_id'] ?? ''),
            'playmat_id' => tcgNormalizePlaymatId($row['playmat_id'] ?? ''),
            'playmat_brightness' => tcgNormalizePlaymatBrightness($row['playmat_brightness'] ?? 1.0),
            'equipped' => intval($row['equipped']) === 1,
            'updated_at' => intval($row['updated_at']),
            'main_count' => count($main),
            'energy_count' => count($energy),
            'legal' => $legal,
            'in_progress' => !$legal,
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
    $validation = tcgValidateDeckLists($main, $energy, $cardMap, $owned, true);
    if (!$validation['valid']) {
        throw new Exception(implode('; ', $validation['errors']));
    }
    $playLegal = tcgValidateDeckLists($main, $energy, $cardMap, $owned)['valid'];
    $db = tcgDb();
    $now = time();
    $hasSleeve = array_key_exists('sleeve_id', $body);
    $sleeveId = tcgNormalizeSleeveId($body['sleeve_id'] ?? '');
    if ($hasSleeve && $sleeveId !== '' && !tcgOwnsSleeve($uid, $sleeveId)) {
        throw new Exception('Sleeve not owned', 400);
    }
    // Always write sleeve_id when the client sends it (including clearing to '').
    // Older clients that omit the key keep the previous sleeve_id.
    if ($hasSleeve) {
        $db->prepare('INSERT INTO tcg_deck_presets (discord_id, slot, name, main_deck, energy_deck, sleeve_id, equipped, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?)
            ON CONFLICT(discord_id, slot) DO UPDATE SET
                name = excluded.name,
                main_deck = excluded.main_deck,
                energy_deck = excluded.energy_deck,
                sleeve_id = excluded.sleeve_id,
                updated_at = excluded.updated_at')
            ->execute([
                $uid, $slot, $name,
                json_encode(array_values($main)),
                json_encode(array_values($energy)),
                $sleeveId,
                $now,
            ]);
    } else {
        $db->prepare('INSERT INTO tcg_deck_presets (discord_id, slot, name, main_deck, energy_deck, sleeve_id, equipped, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?)
            ON CONFLICT(discord_id, slot) DO UPDATE SET
                name = excluded.name,
                main_deck = excluded.main_deck,
                energy_deck = excluded.energy_deck,
                updated_at = excluded.updated_at')
            ->execute([
                $uid, $slot, $name,
                json_encode(array_values($main)),
                json_encode(array_values($energy)),
                '',
                $now,
            ]);
    }
    $playmatId = '';
    $playmatBrightness = 1.0;
    $hasPlaymat = array_key_exists('playmat_id', $body) || array_key_exists('playmat_brightness', $body);
    if ($hasPlaymat) {
        $playmatId = tcgNormalizePlaymatId($body['playmat_id'] ?? '');
        $playmatBrightness = tcgNormalizePlaymatBrightness($body['playmat_brightness'] ?? 1.0);
        if ($playmatId !== '' && !tcgOwnsPlaymat($uid, $playmatId)) {
            throw new Exception('Playmat not owned', 400);
        }
        if ($playmatId === '') {
            $playmatBrightness = 1.0;
        }
        $db->prepare('UPDATE tcg_deck_presets SET playmat_id = ?, playmat_brightness = ?, updated_at = ?
            WHERE discord_id = ? AND slot = ?')
            ->execute([$playmatId, $playmatBrightness, $now, $uid, $slot]);
    } else {
        $stmtPm = $db->prepare('SELECT playmat_id, playmat_brightness FROM tcg_deck_presets WHERE discord_id = ? AND slot = ?');
        $stmtPm->execute([$uid, $slot]);
        $pmRow = $stmtPm->fetch(PDO::FETCH_ASSOC) ?: [];
        $playmatId = tcgNormalizePlaymatId($pmRow['playmat_id'] ?? '');
        $playmatBrightness = tcgNormalizePlaymatBrightness($pmRow['playmat_brightness'] ?? 1.0);
    }
    if (!$playLegal) {
        $db->prepare('UPDATE tcg_deck_presets SET equipped = 0 WHERE discord_id = ? AND slot = ?')
            ->execute([$uid, $slot]);
    }
    return [
        'success' => true,
        'slot' => $slot,
        'name' => $name,
        'sleeve_id' => $sleeveId,
        'playmat_id' => $playmatId,
        'playmat_brightness' => $playmatBrightness,
        'validation' => $validation,
        'legal' => $playLegal,
        'in_progress' => !$playLegal,
    ];
}

/** Persist sleeve on an existing account preset without re-validating the full deck. */
function tcgApiDeckSetSleeve(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $slot = intval($body['slot'] ?? 0);
    if ($slot < 1 || $slot > TCG_MAX_DECK_PRESETS) {
        throw new Exception('Deck slot must be 1–' . TCG_MAX_DECK_PRESETS);
    }
    $sleeveId = tcgNormalizeSleeveId($body['sleeve_id'] ?? '');
    if ($sleeveId !== '' && !tcgOwnsSleeve($uid, $sleeveId)) {
        throw new Exception('Sleeve not owned', 400);
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT slot FROM tcg_deck_presets WHERE discord_id = ? AND slot = ?');
    $stmt->execute([$uid, $slot]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('Save the deck once before setting a sleeve', 400);
    }
    $db->prepare('UPDATE tcg_deck_presets SET sleeve_id = ?, updated_at = ? WHERE discord_id = ? AND slot = ?')
        ->execute([$sleeveId, time(), $uid, $slot]);
    return ['success' => true, 'slot' => $slot, 'sleeve_id' => $sleeveId];
}

/** Persist playmat + brightness on an existing account preset. */
function tcgApiDeckSetPlaymat(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $slot = intval($body['slot'] ?? 0);
    if ($slot < 1 || $slot > TCG_MAX_DECK_PRESETS) {
        throw new Exception('Deck slot must be 1–' . TCG_MAX_DECK_PRESETS);
    }
    $playmatId = tcgNormalizePlaymatId($body['playmat_id'] ?? '');
    $brightness = tcgNormalizePlaymatBrightness($body['playmat_brightness'] ?? 1.0);
    if ($playmatId !== '' && !tcgOwnsPlaymat($uid, $playmatId)) {
        throw new Exception('Playmat not owned', 400);
    }
    if ($playmatId === '') {
        $brightness = 1.0;
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT slot FROM tcg_deck_presets WHERE discord_id = ? AND slot = ?');
    $stmt->execute([$uid, $slot]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('Save the deck once before setting a playmat', 400);
    }
    $db->prepare('UPDATE tcg_deck_presets SET playmat_id = ?, playmat_brightness = ?, updated_at = ?
        WHERE discord_id = ? AND slot = ?')
        ->execute([$playmatId, $brightness, time(), $uid, $slot]);
    return [
        'success' => true,
        'slot' => $slot,
        'playmat_id' => $playmatId,
        'playmat_brightness' => $brightness,
    ];
}

function tcgApiExperimentPresetList(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    require_once __DIR__ . '/experiment_decks.php';
    $db = tcgDb();
    $stmt = $db->prepare('SELECT id, slot, name, main_deck, energy_deck, sleeve_id, playmat_id, playmat_brightness, share_password, updated_at
        FROM tcg_experiment_presets WHERE discord_id = ? ORDER BY slot ASC');
    $stmt->execute([$uid]);
    $decks = [];
    $cardMap = tcgBuildCardMap(tcgLoadCardsData());
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $main = json_decode((string)$row['main_deck'], true) ?: [];
        $energy = json_decode((string)$row['energy_deck'], true) ?: [];
        $legal = tcgValidateDeckLists($main, $energy, $cardMap, null)['valid'];
        $decks[] = [
            'id' => intval($row['id']),
            'slot' => intval($row['slot']),
            'name' => normalizeExperimentDeckName((string)$row['name']),
            'main_deck' => $main,
            'energy_deck' => $energy,
            'sleeve_id' => tcgNormalizeSleeveId($row['sleeve_id'] ?? ''),
            'playmat_id' => tcgNormalizePlaymatId($row['playmat_id'] ?? ''),
            'playmat_brightness' => tcgNormalizePlaymatBrightness($row['playmat_brightness'] ?? 1.0),
            'share_password' => $row['share_password'] ? (string)$row['share_password'] : null,
            'updated_at' => intval($row['updated_at']),
            'main_count' => count($main),
            'energy_count' => count($energy),
            'legal' => $legal,
            'in_progress' => !$legal,
        ];
    }
    return [
        'success' => true,
        'decks' => $decks,
        'max_slots' => TCG_MAX_EXPERIMENT_PRESETS,
    ];
}

function tcgApiExperimentPresetSave(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    require_once __DIR__ . '/experiment_decks.php';
    $slot = intval($body['slot'] ?? 0);
    if ($slot < 1 || $slot > TCG_MAX_EXPERIMENT_PRESETS) {
        throw new Exception('Experiment deck slot must be 1–' . TCG_MAX_EXPERIMENT_PRESETS, 400);
    }
    $name = normalizeExperimentDeckName((string)($body['name'] ?? ''));
    $main = $body['main_deck'] ?? [];
    $energy = $body['energy_deck'] ?? [];
    if (!is_array($main) || !is_array($energy)) {
        throw new Exception('Invalid deck payload', 400);
    }
    $cards = tcgLoadCardsData();
    $validated = validateExperimentDeckPayload($main, $energy, $cards, true);
    $share = normalizeExperimentPassword((string)($body['share_password'] ?? ''));
    if ($share !== '' && (strlen($share) < 4 || strlen($share) > EXPERIMENT_PASSWORD_MAX)) {
        throw new Exception('Share password must be 4–' . EXPERIMENT_PASSWORD_MAX . ' letters/numbers', 400);
    }
    $db = tcgDb();
    $now = time();
    $hasSleeve = array_key_exists('sleeve_id', $body);
    $sleeveId = tcgNormalizeSleeveId($body['sleeve_id'] ?? '');
    if ($hasSleeve && $sleeveId !== '' && !tcgOwnsSleeve($uid, $sleeveId)) {
        throw new Exception('Sleeve not owned', 400);
    }
    if ($hasSleeve) {
        $db->prepare('INSERT INTO tcg_experiment_presets
            (discord_id, slot, name, main_deck, energy_deck, sleeve_id, share_password, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(discord_id, slot) DO UPDATE SET
                name = excluded.name,
                main_deck = excluded.main_deck,
                energy_deck = excluded.energy_deck,
                sleeve_id = excluded.sleeve_id,
                share_password = CASE
                    WHEN excluded.share_password IS NOT NULL AND excluded.share_password != \'\'
                    THEN excluded.share_password
                    ELSE tcg_experiment_presets.share_password
                END,
                updated_at = excluded.updated_at')
            ->execute([
                $uid,
                $slot,
                $name,
                json_encode($validated['main'], JSON_UNESCAPED_UNICODE),
                json_encode($validated['energy'], JSON_UNESCAPED_UNICODE),
                $sleeveId,
                $share !== '' ? $share : null,
                $now,
            ]);
    } else {
        $db->prepare('INSERT INTO tcg_experiment_presets
            (discord_id, slot, name, main_deck, energy_deck, sleeve_id, share_password, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(discord_id, slot) DO UPDATE SET
                name = excluded.name,
                main_deck = excluded.main_deck,
                energy_deck = excluded.energy_deck,
                share_password = CASE
                    WHEN excluded.share_password IS NOT NULL AND excluded.share_password != \'\'
                    THEN excluded.share_password
                    ELSE tcg_experiment_presets.share_password
                END,
                updated_at = excluded.updated_at')
            ->execute([
                $uid,
                $slot,
                $name,
                json_encode($validated['main'], JSON_UNESCAPED_UNICODE),
                json_encode($validated['energy'], JSON_UNESCAPED_UNICODE),
                '',
                $share !== '' ? $share : null,
                $now,
            ]);
    }
    $playmatId = '';
    $playmatBrightness = 1.0;
    if (array_key_exists('playmat_id', $body) || array_key_exists('playmat_brightness', $body)) {
        $playmatId = tcgNormalizePlaymatId($body['playmat_id'] ?? '');
        $playmatBrightness = tcgNormalizePlaymatBrightness($body['playmat_brightness'] ?? 1.0);
        if ($playmatId !== '' && !tcgOwnsPlaymat($uid, $playmatId)) {
            throw new Exception('Playmat not owned', 400);
        }
        if ($playmatId === '') {
            $playmatBrightness = 1.0;
        }
        $db->prepare('UPDATE tcg_experiment_presets SET playmat_id = ?, playmat_brightness = ?, updated_at = ?
            WHERE discord_id = ? AND slot = ?')
            ->execute([$playmatId, $playmatBrightness, $now, $uid, $slot]);
    } else {
        $stmtPm = $db->prepare('SELECT playmat_id, playmat_brightness FROM tcg_experiment_presets WHERE discord_id = ? AND slot = ?');
        $stmtPm->execute([$uid, $slot]);
        $pmRow = $stmtPm->fetch(PDO::FETCH_ASSOC) ?: [];
        $playmatId = tcgNormalizePlaymatId($pmRow['playmat_id'] ?? '');
        $playmatBrightness = tcgNormalizePlaymatBrightness($pmRow['playmat_brightness'] ?? 1.0);
    }
    return [
        'success' => true,
        'slot' => $slot,
        'name' => $name,
        'sleeve_id' => $sleeveId,
        'playmat_id' => $playmatId,
        'playmat_brightness' => $playmatBrightness,
        'main_count' => count($validated['main']),
        'energy_count' => count($validated['energy']),
    ];
}

/** Persist sleeve on an existing experiment preset without re-validating the full deck. */
function tcgApiExperimentPresetSetSleeve(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    require_once __DIR__ . '/experiment_decks.php';
    $slot = intval($body['slot'] ?? 0);
    if ($slot < 1 || $slot > TCG_MAX_EXPERIMENT_PRESETS) {
        throw new Exception('Experiment deck slot must be 1–' . TCG_MAX_EXPERIMENT_PRESETS, 400);
    }
    $sleeveId = tcgNormalizeSleeveId($body['sleeve_id'] ?? '');
    if ($sleeveId !== '' && !tcgOwnsSleeve($uid, $sleeveId)) {
        throw new Exception('Sleeve not owned', 400);
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT slot FROM tcg_experiment_presets WHERE discord_id = ? AND slot = ?');
    $stmt->execute([$uid, $slot]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('Save the experiment deck once before setting a sleeve', 400);
    }
    $db->prepare('UPDATE tcg_experiment_presets SET sleeve_id = ?, updated_at = ? WHERE discord_id = ? AND slot = ?')
        ->execute([$sleeveId, time(), $uid, $slot]);
    return ['success' => true, 'slot' => $slot, 'sleeve_id' => $sleeveId];
}

/** Persist playmat + brightness on an existing experiment preset. */
function tcgApiExperimentPresetSetPlaymat(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    require_once __DIR__ . '/experiment_decks.php';
    $slot = intval($body['slot'] ?? 0);
    if ($slot < 1 || $slot > TCG_MAX_EXPERIMENT_PRESETS) {
        throw new Exception('Experiment deck slot must be 1–' . TCG_MAX_EXPERIMENT_PRESETS, 400);
    }
    $playmatId = tcgNormalizePlaymatId($body['playmat_id'] ?? '');
    $brightness = tcgNormalizePlaymatBrightness($body['playmat_brightness'] ?? 1.0);
    if ($playmatId !== '' && !tcgOwnsPlaymat($uid, $playmatId)) {
        throw new Exception('Playmat not owned', 400);
    }
    if ($playmatId === '') {
        $brightness = 1.0;
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT slot FROM tcg_experiment_presets WHERE discord_id = ? AND slot = ?');
    $stmt->execute([$uid, $slot]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('Save the experiment deck once before setting a playmat', 400);
    }
    $db->prepare('UPDATE tcg_experiment_presets SET playmat_id = ?, playmat_brightness = ?, updated_at = ?
        WHERE discord_id = ? AND slot = ?')
        ->execute([$playmatId, $brightness, time(), $uid, $slot]);
    return [
        'success' => true,
        'slot' => $slot,
        'playmat_id' => $playmatId,
        'playmat_brightness' => $brightness,
    ];
}

function tcgApiExperimentPresetDelete(array $body): array {
    $uid = tcgRequireAuthUser($body);
    require_once __DIR__ . '/experiment_decks.php';
    $slot = intval($body['slot'] ?? 0);
    if ($slot < 1 || $slot > TCG_MAX_EXPERIMENT_PRESETS) {
        throw new Exception('Invalid experiment deck slot', 400);
    }
    tcgDb()->prepare('DELETE FROM tcg_experiment_presets WHERE discord_id = ? AND slot = ?')
        ->execute([$uid, $slot]);
    return ['success' => true, 'deleted_slot' => $slot];
}

function tcgApiExperimentPresetGet(array $body): array {
    $uid = tcgRequireAuthUser($body);
    require_once __DIR__ . '/experiment_decks.php';
    $slot = intval($body['slot'] ?? $_GET['slot'] ?? 0);
    if ($slot < 1 || $slot > TCG_MAX_EXPERIMENT_PRESETS) {
        throw new Exception('Invalid experiment deck slot', 400);
    }
    $stmt = tcgDb()->prepare('SELECT slot, name, main_deck, energy_deck, sleeve_id, playmat_id, playmat_brightness, share_password, updated_at
        FROM tcg_experiment_presets WHERE discord_id = ? AND slot = ?');
    $stmt->execute([$uid, $slot]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Experiment deck not found', 404);
    }
    $main = json_decode((string)$row['main_deck'], true) ?: [];
    $energy = json_decode((string)$row['energy_deck'], true) ?: [];
    return [
        'success' => true,
        'deck' => [
            'slot' => intval($row['slot']),
            'name' => normalizeExperimentDeckName((string)$row['name']),
            'main_deck' => $main,
            'energy_deck' => $energy,
            'sleeve_id' => tcgNormalizeSleeveId($row['sleeve_id'] ?? ''),
            'playmat_id' => tcgNormalizePlaymatId($row['playmat_id'] ?? ''),
            'playmat_brightness' => tcgNormalizePlaymatBrightness($row['playmat_brightness'] ?? 1.0),
            'share_password' => $row['share_password'] ? (string)$row['share_password'] : null,
            'updated_at' => intval($row['updated_at']),
        ],
    ];
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
    $stmt = $db->prepare('SELECT slot, main_deck, energy_deck FROM tcg_deck_presets WHERE discord_id = ? AND slot = ?');
    $stmt->execute([$uid, $slot]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Deck not found');
    }
    $main = json_decode((string)$row['main_deck'], true) ?: [];
    $energy = json_decode((string)$row['energy_deck'], true) ?: [];
    $owned = tcgGetCollectionMap($uid);
    $play = tcgValidateDeckLists($main, $energy, tcgBuildCardMap(tcgLoadCardsData()), $owned);
    if (!$play['valid']) {
        throw new Exception('This preset is in progress and cannot be equipped for ranked until it is a legal 60+12 deck');
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
    $starterKey = trim((string)($body['starter'] ?? ''));
    if ($starterKey === '') {
        $starterKey = trim((string)($user['starter_deck'] ?? ''));
    }
    if ($starterKey === '') {
        throw new Exception('No starter deck on this account');
    }
    if (!in_array($starterKey, tcgOwnedStarterKeys($uid), true)) {
        throw new Exception('You do not own that starter deck');
    }
    $cards = tcgLoadCardsData();
    $lists = tcgGetStarterDeckLists($starterKey, $cards);
    // Unlocked starters always use the official catalog list — no collection ownership gate.
    $validation = tcgValidateDeckLists(
        $lists['main_deck'],
        $lists['energy_deck'],
        tcgBuildCardMap($cards),
        null
    );
    if (!$validation['valid']) {
        throw new Exception('Starter deck is invalid: ' . implode('; ', $validation['errors']));
    }
    tcgSetRankedStarterEquip($uid, $starterKey);
    return array_merge(['success' => true], tcgFormatEquippedLoadout($body));
}

function tcgApiDeckResetStarter(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $user = tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    if (empty($user['starter_deck'])) {
        throw new Exception('No starter deck on this account', 400);
    }
    $slot = intval($body['slot'] ?? 1);
    if ($slot < 1 || $slot > TCG_MAX_DECK_PRESETS) {
        throw new Exception('Deck slot must be 1–' . TCG_MAX_DECK_PRESETS, 400);
    }
    $cards = tcgLoadCardsData();
    $lists = tcgGetStarterDeckLists($user['starter_deck'], $cards);
    $cardMap = tcgBuildCardMap($cards);
    // Official starter list — always writable; collection ownership is not required.
    $validation = tcgValidateDeckLists($lists['main_deck'], $lists['energy_deck'], $cardMap, null);
    if (!$validation['valid']) {
        throw new Exception(implode('; ', $validation['errors']), 400);
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

/**
 * Import a deck log recipe into the account deck builder.
 * Returns complete lists when the collection covers the recipe; otherwise
 * missing-card details with obtain hints and substitute suggestions.
 *
 * Body: code|url, optional substitutions: { missing_card_no: substitute_card_no|[card_no…] },
 * optional auto_sub_energy: bool (fill Energy shortfalls from other owned Energy).
 * List form = one substitute per missing copy; string form = reuse that card for every copy.
 */
function tcgApiDeckImportDecklog(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    if (function_exists('tcgRateLimitForAction')) {
        tcgRateLimitForAction('deck_import_decklog', $body);
    }
    require_once __DIR__ . '/decklog_import.php';
    $raw = trim((string)($body['code'] ?? $body['url'] ?? ''));
    // Pass raw input through so EN/JP view URLs keep preferred host (do not normalize first).
    $payload = tcgFetchDecklogView($raw);
    $code = tcgNormalizeDecklogCode($raw);
    $cards = tcgLoadCardsData();
    $mapped = tcgMapDecklogPayloadToExperimentLists($payload, $cards);
    $cardMap = tcgBuildCardMap($cards);
    $owned = tcgGetCollectionMap($uid);
    $main = $mapped['main_deck'];
    $energy = $mapped['energy_deck'];
    $name = trim($mapped['title']) !== ''
        ? $mapped['title']
        : ('deck log ' . ($mapped['deck_id'] !== '' ? $mapped['deck_id'] : $code));

    $rawSubs = $body['substitutions'] ?? [];
    $substitutions = [];
    if (is_array($rawSubs)) {
        foreach ($rawSubs as $from => $to) {
            $fromNo = trim((string)$from);
            if ($fromNo === '') {
                continue;
            }
            if (is_array($to)) {
                $list = [];
                foreach ($to as $item) {
                    $toNo = trim((string)$item);
                    if ($toNo !== '' && isset($cardMap[$toNo])) {
                        $list[] = $toNo;
                    }
                }
                if ($list) {
                    $substitutions[$fromNo] = $list;
                }
                continue;
            }
            $toNo = trim((string)$to);
            if ($toNo !== '' && isset($cardMap[$toNo])) {
                $substitutions[$fromNo] = $toNo;
            }
        }
    }

    if (!empty($body['auto_sub_energy'])) {
        $substitutions = tcgDecklogBuildAutoEnergySubstitutions(
            $main,
            $energy,
            $owned,
            $cardMap,
            $substitutions
        );
    }

    // Apply onto copies for the completeness check. Incomplete responses keep the
    // original recipe in missing[] so Energy shortfalls stay visible with pre-picks.
    $appliedMain = $main;
    $appliedEnergy = $energy;
    if ($substitutions) {
        $ownedLeft = $owned;
        [$appliedMain, $unMain] = tcgDecklogApplySubstitutionsToList($main, $ownedLeft, $substitutions);
        [$appliedEnergy, $unEnergy] = tcgDecklogApplySubstitutionsToList($energy, $ownedLeft, $substitutions);
        unset($unMain, $unEnergy);
    }

    $missingAfter = tcgDecklogMissingFromOwned($appliedMain, $appliedEnergy, $owned, $cardMap);
    $decklogCode = $mapped['deck_id'] !== '' ? $mapped['deck_id'] : $code;

    if ($missingAfter) {
        $missingShow = tcgDecklogMissingFromOwned($main, $energy, $owned, $cardMap);
        return [
            'success' => true,
            'complete' => false,
            'decklog_code' => $decklogCode,
            'name' => $name,
            'main_deck' => array_values($main),
            'energy_deck' => array_values($energy),
            'missing' => $missingShow,
            'substitutions' => $substitutions,
            'auto_sub_energy' => !empty($body['auto_sub_energy']),
            'message' => 'The following cards are missing to create this deck',
        ];
    }

    $validation = tcgValidateDeckLists($appliedMain, $appliedEnergy, $cardMap, $owned);
    if (!$validation['valid']) {
        throw new Exception(implode('; ', $validation['errors']), 400);
    }

    return [
        'success' => true,
        'complete' => true,
        'decklog_code' => $decklogCode,
        'name' => $name,
        'main_deck' => array_values($appliedMain),
        'energy_deck' => array_values($appliedEnergy),
        'validation' => $validation,
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
    $explicitGroup = $forcedGroup !== null && $forcedGroup !== '' && strcasecmp($forcedGroup, 'mixed') !== 0;
    $subunitPref = function_exists('deckgenNormalizePreferSubunit')
        ? deckgenNormalizePreferSubunit($body['subunit'] ?? '')
        : (trim((string)($body['subunit'] ?? '')) ?: null);
    // Explicit UI group filter: never silently replace with the starter loadout.
    $gen = generateCollectionDeckLists(
        $cards['cards'] ?? [],
        $owned,
        $forcedGroup,
        $explicitGroup ? null : $starterLists,
        $subunitPref
    );
    $cardMap = tcgBuildCardMap($cards);
    $validation = tcgValidateDeckLists($gen['main_deck'], $gen['energy_deck'], $cardMap, $owned);
    if (!$validation['valid']) {
        if ($explicitGroup) {
            throw new Exception(
                'Could not auto-build a legal ' . (function_exists('deckgenGroupDisplay') ? deckgenGroupDisplay($forcedGroup) : $forcedGroup)
                . ' deck from your collection: ' . implode('; ', $validation['errors'])
            );
        }
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
            'subunit' => $gen['subunit'] ?? ($subunitPref ?? ''),
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
    require_once __DIR__ . '/game_mode.php';
    $uid = tcgRequireAuthUser($body);
    $user = tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    if (empty($user['starter_deck'])) {
        throw new Exception('Choose a starter deck first', 400);
    }
    $gameMode = tcgNormalizeRankedGameMode($body['game_mode'] ?? TCG_GAME_MODE_STANDARD);
    $starterKey = trim((string)($body['starter'] ?? ''));
    if ($gameMode === TCG_GAME_MODE_RANDOMIZED) {
        // Decks are generated at room create — no equipped preset required.
    } elseif ($gameMode === TCG_GAME_MODE_STARTERS) {
        if ($starterKey === '') {
            $equippedProbe = tcgGetEquippedDeck($uid);
            $starterKey = trim((string)($equippedProbe['starter_key'] ?? ''));
        }
        if ($starterKey === '') {
            $starterKey = trim((string)($user['starter_deck'] ?? ''));
        }
        if ($starterKey === '' || !in_array($starterKey, tcgOwnedStarterKeys($uid), true)) {
            throw new Exception('Starter decks only mode requires an owned starter deck', 400);
        }
        tcgSetRankedStarterEquip($uid, $starterKey);
    } elseif ($starterKey !== '') {
        // Optional: equip a specific owned starter before standard queue.
        if (!in_array($starterKey, tcgOwnedStarterKeys($uid), true)) {
            throw new Exception('You do not own that starter deck', 400);
        }
        tcgSetRankedStarterEquip($uid, $starterKey);
    }

    if ($gameMode !== TCG_GAME_MODE_RANDOMIZED) {
        $equipped = tcgGetEquippedDeck($uid);
        if (!$equipped) {
            throw new Exception('Equip a deck preset for ranked play', 400);
        }
        if ($gameMode === TCG_GAME_MODE_STARTERS && (($equipped['source'] ?? '') !== 'starter')) {
            throw new Exception('Starter decks only mode requires a starter deck', 400);
        }
        $main = json_decode($equipped['main_deck'], true) ?: [];
        $energy = json_decode($equipped['energy_deck'], true) ?: [];
        $cards = tcgLoadCardsData();
        // Catalog starter loadouts skip collection checks (exchanged starter cards still playable).
        $ownedCheck = (($equipped['source'] ?? '') === 'starter')
            ? null
            : tcgGetCollectionMap($uid);
        $validation = tcgValidateDeckLists($main, $energy, tcgBuildCardMap($cards), $ownedCheck);
        if (!$validation['valid']) {
            tcgUnequipIllegalEquippedLoadout($uid);
            throw new Exception('Equipped deck is invalid: ' . implode('; ', $validation['errors']), 400);
        }
    }
    $active = tcgGetActiveRankedGame($uid); // sanitizes finished/missing pending rows first
    if ($active) {
        $confirmResign = !empty($body['confirm_resign']) || !empty($body['force']);
        if (!$confirmResign) {
            throw new Exception(
                'You still have an active ranked match. Finish or resign it before searching again.',
                409
            );
        }
        $left = tcgAbandonActiveRankedGame($uid, ['confirm_resign' => true]);
        if (empty($left['left'])) {
            throw new Exception(
                'Could not leave your active ranked match (it may still be live). Open it from reconnect, or resign from Options.',
                409
            );
        }
    }
    // Never queue while any pending ranked seat remains (including unsanitized duplicates).
    if (tcgDiscordIdHasPendingRankedMatch($uid)) {
        throw new Exception(
            'You still have an active ranked match. Finish or resign it before searching again.',
            409
        );
    }
    $challengeId = trim((string)($body['challenge_discord_id'] ?? ''));
    if ($challengeId !== '' && $challengeId === $uid) {
        throw new Exception('You cannot challenge yourself', 400);
    }
    $join = tcgQueueJoin($uid, $gameMode);
    if ($challengeId !== '') {
        $match = tcgTryMatchmakeWithChallenge($uid, $challengeId, $gameMode);
        if (!$match) {
            tcgQueueLeave($uid, $gameMode);
            throw new Exception('That player is no longer waiting for a ranked match', 409);
        }
        return [
            'success' => true,
            'queue' => $join,
            'match' => $match,
            'queue_stats' => tcgQueuePublicStats($gameMode),
            'game_mode' => $gameMode,
        ];
    }
    $match = tcgTryMatchmake($uid, $gameMode);
    if ($match) {
        return [
            'success' => true,
            'queue' => $join,
            'match' => $match,
            'queue_stats' => tcgQueuePublicStats($gameMode),
            'game_mode' => $gameMode,
        ];
    }
    if (function_exists('tcgPushNotifyFriendsQueued')) {
        tcgPushNotifyFriendsQueued($uid, 'ranked', $gameMode);
    }
    return [
        'success' => true,
        'queue' => $join,
        'match' => null,
        'queue_stats' => tcgQueuePublicStats($gameMode),
        'game_mode' => $gameMode,
    ];
}

function tcgApiRankedLeave(array $body): array {
    require_once __DIR__ . '/game_mode.php';
    $uid = tcgRequireAuthUser($body);
    $gameMode = isset($body['game_mode']) ? tcgNormalizeGameMode($body['game_mode']) : null;
    $left = tcgQueueLeave($uid, $gameMode);
    $statsMode = $gameMode ?? tcgNormalizeGameMode(TCG_GAME_MODE_STANDARD);
    return [
        'success' => true,
        'queue' => $left,
        'queue_stats' => tcgQueuePublicStats($statsMode),
        'game_mode' => $statsMode,
    ];
}

function tcgApiActiveGame(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $active = tcgGetActiveRankedGame($uid);
    if (!$active && function_exists('tcgGetActiveTournamentGame')) {
        $active = tcgGetActiveTournamentGame($uid);
    }
    return ['success' => true, 'active' => $active];
}

/**
 * VPS finish webhook → Hostinger Elo/PR (secret-gated, no user session).
 *
 * @param array<string,mixed> $body
 * @return array<string,mixed>
 */
function tcgApiRankedApplyResult(array $body): array {
    require_once __DIR__ . '/match_bridge.php';
    tcgRequireInternalMatchSecret();
    return tcgApplyRankedResultFromWebhook($body);
}

/**
 * Credit daily_use_stamp on Hostinger.
 * Accepts either the VPS internal secret + discord_id, or a signed-in user token.
 *
 * @param array<string,mixed> $body
 * @return array<string,mixed>
 */
function tcgApiMissionStampSent(array $body): array {
    require_once __DIR__ . '/match_bridge.php';
    require_once __DIR__ . '/missions.php';
    $uid = '';
    if (tcgInternalMatchSecretOk(tcgRequestInternalMatchSecret())) {
        $uid = trim((string)($body['discord_id'] ?? ''));
        if ($uid === '') {
            throw new Exception('discord_id required', 400);
        }
    } else {
        $uid = tcgRequireAuthUser($body);
    }
    tcgEnsureUser($uid);
    $completions = tcgMissionOnStampSent($uid);
    return [
        'success' => true,
        'mission_completions' => $completions,
        'missions' => tcgMissionSummaryForUser($uid),
    ];
}

/**
 * Credit finish missions (group wins, unranked thresholds, etc.) on Hostinger.
 * VPS match-primary posts here; ranked overflow uses ranked_apply_result instead.
 *
 * @param array<string,mixed> $body
 * @return array<string,mixed>
 */
function tcgApiMissionGameFinished(array $body): array {
    require_once __DIR__ . '/match_bridge.php';
    require_once __DIR__ . '/missions.php';
    require_once __DIR__ . '/coins.php';
    require_once __DIR__ . '/play_stats.php';
    tcgRequireInternalMatchSecret();
    $playersIn = is_array($body['players'] ?? null) ? $body['players'] : [];
    $p1 = tcgMissionPlayerSlim(is_array($playersIn['p1'] ?? null) ? $playersIn['p1'] : null);
    $p2 = tcgMissionPlayerSlim(is_array($playersIn['p2'] ?? null) ? $playersIn['p2'] : null);
    $state = [
        'room_id' => (string)($body['room_id'] ?? ''),
        'mode' => (string)($body['mode'] ?? ''),
        'status' => 'finished',
        'winner' => $body['winner'] ?? null,
        'end_reason' => $body['end_reason'] ?? null,
        'resigned_by' => $body['resigned_by'] ?? null,
        'disconnected_player' => $body['disconnected_player'] ?? null,
        'cpu_solo' => !empty($body['cpu_solo']),
        'cpu_difficulty' => (string)($body['cpu_difficulty'] ?? ''),
        'turn' => intval($body['turn'] ?? 0),
        '_mission_peaks' => is_array($body['mission_peaks'] ?? null) ? $body['mission_peaks'] : [],
        'players' => [
            'p1' => $p1,
            'p2' => $p2,
        ],
    ];
    if (is_array($body['ranked'] ?? null)) {
        $state['ranked'] = $body['ranked'];
    }
    $deltas = is_array($body['play_stat_deltas'] ?? null) ? $body['play_stat_deltas'] : [];
    if ($deltas !== []) {
        tcgApplyPlayStatDeltasOnce((string)($state['room_id'] ?? ''), $deltas);
    }
    $completions = tcgMissionOnGameFinished($state);
    $coinGrants = tcgCoinsOnGameFinished($state);
    $mode = strtolower((string)($state['mode'] ?? ''));
    if (empty($state['cpu_solo']) && $mode !== 'cpu' && !str_contains($mode, 'cpu')) {
        $p1Id = (string)($p1['discord_id'] ?? '');
        $p2Id = (string)($p2['discord_id'] ?? '');
        $winnerPid = $state['winner'] ?? null;
        $winnerId = $winnerPid === 'p1' ? $p1Id : ($winnerPid === 'p2' ? $p2Id : null);
        if (function_exists('tcgRecordPvpResult')) {
            tcgRecordPvpResult((string)($state['room_id'] ?? ''), $mode !== '' ? $mode : 'casual', $p1Id, $p2Id, $winnerId);
        }
    }
    return [
        'success' => true,
        'mission_completions' => $completions,
        'coin_grants' => $coinGrants,
    ];
}

function tcgApiLeaveActiveGame(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $result = tcgAbandonActiveRankedGame($uid, [
        'confirm_resign' => !empty($body['confirm_resign']) || !empty($body['force']),
    ]);
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
        $seatDiscord = $state['players'][$playerId]['discord_id'] ?? null;
        if ($seatDiscord !== null && (string)$seatDiscord !== '' && (string)$seatDiscord !== $uid) {
            throw new Exception('This replay belongs to a different account', 403);
        }
        return;
    }
    $expected = $ranked[$playerId . '_discord_id'] ?? null;
    if ($expected !== null && (string)$expected !== $uid) {
        throw new Exception('This ranked replay belongs to a different account', 403);
    }
}

/** Ownership checks when saving a client/VPS-exported replay payload (no local room file). */
function tcgAssertReplaySaveAllowedFromPayload(string $uid, array $payload): void {
    $saver = (string)($payload['meta']['saver_player_id'] ?? '');
    if ($saver !== 'p1' && $saver !== 'p2') {
        throw new Exception('Replay missing saver_player_id', 400);
    }
    $baseline = $payload['baseline'] ?? [];
    if (!is_array($baseline)) {
        throw new Exception('Replay missing baseline', 400);
    }
    $ranked = $baseline['ranked'] ?? null;
    if (is_array($ranked)) {
        $expected = $ranked[$saver . '_discord_id'] ?? null;
        if ($expected !== null && (string)$expected !== $uid) {
            throw new Exception('This ranked replay belongs to a different account', 403);
        }
        return;
    }
    $seatDiscord = $baseline['players'][$saver]['discord_id'] ?? null;
    if ($seatDiscord !== null && (string)$seatDiscord !== '' && (string)$seatDiscord !== $uid) {
        throw new Exception('This replay belongs to a different account', 403);
    }
}

function tcgReplayOpponentNameFromPayload(array $payload): string {
    $saver = (string)($payload['meta']['saver_player_id'] ?? 'p1');
    $opp = ($saver === 'p1') ? 'p2' : 'p1';
    return (string)($payload['baseline']['players'][$opp]['name'] ?? $opp);
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
    $payload = replayPayloadDecodeFromStorage((string)($row['payload_json'] ?? ''));
    validateReplayFile($payload);
    $upgraded = ensureReplayPayloadV2($payload);
    // Lazy rewrite v1 → v2 (and gzip large payloads) back into SQLite.
    if (intval($payload['schema_version'] ?? 0) < REPLAY_SCHEMA_VERSION
        || !isset($payload['frames'])
        || empty($payload['full_log'])
        || empty($payload['log_ends'])
        || (is_array($upgraded['frames'] ?? null)
            && count($upgraded['frames']) !== count($payload['frames'] ?? []))) {
        $id = intval($row['id'] ?? 0);
        $uid = (string)($row['discord_id'] ?? '');
        if ($id > 0 && $uid !== '') {
            try {
                $encoded = replayPayloadEncodeForStorage($upgraded);
                tcgDb()->prepare('UPDATE tcg_replays SET payload_json = ? WHERE id = ? AND discord_id = ?')
                    ->execute([$encoded, $id, $uid]);
            } catch (Throwable $e) {
                // Still return upgraded payload even if rewrite fails.
            }
        }
    }
    return $upgraded;
}

/** Standard ranked rating high enough that finished games feed the CPU policy corpus. */
function tcgReplaySaverKeepsRankedCorpus(string $uid): bool
{
    if ($uid === '') {
        return false;
    }
    if (!class_exists('CpuPolicy', false)) {
        $path = __DIR__ . '/src/Game/CpuPolicy.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
    $floor = class_exists('CpuPolicy') ? CpuPolicy::RANK_FLOOR : 1100;
    $minGames = class_exists('CpuPolicy') ? CpuPolicy::RANK_MIN_GAMES : 8;
    try {
        $stmt = tcgDb()->prepare(
            'SELECT rating, games FROM tcg_rank WHERE discord_id = ? AND game_mode = ? LIMIT 1'
        );
        $stmt->execute([$uid, defined('TCG_GAME_MODE_STANDARD') ? TCG_GAME_MODE_STANDARD : 'standard']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
    if (!$row) {
        return false;
    }
    return intval($row['games'] ?? 0) >= $minGames && intval($row['rating'] ?? 0) >= $floor;
}

function tcgReplayRoomIsRanked(string $roomId): bool
{
    if ($roomId === '') {
        return false;
    }
    try {
        $stmt = tcgDb()->prepare('SELECT 1 FROM tcg_ranked_matches WHERE room_id = ? LIMIT 1');
        $stmt->execute([$roomId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Keep at most $keep non-preserved (autosave) rows per user; oldest deleted.
 * Tournament-archived rooms are excluded so a mid-event FIFO trim cannot drop
 * a round while later rounds still land in Recent.
 * Ranked rooms for the policy corpus are trimmed separately (larger keep).
 */
function tcgReplayTrimAutosaves(string $uid, int $keep = 10): void {
    $keep = max(1, min(50, $keep));
    $rankedKeep = class_exists('CpuPolicy') ? CpuPolicy::RANKED_AUTOSAVE_KEEP : 40;
    $protectRanked = tcgReplaySaverKeepsRankedCorpus($uid);
    $db = tcgDb();
    $hasTournamentTable = false;
    try {
        $chk = $db->query(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name='tcg_tournament_replays' LIMIT 1"
        );
        $hasTournamentTable = (bool)($chk && $chk->fetchColumn());
    } catch (Throwable $e) {
        $hasTournamentTable = false;
    }
    $rankedExclude = '';
    if ($protectRanked) {
        $rankedExclude = ' AND NOT EXISTS (
                 SELECT 1 FROM tcg_ranked_matches rm WHERE rm.room_id = r.room_id
               )';
    }
    if ($hasTournamentTable) {
        $stmt = $db->prepare(
            'SELECT r.id FROM tcg_replays r
             WHERE r.discord_id = ? AND COALESCE(r.preserved, 0) = 0
               AND NOT EXISTS (
                 SELECT 1 FROM tcg_tournament_replays t WHERE t.room_id = r.room_id
               )' . $rankedExclude . '
             ORDER BY r.saved_at DESC, r.id DESC'
        );
    } else {
        $stmt = $db->prepare(
            'SELECT r.id FROM tcg_replays r
             WHERE r.discord_id = ? AND COALESCE(r.preserved, 0) = 0' . $rankedExclude . '
             ORDER BY r.saved_at DESC, r.id DESC'
        );
    }
    $stmt->execute([$uid]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $del = $db->prepare('DELETE FROM tcg_replays WHERE id = ? AND discord_id = ? AND COALESCE(preserved, 0) = 0');
    if (count($ids) > $keep) {
        foreach (array_slice($ids, $keep) as $id) {
            $del->execute([$id, $uid]);
        }
    }
    if (!$protectRanked) {
        return;
    }
    try {
        $rk = $db->prepare(
            'SELECT r.id FROM tcg_replays r
             WHERE r.discord_id = ? AND COALESCE(r.preserved, 0) = 0
               AND EXISTS (SELECT 1 FROM tcg_ranked_matches rm WHERE rm.room_id = r.room_id)
             ORDER BY r.saved_at DESC, r.id DESC'
        );
        $rk->execute([$uid]);
        $rankedIds = array_map('intval', $rk->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return;
    }
    if (count($rankedIds) <= $rankedKeep) {
        return;
    }
    foreach (array_slice($rankedIds, $rankedKeep) as $id) {
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

/** True when a library row exists but cannot be played back (failed partial save). */
function tcgReplayRowNeedsRepair(array $row): bool {
    if (intval($row['action_count'] ?? 0) <= 0) {
        return true;
    }
    $raw = trim((string)($row['payload_json'] ?? ''));
    if ($raw === '') {
        return true;
    }
    try {
        $payload = replayPayloadDecodeFromStorage($raw);
        validateReplayFile($payload);
        if (count($payload['actions'] ?? []) === 0) {
            return true;
        }
        $actions = $payload['actions'] ?? [];
        $frames = $payload['frames'] ?? [];
        if (is_array($frames) && $frames !== []
            && count($frames) !== count($actions) + 1) {
            return true;
        }
    } catch (Throwable $e) {
        return true;
    }
    return false;
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

    $existing = tcgReplayFindOwnedByRoom($uid, $roomId);
    if ($existing && tcgReplayRowNeedsRepair($existing)) {
        tcgDb()->prepare('DELETE FROM tcg_replays WHERE id = ? AND discord_id = ?')
            ->execute([intval($existing['id']), $uid]);
        $existing = null;
    }
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

    // Match-primary: finished rooms live on VPS Redis/disk. Prefer a client-exported
    // payload (already pulled from the match origin), else fetch replay_export from overflow.
    $payload = null;
    $playerId = null;
    $winner = null;
    $endReason = null;
    $state = null;
    $fromOverflowExport = false;
    $clientReplay = $body['replay'] ?? null;
    if (is_array($clientReplay) && $clientReplay !== []) {
        validateReplayFile($clientReplay);
        $metaRoom = strtoupper(trim((string)($clientReplay['meta']['room_id'] ?? '')));
        if ($metaRoom !== '' && $metaRoom !== $roomId) {
            throw new Exception('Replay room mismatch', 400);
        }
        tcgAssertReplaySaveAllowedFromPayload($uid, $clientReplay);
        // Slim v1 transfer (no frames) or legacy full v2 — convert once on Hostinger.
        $payload = ensureReplayPayloadV2($clientReplay);
        $playerId = (string)($payload['meta']['saver_player_id'] ?? '');
        $winner = $payload['baseline']['winner'] ?? ($payload['frames'][count($payload['frames'] ?? []) - 1]['winner'] ?? null);
        $endReason = $payload['baseline']['end_reason'] ?? null;
        $fromOverflowExport = true; // client pulled from match API; snapshot can go
    } else {
        $state = loadGame($roomId);
        if ($state) {
            $playerId = getPlayerIdByToken($state, $token);
            if (!$playerId) {
                throw new Exception('Invalid player token', 403);
            }
            tcgAssertReplaySaveAllowedForAccount($uid, $state, $playerId);
            if (($state['status'] ?? '') !== 'finished') {
                throw new Exception('Replay can only be saved after the match finishes', 400);
            }
            $payload = buildReplayExportPayload($state, $playerId);
            $winner = $state['winner'] ?? null;
            $endReason = $state['end_reason'] ?? null;
        } else {
            require_once __DIR__ . '/match_bridge.php';
            $payload = tcgFetchOverflowReplayExportWithRetry($roomId, $token);
            validateReplayFile($payload);
            tcgAssertReplaySaveAllowedFromPayload($uid, $payload);
            $payload = ensureReplayPayloadV2($payload);
            $playerId = (string)($payload['meta']['saver_player_id'] ?? '');
            $winner = $payload['baseline']['winner'] ?? null;
            $endReason = $payload['baseline']['end_reason'] ?? null;
            $frames = is_array($payload['frames'] ?? null) ? $payload['frames'] : [];
            if (($winner === null || $winner === '') && $frames !== []) {
                $last = $frames[count($frames) - 1];
                if (is_array($last)) {
                    $winner = $last['winner'] ?? $winner;
                    $endReason = $last['end_reason'] ?? $endReason;
                }
            }
            $fromOverflowExport = true;
        }
    }
    validateReplayFile($payload);
    if (count($payload['actions'] ?? []) === 0) {
        throw new Exception('No recorded actions yet', 400);
    }
    if ($playerId !== 'p1' && $playerId !== 'p2') {
        throw new Exception('Invalid saver player', 400);
    }
    $payloadJson = replayPayloadEncodeForStorage($payload);

    $meta = $payload['meta'] ?? [];
    $db = tcgDb();
    $now = time();
    $preserved = $wantPreserve ? 1 : 0;
    $opponentName = isset($state) && is_array($state)
        ? tcgReplayOpponentName($state, $playerId)
        : tcgReplayOpponentNameFromPayload($payload);
    $db->prepare('INSERT INTO tcg_replays (
            discord_id, room_id, saver_player_id, saver_name, opponent_name, winner, end_reason,
            turn, phase, action_count, duration_seconds, payload_json, saved_at, preserved
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $uid,
            (string)($meta['room_id'] ?? $roomId),
            $playerId,
            (string)($meta['saver_name'] ?? $playerId),
            $opponentName,
            $winner,
            $endReason,
            intval($meta['turn'] ?? ($payload['baseline']['turn'] ?? 0)),
            (string)($meta['phase'] ?? ($payload['baseline']['phase'] ?? '')),
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
    if ($fromOverflowExport) {
        require_once __DIR__ . '/match_bridge.php';
        if (function_exists('tcgNotifyOverflowDeleteGameSnapshot')) {
            tcgNotifyOverflowDeleteGameSnapshot($roomId);
        }
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
    require_once __DIR__ . '/game_mode.php';
    $uid = tcgRequireAuthUser($body);
    $status = tcgQueueStatus($uid);
    // accountGet sends game_mode as a query param (body is empty on GET).
    $gameMode = tcgRankedStatusStatsGameMode($status, $body, $_GET);
    if (($status['status'] ?? '') === 'searching') {
        $match = tcgTryMatchmake($uid, $gameMode);
        if ($match) {
            $status = tcgQueueStatus($uid);
            $gameMode = tcgRankedStatusStatsGameMode($status, $body, $_GET);
        }
    }
    $includeStats = true;
    if (array_key_exists('include_stats', $_GET)) {
        $includeStats = filter_var($_GET['include_stats'], FILTER_VALIDATE_BOOLEAN);
    } elseif (array_key_exists('include_stats', $body)) {
        $includeStats = filter_var($body['include_stats'], FILTER_VALIDATE_BOOLEAN);
    }
    $out = ['success' => true, 'ranked' => $status, 'game_mode' => $gameMode];
    if ($includeStats) {
        $out['queue_stats'] = tcgQueuePublicStats($gameMode);
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

/**
 * Decode banner/card wire forms. Prefer card_no_b64 so Hostinger WAF is less
 * likely to trip on "!" inside JSON bodies (PL!SP-… / PL!HS-…).
 */
function tcgDecodeCardNoFromBody(array $body): string {
    $b64 = trim((string)($body['card_no_b64'] ?? ''));
    if ($b64 !== '') {
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode(strtr($b64, '-_', '+/'), true);
        if (is_string($raw) && $raw !== '') {
            return trim($raw);
        }
    }
    $plain = trim((string)($body['card_no'] ?? ''));
    if ($plain !== '' && class_exists('Normalizer')) {
        $norm = \Normalizer::normalize($plain, \Normalizer::FORM_KC);
        if (is_string($norm) && $norm !== '') {
            $plain = $norm;
        }
    }
    // Fullwidth exclamation (！) → ASCII ! for catalog / collection keys.
    return str_replace("\u{FF01}", '!', $plain);
}

/** Normalize IANA timezone; default Asia/Tokyo (JST). */
function tcgNormalizePreferredTimezone(?string $tz): string {
    $tz = trim((string)$tz);
    if ($tz === '') {
        return 'Asia/Tokyo';
    }
    try {
        new DateTimeZone($tz);
        return $tz;
    } catch (Throwable $e) {
        return 'Asia/Tokyo';
    }
}

function tcgApiTimezoneSet(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $tz = tcgNormalizePreferredTimezone($body['timezone'] ?? $body['preferred_timezone'] ?? null);
    tcgDb()->prepare('UPDATE tcg_users SET preferred_timezone = ?, updated_at = ? WHERE discord_id = ?')
        ->execute([$tz, time(), $uid]);
    return ['success' => true, 'preferred_timezone' => $tz];
}

function tcgApiRankBannerSet(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $profile = tcgAuthUserProfile($uid);
    $user = tcgEnsureUser($uid, $profile);
    $cardNo = tcgDecodeCardNoFromBody($body);
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
    if ($crop === null) {
        throw new Exception('Invalid crop — use normalized x,y,w,h (0–1)');
    }
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
    require_once __DIR__ . '/game_mode.php';
    $uid = tcgRequireAuthUser($body);
    $profile = tcgAuthUserProfile($uid);
    $user = tcgEnsureUser($uid, $profile);
    $gameMode = tcgNormalizeGameMode($body['game_mode'] ?? $_GET['game_mode'] ?? TCG_GAME_MODE_STANDARD);
    $rank = tcgRankRow($uid, $gameMode);
    $cards = tcgLoadCardsData();
    $db = tcgDb();
    $stmt = $db->prepare('SELECT r.discord_id, r.rating, r.wins, r.losses, r.draws, r.games, r.game_mode,
            u.username, u.avatar_url, u.banner_card_no, u.banner_crop, u.equipped_flag, u.stamp_favorites
        FROM tcg_rank r
        JOIN tcg_users u ON u.discord_id = r.discord_id
        WHERE r.games > 0 AND r.game_mode = ?
        ORDER BY r.rating DESC, r.wins DESC');
    $stmt->execute([$gameMode]);
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
        'game_mode' => $gameMode,
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
function tcgTryMatchmake(string $discordId, string $gameMode = TCG_GAME_MODE_STANDARD): ?array {
    require_once __DIR__ . '/game_mode.php';
    $gameMode = tcgNormalizeGameMode($gameMode);
    $db = tcgDb();
    $stmt = $db->prepare('SELECT rating, game_mode FROM tcg_match_queue WHERE discord_id = ? AND game_mode = ?');
    $stmt->execute([$discordId, $gameMode]);
    $self = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$self) {
        return null;
    }
    $opp = tcgFindQueueOpponent($discordId, intval($self['rating']), $gameMode);
    if (!$opp) {
        return null;
    }
    $oppId = $opp['discord_id'];
    if ($oppId === $discordId) {
        return null;
    }

    return tcgFinalizeRankedPair($discordId, $oppId, $gameMode);
}

/** Pair specifically with a challenged player who is still in the ranked queue. */
function tcgTryMatchmakeWithChallenge(
    string $discordId,
    string $challengeDiscordId,
    string $gameMode = TCG_GAME_MODE_STANDARD
): ?array {
    require_once __DIR__ . '/game_mode.php';
    $gameMode = tcgNormalizeGameMode($gameMode);
    if ($challengeDiscordId === '' || $challengeDiscordId === $discordId) {
        return null;
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT discord_id FROM tcg_match_queue WHERE discord_id = ? AND game_mode = ?');
    $stmt->execute([$challengeDiscordId, $gameMode]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        return null;
    }
    $stmt = $db->prepare('SELECT discord_id FROM tcg_match_queue WHERE discord_id = ? AND game_mode = ?');
    $stmt->execute([$discordId, $gameMode]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        return null;
    }
    return tcgFinalizeRankedPair($discordId, $challengeDiscordId, $gameMode);
}

function tcgFinalizeRankedPair(string $discordId, string $oppId, string $gameMode = TCG_GAME_MODE_STANDARD): ?array {
    require_once __DIR__ . '/game_mode.php';
    $gameMode = tcgNormalizeGameMode($gameMode);
    // Defense in depth: never create a second room for someone already seated.
    if (tcgDiscordIdHasPendingRankedMatch($discordId)) {
        tcgQueueLeave($discordId);
        return null;
    }
    if (tcgDiscordIdHasPendingRankedMatch($oppId)) {
        tcgQueueLeave($oppId);
        return null;
    }
    require_once __DIR__ . '/ranked_room.php';
    $pair = tcgCreateRankedRoomPair($discordId, $oppId, $gameMode);
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
        'game_mode' => $gameMode,
        'match_api' => (string)($pair['match_api'] ?? 'overflow'),
    ];
}

/** Public ranked leaderboard for Discord /loveca (no auth). Optional limit; omit or 0 = full board. */
function tcgApiPublicLeaderboard(array $params): array {
    require_once __DIR__ . '/game_mode.php';
    $gameMode = tcgNormalizeGameMode($params['game_mode'] ?? TCG_GAME_MODE_STANDARD);
    $limit = 0;
    if (array_key_exists('limit', $params) && $params['limit'] !== '' && $params['limit'] !== null) {
        $limit = intval($params['limit']);
    }
    if ($limit < 0) {
        $limit = 0;
    }
    $db = tcgDb();
    $sql = 'SELECT r.discord_id, r.rating, r.wins, r.losses, r.draws, r.games, u.username
         FROM tcg_rank r
         JOIN tcg_users u ON u.discord_id = r.discord_id
         WHERE r.games > 0 AND r.game_mode = ?
         ORDER BY r.rating DESC, r.wins DESC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([$gameMode]);
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
        'game_mode' => $gameMode,
        'limit' => $limit > 0 ? $limit : null,
        'leaderboard' => $leaderboard,
    ];
}

/** Operator batch repair for tcg_users rows stuck on placeholder Discord names. */
function tcgApiRepairPlaceholderUsernames(array $body): array {
    $secret = trim((string)($body['secret'] ?? $_SERVER['HTTP_X_TCG_INTERNAL_SECRET'] ?? ''));
    $expected = '';
    $local = __DIR__ . '/tcg_sync.local.php';
    if (is_file($local)) {
        require_once $local;
    }
    if (defined('TCG_INTERNAL_MATCH_SECRET')) {
        $expected = trim((string)TCG_INTERNAL_MATCH_SECRET);
    }
    if ($expected === '' || $secret === '' || !hash_equals($expected, $secret)) {
        throw new Exception('forbidden', 403);
    }
    require_once __DIR__ . '/auth_profile.php';

    $manual = $body['updates'] ?? null;
    if (is_array($manual) && $manual !== []) {
        return array_merge(['success' => true], tcgApplyUsernameUpdates($manual));
    }

    $result = tcgRepairPlaceholderUsernames(50);
    return array_merge(['success' => true], $result);
}

/**
 * @param list<array{discord_id?:string,user_id?:string,username:string,avatar_url?:?string}> $updates
 * @return array{applied:int,skipped:int}
 */
function tcgApplyUsernameUpdates(array $updates): array
{
    $db = tcgDb();
    $now = time();
    $applied = 0;
    $skipped = 0;
    foreach ($updates as $row) {
        if (!is_array($row)) {
            $skipped++;
            continue;
        }
        $uid = trim((string)($row['discord_id'] ?? $row['user_id'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));
        if ($uid === '' || !preg_match('/^\d{5,32}$/', $uid) || tcgIsPlaceholderUsername($username)) {
            $skipped++;
            continue;
        }
        $avatar = array_key_exists('avatar_url', $row) ? ($row['avatar_url'] ?? null) : null;
        $stmt = $db->prepare('UPDATE tcg_users SET username = ?, avatar_url = COALESCE(?, avatar_url), updated_at = ? WHERE discord_id = ?');
        $stmt->execute([$username, $avatar, $now, $uid]);
        if ($stmt->rowCount() > 0) {
            $applied++;
        } else {
            $skipped++;
        }
    }
    return ['applied' => $applied, 'skipped' => $skipped];
}

/** Public Loveca profile for Discord /loveca profile (no auth). */
function tcgApiPublicProfile(array $params): array {
    $discordId = trim((string)($params['discord_id'] ?? ''));
    $usernames = [];
    if (isset($params['username']) && is_string($params['username'])) {
        $usernames[] = $params['username'];
    }
    if (isset($params['usernames'])) {
        $raw = $params['usernames'];
        if (is_string($raw)) {
            foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
                if ($part !== '') {
                    $usernames[] = $part;
                }
            }
        } elseif (is_array($raw)) {
            foreach ($raw as $part) {
                if (is_string($part) && $part !== '') {
                    $usernames[] = $part;
                }
            }
        }
    }
    // Also accept Discord global_name / display_name aliases from the bot.
    foreach (['global_name', 'display_name'] as $aliasKey) {
        if (!empty($params[$aliasKey]) && is_string($params[$aliasKey])) {
            $usernames[] = $params[$aliasKey];
        }
    }

    if ($discordId === '' && empty($usernames)) {
        throw new Exception('discord_id or username required', 400);
    }
    if ($discordId !== '' && !preg_match('/^\d{5,32}$/', $discordId) && empty($usernames)) {
        throw new Exception('discord_id required', 400);
    }

    $user = tcgFindUserForPublicProfile($discordId, $usernames);
    if (!$user) {
        throw new Exception('Player not found', 404);
    }
    $discordId = (string)($user['discord_id'] ?? $discordId);

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
    // Only inspect rooms tracked by casual matchmaking. Scanning every games/*.json
    // made public_profile take 15–30s and caused Discord /loveca profile to time out
    // (bot HTTP budget ~12s) for every account — including non-leaderboard players.
    $db = tcgDb();
    $stmt = $db->query(
        'SELECT DISTINCT room_id FROM tcg_casual_matches ORDER BY created_at DESC LIMIT 40'
    );
    $roomIds = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
    foreach ($roomIds as $roomIdRaw) {
        $roomId = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$roomIdRaw));
        if ($roomId === '') {
            continue;
        }
        $path = GAMES_DIR . $roomId . '.json';
        if (!is_file($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
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

function tcgApiOwnedSleeves(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    return [
        'success' => true,
        'coins' => tcgGetCoins($uid),
        'owned_sleeves' => tcgOwnedSleeveIds($uid),
        'sleeves_need_intro' => tcgOwnedSleevesNeedingIntro($uid),
        'free_sleeve_claims' => tcgGetFreeSleeveClaims($uid),
        'sleeve_shop_price' => TCG_SLEEVE_SHOP_PRICE,
    ];
}

function tcgApiSleeveShopCatalog(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    tcgTouchLoginDays($uid);
    tcgMissionCheckLoginDays($uid);

    $catalog = tcgLoadSleeveCatalog();
    $owned = array_fill_keys(tcgOwnedSleeveIds($uid), true);
    $portraits = tcgLoadIdolPortraits();
    $portraitById = [];
    foreach ($portraits as $p) {
        $portraitById[strtolower($p['id'])] = $p;
    }

    $byUnit = [];
    foreach ($catalog as $sleeve) {
        $unit = tcgSleeveShopUnitForGroup($sleeve['group']);
        $idol = trim((string)$sleeve['idol']) ?: 'Other';
        if (!isset($byUnit[$unit])) {
            $byUnit[$unit] = [];
        }
        if (!isset($byUnit[$unit][$idol])) {
            $byUnit[$unit][$idol] = [];
        }
        $byUnit[$unit][$idol][] = [
            'id' => $sleeve['id'],
            'name' => $sleeve['name'],
            'src' => $sleeve['src'],
            'orientation' => ($sleeve['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait',
            'owned' => isset($owned[$sleeve['id']]),
            'price' => TCG_SLEEVE_SHOP_PRICE,
            'added_at' => (string)($sleeve['added_at'] ?? ''),
        ];
    }

    $generations = [];
    foreach (tcgSleeveShopGenerationOrder() as $unit) {
        if (empty($byUnit[$unit])) {
            continue;
        }
        $chars = [];
        $groupSleeves = [];
        foreach ($byUnit[$unit] as $idol => $sleeves) {
            if (tcgSleeveShopIsGroupIdol((string)$idol, $unit)) {
                foreach ($sleeves as $row) {
                    $groupSleeves[] = $row;
                }
                continue;
            }
            $key = strtolower($idol);
            $port = $portraitById[$key] ?? null;
            $chars[] = [
                'id' => $idol,
                'name' => $port['name'] ?? $idol,
                'portrait' => $port['portrait'] ?? '',
                'icon' => $port['portrait'] ?? '',
                'is_group' => false,
                'sleeve_count' => count($sleeves),
                'sleeves' => $sleeves,
            ];
        }
        if ($groupSleeves !== []) {
            array_unshift($chars, [
                'id' => $unit,
                'name' => $unit,
                'portrait' => '',
                'icon' => tcgSleeveShopUnitIconUrl($unit),
                'is_group' => true,
                'sleeve_count' => count($groupSleeves),
                'sleeves' => $groupSleeves,
            ]);
        }
        $groupFirst = [];
        $rest = [];
        foreach ($chars as $c) {
            if (!empty($c['is_group'])) {
                $groupFirst[] = $c;
            } else {
                $rest[] = $c;
            }
        }
        usort($rest, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $chars = array_merge($groupFirst, $rest);
        $generations[] = [
            'id' => $unit,
            'name' => $unit,
            'icon' => tcgSleeveShopUnitIconUrl($unit),
            'characters' => $chars,
        ];
    }

    return [
        'success' => true,
        'coins' => tcgGetCoins($uid),
        'free_sleeve_claims' => tcgGetFreeSleeveClaims($uid),
        'sleeve_shop_price' => TCG_SLEEVE_SHOP_PRICE,
        'owned_sleeves' => array_keys($owned),
        'sleeves_need_intro' => tcgOwnedSleevesNeedingIntro($uid),
        'generations' => $generations,
        'default_back' => 'lltcg-back.png',
    ];
}

function tcgApiSleeveBuy(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $sleeveId = tcgNormalizeSleeveId($body['sleeve_id'] ?? '');
    if ($sleeveId === '' || !tcgSleeveCatalogIdValid($sleeveId)) {
        throw new Exception('Unknown sleeve', 400);
    }
    if (tcgOwnsSleeve($uid, $sleeveId)) {
        throw new Exception('Sleeve already owned', 400);
    }
    tcgDeductCoins($uid, TCG_SLEEVE_SHOP_PRICE);
    tcgGrantOwnedSleeve($uid, $sleeveId, 'shop');
    return [
        'success' => true,
        'sleeve_id' => $sleeveId,
        'coins' => tcgGetCoins($uid),
        'owned_sleeves' => tcgOwnedSleeveIds($uid),
        'free_sleeve_claims' => tcgGetFreeSleeveClaims($uid),
    ];
}

function tcgApiSleeveClaimFree(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $sleeveId = tcgNormalizeSleeveId($body['sleeve_id'] ?? '');
    if ($sleeveId === '' || !tcgSleeveCatalogIdValid($sleeveId)) {
        throw new Exception('Unknown sleeve', 400);
    }
    if (tcgOwnsSleeve($uid, $sleeveId)) {
        throw new Exception('Sleeve already owned', 400);
    }
    $claims = tcgGetFreeSleeveClaims($uid);
    if ($claims < 1) {
        throw new Exception('No free sleeve claim available', 400);
    }
    tcgSetFreeSleeveClaims($uid, $claims - 1);
    tcgGrantOwnedSleeve($uid, $sleeveId, 'free_milestone');
    return [
        'success' => true,
        'sleeve_id' => $sleeveId,
        'coins' => tcgGetCoins($uid),
        'owned_sleeves' => tcgOwnedSleeveIds($uid),
        'free_sleeve_claims' => tcgGetFreeSleeveClaims($uid),
    ];
}

function tcgApiSleeveEquipIntroSeen(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $sleeveId = tcgNormalizeSleeveId($body['sleeve_id'] ?? '');
    if ($sleeveId === '' || !tcgOwnsSleeve($uid, $sleeveId)) {
        throw new Exception('Sleeve not owned', 400);
    }
    tcgMarkSleeveEquipIntroSeen($uid, $sleeveId);
    return ['success' => true, 'sleeve_id' => $sleeveId];
}

function tcgApiOwnedPlaymats(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    return [
        'success' => true,
        'coins' => tcgGetCoins($uid),
        'owned_playmats' => tcgOwnedPlaymatIds($uid),
        'playmat_shop_price' => TCG_PLAYMAT_SHOP_PRICE,
    ];
}

function tcgApiPlaymatShopCatalog(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    tcgTouchLoginDays($uid);

    $catalog = tcgLoadPlaymatCatalog();
    $owned = array_fill_keys(tcgOwnedPlaymatIds($uid), true);
    $portraits = tcgLoadIdolPortraits();
    $portraitById = [];
    $portraitsByUnit = [];
    foreach ($portraits as $p) {
        $portraitById[strtolower($p['id'])] = $p;
        $unit = tcgSleeveShopUnitForGroup((string)($p['unit'] ?? 'Other'));
        $portraitsByUnit[$unit][] = $p;
    }

    $byUnit = [];
    foreach ($catalog as $mat) {
        $unit = tcgSleeveShopUnitForGroup($mat['group']);
        $idol = trim((string)$mat['idol']) ?: 'Group';
        if (!isset($byUnit[$unit])) {
            $byUnit[$unit] = [];
        }
        if (!isset($byUnit[$unit][$idol])) {
            $byUnit[$unit][$idol] = [];
        }
        $byUnit[$unit][$idol][] = [
            'id' => $mat['id'],
            'name' => $mat['name'],
            'src' => $mat['src'],
            'vol' => $mat['vol'] ?? null,
            'owned' => isset($owned[$mat['id']]),
            'price' => TCG_PLAYMAT_SHOP_PRICE,
            'added_at' => (string)($mat['added_at'] ?? ''),
        ];
    }

    $generations = [];
    foreach (tcgSleeveShopGenerationOrder() as $unit) {
        // Playmat catalog has no Mixed/Other (and may omit future empty units) —
        // do not show empty generation tabs the way sleeve shop does.
        if (empty($byUnit[$unit])) {
            continue;
        }
        $chars = [];
        $groupMats = [];
        $seenIdol = [];
        foreach (($byUnit[$unit] ?? []) as $idol => $mats) {
            if (tcgSleeveShopIsGroupIdol((string)$idol, $unit)) {
                foreach ($mats as $row) {
                    $groupMats[] = $row;
                }
                continue;
            }
            $key = strtolower((string)$idol);
            $seenIdol[$key] = true;
            $port = $portraitById[$key] ?? null;
            $chars[] = [
                'id' => $idol,
                'name' => $port['name'] ?? $idol,
                'portrait' => $port['portrait'] ?? '',
                'icon' => $port['portrait'] ?? '',
                'is_group' => false,
                'playmat_count' => count($mats),
                'playmats' => $mats,
            ];
        }
        // Keep empty member slots from idol portraits so filters stay complete.
        foreach ($portraitsByUnit[$unit] ?? [] as $port) {
            $key = strtolower((string)$port['id']);
            if (isset($seenIdol[$key])) {
                continue;
            }
            $chars[] = [
                'id' => $port['id'],
                'name' => $port['name'] ?? $port['id'],
                'portrait' => $port['portrait'] ?? '',
                'icon' => $port['portrait'] ?? '',
                'is_group' => false,
                'playmat_count' => 0,
                'playmats' => [],
            ];
            $seenIdol[$key] = true;
        }
        array_unshift($chars, [
            'id' => $unit,
            'name' => $unit,
            'portrait' => '',
            'icon' => tcgSleeveShopUnitIconUrl($unit),
            'is_group' => true,
            'playmat_count' => count($groupMats),
            'playmats' => $groupMats,
        ]);
        $groupFirst = [];
        $rest = [];
        foreach ($chars as $c) {
            if (!empty($c['is_group'])) {
                $groupFirst[] = $c;
            } else {
                $rest[] = $c;
            }
        }
        usort($rest, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $chars = array_merge($groupFirst, $rest);
        $unitPlaymatCount = 0;
        foreach ($chars as $c) {
            $unitPlaymatCount += (int)($c['playmat_count'] ?? 0);
        }
        // Skip empty gens (e.g. Mixed / Other) — no playmats in catalog for those.
        if ($unitPlaymatCount < 1) {
            continue;
        }
        $generations[] = [
            'id' => $unit,
            'name' => $unit,
            'icon' => tcgSleeveShopUnitIconUrl($unit),
            'characters' => $chars,
        ];
    }

    return [
        'success' => true,
        'coins' => tcgGetCoins($uid),
        'playmat_shop_price' => TCG_PLAYMAT_SHOP_PRICE,
        'owned_playmats' => array_keys($owned),
        'generations' => $generations,
        'default_playmat' => 'playmat.png',
    ];
}

function tcgApiPlaymatBuy(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $playmatId = tcgNormalizePlaymatId($body['playmat_id'] ?? '');
    if ($playmatId === '' || !tcgPlaymatCatalogIdValid($playmatId)) {
        throw new Exception('Unknown playmat', 400);
    }
    if (tcgOwnsPlaymat($uid, $playmatId)) {
        throw new Exception('Playmat already owned', 400);
    }
    tcgDeductCoins($uid, TCG_PLAYMAT_SHOP_PRICE);
    tcgGrantOwnedPlaymat($uid, $playmatId, 'shop');
    return [
        'success' => true,
        'playmat_id' => $playmatId,
        'coins' => tcgGetCoins($uid),
        'owned_playmats' => tcgOwnedPlaymatIds($uid),
    ];
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
