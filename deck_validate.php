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

/** Non-PRカード booster_pack values whose cards still roll in the PR Card Pack pool. */
const TCG_PR_EXTRA_BOOSTER_PACKS = [
    // Collection Clear Pocket — Hasunosora (CLHS01, PL!HS-cl1-*, rarity CL).
    'コレクション クリアポケット ラブライブ！蓮ノ空女学院スクールアイドルクラブ',
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
    $pack = $card['booster_pack'] ?? '';
    return $pack === 'PRカード' || in_array($pack, TCG_PR_EXTRA_BOOSTER_PACKS, true);
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
        if ($no === '') {
            continue;
        }
        // Index + and ＋ so decks/collections using either form still resolve.
        if (function_exists('tcgCardNoLookupKeys')) {
            foreach (tcgCardNoLookupKeys($no) as $key) {
                $map[$key] = $c;
            }
        } else {
            $map[$no] = $c;
            $ascii = str_replace('＋', '+', $no);
            $map[$ascii] = $c;
            $map[str_replace('+', '＋', $ascii)] = $c;
        }
    }
    return $map;
}

function tcgValidateDeckLists(array $mainDeck, array $energyDeck, array $cardMap, ?array $owned = null, bool $allowIncomplete = false): array {
    $errors = [];
    $mainN = count($mainDeck);
    $energyN = count($energyDeck);
    if ($mainN > TCG_MAIN_DECK_SIZE) {
        $errors[] = 'Main deck cannot exceed ' . TCG_MAIN_DECK_SIZE . ' cards (got ' . $mainN . ')';
    } elseif (!$allowIncomplete && $mainN !== TCG_MAIN_DECK_SIZE) {
        $errors[] = 'Main deck must be exactly ' . TCG_MAIN_DECK_SIZE . ' cards (got ' . $mainN . ')';
    }
    if ($energyN > TCG_ENERGY_SLOTS) {
        $errors[] = 'Energy deck cannot exceed ' . TCG_ENERGY_SLOTS . ' cards (got ' . $energyN . ')';
    } elseif (!$allowIncomplete && $energyN !== TCG_ENERGY_SLOTS) {
        $errors[] = 'Energy deck must be exactly ' . TCG_ENERGY_SLOTS . ' cards (got ' . $energyN . ')';
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
    $mainIdentityCounts = [];
    foreach ($mainCounts as $no => $qty) {
        $id = tcgDeckCopyIdentity((string)$no);
        $mainIdentityCounts[$id] = ($mainIdentityCounts[$id] ?? 0) + $qty;
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
    foreach ($mainIdentityCounts as $id => $qty) {
        if ($qty > TCG_MAX_COPIES) {
            $errors[] = "Too many copies of $id including alternate versions (max " . TCG_MAX_COPIES . ')';
        }
    }

    if ($members > TCG_MEMBER_SLOTS) {
        $errors[] = 'Main deck cannot have more than ' . TCG_MEMBER_SLOTS . ' Member cards (got ' . $members . ')';
    } elseif (!$allowIncomplete && $members !== TCG_MEMBER_SLOTS) {
        $errors[] = 'Main deck must have exactly ' . TCG_MEMBER_SLOTS . ' Member cards (got ' . $members . ')';
    }
    if ($lives > TCG_LIVE_SLOTS) {
        $errors[] = 'Main deck cannot have more than ' . TCG_LIVE_SLOTS . ' Live cards (got ' . $lives . ')';
    } elseif (!$allowIncomplete && $lives !== TCG_LIVE_SLOTS) {
        $errors[] = 'Main deck must have exactly ' . TCG_LIVE_SLOTS . ' Live cards (got ' . $lives . ')';
    }

    $energyTypes = [];
    $energyIdentityCounts = [];
    foreach ($energyCounts as $no => $qty) {
        $id = tcgDeckCopyIdentity((string)$no);
        $energyIdentityCounts[$id] = ($energyIdentityCounts[$id] ?? 0) + $qty;
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
    foreach ($energyIdentityCounts as $id => $qty) {
        if ($qty > TCG_MAX_ENERGY_COPIES) {
            $errors[] = "Too many energy copies of $id including alternate versions (max " . TCG_MAX_ENERGY_COPIES . ')';
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
