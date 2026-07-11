<?php
/**
 * Booster box definitions and pack-opening simulation.
 * Pack structure: 2×N, 1×R, 1× base (N/R/L/R+), 1× guaranteed foil (Tier 3→2→1, always last).
 * God Pack: all 5× LLE (~1/480). RM ~1/box via pity. Box pity tracked per user/box.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/deck_validate.php';

const TCG_PACKS_PER_BOX = 30;
const TCG_PACK_SIZE = 5;
/** Daily booster opens (JST): welcome day vs normal days. */
const TCG_DAILY_PACK_LIMIT = 5;
const TCG_WELCOME_DAY_PACK_LIMIT = 10;
/** ~1 God Pack per 16 booster boxes (extremely rare). */
const TCG_GOD_PACK_ODDS = 480;

/**
 * Booster box catalog.
 * - image: official 3D box render (llofficial-cardgame.com) — box picker grid ONLY.
 * - pack_images / pack_image: flat pack wrapper art from Amazon listings (see pack_listings.json).
 *   Never use image for pack-open animation.
 */
function tcgBoosterBoxes(): array {
    return [
        ['id' => 'bp_vol1', 'name_en' => 'Booster Pack vol.1', 'name_jp' => 'ブースターパック vol.1',
         'filter' => 'ブースターパック vol.1', 'kind' => 'bp',
         // Box picker: official 3D box art
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2024/12/28162156/L_TCG_-BP_vol1_box_image_250220.png',
         'pack_style' => 'photo',
         'pack_images' => [
             'assets/packs/bp_vol1-a.jpg',
             'assets/packs/bp_vol1-b.jpg',
             'assets/packs/bp_vol1-c.jpg',
         ]],
        ['id' => 'bp_next', 'name_en' => 'Booster Pack NEXT STEP', 'name_jp' => 'ブースターパック NEXT STEP',
         'filter' => 'ブースターパック NEXT STEP', 'kind' => 'bp',
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/01/28114813/L_TCG_-BP_vol2_box_image.png',
         'pack_style' => 'promo',
         'pack_images' => ['assets/packs/bp_next-promo.png']],
        ['id' => 'bp_summer', 'name_en' => 'Booster Pack: Summer, Beginning', 'name_jp' => 'ブースターパック　夏、はじまる。',
         'filter' => 'ブースターパック　夏、はじまる。', 'kind' => 'bp',
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/07/02143841/L_TCG_-BP_vol3_box_image.png',
         'pack_style' => 'promo',
         'pack_images' => ['assets/packs/bp_summer-promo.png']],
        ['id' => 'bp_sapphire', 'name_en' => 'Booster Pack SAPPHIRE MOON', 'name_jp' => 'ブースターパック SAPPHIRE MOON',
         'filter' => 'ブースターパック SAPPHIRE MOON', 'kind' => 'bp',
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/07/26224902/L_TCG_-BP_vol4_box_image.png',
         'pack_style' => 'photo',
         'pack_images' => [
             'assets/packs/bp_sapphire-a.jpg',
             'assets/packs/bp_sapphire-b.jpg',
             'assets/packs/bp_sapphire-c.jpg',
         ]],
        ['id' => 'bp_royal', 'name_en' => 'Booster Pack Royal Holiday', 'name_jp' => 'ブースターパック Royal Holiday',
         'filter' => 'ブースターパック Royal Holiday', 'kind' => 'bp',
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2026/02/27171602/LLC_-BP06_box_image.png',
         'pack_style' => 'photo',
         'pack_images' => [
             'assets/packs/bp_royal-a.png',
             'assets/packs/bp_royal-b.png',
             'assets/packs/bp_royal-c.png',
         ]],
        ['id' => 'bp_anniv', 'name_en' => 'Booster Pack Anniversary 2026', 'name_jp' => 'ブースターパック Anniversary 2026',
         'filter' => 'ブースターパック Anniversary 2026', 'kind' => 'bp',
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/10/05190851/L_TCG_-BP_vol4_box_image-1.png',
         'pack_style' => 'photo',
         'pack_images' => ['assets/packs/bp_anniv-a.jpg']],
        ['id' => 'pb_muse', 'name_en' => "Premium Booster μ's", 'name_jp' => 'プレミアムブースター ラブライブ！',
         'filter' => 'プレミアムブースター ラブライブ！', 'kind' => 'pb',
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/05/26224815/L_TCG_-PBP_03_box_image.png',
         'pack_style' => 'photo',
         'pack_images' => ['assets/packs/pb_muse-a.jpg']],
        ['id' => 'pb_niji', 'name_en' => 'Premium Booster Nijigasaki', 'name_jp' => 'プレミアムブースター ラブライブ！虹ヶ咲学園スクールアイドル同好会',
         'filter' => 'プレミアムブースター ラブライブ！虹ヶ咲学園スクールアイドル同好会', 'kind' => 'pb',
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/08/01160806/L_TCG_-PBP_04_box_image.png',
         'pack_style' => 'promo',
         'pack_images' => ['assets/packs/pb_niji-promo.png']],
        ['id' => 'pb_sunshine', 'name_en' => 'Premium Booster Sunshine!!', 'name_jp' => 'プレミアムブースター ラブライブ！サンシャイン!!',
         'filter' => 'プレミアムブースター ラブライブ！サンシャイン!!', 'kind' => 'pb',
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/02/28161326/L_TCG_-PBP_02_box_image.png',
         'pack_style' => 'photo',
         'pack_images' => ['assets/packs/pb_sunshine-a.jpg']],
        ['id' => 'pb_superstar', 'name_en' => 'Premium Booster Superstar!!', 'name_jp' => 'プレミアムブースター ラブライブ！スーパースター!!',
         'filter' => 'プレミアムブースター ラブライブ！スーパースター!!', 'kind' => 'pb',
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/01/28114915/L_TCG_-PBP_01_box_image.png',
         'pack_style' => 'photo',
         'pack_images' => ['assets/packs/pb_superstar-a.jpg']],
        ['id' => 'pb_superstar_duo', 'name_en' => 'Premium Booster Superstar!! DUO', 'name_jp' => 'プレミアムブースター ラブライブ！スーパースター!! DUO',
         'filter' => 'プレミアムブースター ラブライブ！スーパースター!! DUO', 'kind' => 'pb_duo',
         'pack_size' => 3,
         'packs_per_box' => 20,
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2026/02/27171531/LLC_-PB06_box_image.png',
         'pack_style' => 'photo',
         'pack_images' => ['assets/packs/pb_superstar_duo-a.jpg']],
        ['id' => 'pb_hasunosora', 'name_en' => 'Premium Booster Hasunosora', 'name_jp' => 'プレミアムブースター 蓮ノ空女学院スクールアイドルクラブ',
         'filter' => 'プレミアムブースター 蓮ノ空女学院スクールアイドルクラブ', 'kind' => 'pb',
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/11/17105656/L_TCG_-PBP_06_box_image.png',
         'pack_style' => 'photo',
         'pack_images' => ['assets/packs/pb_hasunosora-a.jpg']],
    ];
}

