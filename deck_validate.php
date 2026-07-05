<?php
/**
 * Legal Loveca deck validation for collection / ranked / experiment saves.
 *
 * Enforces 60 main (48 Member + 12 Live), 12 Energy, copy limits, and optional
 * owned-card checks against the player's collection.
 */
require_once __DIR__ . '/deckgen.php';
require_once __DIR__ . '/loveca_points.php';

const TCG_MAIN_DECK_SIZE = 60;
const TCG_MEMBER_SLOTS = 48;
const TCG_LIVE_SLOTS = 12;
const TCG_ENERGY_SLOTS = 12;
const TCG_MAX_COPIES = 4;
const TCG_MAX_ENERGY_COPIES = 12;

/** Generic starter-deck energies; not part of PR Card Pack pool. */
const TCG_STARTER_BASIC_ENERGY_CARD_NOS = [
    'LL-E-001-SD',
    'LL-E-002-SD',
    'LL-E-003-SD',
    'LL-E-004-SD',
    'LL-E-005-SD',
];

/** Plain PR promo energies; not part of PR Card Pack pool. */
const TCG_PR_EXCLUDED_ENERGY_CARD_NOS = [
    'LL-E-002-PR',
    'LL-E-004-PR',
];

function tcgIsStarterBasicEnergyCard(string $cardNo): bool {
    $cardNo = trim($cardNo);
    if ($cardNo === '') {
        return false;
    }
    if (in_array($cardNo, TCG_STARTER_BASIC_ENERGY_CARD_NOS, true)) {
        return true;
    }
    return (bool) preg_match('/^LL-E-\d+-SD$/i', $cardNo);
}

function tcgIsPrExcludedEnergyCard(string $cardNo): bool {
    $cardNo = trim($cardNo);
    if ($cardNo === '') {
        return false;
    }
    return in_array($cardNo, TCG_PR_EXCLUDED_ENERGY_CARD_NOS, true);
}

/** True when a catalog row may appear in the PR Card Pack pool (rolls + rate sheet). */
function tcgCardEligibleForPrBoosterPool(array $card): bool {
    $no = $card['card_no'] ?? '';
    if (tcgIsStarterBasicEnergyCard($no)) {
        return false;
    }
    if (tcgIsPrExcludedEnergyCard($no)) {
        return false;
    }
    return ($card['booster_pack'] ?? '') === 'PRカード';
}

/** Max playable copies per card_no (Member/Live = 4, Energy = 12). */
function tcgGetDeckMaxCopies(?array $card, ?string $cardNo = null): int {
    $type = $card['card_type'] ?? '';
    if ($type === 'エネルギー') {
        return TCG_MAX_ENERGY_COPIES;
    }
    if ($type === 'メンバー' || $type === 'ライブ') {
        return TCG_MAX_COPIES;
    }
    $no = trim((string)($cardNo ?? ($card['card_no'] ?? '')));
    if ($no !== '' && preg_match('/^LL-E-/i', $no)) {
        return TCG_MAX_ENERGY_COPIES;
    }
    return TCG_MAX_COPIES;
}

function tcgBuildCardMap(array $cardsData): array {
    $map = [];
    foreach ($cardsData['cards'] ?? [] as $c) {
        $no = $c['card_no'] ?? '';
        if ($no !== '') {
            $map[$no] = $c;
        }
    }
    return $map;
}

function tcgValidateDeckLists(array $mainDeck, array $energyDeck, array $cardMap, ?array $owned = null): array {
    $errors = [];
    if (count($mainDeck) !== TCG_MAIN_DECK_SIZE) {
        $errors[] = 'Main deck must be exactly ' . TCG_MAIN_DECK_SIZE . ' cards (got ' . count($mainDeck) . ')';
    }
    if (count($energyDeck) !== TCG_ENERGY_SLOTS) {
        $errors[] = 'Energy deck must be exactly ' . TCG_ENERGY_SLOTS . ' cards (got ' . count($energyDeck) . ')';
    }

    $mainCounts = [];
    foreach ($mainDeck as $no) {
        $mainCounts[$no] = ($mainCounts[$no] ?? 0) + 1;
    }
    $energyCounts = [];
    foreach ($energyDeck as $no) {
        $energyCounts[$no] = ($energyCounts[$no] ?? 0) + 1;
    }

    $members = 0;
    $lives = 0;
    foreach ($mainCounts as $no => $qty) {
        if ($qty > TCG_MAX_COPIES) {
            $errors[] = "Too many copies of $no (max " . TCG_MAX_COPIES . ')';
        }
        $card = $cardMap[$no] ?? null;
        if (!$card) {
            $errors[] = "Unknown card: $no";
            continue;
        }
        $type = $card['card_type'] ?? '';
        if ($type === 'メンバー') {
            $members += $qty;
        } elseif ($type === 'ライブ') {
            $lives += $qty;
        } else {
            $errors[] = "Invalid main-deck card type for $no";
        }
        if ($owned !== null && ($owned[$no] ?? 0) < $qty) {
            $errors[] = "Not enough copies of $no in collection";
        }
    }

    if ($members !== TCG_MEMBER_SLOTS) {
        $errors[] = 'Main deck must have exactly ' . TCG_MEMBER_SLOTS . ' Member cards (got ' . $members . ')';
    }
    if ($lives !== TCG_LIVE_SLOTS) {
        $errors[] = 'Main deck must have exactly ' . TCG_LIVE_SLOTS . ' Live cards (got ' . $lives . ')';
    }

    $energyTypes = [];
    foreach ($energyCounts as $no => $qty) {
        if ($qty > TCG_MAX_ENERGY_COPIES) {
            $errors[] = "Too many energy copies of $no (max " . TCG_MAX_ENERGY_COPIES . ')';
        }
        $card = $cardMap[$no] ?? null;
        if (!$card || ($card['card_type'] ?? '') !== 'エネルギー') {
            $errors[] = "Invalid energy card: $no";
            continue;
        }
        $energyTypes[$no] = true;
        if ($owned !== null && ($owned[$no] ?? 0) < $qty) {
            $errors[] = "Not enough energy copies of $no in collection";
        }
    }

    $lovecaPoints = tcgSumMainDeckLovecaPoints($mainDeck);
    $lovecaLimit = tcgLovecaPointLimit();
    $lovecaErr = tcgValidateLovecaPointBudget($mainDeck);
    if ($lovecaErr !== null) {
        $errors[] = $lovecaErr;
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'members' => $members,
        'lives' => $lives,
        'energy_types' => array_keys($energyTypes),
        'loveca_points' => $lovecaPoints,
        'loveca_limit' => $lovecaLimit,
    ];
}

function tcgDecodeDeckJson(?string $json): array {
    if ($json === null || $json === '') {
        return [];
    }
    $data = json_decode($json, true);
    return is_array($data) ? array_values($data) : [];
}
