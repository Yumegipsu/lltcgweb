<?php
/**
 * General Scout gacha — Star Gem pulls from older BP + starter pools.
 */
require_once __DIR__ . '/booster.php';
require_once __DIR__ . '/deck_validate.php';
require_once __DIR__ . '/db.php';

const TCG_GACHA_SINGLE_COST = 20;
const TCG_GACHA_MULTI_COST = 200;
const TCG_GACHA_MULTI_COUNT = 11;

/**
 * Temporary preview lock: only these Discord IDs may open general gacha.
 * Empty array = open to everyone. Keep in sync with TCG_SOCIAL_OWNER_ID.
 *
 * @return list<string>
 */
function tcgGachaAccessAllowlist(): array {
    return ['213038604975472640'];
}

function tcgGachaUserHasAccess(string $discordId): bool {
    $list = tcgGachaAccessAllowlist();
    if ($list === []) {
        return true;
    }
    return in_array($discordId, $list, true);
}

/** Tier weights out of 10_000 (SIF-style: ~90% N, ~9.2% SR, ~0.8% UR). */
const TCG_GACHA_WEIGHT_N = 9000;
const TCG_GACHA_WEIGHT_SR = 920;
const TCG_GACHA_WEIGHT_UR = 80;

/**
 * Standard BP filters allowed in the general gacha (excludes MELLOW MOMENT).
 *
 * @return list<string>
 */
function tcgGachaAllowedBoosterFilters(): array {
    $out = [];
    foreach (tcgBoosterBoxes() as $box) {
        $kind = (string)($box['kind'] ?? '');
        $id = (string)($box['id'] ?? '');
        if ($kind !== 'bp') {
            continue;
        }
        if ($id === 'bp_mellow') {
            continue;
        }
        $filter = (string)($box['filter'] ?? '');
        if ($filter !== '') {
            $out[] = $filter;
        }
    }
    return $out;
}

/**
 * @return list<string>
 */
function tcgGachaStarterCardNos(array $cardsData): array {
    $nos = [];
    foreach ($cardsData['starter_decks'] ?? [] as $deck) {
        foreach (['main_deck', 'energy_deck', 'cards'] as $key) {
            $list = $deck[$key] ?? null;
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $nos[$entry] = true;
                } elseif (is_array($entry)) {
                    $no = trim((string)($entry['card_no'] ?? $entry['no'] ?? ''));
                    if ($no !== '') {
                        $nos[$no] = true;
                    }
                }
            }
        }
    }
    return array_keys($nos);
}

function tcgGachaIsExcludedCard(array $card): bool {
    $no = trim((string)($card['card_no'] ?? ''));
    if ($no === '') {
        return true;
    }
    if (tcgCardEligibleForPrBoosterPool($card)) {
        return true;
    }
    $r = tcgNormalizePoolRarity((string)($card['rarity'] ?? 'N'), $no);
    if ($r === 'DUO' || $r === 'PR' || $r === 'PR+' || str_starts_with($r, 'PR')) {
        return true;
    }
    if (preg_match('/-DUO$/i', $no)) {
        return true;
    }
    $pack = (string)($card['booster_pack'] ?? '');
    if (str_contains($pack, 'プレミアムブースター') || stripos($pack, 'premium') !== false) {
        return true;
    }
    if ($pack === 'ブースターパック MELLOW MOMENT') {
        return true;
    }
    return false;
}

/**
 * Map normalized rarity → gacha tier: n | sr | ur.
 */
function tcgGachaTierForRarity(string $rarity): string {
    $r = strtoupper(str_replace(['＋', '　'], ['+', ''], $rarity));
    $ur = ['SEC', 'SECL', 'SECE', 'SECS', 'SEC+', 'LLE'];
    if (in_array($r, $ur, true) || str_starts_with($r, 'SEC')) {
        return 'ur';
    }
    $sr = ['P', 'P+', 'PP', 'SRE', 'SRL', 'RM', 'PE+', 'AR', 'RE'];
    if (in_array($r, $sr, true)) {
        return 'sr';
    }
    return 'n';
}

/**
 * @return array{n:list<string>,sr:list<string>,ur:list<string>,all:list<string>}
 */
function tcgGachaBuildPools(array $cardsData): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $allowed = array_fill_keys(tcgGachaAllowedBoosterFilters(), true);
    $starterNos = array_fill_keys(tcgGachaStarterCardNos($cardsData), true);
    $pools = ['n' => [], 'sr' => [], 'ur' => [], 'all' => []];
    foreach ($cardsData['cards'] ?? [] as $card) {
        if (!is_array($card) || tcgGachaIsExcludedCard($card)) {
            continue;
        }
        $no = trim((string)($card['card_no'] ?? ''));
        $pack = (string)($card['booster_pack'] ?? '');
        $inBp = $pack !== '' && isset($allowed[$pack]);
        $inStarter = isset($starterNos[$no]);
        if (!$inBp && !$inStarter) {
            continue;
        }
        $r = tcgNormalizePoolRarity((string)($card['rarity'] ?? 'N'), $no);
        $tier = tcgGachaTierForRarity($r);
        $pools[$tier][] = $no;
        $pools['all'][] = $no;
    }
    foreach (['n', 'sr', 'ur', 'all'] as $k) {
        $pools[$k] = array_values(array_unique($pools[$k]));
    }
    // Keep UR/SR non-empty fallbacks so rolls never hard-fail on empty buckets.
    if (!$pools['ur'] && $pools['sr']) {
        $pools['ur'] = $pools['sr'];
    }
    if (!$pools['sr'] && $pools['n']) {
        $pools['sr'] = $pools['n'];
    }
    if (!$pools['n'] && $pools['all']) {
        $pools['n'] = $pools['all'];
    }
    $cache = $pools;
    return $pools;
}

