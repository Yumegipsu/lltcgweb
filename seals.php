<?php
/**
 * Sticker shop seals: N / R / P / SEC currencies, convert + buy helpers.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booster.php';
require_once __DIR__ . '/deck_validate.php';

const TCG_SEAL_TIERS = ['N', 'R', 'P', 'SEC'];

/** Buy cost in seals of the same tier. */
const TCG_SEAL_BUY_COST = [
    'N' => 20,
    'R' => 15,
    'P' => 10,
    'SEC' => 5,
];

const TCG_SEAL_ICON = [
    'N' => 'assets/seals/N.png',
    'R' => 'assets/seals/R.png',
    'P' => 'assets/seals/P.png',
    'SEC' => 'assets/seals/SEC.png',
];

function tcgSealColumnForTier(string $tier): string {
    $tier = strtoupper($tier);
    return match ($tier) {
        'N' => 'seal_n',
        'R' => 'seal_r',
        'P' => 'seal_p',
        'SEC' => 'seal_sec',
        default => throw new InvalidArgumentException('Invalid seal tier'),
    };
}

/** @return array{n:int,r:int,p:int,sec:int} */
function tcgSealBalances(string $discordId): array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT seal_n, seal_r, seal_p, seal_sec FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'n' => max(0, intval($row['seal_n'] ?? 0)),
        'r' => max(0, intval($row['seal_r'] ?? 0)),
        'p' => max(0, intval($row['seal_p'] ?? 0)),
        'sec' => max(0, intval($row['seal_sec'] ?? 0)),
    ];
}

function tcgAddSeals(string $discordId, string $tier, int $amount): array {
    if ($amount <= 0) {
        return tcgSealBalances($discordId);
    }
    $col = tcgSealColumnForTier($tier);
    $db = tcgDb();
    $db->prepare("UPDATE tcg_users SET {$col} = COALESCE({$col}, 0) + ?, updated_at = ? WHERE discord_id = ?")
        ->execute([$amount, time(), $discordId]);
    return tcgSealBalances($discordId);
}

