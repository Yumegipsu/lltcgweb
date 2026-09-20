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
/** Ticket costs / pull counts (alternate currency for the same pool). */
const TCG_GACHA_TICKET_SINGLE_COST = 1;
const TCG_GACHA_TICKET_MULTI_COST = 10;
const TCG_GACHA_TICKET_MULTI_COUNT = 10;

/**
 * Preview allowlist for general gacha. Empty = open to everyone.
 * Keep any temporary IDs in sync with TCG_SOCIAL_OWNER_ID when used.
 *
 * @return list<string>
 */
function tcgGachaAccessAllowlist(): array {
    return [];
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
 * Standard BPs stay out of general Scout for one calendar month after JP release
 * (not "while newest" forever). Premium / special kinds are never included here.
 */
const TCG_GACHA_NEW_SET_EMBARGO = 'P1M';

/** Optional test override via $GLOBALS['tcg_gacha_now'] (unix). */
function tcgGachaNow(): int {
    if (array_key_exists('tcg_gacha_now', $GLOBALS) && is_int($GLOBALS['tcg_gacha_now'])) {
        return $GLOBALS['tcg_gacha_now'];
    }
    return time();
}

function tcgGachaClearPoolCache(): void {
    $GLOBALS['tcg_gacha_pool_cache'] = null;
}

/**
 * @param array<string,mixed> $box
 */
function tcgGachaBpIsNewSetEmbargoed(array $box, ?int $now = null): bool {
    if ((string)($box['kind'] ?? '') !== 'bp') {
        return false;
    }
    $rd = trim((string)($box['release_date'] ?? ''));
    if ($rd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rd)) {
        return false;
    }
    try {
        $tz = new DateTimeZone('Asia/Tokyo');
        $release = new DateTimeImmutable($rd . ' 00:00:00', $tz);
        $unlock = $release->add(new DateInterval(TCG_GACHA_NEW_SET_EMBARGO));
        $nowTs = $now ?? tcgGachaNow();
        $nowDt = (new DateTimeImmutable('@' . $nowTs))->setTimezone($tz);
        return $nowDt < $unlock;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Standard BP filters currently allowed in the general gacha.
 *
 * @return list<string>
 */
function tcgGachaAllowedBoosterFilters(?int $now = null): array {
    $out = [];
    foreach (tcgBoosterBoxes() as $box) {
        $kind = (string)($box['kind'] ?? '');
        if ($kind !== 'bp') {
            continue;
        }
        if (tcgGachaBpIsNewSetEmbargoed($box, $now)) {
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
    // Starter-pack basic Energy (symbol-only art, e.g. LL-E-*-SD) — not scouts.
    if (function_exists('tcgIsStarterBasicEnergyCard') && tcgIsStarterBasicEnergyCard($no)) {
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
    return false;
}

/**
 * Map normalized rarity → gacha tier: n | sr | ur.
 *
 * grey (N):  N, R, R+, L, L+, PE, and anything else not listed below
 * gold (SR): P, P+, PP, SRE, SRL, RM, PE+, AR, RE
 * rainbow (UR): SEC / SECL / SECE / SECS / SEC+ / LLE (and other SEC*)
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
function tcgGachaBuildPools(array $cardsData, ?int $now = null): array {
    $nowTs = $now ?? tcgGachaNow();
    $dayKey = gmdate('Y-m-d', $nowTs);
    $cache = $GLOBALS['tcg_gacha_pool_cache'] ?? null;
    if (is_array($cache) && ($cache['_day'] ?? '') === $dayKey && is_array($cache['pools'] ?? null)) {
        return $cache['pools'];
    }
    $allowed = array_fill_keys(tcgGachaAllowedBoosterFilters($nowTs), true);
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
    $GLOBALS['tcg_gacha_pool_cache'] = ['_day' => $dayKey, 'pools' => $pools];
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
 * SR+ pity roll: SR vs UR using the same relative weights as a normal pull
 * (UR stays scarce; guarantee does not inflate rainbow odds within SR+).
 */
function tcgGachaPickSrPlusTier(): string {
    $total = TCG_GACHA_WEIGHT_SR + TCG_GACHA_WEIGHT_UR;
    if ($total <= 0) {
        return 'sr';
    }
    $roll = random_int(1, $total);
    return $roll <= TCG_GACHA_WEIGHT_UR ? 'ur' : 'sr';
}

/**
 * Multi scout: ensure at least one SR+, and that card is always the final pull
 * (spotlight + results end on the guarantee).
 *
 * @param list<array{card_no:string,tier:string,rarity:string}> $out
 * @param array{n:list<string>,sr:list<string>,ur:list<string>,all:list<string>} $pools
 */
function tcgGachaEnsureMultiSrPlus(array &$out, array $pools, array $cardMap): void {
    if (count($out) < TCG_GACHA_TICKET_MULTI_COUNT) {
        return;
    }
    $last = count($out) - 1;
    $lastTier = (string)($out[$last]['tier'] ?? '');
    if ($lastTier === 'sr' || $lastTier === 'ur') {
        return;
    }

    // Prefer moving an existing UR, else any SR, into the final slot.
    $moveIdx = null;
    $moveRank = 0; // 2 = UR, 1 = SR
    foreach ($out as $i => $row) {
        if ($i === $last) {
            continue;
        }
        $tier = (string)($row['tier'] ?? '');
        $rank = $tier === 'ur' ? 2 : ($tier === 'sr' ? 1 : 0);
        if ($rank > $moveRank) {
            $moveRank = $rank;
            $moveIdx = $i;
        }
    }
    if ($moveIdx !== null) {
        $tmp = $out[$last];
        $out[$last] = $out[$moveIdx];
        $out[$moveIdx] = $tmp;
        return;
    }

    $tier = tcgGachaPickSrPlusTier();
    $no = tcgGachaPickCardNo($pools, $tier);
    $card = $cardMap[$no] ?? null;
    $r = is_array($card)
        ? tcgNormalizePoolRarity((string)($card['rarity'] ?? 'N'), $no)
        : 'N';
    $out[$last] = [
        'card_no' => $no,
        'tier' => $tier,
        'rarity' => $r,
    ];
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
    // Multi scout (gems 10+1 or tickets ×10): at least one SR+ (gold/rainbow).
    if ($count >= TCG_GACHA_TICKET_MULTI_COUNT) {
        tcgGachaEnsureMultiSrPlus($out, $pools, $cardMap);
    }
    return $out;
}

/**
 * Build a forced tier list for sim pulls. Remaining slots are N.
 *
 * @return list<string> tiers n|sr|ur
 */
function tcgGachaBuildForcedTier(int $count, int $urCount, int $srCount): array {
    $count = max(1, min(20, $count));
    $urCount = max(0, min($count, $urCount));
    $srCount = max(0, min($count - $urCount, $srCount));
    $tiers = array_merge(
        array_fill(0, $urCount, 'ur'),
        array_fill(0, $srCount, 'sr'),
        array_fill(0, $count - $urCount - $srCount, 'n')
    );
    // Shuffle so forced high/mid cards aren't always first in the spotlight order.
    for ($i = count($tiers) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $tiers[$i];
        $tiers[$i] = $tiers[$j];
        $tiers[$j] = $tmp;
    }
    return $tiers;
}

/**
 * Roll pulls with an explicit tier plan (no pity rewrite). Simulation / testing only.
 *
 * @param list<string> $tiers
 * @return list<array{card_no:string,tier:string,rarity:string}>
 */
function tcgGachaRollPullsForced(array $tiers, array $cardsData, array $cardMap): array {
    $pools = tcgGachaBuildPools($cardsData);
    $out = [];
    foreach ($tiers as $tierRaw) {
        $tier = strtolower(trim((string)$tierRaw));
        if ($tier !== 'n' && $tier !== 'sr' && $tier !== 'ur') {
            $tier = 'n';
        }
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

/**
 * Serialize rolled pulls for API (no collection apply).
 *
 * @param list<array{card_no:string,tier:string,rarity:string}> $rolled
 * @return list<array<string,mixed>>
 */
function tcgGachaSerializePulls(array $rolled, array $cardMap): array {
    $pulls = [];
    foreach ($rolled as $row) {
        $card = $cardMap[$row['card_no']] ?? null;
        $pulls[] = [
            'card_no' => $row['card_no'],
            'tier' => $row['tier'],
            'rarity' => $row['rarity'],
            'name_en' => is_array($card) ? (string)($card['name_en'] ?? '') : '',
            'idol_key' => tcgGachaIdolKeyFromCard(is_array($card) ? $card : null),
            'converted' => false,
            'star_gems' => 0,
        ];
    }
    return $pulls;
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

/**
 * Catalog of products in / out of the general Scout pool (for the rates modal).
 *
 * @return array{
 *   included: list<array{id:string,name_en:string,name_jp:string,kind:string}>,
 *   excluded: list<array{id:string,name_en:string,name_jp:string,kind:string}>
 * }
 */
function tcgGachaPackCatalog(?int $now = null): array {
    $included = [];
    $excluded = [];
    $nowTs = $now ?? tcgGachaNow();
    foreach (tcgBoosterBoxes() as $box) {
        $entry = [
            'id' => (string)($box['id'] ?? ''),
            'name_en' => (string)($box['name_en'] ?? $box['id'] ?? ''),
            'name_jp' => (string)($box['name_jp'] ?? ''),
            'kind' => (string)($box['kind'] ?? ''),
        ];
        $kind = $entry['kind'];
        if ($kind === 'bp' && !tcgGachaBpIsNewSetEmbargoed($box, $nowTs)) {
            $included[] = $entry;
        } else {
            if ($kind === 'bp' && !empty($box['release_date'])) {
                $entry['release_date'] = (string)$box['release_date'];
                $entry['embargo'] = true;
            }
            $excluded[] = $entry;
        }
    }
    $included[] = [
        'id' => 'starters',
        'name_en' => 'Starter decks',
        'name_jp' => 'スタートデッキ',
        'kind' => 'starter',
    ];
    $excluded[] = [
        'id' => 'pr_cards',
        'name_en' => 'PR cards',
        'name_jp' => 'PRカード',
        'kind' => 'pr',
    ];
    return ['included' => $included, 'excluded' => $excluded];
}

/**
 * Full rate disclosure for Standard Gacha (Loveca rarities + per-card odds).
 *
 * Pull model: roll scout band (N/SR/UR weights) then uniform card within that band.
 * Percents are chance per single pull.
 *
 * @return array{
 *   pool: array{n:int,sr:int,ur:int,total:int},
 *   scout_bands: array{n:float,sr:float,ur:float},
 *   rarity_rates: list<array{rarity:string,percent:float,count:int}>,
 *   cards: list<array{card_no:string,name_en:string,rarity:string,tier:string,percent:float,image:?string}>,
 *   packs_included: list<array{id:string,name_en:string,name_jp:string,kind:string}>,
 *   packs_excluded: list<array{id:string,name_en:string,name_jp:string,kind:string}>,
 *   notes: list<string>
 * }
 */
function tcgComputeGachaRates(array $cardsData): array {
    $pools = tcgGachaBuildPools($cardsData);
    $cardMap = tcgBuildCardMap($cardsData);
    $tierWeights = [
        'n' => TCG_GACHA_WEIGHT_N,
        'sr' => TCG_GACHA_WEIGHT_SR,
        'ur' => TCG_GACHA_WEIGHT_UR,
    ];
    $totalW = array_sum($tierWeights);
    if ($totalW <= 0) {
        $totalW = 10000;
    }

    $cardProb = [];
    $cardMeta = [];
    $tierRank = ['n' => 1, 'sr' => 2, 'ur' => 3];

    foreach (['n', 'sr', 'ur'] as $tier) {
        $list = $pools[$tier] ?? [];
        $n = count($list);
        if ($n === 0) {
            continue;
        }
        $tierP = $tierWeights[$tier] / $totalW;
        $perCard = $tierP / $n;
        foreach ($list as $no) {
            $c = $cardMap[$no] ?? null;
            $r = is_array($c)
                ? tcgNormalizePoolRarity((string)($c['rarity'] ?? 'N'), $no)
                : 'N';
            if ($r === '') {
                $r = 'N';
            }
            $cardProb[$no] = ($cardProb[$no] ?? 0.0) + $perCard;
            $prev = $cardMeta[$no] ?? null;
            $prevRank = is_array($prev) ? ($tierRank[(string)($prev['tier'] ?? 'n')] ?? 0) : 0;
            if ($prevRank <= ($tierRank[$tier] ?? 0)) {
                $cardMeta[$no] = [
                    'rarity' => $r,
                    'tier' => $tier,
                    'name_en' => is_array($c)
                        ? (string)($c['name_en'] ?? $c['name'] ?? $no)
                        : $no,
                    'image' => is_array($c) ? ($c['image'] ?? null) : null,
                ];
            }
        }
    }

    $cardsOut = [];
    $rarityProb = [];
    $rarityCount = [];
    foreach ($cardProb as $no => $p) {
        $meta = $cardMeta[$no] ?? [
            'rarity' => 'N',
            'tier' => 'n',
            'name_en' => $no,
            'image' => null,
        ];
        $r = (string)$meta['rarity'];
        $cardsOut[] = [
            'card_no' => $no,
            'name_en' => (string)$meta['name_en'],
            'rarity' => $r,
            'tier' => (string)$meta['tier'],
            'percent' => round($p * 100, 6),
            'image' => $meta['image'],
        ];
        $rarityProb[$r] = ($rarityProb[$r] ?? 0.0) + $p;
        $rarityCount[$r] = ($rarityCount[$r] ?? 0) + 1;
    }

    usort($cardsOut, static function ($a, $b) {
        return $b['percent'] <=> $a['percent']
            ?: strcmp((string)$a['card_no'], (string)$b['card_no']);
    });

    $rarityRates = [];
    foreach ($rarityProb as $r => $p) {
        $rarityRates[] = [
            'rarity' => $r,
            'percent' => round($p * 100, 4),
            'count' => (int)($rarityCount[$r] ?? 0),
        ];
    }
    usort($rarityRates, static function ($a, $b) {
        return $b['percent'] <=> $a['percent']
            ?: strcmp((string)$a['rarity'], (string)$b['rarity']);
    });

    $packs = tcgGachaPackCatalog();
    return [
        'pool' => [
            'n' => count($pools['n']),
            'sr' => count($pools['sr']),
            'ur' => count($pools['ur']),
            'total' => count($pools['all']),
        ],
        'scout_bands' => [
            'n' => TCG_GACHA_WEIGHT_N / 100,
            'sr' => TCG_GACHA_WEIGHT_SR / 100,
            'ur' => TCG_GACHA_WEIGHT_UR / 100,
        ],
        'rarity_rates' => $rarityRates,
        'cards' => $cardsOut,
        'packs_included' => $packs['included'],
        'packs_excluded' => $packs['excluded'],
        'notes' => [
            'Each pull first rolls a scout band, then picks one card uniformly from that band.',
            'Percents are chance per single Scout ×1 pull.',
            'Scout 10+1 guarantees at least one SR+ (gold/rainbow); UR within that guarantee stays rare.',
            'Grey (N): N, R, R+, L, L+, PE, and other non-listed rarities.',
            'Gold (SR): P, P+, PP, SRE, SRL, RM, PE+, AR, RE.',
            'Rainbow (UR): SEC / SECL / SECE / SECS / SEC+ / LLE.',
            'PR, DUO, and Premium Booster cards are not in this pool.',
            'New standard booster packs join this pool one month after release.',
        ],
    ];
}

function tcgApiGachaInfo(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $unlocked = tcgGachaUserHasAccess($uid);
    $cards = tcgLoadCardsData();
    $pools = tcgGachaBuildPools($cards);
    if (!function_exists('tcgSocialIsOwner')) {
        require_once __DIR__ . '/social.php';
    }
    return [
        'success' => true,
        'unlocked' => $unlocked,
        'locked' => !$unlocked,
        'star_gems' => tcgGetStarGems($uid),
        'scouting_tickets' => tcgGetScoutingTickets($uid),
        'single_cost' => TCG_GACHA_SINGLE_COST,
        'multi_cost' => TCG_GACHA_MULTI_COST,
        'multi_count' => TCG_GACHA_MULTI_COUNT,
        'ticket_single_cost' => TCG_GACHA_TICKET_SINGLE_COST,
        'ticket_multi_cost' => TCG_GACHA_TICKET_MULTI_COST,
        'ticket_multi_count' => TCG_GACHA_TICKET_MULTI_COUNT,
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
        'sim_available' => tcgSocialIsOwner($uid),
    ];
}

function tcgApiGachaRates(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $cards = tcgLoadCardsData();
    return [
        'success' => true,
        'rates' => tcgComputeGachaRates($cards),
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
    $payWith = strtolower(trim((string)($body['currency'] ?? $body['pay_with'] ?? 'star_gems')));
    $useTickets = in_array($payWith, ['ticket', 'tickets', 'scouting_ticket', 'scouting_tickets'], true);
    $mode = trim(strtolower((string)($body['mode'] ?? $body['pull'] ?? 'single')));
    $wantMulti = in_array($mode, ['multi', '10', '10+1', 'eleven', 'x11', 'x10'], true)
        || intval($body['count'] ?? 0) === TCG_GACHA_MULTI_COUNT
        || intval($body['count'] ?? 0) === TCG_GACHA_TICKET_MULTI_COUNT;
    if ($useTickets) {
        // 1 ticket = 1 pull. Prefer explicit count from the ticket picker.
        $reqCount = intval($body['count'] ?? $body['tickets'] ?? 0);
        if ($reqCount > 0) {
            $count = max(1, min(20, $reqCount));
            $cost = $count * TCG_GACHA_TICKET_SINGLE_COST;
            $mode = $count >= TCG_GACHA_TICKET_MULTI_COUNT ? 'multi' : 'single';
        } elseif ($wantMulti) {
            $count = TCG_GACHA_TICKET_MULTI_COUNT;
            $cost = TCG_GACHA_TICKET_MULTI_COST;
            $mode = 'multi';
        } else {
            $count = 1;
            $cost = TCG_GACHA_TICKET_SINGLE_COST;
            $mode = 'single';
        }
        $have = tcgGetScoutingTickets($uid);
        if ($have < $cost) {
            throw new Exception('Not enough Scouting Tickets', 400);
        }
    } else {
        if ($wantMulti) {
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
    }
    $cards = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cards);
    $rolled = tcgGachaRollPulls($count, $cards, $cardMap);
    $nos = array_map(static fn ($r) => $r['card_no'], $rolled);
    if ($useTickets) {
        tcgDeductScoutingTickets($uid, $cost);
    } else {
        tcgDeductStarGems($uid, $cost);
    }
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
        'currency' => $useTickets ? 'scouting_tickets' : 'star_gems',
        'cost' => $cost,
        'count' => $count,
        'pulls' => $pulls,
        'star_gems_earned' => intval($applied['star_gems_earned'] ?? 0),
        'star_gems' => intval($applied['star_gems'] ?? tcgGetStarGems($uid)),
        'scouting_tickets' => tcgGetScoutingTickets($uid),
    ];
    if (function_exists('tcgMissionAttachCompletions')) {
        return tcgMissionAttachCompletions($payload, $completions);
    }
    return $payload;
}

/**
 * Owner-only animation sim — rolls from the real pool but never grants cards or spends currency.
 *
 * Body:
 *   mode: single|multi (default single → 1, multi → TCG_GACHA_MULTI_COUNT)
 *   count: optional override 1–20
 *   force: random|custom (default random)
 *   ur_count / sr_count: forced high/mid slots when force=custom (rest N)
 *
 * @param array<string,mixed> $body
 * @return array<string,mixed>
 */
function tcgApiGachaSim(array $body): array {
    $uid = tcgRequireAuthUser($body);
    if (!function_exists('tcgSocialIsOwner')) {
        require_once __DIR__ . '/social.php';
    }
    if (!tcgSocialIsOwner($uid)) {
        throw new Exception('Admin only', 403);
    }
    $mode = trim(strtolower((string)($body['mode'] ?? 'single')));
    $wantMulti = in_array($mode, ['multi', '10', '10+1', 'eleven', 'x11', 'x10'], true);
    $count = isset($body['count']) ? intval($body['count']) : 0;
    if ($count < 1) {
        $count = $wantMulti ? TCG_GACHA_MULTI_COUNT : 1;
    }
    $count = max(1, min(20, $count));
    $mode = $count > 1 ? 'multi' : 'single';

    $force = strtolower(trim((string)($body['force'] ?? 'random')));
    $cards = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cards);
    if ($force === 'custom' || $force === 'forced' || isset($body['ur_count']) || isset($body['sr_count'])) {
        $ur = intval($body['ur_count'] ?? $body['high'] ?? 0);
        $sr = intval($body['sr_count'] ?? $body['mid'] ?? 0);
        $tiers = tcgGachaBuildForcedTier($count, $ur, $sr);
        $rolled = tcgGachaRollPullsForced($tiers, $cards, $cardMap);
        $force = 'custom';
    } else {
        $rolled = tcgGachaRollPulls($count, $cards, $cardMap);
        $force = 'random';
    }

    return [
        'success' => true,
        'simulated' => true,
        'mode' => $mode,
        'force' => $force,
        'count' => $count,
        'cost' => 0,
        'currency' => 'sim',
        'pulls' => tcgGachaSerializePulls($rolled, $cardMap),
        'star_gems_earned' => 0,
        'star_gems' => tcgGetStarGems($uid),
        'scouting_tickets' => tcgGetScoutingTickets($uid),
    ];
}