function tcgGachaPickTier(): string {
    $roll = random_int(1, 10000);
    if ($roll <= TCG_GACHA_WEIGHT_UR) {
        return 'ur';
    }
    if ($roll <= TCG_GACHA_WEIGHT_UR + TCG_GACHA_WEIGHT_SR) {
        return 'sr';
    }
    return 'n';
}

/**
 * @param array{n:list<string>,sr:list<string>,ur:list<string>,all:list<string>} $pools
 */
function tcgGachaPickCardNo(array $pools, string $tier): string {
    $list = $pools[$tier] ?? [];
    if (!$list) {
        $list = $pools['all'] ?? [];
    }
    if (!$list) {
        throw new Exception('Gacha pool is empty', 500);
    }
    return $list[array_rand($list)];
}

/**
 * @return list<array{card_no:string,tier:string,rarity:string}>
 */
function tcgGachaRollPulls(int $count, array $cardsData, array $cardMap): array {
    $pools = tcgGachaBuildPools($cardsData);
    $count = max(1, min(20, $count));
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $tier = tcgGachaPickTier();
        $no = tcgGachaPickCardNo($pools, $tier);
        $card = $cardMap[$no] ?? null;
        $r = is_array($card)
            ? tcgNormalizePoolRarity((string)($card['rarity'] ?? 'N'), $no)
            : 'N';
        $out[] = [
            'card_no' => $no,
            'tier' => $tier,
            'rarity' => $r,
        ];
    }
    return $out;
}

function tcgGachaIdolKeyFromCard(?array $card): string {
    if (!is_array($card)) {
        return '';
    }
    $name = trim((string)($card['name_en'] ?? ''));
    if ($name === '') {
        return '';
    }
    // "Honoka Kosaka" → honoka; Lives/songs often lack a member first name.
    if (preg_match('/^([A-Za-z]+)\b/', $name, $m)) {
        return strtolower($m[1]);
    }
    return '';
}

function tcgApiGachaInfo(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $unlocked = tcgGachaUserHasAccess($uid);
    $cards = tcgLoadCardsData();
    $pools = tcgGachaBuildPools($cards);
    return [
        'success' => true,
        'unlocked' => $unlocked,
        'locked' => !$unlocked,
        'star_gems' => tcgGetStarGems($uid),
        'single_cost' => TCG_GACHA_SINGLE_COST,
        'multi_cost' => TCG_GACHA_MULTI_COST,
        'multi_count' => TCG_GACHA_MULTI_COUNT,
        'rates' => [
            'n' => TCG_GACHA_WEIGHT_N / 100,
            'sr' => TCG_GACHA_WEIGHT_SR / 100,
            'ur' => TCG_GACHA_WEIGHT_UR / 100,
        ],
        'pool' => [
            'n' => count($pools['n']),
            'sr' => count($pools['sr']),
            'ur' => count($pools['ur']),
            'total' => count($pools['all']),
        ],
    ];
}

function tcgApiOpenGacha(array $body): array {
    tcgRateLimitForAction('open_gacha', $body);
    $uid = tcgRequireAuthUser($body);
    if (!tcgGachaUserHasAccess($uid)) {
        throw new Exception('Gacha is not available yet', 403);
    }
    $user = tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    if (empty($user['starter_deck'])) {
        throw new Exception('Choose a starter deck first', 400);
    }
    $mode = trim(strtolower((string)($body['mode'] ?? $body['pull'] ?? 'single')));
    if (in_array($mode, ['multi', '10', '10+1', 'eleven', 'x11'], true)
        || intval($body['count'] ?? 0) === TCG_GACHA_MULTI_COUNT) {
        $count = TCG_GACHA_MULTI_COUNT;
        $cost = TCG_GACHA_MULTI_COST;
        $mode = 'multi';
    } else {
        $count = 1;
        $cost = TCG_GACHA_SINGLE_COST;
        $mode = 'single';
    }
    $have = tcgGetStarGems($uid);
    if ($have < $cost) {
        throw new Exception('Not enough Star Gems', 400);
    }
    $cards = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cards);
    $rolled = tcgGachaRollPulls($count, $cards, $cardMap);
    $nos = array_map(static fn ($r) => $r['card_no'], $rolled);
    tcgDeductStarGems($uid, $cost);
    $applied = tcgApplyBoosterPullWithGems($uid, $nos, $cardMap);
    $pulls = [];
    foreach ($rolled as $i => $row) {
        $app = $applied['pulls'][$i] ?? ['converted' => false, 'star_gems' => 0];
        $card = $cardMap[$row['card_no']] ?? null;
        $pulls[] = [
            'card_no' => $row['card_no'],
            'tier' => $row['tier'],
            'rarity' => $row['rarity'],
            'name_en' => is_array($card) ? (string)($card['name_en'] ?? '') : '',
            'idol_key' => tcgGachaIdolKeyFromCard(is_array($card) ? $card : null),
            'converted' => !empty($app['converted']),
            'star_gems' => intval($app['star_gems'] ?? 0),
        ];
    }
    $completions = [];
    if (function_exists('tcgMissionCheckCollectionThresholds')) {
        require_once __DIR__ . '/missions.php';
        $completions = tcgMissionCheckCollectionThresholds($uid);
    }
    $payload = [
        'success' => true,
        'mode' => $mode,
        'cost' => $cost,
        'count' => $count,
        'pulls' => $pulls,
        'star_gems_earned' => intval($applied['star_gems_earned'] ?? 0),
        'star_gems' => intval($applied['star_gems'] ?? tcgGetStarGems($uid)),
    ];
    if (function_exists('tcgMissionAttachCompletions')) {
        return tcgMissionAttachCompletions($payload, $completions);
    }
    return $payload;
}