function tcgDeductSeals(string $discordId, string $tier, int $amount): array {
    if ($amount <= 0) {
        return tcgSealBalances($discordId);
    }
    $col = tcgSealColumnForTier($tier);
    $db = tcgDb();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT {$col} FROM tcg_users WHERE discord_id = ?");
        $stmt->execute([$discordId]);
        $have = max(0, intval($stmt->fetchColumn() ?: 0));
        if ($have < $amount) {
            throw new Exception('Not enough seals', 400);
        }
        $db->prepare("UPDATE tcg_users SET {$col} = {$col} - ?, updated_at = ? WHERE discord_id = ?")
            ->execute([$amount, time(), $discordId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return tcgSealBalances($discordId);
}

/** Gacha booster_pack filter strings (excludes PR). */
function tcgGachaBoosterFilters(): array {
    static $filters = null;
    if ($filters !== null) {
        return $filters;
    }
    $set = [];
    foreach (tcgBoosterBoxes() as $box) {
        $kind = $box['kind'] ?? '';
        if (!in_array($kind, ['bp', 'pb', 'pb_duo'], true)) {
            continue;
        }
        $f = (string)($box['filter'] ?? '');
        if ($f !== '' && $f !== 'PRカード') {
            $set[$f] = true;
        }
    }
    $filters = array_keys($set);
    return $filters;
}

function tcgIsGachaBoosterCard(array $card): bool {
    $pack = (string)($card['booster_pack'] ?? '');
    if ($pack === 'PRカード') {
        return false;
    }
    if ($pack !== '' && in_array($pack, tcgGachaBoosterFilters(), true)) {
        return true;
    }
    // Fallback when booster_pack is missing/mismatched: set codes from booster/premium lines.
    $no = (string)($card['card_no'] ?? '');
    return $no !== '' && (bool)preg_match('/-(?:bp|pb)\d+/i', $no);
}

function tcgIsPrSealBlockedCard(array $card): bool {
    $pack = (string)($card['booster_pack'] ?? '');
    if ($pack === 'PRカード') {
        return true;
    }
    $r = tcgNormalizePoolRarity((string)($card['rarity'] ?? ''), (string)($card['card_no'] ?? ''));
    return $r === 'PR' || $r === 'PR+';
}

/**
 * Map printed rarity → seal tier, or null if unmapped.
 * @return 'N'|'R'|'P'|'SEC'|null
 */
function tcgSealTierForCard(array $card): ?string {
    $r = tcgNormalizePoolRarity((string)($card['rarity'] ?? ''), (string)($card['card_no'] ?? ''));
    if ($r === '') {
        return null;
    }
    static $map = [
        'N' => 'N', 'SD' => 'N', 'SD2' => 'N', 'CL' => 'N', 'L' => 'N', 'PE' => 'N',
        'R' => 'R', 'R+' => 'R', 'RM' => 'R', 'RE' => 'R', 'L+' => 'R',
        'P' => 'P', 'P+' => 'P', 'PP' => 'P', 'AR' => 'P', 'PE+' => 'P', 'SRE' => 'P', 'PR+' => 'P',
        'SEC' => 'SEC', 'SEC+' => 'SEC', 'SECE' => 'SEC', 'SECL' => 'SEC', 'SECS' => 'SEC',
        'LLE' => 'SEC', 'SRL' => 'SEC', 'DUO' => 'SEC',
    ];
    return $map[$r] ?? null;
}

function tcgSealBuyCostForTier(string $tier): int {
    return intval(TCG_SEAL_BUY_COST[strtoupper($tier)] ?? 0);
}

function tcgCardConvertibleToSeal(array $card): bool {
    if (tcgIsPrSealBlockedCard($card)) {
        return false;
    }
    if (!tcgIsGachaBoosterCard($card)) {
        return false;
    }
    return tcgSealTierForCard($card) !== null;
}

function tcgCardPurchasableWithSeal(array $card): bool {
    if (tcgIsPrSealBlockedCard($card)) {
        return false;
    }
    return tcgSealTierForCard($card) !== null;
}

/** Max copies of card_no reserved by any saved deck preset. */
function tcgMinReservedCopies(string $discordId, string $cardNo): int {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT main_deck, energy_deck FROM tcg_deck_presets WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $max = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $count = 0;
        foreach ([json_decode($row['main_deck'] ?? '[]', true) ?: [], json_decode($row['energy_deck'] ?? '[]', true) ?: []] as $list) {
            foreach ($list as $no) {
                if ((string)$no === $cardNo) {
                    $count++;
                }
            }
        }
        if ($count > $max) {
            $max = $count;
        }
    }
    return $max;
}

function tcgRemoveFromCollection(string $discordId, string $cardNo, int $qty): int {
    if ($qty <= 0) {
        return tcgGetCollectionMap($discordId)[$cardNo] ?? 0;
    }
    $db = tcgDb();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT qty FROM tcg_collection WHERE discord_id = ? AND card_no = ?');
        $stmt->execute([$discordId, $cardNo]);
        $have = max(0, intval($stmt->fetchColumn() ?: 0));
        if ($have < $qty) {
            throw new Exception('Not enough copies', 400);
        }
        $left = $have - $qty;
        if ($left <= 0) {
            $db->prepare('DELETE FROM tcg_collection WHERE discord_id = ? AND card_no = ?')
                ->execute([$discordId, $cardNo]);
            $left = 0;
        } else {
            $db->prepare('UPDATE tcg_collection SET qty = ? WHERE discord_id = ? AND card_no = ?')
                ->execute([$left, $discordId, $cardNo]);
        }
        $db->commit();
        return $left;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * @return list<array{id:string,kind:string,name_en:string,name_jp?:string,image:string,starter_key?:string}>
 */
function tcgStickerShopCatalog(string $discordId): array {
    $out = [];
    foreach (tcgBoosterBoxes() as $box) {
        $kind = $box['kind'] ?? '';
        if (!in_array($kind, ['bp', 'pb', 'pb_duo'], true)) {
            continue;
        }
        $out[] = [
            'id' => 'box:' . $box['id'],
            'product_type' => 'booster',
            'box_id' => $box['id'],
            'kind' => $kind,
            'name_en' => $box['name_en'] ?? $box['id'],
            'name_jp' => $box['name_jp'] ?? '',
            'image' => $box['image'] ?? '',
        ];
    }
    $owned = array_fill_keys(tcgOwnedStarterKeys($discordId), true);
    foreach (tcgStarterDecks() as $deck) {
        $key = $deck['id'];
        if (empty($owned[$key])) {
            continue;
        }
        $out[] = [
            'id' => 'starter:' . $key,
            'product_type' => 'starter',
            'starter_key' => $key,
            'kind' => 'starter',
            'name_en' => $deck['label'] ?? $key,
            'name_jp' => '',
            'image' => $deck['image'] ?? '',
        ];
    }
    return $out;
}

/**
 * Unique card_nos offered by a shop product.
 * @return list<string>
 */
function tcgStickerShopProductCardNos(string $productId, array $cardsData): array {
    if (str_starts_with($productId, 'box:')) {
        $boxId = substr($productId, 4);
        $box = null;
        foreach (tcgBoosterBoxes() as $b) {
            if (($b['id'] ?? '') === $boxId) {
                $box = $b;
                break;
            }
        }
        if (!$box || !in_array($box['kind'] ?? '', ['bp', 'pb', 'pb_duo'], true)) {
            throw new Exception('Unknown booster product', 404);
        }
        $pools = tcgBuildBoxPools($cardsData, $box);
        $nos = [];
        foreach ($pools as $list) {
            foreach ($list as $no) {
                $nos[$no] = true;
            }
        }
        return array_keys($nos);
    }
    if (str_starts_with($productId, 'starter:')) {
        $key = substr($productId, 8);
        $lists = tcgGetStarterDeckLists($key, $cardsData);
        $nos = [];
        foreach (array_merge($lists['main_deck'] ?? [], $lists['energy_deck'] ?? []) as $no) {
            $no = (string)$no;
            if ($no !== '') {
                $nos[$no] = true;
            }
        }
        return array_keys($nos);
    }
    throw new Exception('Unknown product', 404);
}

function tcgStickerShopProductAllowedForUser(string $discordId, string $productId): bool {
    if (str_starts_with($productId, 'box:')) {
        $boxId = substr($productId, 4);
        foreach (tcgBoosterBoxes() as $b) {
            if (($b['id'] ?? '') === $boxId && in_array($b['kind'] ?? '', ['bp', 'pb', 'pb_duo'], true)) {
                return true;
            }
        }
        return false;
    }
    if (str_starts_with($productId, 'starter:')) {
        $key = substr($productId, 8);
        return in_array($key, tcgOwnedStarterKeys($discordId), true);
    }
    return false;
}

/**
 * Whether card_no appears in a specific sticker-shop product the user can access.
 */
function tcgCardInStickerShopProduct(string $discordId, string $cardNo, string $productId, array $cardsData): bool {
    if (!tcgStickerShopProductAllowedForUser($discordId, $productId)) {
        return false;
    }
    return in_array($cardNo, tcgStickerShopProductCardNos($productId, $cardsData), true);
}

/**
 * Whether card_no appears in any sticker-shop product the user can access.
 */
function tcgCardInAccessibleStickerShop(string $discordId, string $cardNo, array $cardsData): bool {
    foreach (tcgStickerShopCatalog($discordId) as $product) {
        if (tcgCardInStickerShopProduct($discordId, $cardNo, $product['id'], $cardsData)) {
            return true;
        }
    }
    return false;
}

/** Slim card fields for sticker shop JSON (avoid multi‑MB ability dumps). */
function tcgStickerShopCardSummary(array $card): array {
    return [
        'card_no' => (string)($card['card_no'] ?? ''),
        'name' => (string)($card['name'] ?? ''),
        'name_en' => (string)($card['name_en'] ?? ''),
        'rarity' => (string)($card['rarity'] ?? ''),
        'image' => (string)($card['image'] ?? ''),
        'card_type' => (string)($card['card_type'] ?? ''),
        'booster_pack' => (string)($card['booster_pack'] ?? ''),
    ];
}

/**
 * @return array{success:bool,seals:array,card_no:string,qty_left:int,tier:string,seals_gained:int}
 */
function tcgConvertCardsToSeals(string $discordId, string $cardNo, int $qty, array $cardMap): array {
    $qty = max(1, $qty);
    $card = $cardMap[$cardNo] ?? null;
    if (!$card) {
        throw new Exception('Unknown card', 404);
    }
    if (!tcgCardConvertibleToSeal($card)) {
        throw new Exception('This card cannot be converted to seals', 400);
    }
    $tier = tcgSealTierForCard($card);
    if ($tier === null) {
        throw new Exception('This card cannot be converted to seals', 400);
    }
    $owned = tcgGetCollectionMap($discordId)[$cardNo] ?? 0;
    $reserved = tcgMinReservedCopies($discordId, $cardNo);
    $spare = max(0, $owned - $reserved);
    if ($qty > $spare) {
        throw new Exception(
            $reserved > 0
                ? "Only {$spare} spare copy(ies) can be converted ({$reserved} reserved by saved decks)"
                : 'Not enough copies to convert',
            400
        );
    }
    $left = tcgRemoveFromCollection($discordId, $cardNo, $qty);
    $seals = tcgAddSeals($discordId, $tier, $qty);
    return [
        'success' => true,
        'card_no' => $cardNo,
        'qty_left' => $left,
        'tier' => $tier,
        'seals_gained' => $qty,
        'seals' => $seals,
    ];
}

/**
 * @return array{success:bool,seals:array,card_no:string,owned_qty:int,tier:string,cost:int}
 */
function tcgStickerBuyCard(string $discordId, string $cardNo, array $cardMap, array $cardsData, ?string $productId = null): array {
    $card = $cardMap[$cardNo] ?? null;
    if (!$card) {
        throw new Exception('Unknown card', 404);
    }
    if (!tcgCardPurchasableWithSeal($card)) {
        throw new Exception('This card is not available in the sticker shop', 400);
    }
    $productId = $productId !== null ? trim($productId) : '';
    if ($productId !== '') {
        if (!tcgCardInStickerShopProduct($discordId, $cardNo, $productId, $cardsData)) {
            throw new Exception('Unlock this product before buying its cards', 400);
        }
    } elseif (!tcgCardInAccessibleStickerShop($discordId, $cardNo, $cardsData)) {
        throw new Exception('Unlock this product before buying its cards', 400);
    }
    $tier = tcgSealTierForCard($card);
    if ($tier === null) {
        throw new Exception('This card is not available in the sticker shop', 400);
    }
    $cost = tcgSealBuyCostForTier($tier);
    $owned = tcgGetCollectionMap($discordId)[$cardNo] ?? 0;
    $max = tcgGetDeckMaxCopies($card, $cardNo);
    if ($owned >= $max) {
        throw new Exception("Already own the maximum copies ({$max})", 400);
    }
    tcgDeductSeals($discordId, $tier, $cost);
    tcgAddCardsToCollection($discordId, [$cardNo]);
    return [
        'success' => true,
        'card_no' => $cardNo,
        'owned_qty' => $owned + 1,
        'tier' => $tier,
        'cost' => $cost,
        'seals' => tcgSealBalances($discordId),
    ];
}
