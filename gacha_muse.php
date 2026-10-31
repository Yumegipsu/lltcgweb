<?php
/**
 * μ's Premium 10-Pull banner: shown in the gacha banner strip only while the player owns a
 * μ's Premium 10-Pull Ticket (a gift item, see gifts.php). Pool = every non-PR μ's Member card
 * from all packs, Honoka's cards boosted like a birthday banner, and the final (10th) pull is
 * always an SR+ Honoka card. One ticket = one 10-pull; the banner takes tickets only.
 */

const TCG_GACHA_MUSE_BANNER_ID = 'muse10';
const TCG_GACHA_MUSE_GROUP = "μ's";
const TCG_GACHA_MUSE_IDOL = 'honoka';
const TCG_GACHA_MUSE_PULL_COUNT = 10;
const TCG_GACHA_MUSE_TICKET_COST = 1;

/** Aliases accepted for the pay-with value of a μ's Premium ticket pull. */
function tcgGachaIsMuseTicketCurrency(string $payWith): bool {
    return in_array($payWith, ['muse_ticket', 'muse_premium_ticket', 'ticket_muse'], true);
}

function tcgGachaMuseCardEligible(array $card): bool {
    return (string)($card['group'] ?? '') === TCG_GACHA_MUSE_GROUP
        && (string)($card['card_type_en'] ?? '') === 'Member'
        && tcgGachaBirthdayExtraEligible($card);
}

/**
 * @return array{n:list<string>,sr:list<string>,ur:list<string>,all:list<string>,_w:array<string,float>}
 */
function tcgGachaBuildMusePools(array $cardsData): array {
    $pools = ['n' => [], 'sr' => [], 'ur' => [], 'all' => []];
    $weights = [];
    foreach ($cardsData['cards'] ?? [] as $card) {
        if (!is_array($card) || !tcgGachaMuseCardEligible($card)) {
            continue;
        }
        $no = trim((string)($card['card_no'] ?? ''));
        if ($no === '') {
            continue;
        }
        $tier = tcgGachaTierForRarity(tcgNormalizePoolRarity((string)($card['rarity'] ?? 'N'), $no));
        $pools[$tier][] = $no;
        $pools['all'][] = $no;
        if (tcgGachaCardMatchesIdol($card, TCG_GACHA_MUSE_IDOL)) {
            $weights[$no] = TCG_GACHA_BDAY_BOOST;
        }
    }
    foreach (['n', 'sr', 'ur', 'all'] as $k) {
        $pools[$k] = array_values(array_unique($pools[$k]));
    }
    if (!$pools['all']) {
        throw new Exception('Gacha pool is empty', 500);
    }
    // Same empty-bucket fallbacks as the standard pool so a roll never hard-fails.
    if (!$pools['ur'] && $pools['sr']) {
        $pools['ur'] = $pools['sr'];
    }
    if (!$pools['sr'] && $pools['n']) {
        $pools['sr'] = $pools['n'];
    }
    if (!$pools['n']) {
        $pools['n'] = $pools['all'];
    }
    if (!$weights) {
        throw new Exception('μ\'s pool has no Honoka cards', 500);
    }
    $pools['_w'] = $weights;
    return $pools;
}

/**
 * The banner descriptor for a player who owns at least one ticket, else null.
 *
 * @return array<string,mixed>|null
 */
function tcgGachaMuseBanner(string $uid): ?array {
    $tickets = tcgGetMusePremiumTickets($uid);
    if ($tickets < TCG_GACHA_MUSE_TICKET_COST) {
        return null;
    }
    return [
        'id' => TCG_GACHA_MUSE_BANNER_ID,
        'type' => 'muse_premium',
        'idol_key' => TCG_GACHA_MUSE_IDOL,
        'name_en' => "μ's Premium 10-Pull",
        'pull_count' => TCG_GACHA_MUSE_PULL_COUNT,
        'tickets' => $tickets,
        'bg' => '#8a2457',
        'fg' => '#ffffff',
        'art' => 'assets/gacha/renders/muse_honoka_full.png',
    ];
}