/** PR pool for ranked win rewards (not sold as a booster box). */
function tcgPrCardPoolBox(): array {
    return [
        'id' => 'pr_cards',
        'filter' => 'PRカード',
        'kind' => 'pr',
    ];
}

function tcgBoosterBoxById(string $boxId): ?array {
    foreach (tcgBoosterBoxes() as $box) {
        if ($box['id'] === $boxId) {
            return $box;
        }
    }
    return null;
}

function tcgPickPackWrapper(array $box): ?string {
    $images = $box['pack_images'] ?? [];
    if (is_array($images) && count($images) > 0) {
        return $images[array_rand($images)];
    }
    $single = $box['pack_image'] ?? null;
    if (is_string($single) && $single !== '') {
        return $single;
    }
    // Do not fall back to box picker image (official 3D box render).
    return null;
}

function tcgStarterDecks(): array {
    return [
        ['id' => 'muse', 'label' => "μ's Start Deck",
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/04/09153747/L_TCG_SD02_BOX_image.png'],
        ['id' => 'hasunosora', 'label' => 'Hasunosora Start Deck',
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/10/20192829/L_TCG_SD03_BOX_image-_02.png'],
        ['id' => 'nijigasaki', 'label' => 'Nijigasaki Start Deck',
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2024/12/24144340/L_TCG_SD_02_BOX_image.png'],
        ['id' => 'sunshine', 'label' => 'Sunshine!! Start Deck',
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/10/20192128/L_TCG_SD03_BOX_image-_01.png'],
        ['id' => 'liella', 'label' => 'Liella! Start Deck',
         'image' => 'https://llofficial-cardgame.com/wordpress/wp-content/uploads/2024/12/24145933/L_TCG_SD_01_BOX_image.png'],
    ];
}

function tcgStarterDeckKeys(): array {
    return array_column(tcgStarterDecks(), 'id');
}

function tcgStarterLabel(string $key): string {
    foreach (tcgStarterDecks() as $deck) {
        if ($deck['id'] === $key) {
            return $deck['label'];
        }
    }
    return $key;
}

/** Decode legacy deck names stored with htmlspecialchars (e.g. μ&#039;s → μ's). */
function tcgNormalizeDeckPresetName(?string $name): string {
    if ($name === null || $name === '') {
        return '';
    }
    return html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function tcgStarterImage(string $key): string {
    foreach (tcgStarterDecks() as $deck) {
        if ($deck['id'] === $key) {
            return $deck['image'];
        }
    }
    return '';
}

function tcgBaseSlotRarityWeights(): array {
    return [
        ['r' => 'N', 'w' => 400],
        ['r' => 'R', 'w' => 350],
        ['r' => 'L', 'w' => 180],
        ['r' => 'R+', 'w' => 70],
        ['r' => 'PR', 'w' => 30],
        ['r' => 'PR+', 'w' => 20],
    ];
}

function tcgFoilSlotRarityWeights(): array {
    return [
        ['r' => 'SECE', 'w' => 3],
        ['r' => 'SEC', 'w' => 4],
        ['r' => 'SECL', 'w' => 4],
        ['r' => 'SEC+', 'w' => 2],
        ['r' => 'LLE', 'w' => 2],
        ['r' => 'AR', 'w' => 8],
        ['r' => 'PE+', 'w' => 25],
        ['r' => 'P+', 'w' => 80],
        ['r' => 'SRE', 'w' => 40],
        ['r' => 'RM', 'w' => 33],
        ['r' => 'P', 'w' => 5000],
        ['r' => 'PE', 'w' => 2000],
        ['r' => 'RE', 'w' => 1500],
        ['r' => 'L+', 'w' => 70],
    ];
}

function tcgBoxPackSize(array $box): int {
    return max(1, intval($box['pack_size'] ?? TCG_PACK_SIZE));
}

function tcgBoxPacksPerBox(array $box): int {
    return max(1, intval($box['packs_per_box'] ?? TCG_PACKS_PER_BOX));
}

/** Map fullwidth + / card-number suffixes into pool rarity keys. */
function tcgNormalizePoolRarity(string $rarity, string $cardNo = ''): string {
    $r = str_replace(['＋', '　'], ['+', ''], $rarity);
    if ($cardNo !== '' && preg_match('/-(DUO|PP|SRL|SECS|SECL|SECE|PE2|P2)$/', $cardNo, $m)) {
        $suffix = $m[1];
        if ($suffix === 'P2') {
            return 'P+';
        }
        if ($suffix === 'PE2') {
            return 'PE+';
        }
        return $suffix;
    }
    return $r;
}

/** DUO slot 1 — non-foil commons (official: 1 of 3 cards). */
function tcgPbDuoCommonSlotRarityWeights(): array {
    return [
        ['r' => 'N', 'w' => 52],
        ['r' => 'R', 'w' => 48],
    ];
}

/**
 * DUO holo slots (official: 2 of 3 cards are kira).
 * Weights mirror pb_superstar foil tiers but swap SRE→SRL and add DUO/PP/SECS.
 */
function tcgPbDuoHoloSlotRarityWeights(): array {
    return [
        ['r' => 'SECE', 'w' => 3],
        ['r' => 'SECS', 'w' => 2],
        ['r' => 'DUO', 'w' => 4],
        ['r' => 'SECL', 'w' => 6],
        ['r' => 'LLE', 'w' => 8],
        ['r' => 'L+', 'w' => 12],
        ['r' => 'L', 'w' => 25],
        ['r' => 'PE+', 'w' => 120],
        ['r' => 'PP', 'w' => 400],
        ['r' => 'P+', 'w' => 350],
        ['r' => 'SRL', 'w' => 3500],
    ];
}

/** Two pack indices per 20-pack DUO box where all three cards are holo. */
function tcgPbDuoAllHoloPackIndices(string $discordId, string $boxId, int $boxNumber, int $packsPerBox): array {
    $seed = crc32($discordId . '|' . $boxId . '|' . $boxNumber);
    mt_srand($seed);
    $indices = range(1, $packsPerBox);
    shuffle($indices);
    mt_srand();
    return [$indices[0], $indices[1]];
}

function tcgPickPbDuoHolo(array $pools, array &$progress, int $packsPerBox): ?string {
    $progress['pplus_pity'] = intval($progress['pplus_pity']) + 1;
    $progress['pe_pity'] = intval($progress['pe_pity']) + 1;
    $progress['sec_pity'] = intval($progress['sec_pity']) + 1;

    if ($progress['sec_pity'] >= $packsPerBox * 10) {
        $secPool = array_merge(
            $pools['SECE'] ?? [],
            $pools['SECS'] ?? [],
            $pools['DUO'] ?? [],
            $pools['SECL'] ?? []
        );
        if (!empty($secPool)) {
            $progress['sec_pity'] = 0;
            $picked = tcgPickFromPool($secPool);
            if ($picked) {
                tcgApplyFoilPityReset(tcgRarityForCardNo($picked, $pools) ?? 'SECL', $progress);
            }
            return $picked;
        }
    }
    if ($progress['pe_pity'] >= $packsPerBox && !empty($pools['PE+'])) {
        $progress['pe_pity'] = 0;
        return tcgPickFromPool($pools['PE+']);
    }
    if ($progress['pplus_pity'] >= $packsPerBox * 4 && !empty($pools['P+'])) {
        $progress['pplus_pity'] = 0;
        return tcgPickFromPool($pools['P+']);
    }

    $picked = tcgPickWeightedRarity($pools, tcgPbDuoHoloSlotRarityWeights());
    if (!$picked) {
        foreach (['SRL', 'PP', 'P+', 'PE+', 'L', 'LLE', 'SECL'] as $r) {
            if (!empty($pools[$r])) {
                return tcgPickFromPool($pools[$r]);
            }
        }
        return null;
    }

    $rarity = tcgRarityForCardNo($picked, $pools);
    if ($rarity) {
        tcgApplyFoilPityReset($rarity, $progress);
    }
    return $picked;
}

function tcgRollPbDuoPack(array $pools, array &$progress, int $packsPerBox, bool $allHoloPack): array {
    $slots = [];
    if ($allHoloPack) {
        for ($i = 0; $i < 3; $i++) {
            $slots[] = tcgPickPbDuoHolo($pools, $progress, $packsPerBox);
        }
        return array_values(array_filter($slots));
    }
    $slots[] = tcgPickWeightedRarity($pools, tcgPbDuoCommonSlotRarityWeights())
        ?: tcgPickFromPool($pools['N']) ?: tcgPickFromPool($pools['R']);
    $slots[] = tcgPickPbDuoHolo($pools, $progress, $packsPerBox);
    $slots[] = tcgPickPbDuoHolo($pools, $progress, $packsPerBox);
    return array_values(array_filter($slots));
}

function tcgPrFifthSlotRarityWeights(): array {
    return [
        ['r' => 'PR', 'w' => 925],
        ['r' => 'PR+', 'w' => 75],
    ];
}

function tcgWeightedRarityWeightTotal(array $pools, array $weights): int {
    $total = 0;
    foreach ($weights as $entry) {
        if (!empty($pools[$entry['r']])) {
            $total += $entry['w'];
        }
    }
    return $total;
}

/** Per-pull probability for one card from a uniform pool slot. */
function tcgCardProbUniformPool(array $pool, string $cardNo): float {
    if (empty($pool) || !in_array($cardNo, $pool, true)) {
        return 0.0;
    }
    return 1.0 / count($pool);
}

/** Per-pull probability for one card from a rarity-weighted slot (no pity). */
function tcgCardProbWeightedSlot(array $pools, array $weights, string $cardNo): float {
    $rarity = tcgRarityForCardNo($cardNo, $pools);
    if (!$rarity || empty($pools[$rarity]) || !in_array($cardNo, $pools[$rarity], true)) {
        return 0.0;
    }
    $total = tcgWeightedRarityWeightTotal($pools, $weights);
    if ($total <= 0) {
        return 0.0;
    }
    $w = 0;
    foreach ($weights as $entry) {
        if ($entry['r'] === $rarity) {
            $w = $entry['w'];
            break;
        }
    }
    if ($w <= 0) {
        return 0.0;
    }
    return ($w / $total) / count($pools[$rarity]);
}

/** Per-pull rarity chance in a weighted slot (before splitting within rarity). */
function tcgRarityProbWeightedSlot(array $pools, array $weights, string $rarity): float {
    if (empty($pools[$rarity])) {
        return 0.0;
    }
    $total = tcgWeightedRarityWeightTotal($pools, $weights);
    if ($total <= 0) {
        return 0.0;
    }
    foreach ($weights as $entry) {
        if ($entry['r'] === $rarity) {
            return $entry['w'] / $total;
        }
    }
    return 0.0;
}

function tcgPrPackCommonSlotPool(array $pools): array {
    $prPool = $pools['PR'] ?? [];
    if (!empty($prPool)) {
        return $prPool;
    }
    return $pools['PR+'] ?? [];
}

/**
 * Approximate pull rates for one pack (no pity counters).
 * Card percent = chance the card appears at least once in the 5-card pack.
 * Rarity percent = expected share of pack slots for that rarity (sums to ~100%).
 */
function tcgComputeBoosterPackRates(array $box, array $cardsData): array {
    $pools = tcgBuildBoxPools($cardsData, $box);
    $packSize = tcgBoxPackSize($box);
    $cardMap = [];
    foreach ($cardsData['cards'] ?? [] as $c) {
        $no = $c['card_no'] ?? '';
        if ($no !== '') {
            $cardMap[$no] = $c;
        }
    }

    $allNos = [];
    foreach ($pools as $nos) {
        foreach ($nos as $no) {
            $allNos[$no] = true;
        }
    }

    $slotCardProbs = [];
    $slotRarityProbs = [];
    $notes = [];

    if (($box['kind'] ?? '') === 'pr') {
        $commonPool = tcgPrPackCommonSlotPool($pools);
        $fifthWeights = tcgPrFifthSlotRarityWeights();
        for ($i = 0; $i < TCG_PACK_SIZE - 1; $i++) {
            $slotCardProbs[$i] = [];
            foreach ($commonPool as $no) {
                $slotCardProbs[$i][$no] = tcgCardProbUniformPool($commonPool, $no);
            }
            $slotRarityProbs[$i] = [];
            foreach (['PR', 'PR+'] as $r) {
                if (!empty($pools[$r])) {
                    $slotRarityProbs[$i][$r] = count(array_intersect($commonPool, $pools[$r])) / count($commonPool);
                }
            }
        }
        $last = TCG_PACK_SIZE - 1;
        $slotCardProbs[$last] = [];
        $slotRarityProbs[$last] = [];
        foreach (array_keys($allNos) as $no) {
            $p = tcgCardProbWeightedSlot($pools, $fifthWeights, $no);
            if ($p > 0) {
                $slotCardProbs[$last][$no] = $p;
            }
        }
        foreach (['PR', 'PR+'] as $r) {
            $rp = tcgRarityProbWeightedSlot($pools, $fifthWeights, $r);
            if ($rp > 0) {
                $slotRarityProbs[$last][$r] = $rp;
            }
        }
        $notes[] = 'Slots 1–4 pull uniformly from the PR pool. Slot 5 uses PR / PR+ rarity weights.';
        if (($box['filter'] ?? '') === 'PRカード') {
            $notes[] = 'Starter-deck basic energy cards (LL-E-*-SD) and plain PR energies (LL-E-002-PR, LL-E-004-PR) are excluded from this pool.';
        }
    } elseif (($box['kind'] ?? '') === 'pb_duo') {
        $commonWeights = tcgPbDuoCommonSlotRarityWeights();
        $holoWeights = tcgPbDuoHoloSlotRarityWeights();
        $slotDefs = [
            ['weights' => $commonWeights],
            ['weights' => $holoWeights],
            ['weights' => $holoWeights],
        ];
        foreach ($slotDefs as $i => $def) {
            $slotCardProbs[$i] = [];
            $slotRarityProbs[$i] = [];
            foreach (array_keys($allNos) as $no) {
                $p = tcgCardProbWeightedSlot($pools, $def['weights'], $no);
                if ($p > 0) {
                    $slotCardProbs[$i][$no] = $p;
                }
            }
            foreach ($def['weights'] as $entry) {
                $rp = tcgRarityProbWeightedSlot($pools, $def['weights'], $entry['r']);
                if ($rp > 0) {
                    $slotRarityProbs[$i][$entry['r']] = $rp;
                }
            }
        }
        $notes[] = 'DUO premium pack: 3 cards. Slot 1 is N/R; slots 2–3 are guaranteed holo (PP, P+, SRL, SECL, …).';
        $notes[] = sprintf(
            'Each %d-pack box also contains 2 all-holo packs (not reflected in per-pack percentages).',
            tcgBoxPacksPerBox($box)
        );
        $notes[] = 'Approximate rates without box pity counters.';
    } else {
        $nPool = $pools['N'] ?? [];
        $rPool = $pools['R'] ?? [];
        $baseWeights = tcgBaseSlotRarityWeights();
        $foilWeights = tcgFoilSlotRarityWeights();
        $slotDefs = [
            ['pool' => $nPool],
            ['pool' => $nPool],
            ['pool' => $rPool],
            ['weights' => $baseWeights],
            ['weights' => $foilWeights],
        ];
        foreach ($slotDefs as $i => $def) {
            $slotCardProbs[$i] = [];
            $slotRarityProbs[$i] = [];
            if (isset($def['pool'])) {
                foreach ($def['pool'] as $no) {
                    $slotCardProbs[$i][$no] = tcgCardProbUniformPool($def['pool'], $no);
                }
                $rarity = tcgRarityForCardNo($def['pool'][0] ?? '', $pools);
                if ($rarity) {
                    $slotRarityProbs[$i][$rarity] = 1.0;
                }
            } else {
                foreach (array_keys($allNos) as $no) {
                    $p = tcgCardProbWeightedSlot($pools, $def['weights'], $no);
                    if ($p > 0) {
                        $slotCardProbs[$i][$no] = $p;
                    }
                }
                foreach ($def['weights'] as $entry) {
                    $rp = tcgRarityProbWeightedSlot($pools, $def['weights'], $entry['r']);
                    if ($rp > 0) {
                        $slotRarityProbs[$i][$entry['r']] = $rp;
                    }
                }
            }
        }
        $notes[] = 'Approximate rates without box pity counters. Slot 5 foil pity may improve premium pulls over many packs.';
        $notes[] = sprintf(
            'Box opens (%d packs) share pity with single packs for this set: ~1 RM / box, ~1 PE+ / box when available, Live pity after dry packs, rarer SEC/P+ over multiple boxes — similar to real Loveca box guarantees.',
            tcgBoxPacksPerBox($box)
        );
        if (!empty($pools['LLE'])) {
            $godPct = round(100 / TCG_GOD_PACK_ODDS, 4);
            $notes[] = sprintf('God Pack (~%s%%): all five cards are LLE when triggered.', rtrim(rtrim(number_format($godPct, 4, '.', ''), '0'), '.'));
        }
    }

    $rarityExpected = [];
    foreach ($slotRarityProbs as $byRarity) {
        foreach ($byRarity as $r => $p) {
            $rarityExpected[$r] = ($rarityExpected[$r] ?? 0.0) + $p;
        }
    }
    $rarityRates = [];
    foreach ($rarityExpected as $r => $expected) {
        $rarityRates[] = [
            'rarity' => $r,
            'percent' => round($expected / $packSize * 100, 4),
        ];
    }
    usort($rarityRates, static function ($a, $b) {
        return $b['percent'] <=> $a['percent'] ?: strcmp($a['rarity'], $b['rarity']);
    });

    $cardsOut = [];
    foreach (array_keys($allNos) as $no) {
        $pAny = 0.0;
        foreach ($slotCardProbs as $byCard) {
            $pSlot = $byCard[$no] ?? 0.0;
            if ($pSlot > 0) {
                $pAny = 1.0 - (1.0 - $pAny) * (1.0 - $pSlot);
            }
        }
        if ($pAny <= 0) {
            continue;
        }
        $c = $cardMap[$no] ?? ['card_no' => $no];
        $cardsOut[] = [
            'card_no' => $no,
            'name_en' => $c['name_en'] ?? $c['name'] ?? $no,
            'rarity' => $c['rarity'] ?? tcgRarityForCardNo($no, $pools) ?? '?',
            'image' => $c['image'] ?? null,
            'percent' => round($pAny * 100, 4),
        ];
    }
    usort($cardsOut, static function ($a, $b) {
        return $b['percent'] <=> $a['percent'] ?: strcmp($a['card_no'], $b['card_no']);
    });

    return [
        'box' => ['id' => $box['id'], 'name_en' => $box['name_en'] ?? $box['id']],
        'pack_size' => $packSize,
        'pool_size' => count($allNos),
        'rarity_rates' => $rarityRates,
        'cards' => $cardsOut,
        'notes' => $notes,
    ];
}

function tcgBuildBoxPools(array $cardsData, array $box): array {
    $filter = $box['filter'];
    $pools = [
        'N' => [], 'R' => [], 'R+' => [], 'P' => [], 'P+' => [], 'PP' => [],
        'L' => [], 'L+' => [], 'LLE' => [], 'PE' => [], 'PE+' => [],
        'SEC' => [], 'SECL' => [], 'SEC+' => [], 'SECE' => [], 'SECS' => [],
        'SRE' => [], 'SRL' => [], 'DUO' => [], 'AR' => [],
        'PR' => [], 'PR+' => [],
    ];
    foreach ($cardsData['cards'] ?? [] as $c) {
        if (($c['booster_pack'] ?? '') !== $filter) {
            continue;
        }
        if ($filter === 'PRカード' && !tcgCardEligibleForPrBoosterPool($c)) {
            continue;
        }
        $cardNo = $c['card_no'] ?? '';
        $r = tcgNormalizePoolRarity($c['rarity'] ?? 'N', $cardNo);
        if (!isset($pools[$r])) {
            $pools[$r] = [];
        }
        $pools[$r][] = $cardNo;
    }
    return $pools;
}

function tcgPickFromPool(array $pool): ?string {
    if (empty($pool)) {
        return null;
    }
    return $pool[array_rand($pool)];
}

function tcgPickWeightedRarity(array $pools, array $weights): ?string {
    $options = [];
    $total = 0;
    foreach ($weights as $entry) {
        $r = $entry['r'];
        if (!empty($pools[$r])) {
            $options[] = $entry;
            $total += $entry['w'];
        }
    }
    if ($total <= 0) {
        return null;
    }
    $roll = mt_rand(1, $total);
    $acc = 0;
    $chosenR = $options[0]['r'];
    foreach ($options as $opt) {
        $acc += $opt['w'];
        if ($roll <= $acc) {
            $chosenR = $opt['r'];
            break;
        }
    }
    return tcgPickFromPool($pools[$chosenR]);
}

function tcgPickBaseSlot(array $pools, ?array &$progress = null): ?string {
    if ($progress !== null) {
        $forced = tcgMaybeForceLivePick($pools, $progress);
        if ($forced !== null) {
            return $forced;
        }
    }
    $picked = tcgPickWeightedRarity($pools, tcgBaseSlotRarityWeights())
        ?: tcgPickFromPool($pools['N']) ?: tcgPickFromPool($pools['R']);
    return $picked;
}

function tcgIsLiveRarity(?string $rarity): bool {
    return in_array($rarity, ['L', 'L+', 'LLE'], true);
}

function tcgPickForcedLiveCard(array $pools): ?string {
    foreach (['L', 'L+', 'LLE'] as $r) {
        if (!empty($pools[$r])) {
            return tcgPickFromPool($pools[$r]);
        }
    }
    return null;
}

function tcgMaybeForceLivePick(array $pools, array &$progress): ?string {
    if (intval($progress['live_pity'] ?? 0) < 9) {
        return null;
    }
    $forced = tcgPickForcedLiveCard($pools);
    if ($forced !== null) {
        $progress['live_pity'] = 0;
    }
    return $forced;
}

function tcgNoteLivePullInPack(array $pulled, array $pools, array &$progress): void {
    foreach ($pulled as $no) {
        if (!$no) {
            continue;
        }
        if (tcgIsLiveRarity(tcgRarityForCardNo($no, $pools))) {
            $progress['live_pity'] = 0;
            return;
        }
    }
    $progress['live_pity'] = intval($progress['live_pity'] ?? 0) + 1;
}

function tcgApplyFoilPityReset(string $rarity, array &$progress): void {
    if ($rarity === 'P+') {
        $progress['pplus_pity'] = 0;
    }
    if ($rarity === 'PE+') {
        $progress['pe_pity'] = 0;
    }
    if (in_array($rarity, ['SEC', 'SECL', 'SECE', 'SEC+', 'SECS', 'DUO'], true)) {
        $progress['sec_pity'] = 0;
    }
    if ($rarity === 'RM') {
        $progress['rm_pity'] = 0;
    }
    if (tcgIsLiveRarity($rarity)) {
        $progress['live_pity'] = 0;
    }
}

function tcgRarityForCardNo(string $cardNo, array $pools): ?string {
    foreach ($pools as $rarity => $nos) {
        if (in_array($cardNo, $nos, true)) {
            return $rarity;
        }
    }
    return null;
}

/** Tier 3 standard pack foils + Tier 2 premium + Tier 1 master chases (slot 5). */
function tcgPickGuaranteedFoil(array $pools, array &$progress): ?string {
    $forced = tcgMaybeForceLivePick($pools, $progress);
    if ($forced !== null) {
        return $forced;
    }
    $progress['pplus_pity'] = intval($progress['pplus_pity']) + 1;
    $progress['pe_pity'] = intval($progress['pe_pity']) + 1;
    $progress['sec_pity'] = intval($progress['sec_pity']) + 1;
    $progress['rm_pity'] = intval($progress['rm_pity']) + 1;

    if ($progress['rm_pity'] >= TCG_PACKS_PER_BOX && !empty($pools['RM'])) {
        $progress['rm_pity'] = 0;
        return tcgPickFromPool($pools['RM']);
    }
    if ($progress['sec_pity'] >= TCG_PACKS_PER_BOX * 12) {
        $secPool = array_merge(
            $pools['SECE'] ?? [],
            $pools['SEC'] ?? [],
            $pools['SECL'] ?? [],
            $pools['SEC+'] ?? []
        );
        if (!empty($secPool)) {
            $progress['sec_pity'] = 0;
            $picked = tcgPickFromPool($secPool);
            if ($picked) {
                tcgApplyFoilPityReset(tcgRarityForCardNo($picked, $pools) ?? 'SEC', $progress);
            }
            return $picked;
        }
    }
    if ($progress['pe_pity'] >= TCG_PACKS_PER_BOX && !empty($pools['PE+'])) {
        $progress['pe_pity'] = 0;
        return tcgPickFromPool($pools['PE+']);
    }
    if ($progress['pplus_pity'] >= TCG_PACKS_PER_BOX * 5 && !empty($pools['P+'])) {
        $progress['pplus_pity'] = 0;
        return tcgPickFromPool($pools['P+']);
    }

    $picked = tcgPickWeightedRarity($pools, tcgFoilSlotRarityWeights());

    if (!$picked) {
        foreach (['P', 'PE', 'RE', 'L+', 'P+', 'PE+'] as $r) {
            if (!empty($pools[$r])) {
                return tcgPickFromPool($pools[$r]);
            }
        }
        return null;
    }

    $rarity = tcgRarityForCardNo($picked, $pools);
    if ($rarity) {
        tcgApplyFoilPityReset($rarity, $progress);
    }
    return $picked;
}

function tcgGodPackLleLineup(string $boxId, array $llePool): array {
    $lineups = [
        'bp_vol1' => [
            'PL!-bp1-000-LLE',
            'PL!HS-bp1-000-LLE',
            'PL!N-bp1-000-LLE',
            'PL!S-bp1-000-LLE',
            'PL!SP-bp1-000-LLE',
        ],
    ];
    if (isset($lineups[$boxId])) {
        $set = array_values(array_filter($lineups[$boxId], static fn($no) => in_array($no, $llePool, true)));
        if (count($set) >= TCG_PACK_SIZE) {
            return array_slice($set, 0, TCG_PACK_SIZE);
        }
    }
    $pool = $llePool;
    shuffle($pool);
    return array_slice($pool, 0, min(TCG_PACK_SIZE, count($pool)));
}

function tcgRollGodPack(array $pools, string $boxId): ?array {
    if (empty($pools['LLE'])) {
        return null;
    }
    if (mt_rand(1, TCG_GOD_PACK_ODDS) !== 1) {
        return null;
    }
    $slots = tcgGodPackLleLineup($boxId, $pools['LLE']);
    if (count($slots) < TCG_PACK_SIZE) {
        return null;
    }
    return $slots;
}

/** PR Card Pack — five promo pulls (no commons/foil structure; pool is PR / PR+ only). */
function tcgRollPrPack(array $pools): array {
    $prPool = $pools['PR'] ?? [];
    $prPlusPool = $pools['PR+'] ?? [];
    if (empty($prPool) && empty($prPlusPool)) {
        throw new Exception('PR card pool is empty');
    }
    $fallback = static function () use ($prPool, $prPlusPool): ?string {
        return tcgPickFromPool($prPool) ?: tcgPickFromPool($prPlusPool);
    };
    $slots = [];
    for ($i = 0; $i < TCG_PACK_SIZE - 1; $i++) {
        $slots[] = $fallback();
    }
    $slots[] = tcgPickWeightedRarity($pools, tcgPrFifthSlotRarityWeights()) ?: $fallback();
    return array_values(array_filter($slots));
}

function tcgGetBoxProgress(string $discordId, string $boxId): array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_box_progress WHERE discord_id = ? AND box_id = ?');
    $stmt->execute([$discordId, $boxId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['rm_pity'] = intval($row['rm_pity'] ?? 0);
        $row['live_pity'] = intval($row['live_pity'] ?? 0);
        return $row;
    }
    $db->prepare('INSERT INTO tcg_box_progress (discord_id, box_id) VALUES (?, ?)')
        ->execute([$discordId, $boxId]);
    return [
        'discord_id' => $discordId,
        'box_id' => $boxId,
        'packs_in_box' => 0,
        'boxes_opened' => 0,
        'pe_pity' => 0,
        'pplus_pity' => 0,
        'sec_pity' => 0,
        'rm_pity' => 0,
        'live_pity' => 0,
    ];
}

function tcgSaveBoxProgress(array $progress): void {
    $db = tcgDb();
    $db->prepare('INSERT INTO tcg_box_progress
        (discord_id, box_id, packs_in_box, boxes_opened, pe_pity, pplus_pity, sec_pity, rm_pity, live_pity)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(discord_id, box_id) DO UPDATE SET
            packs_in_box = excluded.packs_in_box,
            boxes_opened = excluded.boxes_opened,
            pe_pity = excluded.pe_pity,
            pplus_pity = excluded.pplus_pity,
            sec_pity = excluded.sec_pity,
            rm_pity = excluded.rm_pity,
            live_pity = excluded.live_pity')
        ->execute([
            $progress['discord_id'],
            $progress['box_id'],
            intval($progress['packs_in_box']),
            intval($progress['boxes_opened']),
            intval($progress['pe_pity']),
            intval($progress['pplus_pity']),
            intval($progress['sec_pity']),
            intval($progress['rm_pity'] ?? 0),
            intval($progress['live_pity'] ?? 0),
        ]);
}

function tcgSyncWelcomeBonusState(string $discordId, array $row, string $today): array {
    $db = tcgDb();
    $lastDate = $row['last_open_date'] ?? null;
    $openedToday = ($lastDate === $today) ? intval($row['packs_opened_today']) : 0;
    $flagUsed = intval($row['first_day_bonus_used']) === 1;

    // Welcome = up to TCG_WELCOME_DAY_PACK_LIMIT on the first calendar day you open boosters only.
    $welcomeEligible = !$flagUsed && ($lastDate === null || $lastDate === $today);

    $markUsed = false;
    if (!$flagUsed) {
        if ($lastDate !== null && $lastDate !== $today) {
            // A prior JST day already passed — welcome window closed even if they opened < quota then.
            $welcomeEligible = false;
            $markUsed = true;
        } elseif ($lastDate === $today && $openedToday >= TCG_WELCOME_DAY_PACK_LIMIT) {
            // Finished (or exceeded) welcome quota today.
            $welcomeEligible = false;
            $markUsed = true;
        }
    }

    if ($markUsed) {
        $db->prepare('UPDATE tcg_daily_state SET first_day_bonus_used = 1 WHERE discord_id = ?')
            ->execute([$discordId]);
    }

    $limit = $welcomeEligible ? TCG_WELCOME_DAY_PACK_LIMIT : TCG_DAILY_PACK_LIMIT;
    return [
        'date_jst' => $today,
        'date_utc' => $today, // legacy key; value is JST calendar date
        'limit' => $limit,
        'opened_today' => $openedToday,
        'remaining' => max(0, $limit - $openedToday),
        'first_day_bonus' => $welcomeEligible,
    ];
}

function tcgDailyOpenAllowance(string $discordId): array {
    $db = tcgDb();
    $today = tcgTodayJst();
    $stmt = $db->prepare('SELECT * FROM tcg_daily_state WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->prepare('INSERT INTO tcg_daily_state (discord_id) VALUES (?)')->execute([$discordId]);
        $row = ['last_open_date' => null, 'packs_opened_today' => 0, 'first_day_bonus_used' => 0];
    }
    return tcgSyncWelcomeBonusState($discordId, $row, $today);
}

function tcgRecordDailyOpen(string $discordId): void {
    $db = tcgDb();
    $today = tcgTodayJst();
    $allow = tcgDailyOpenAllowance($discordId);
    if ($allow['remaining'] <= 0) {
        throw new Exception('No booster packs remaining today');
    }
    $opened = $allow['opened_today'] + 1;
    $markFirstDone = ($allow['first_day_bonus'] && $opened >= $allow['limit']) ? 1 : 0;
    if ($allow['opened_today'] === 0) {
        $db->prepare('UPDATE tcg_daily_state SET last_open_date = ?, packs_opened_today = 1,
            first_day_bonus_used = CASE WHEN ? = 1 THEN 1 ELSE first_day_bonus_used END
            WHERE discord_id = ?')
            ->execute([$today, $markFirstDone, $discordId]);
    } else {
        $db->prepare('UPDATE tcg_daily_state SET packs_opened_today = packs_opened_today + 1,
            first_day_bonus_used = CASE WHEN ? = 1 THEN 1 ELSE first_day_bonus_used END
            WHERE discord_id = ?')
            ->execute([$markFirstDone, $discordId]);
    }
    if ($markFirstDone) {
        $db->prepare('UPDATE tcg_daily_state SET first_day_bonus_used = 1 WHERE discord_id = ?')
            ->execute([$discordId]);
    }
}

/** Roll one booster pack (pity/box progress persisted; no collection or daily charge). */
function tcgRollBoosterPack(string $discordId, string $boxId, array $cardsData): array {
    $box = tcgBoosterBoxById($boxId);
    if (!$box) {
        throw new Exception('Unknown booster box');
    }
    $pools = tcgBuildBoxPools($cardsData, $box);
    $progress = tcgGetBoxProgress($discordId, $boxId);
    $packsPerBox = tcgBoxPacksPerBox($box);
    $godPack = false;
    $slots = [];

    if (($box['kind'] ?? '') === 'pr') {
        $slots = tcgRollPrPack($pools);
    } elseif (($box['kind'] ?? '') === 'pb_duo') {
        $packIndex = intval($progress['packs_in_box']) + 1;
        $boxNumber = intval($progress['boxes_opened']) + 1;
        [$holoA, $holoB] = tcgPbDuoAllHoloPackIndices($discordId, $boxId, $boxNumber, $packsPerBox);
        $allHoloPack = ($packIndex === $holoA || $packIndex === $holoB);
        $slots = tcgRollPbDuoPack($pools, $progress, $packsPerBox, $allHoloPack);
    } else {
        $godSlots = tcgRollGodPack($pools, $boxId);
        if ($godSlots !== null) {
            $godPack = true;
            $slots = $godSlots;
        } else {
            for ($i = 0; $i < 2; $i++) {
                $slots[] = tcgPickFromPool($pools['N']) ?: tcgPickFromPool($pools['R']);
            }
            $slots[] = tcgPickFromPool($pools['R']) ?: tcgPickFromPool($pools['N']);
            $slots[] = tcgPickBaseSlot($pools, $progress);
            $foil = tcgPickGuaranteedFoil($pools, $progress);
            $slots[] = $foil ?: tcgPickFromPool($pools['P']) ?: tcgPickFromPool($pools['PE']);
        }
    }

    $progress['packs_in_box'] = intval($progress['packs_in_box']) + 1;
    if ($progress['packs_in_box'] >= $packsPerBox) {
        $progress['packs_in_box'] = 0;
        $progress['boxes_opened'] = intval($progress['boxes_opened']) + 1;
    }

    $pulled = [];
    foreach ($slots as $no) {
        if ($no) {
            $pulled[] = $no;
        }
    }
    if (($box['kind'] ?? '') !== 'pr' && ($box['kind'] ?? '') !== 'pb_duo' && !$godPack) {
        tcgNoteLivePullInPack($pulled, $pools, $progress);
    }
    tcgSaveBoxProgress($progress);

    return [
        'box' => ['id' => $box['id'], 'name_en' => $box['name_en']],
        'card_nos' => $pulled,
        'god_pack' => $godPack,
        'pack_wrapper' => tcgPickPackWrapper($box),
        'pack_style' => $box['pack_style'] ?? 'photo',
        'box_progress' => [
            'packs_in_box' => intval($progress['packs_in_box']),
            'boxes_opened' => intval($progress['boxes_opened']),
        ],
    ];
}

function tcgFormatBoosterOpenCards(array $cardNos, array $pullMeta, array $cardMap): array {
    $cardsOut = [];
    foreach ($cardNos as $i => $no) {
        $meta = $pullMeta[$i] ?? ['converted' => false, 'star_gems' => 0];
        $base = $cardMap[$no] ?? ['card_no' => $no];
        $cardsOut[] = array_merge($base, [
            'converted' => !empty($meta['converted']),
            'star_gems' => intval($meta['star_gems'] ?? 0),
        ]);
    }
    return $cardsOut;
}

function tcgOpenBoosterPack(string $discordId, string $boxId, array $cardsData, string $payment = 'daily'): array {
    $box = tcgBoosterBoxById($boxId);
    if (!$box) {
        throw new Exception('Unknown booster box');
    }
    $cardMap = tcgBuildCardMap($cardsData);
    $payment = trim(strtolower($payment));
    if ($payment === '' || $payment === 'auto') {
        $allow = tcgDailyOpenAllowance($discordId);
        $payment = ($allow['remaining'] > 0) ? 'daily' : 'gems';
    }

    if ($payment === 'gems_box') {
        return tcgOpenBoosterBoxWithGems($discordId, $boxId, $cardsData);
    }

    $gemsSpent = 0;
    if ($payment === 'daily') {
        tcgRecordDailyOpen($discordId);
    } elseif ($payment === 'gems') {
        tcgDeductStarGems($discordId, TCG_STAR_GEMS_PACK_COST);
        $gemsSpent = TCG_STAR_GEMS_PACK_COST;
    } else {
        throw new Exception('Invalid booster payment mode');
    }

    $roll = tcgRollBoosterPack($discordId, $boxId, $cardsData);
    $gemResult = tcgApplyBoosterPullWithGems($discordId, $roll['card_nos'], $cardMap);

    return [
        'box' => $roll['box'],
        'cards' => tcgFormatBoosterOpenCards($roll['card_nos'], $gemResult['pulls'], $cardMap),
        'card_nos' => $roll['card_nos'],
        'pulls' => $gemResult['pulls'],
        'god_pack' => $roll['god_pack'],
        'pack_wrapper' => $roll['pack_wrapper'],
        'pack_style' => $roll['pack_style'],
        'payment' => $payment,
        'star_gems_spent' => $gemsSpent,
        'star_gems_earned' => $gemResult['star_gems_earned'],
        'star_gems' => $gemResult['star_gems'],
        'daily' => tcgDailyOpenAllowance($discordId),
        'box_progress' => $roll['box_progress'],
        'mode' => 'pack',
    ];
}

function tcgOpenBoosterBoxWithGems(string $discordId, string $boxId, array $cardsData): array {
    $box = tcgBoosterBoxById($boxId);
    if (!$box) {
        throw new Exception('Unknown booster box');
    }
    if (($box['kind'] ?? '') === 'pr') {
        throw new Exception('PR Card Pack cannot be opened as a full booster box');
    }
    tcgDeductStarGems($discordId, TCG_STAR_GEMS_BOX_COST);
    $cardMap = tcgBuildCardMap($cardsData);
    $packsPerBox = tcgBoxPacksPerBox($box);

    $packsOut = [];
    $allCardNos = [];
    $allPullMeta = [];
    $totalGemsEarned = 0;
    $godPackCount = 0;

    for ($p = 0; $p < $packsPerBox; $p++) {
        $roll = tcgRollBoosterPack($discordId, $boxId, $cardsData);
        if (!empty($roll['god_pack'])) {
            $godPackCount++;
        }
        $gemResult = tcgApplyBoosterPullWithGems($discordId, $roll['card_nos'], $cardMap);
        $totalGemsEarned += $gemResult['star_gems_earned'];
        $packCards = tcgFormatBoosterOpenCards($roll['card_nos'], $gemResult['pulls'], $cardMap);
        $packsOut[] = [
            'index' => $p + 1,
            'cards' => $packCards,
            'card_nos' => $roll['card_nos'],
            'pulls' => $gemResult['pulls'],
            'god_pack' => $roll['god_pack'],
            'pack_wrapper' => $roll['pack_wrapper'],
            'pack_style' => $roll['pack_style'],
        ];
        foreach ($roll['card_nos'] as $i => $no) {
            $allCardNos[] = $no;
            $allPullMeta[] = $gemResult['pulls'][$i] ?? ['converted' => false, 'star_gems' => 0];
        }
    }

    $progress = tcgGetBoxProgress($discordId, $boxId);

    return [
        'box' => ['id' => $box['id'], 'name_en' => $box['name_en']],
        'mode' => 'box',
        'packs' => $packsOut,
        'cards' => tcgFormatBoosterOpenCards($allCardNos, $allPullMeta, $cardMap),
        'card_nos' => $allCardNos,
        'pulls' => $allPullMeta,
        'god_pack' => $godPackCount > 0,
        'god_pack_count' => $godPackCount,
        'payment' => 'gems_box',
        'star_gems_spent' => TCG_STAR_GEMS_BOX_COST,
        'star_gems_earned' => $totalGemsEarned,
        'star_gems' => tcgGetStarGems($discordId),
        'daily' => tcgDailyOpenAllowance($discordId),
        'box_progress' => [
            'packs_in_box' => intval($progress['packs_in_box']),
            'boxes_opened' => intval($progress['boxes_opened']),
        ],
    ];
}

function tcgGetStarterDeckLists(string $starterKey, array $cardsData): array {
    if (!in_array($starterKey, tcgStarterDeckKeys(), true)) {
        throw new Exception('Invalid starter deck');
    }
    $deck = $cardsData['starter_decks'][$starterKey] ?? null;
    if (!$deck) {
        throw new Exception('Starter deck not found in card data');
    }
    return [
        'main_deck' => array_values($deck['main_deck'] ?? []),
        'energy_deck' => array_values($deck['energy_deck'] ?? []),
        'name' => tcgStarterLabel($starterKey),
    ];
}

function tcgReadCardsDataFile(): array {
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }
    $path = __DIR__ . '/cards.json';
    if (!file_exists($path)) {
        $cached = ['cards' => [], 'starter_decks' => []];
        return $cached;
    }
    $cached = json_decode(file_get_contents($path), true) ?: ['cards' => [], 'starter_decks' => []];
    return $cached;
}

function tcgUserUsesRankedStarterEquip(string $discordId): bool {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT ranked_equipped_starter, starter_deck FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row
        && intval($row['ranked_equipped_starter'] ?? 0) === 1
        && !empty($row['starter_deck']);
}

function tcgClearRankedStarterEquip(string $discordId): void {
    tcgDb()->prepare('UPDATE tcg_users SET ranked_equipped_starter = 0, updated_at = ? WHERE discord_id = ?')
        ->execute([time(), $discordId]);
}

function tcgSetRankedStarterEquip(string $discordId): void {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT starter_deck FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (empty($row['starter_deck'])) {
        throw new Exception('No starter deck on this account');
    }
    $db->prepare('UPDATE tcg_deck_presets SET equipped = 0 WHERE discord_id = ?')->execute([$discordId]);
    $db->prepare('UPDATE tcg_users SET ranked_equipped_starter = 1, updated_at = ? WHERE discord_id = ?')
        ->execute([time(), $discordId]);
}

/** Ranked loadout: equipped preset, or account starter when ranked_equipped_starter is set. */
function tcgGetEquippedDeckRow(string $discordId): ?array {
    if (tcgUserUsesRankedStarterEquip($discordId)) {
        $db = tcgDb();
        $stmt = $db->prepare('SELECT starter_deck FROM tcg_users WHERE discord_id = ?');
        $stmt->execute([$discordId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (empty($user['starter_deck'])) {
            return null;
        }
        $lists = tcgGetStarterDeckLists($user['starter_deck'], tcgReadCardsDataFile());
        return [
            'slot' => null,
            'name' => $lists['name'],
            'main_deck' => json_encode(array_values($lists['main_deck'])),
            'energy_deck' => json_encode(array_values($lists['energy_deck'])),
            'equipped' => 1,
            'source' => 'starter',
        ];
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_deck_presets WHERE discord_id = ? AND equipped = 1 LIMIT 1');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['source'] = 'preset';
    return $row;
}

function tcgWriteDeckPreset(string $discordId, int $slot, string $name, array $main, array $energy, ?bool $equip = null): void {
    if ($slot < 1 || $slot > TCG_MAX_DECK_PRESETS) {
        throw new Exception('Deck slot must be 1–' . TCG_MAX_DECK_PRESETS);
    }
    $db = tcgDb();
    $now = time();
    if ($equip === null) {
        $stmt = $db->prepare('SELECT equipped FROM tcg_deck_presets WHERE discord_id = ? AND slot = ?');
        $stmt->execute([$discordId, $slot]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $equip = $row ? (intval($row['equipped']) === 1) : ($slot === 1);
    }
    if ($equip) {
        $db->prepare('UPDATE tcg_deck_presets SET equipped = 0 WHERE discord_id = ?')->execute([$discordId]);
        tcgClearRankedStarterEquip($discordId);
    }
    $db->prepare('INSERT INTO tcg_deck_presets (discord_id, slot, name, main_deck, energy_deck, equipped, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(discord_id, slot) DO UPDATE SET
            name = excluded.name,
            main_deck = excluded.main_deck,
            energy_deck = excluded.energy_deck,
            equipped = excluded.equipped,
            updated_at = excluded.updated_at')
        ->execute([
            $discordId, $slot, $name,
            json_encode(array_values($main)),
            json_encode(array_values($energy)),
            $equip ? 1 : 0,
            $now,
        ]);
}

function tcgSaveStarterPreset(string $discordId, string $starterKey, array $cardsData, int $slot = 1, bool $equip = true): void {
    $lists = tcgGetStarterDeckLists($starterKey, $cardsData);
    $cardMap = tcgBuildCardMap($cardsData);
    $owned = tcgGetCollectionMap($discordId);
    $validation = tcgValidateDeckLists($lists['main_deck'], $lists['energy_deck'], $cardMap, $owned);
    if (!$validation['valid']) {
        throw new Exception('Starter deck validation failed: ' . implode('; ', $validation['errors']));
    }
    tcgWriteDeckPreset($discordId, $slot, $lists['name'], $lists['main_deck'], $lists['energy_deck'], $equip);
}

function tcgEnsureStarterPresetSlot1(string $discordId, string $starterKey, array $cardsData): bool {
    if ($starterKey === '') {
        return false;
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT slot, main_deck, energy_deck FROM tcg_deck_presets WHERE discord_id = ? AND slot = 1');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $main = json_decode($row['main_deck'], true) ?: [];
        $energy = json_decode($row['energy_deck'], true) ?: [];
        if (count($main) + count($energy) > 0) {
            return false;
        }
    }
    tcgSaveStarterPreset($discordId, $starterKey, $cardsData, 1, true);
    return true;
}

function tcgGrantStarterDeck(string $discordId, string $starterKey, array $cardsData): array {
    if (!in_array($starterKey, tcgStarterDeckKeys(), true)) {
        throw new Exception('Invalid starter deck');
    }
    $deck = $cardsData['starter_decks'][$starterKey] ?? null;
    if (!$deck) {
        throw new Exception('Starter deck not found in card data');
    }
    $all = array_merge($deck['main_deck'] ?? [], $deck['energy_deck'] ?? []);
    tcgAddCardsToCollection($discordId, $all);
    tcgSaveStarterPreset($discordId, $starterKey, $cardsData, 1);
    $db = tcgDb();
    $db->prepare('UPDATE tcg_users SET starter_deck = ?, updated_at = ? WHERE discord_id = ?')
        ->execute([$starterKey, time(), $discordId]);
    return [
        'starter_deck' => $starterKey,
        'label' => $deck['name_en'] ?? $deck['name'] ?? $starterKey,
        'cards_granted' => count($all),
        'preset_slot' => 1,
    ];
}
