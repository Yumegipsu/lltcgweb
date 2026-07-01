<?php
/**
 * Random legal Loveca deck builder (60 main + 12 energy).
 * Targets 4 → 9 → 15 baton ramp, heart-heavy fillers, and color-aligned Lives.
 */

const DECKGEN_MEMBER_SLOTS = 48;
const DECKGEN_LIVE_SLOTS   = 12;
const DECKGEN_ENERGY_SLOTS = 12;
const DECKGEN_MAX_COPIES   = 4;
const DECKGEN_MAX_ENERGY_COPIES = 12;

const DECKGEN_GROUPS = ["μ's", 'Nijigasaki', 'Sunshine', 'Superstar', 'Hasunosora'];

function deckgenGroupDisplay(string $group): string {
    return match ($group) {
        'Sunshine'   => 'Aqours',
        'Superstar'  => 'Liella!',
        default      => $group,
    };
}

function deckgenMemberHeartTotal(array $card): int {
    $n = 0;
    foreach ($card['hearts'] ?? [] as $h) {
        $n += intval($h['count'] ?? 1);
    }
    return $n;
}

function deckgenMemberHeartColors(array $card): array {
    $out = [];
    foreach ($card['hearts'] ?? [] as $h) {
        $color = $h['color'] ?? '';
        $cnt   = intval($h['count'] ?? 1);
        for ($i = 0; $i < $cnt; $i++) {
            $out[] = $color;
        }
    }
    foreach ($card['blade_hearts'] ?? [] as $bh) {
        if (is_string($bh) && $bh !== '') {
            $out[] = $bh;
        }
    }
    return $out;
}

function deckgenLiveRequiredColors(array $card): array {
    $out = [];
    foreach ($card['required_hearts'] ?? [] as $h) {
        $c = $h['color'] ?? '';
        if ($c !== '') {
            $out[] = $c;
        }
    }
    return $out;
}

function deckgenRebuildCounts(array $cardNos): array {
    $counts = [];
    foreach ($cardNos as $no) {
        $counts[$no] = ($counts[$no] ?? 0) + 1;
    }
    return $counts;
}

function deckgenAddCopies(array &$list, string $cardNo, int $want, array &$counts, ?array $owned = null): int {
    $have = $counts[$cardNo] ?? 0;
    $cap  = DECKGEN_MAX_COPIES - $have;
    if ($owned !== null) {
        $cap = min($cap, max(0, ($owned[$cardNo] ?? 0) - $have));
    }
    $add = min($want, $cap);
    for ($i = 0; $i < $add; $i++) {
        $list[]            = $cardNo;
        $counts[$cardNo] = ($counts[$cardNo] ?? 0) + 1;
    }
    return $add;
}

function deckgenMemberBuildScore(array $card): int {
    $score = deckgenMemberHeartTotal($card);
    $score += intval($card['blade'] ?? 0) * 2;
    $score += count($card['abilities'] ?? []) * 5;
    $cost = intval($card['cost'] ?? 0);
    if ($cost >= 13) {
        $score += 6;
    } elseif ($cost >= 9) {
        $score += 5;
    } elseif ($cost >= 5) {
        $score += 2;
    } elseif ($cost === 4) {
        $score += 2;
    }
    $rarity = (string)($card['rarity'] ?? '');
    if (preg_match('/^(SEC|SECE|SECL|LLE?)/', $rarity) || str_contains($rarity, 'SEC')) {
        $score += 8;
    } elseif (preg_match('/^(R\+|P\+|PE\+|AR|L\+)/', $rarity)) {
        $score += 4;
    } elseif (preg_match('/^(RR|SR|RE)/', $rarity)) {
        $score += 2;
    }
    return $score;
}

/** Cost curve bucket for auto-build (4→9→15 ramp plus mid/high lines). */
function deckgenMemberCostTier(int $cost): string {
    if ($cost === 4) {
        return 'ramp4';
    }
    if ($cost <= 3) {
        return 'low';
    }
    if ($cost <= 8) {
        return 'mid';
    }
    if ($cost <= 12) {
        return 'high';
    }
    return 'top';
}

function deckgenMembersInCostTier(array $pool, string $tier): array {
    return array_values(array_filter($pool, function ($c) use ($tier) {
        return deckgenMemberCostTier(intval($c['cost'] ?? 0)) === $tier;
    }));
}

function deckgenMemberCurveSortScore(array $card): int {
    $tierWeight = match (deckgenMemberCostTier(intval($card['cost'] ?? 0))) {
        'top'   => 50,
        'high'  => 38,
        'mid'   => 24,
        'ramp4' => 12,
        'low'   => 4,
        default => 0,
    };
    return $tierWeight * 100 + deckgenMemberBuildScore($card);
}

function deckgenFillCostTierPool(
    array $tierPool,
    int $targetCopies,
    int $pickCount,
    array &$main,
    array &$counts,
    ?array $owned,
    callable $scoreFn
): int {
    if ($targetCopies <= 0 || empty($tierPool)) {
        return 0;
    }
    $picks = deckgenPickCandidates($tierPool, min($pickCount, count($tierPool)), $scoreFn);
    if (empty($picks)) {
        return 0;
    }
    $added = 0;
    $n = count($picks);
    $base = intdiv($targetCopies, $n);
    $rem = $targetCopies % $n;
    foreach ($picks as $i => $c) {
        $want = $base + ($i < $rem ? 1 : 0);
        if ($want <= 0) {
            continue;
        }
        $added += deckgenAddCopies($main, $c['card_no'], $want, $counts, $owned);
    }
    return $added;
}

/**
 * Build 48 member slots with a balanced curve: fewer chaff, more mid/high/top.
 * Targets (48 total): 8× cost-4 ramp · 6× low · 12× mid · 14× high (9–12) · 8× top (13+).
 */
function deckgenBuildBalancedMemberMain(array $memberPool, ?array $owned, ?callable $scoreFn = null): array {
    $scoreFn ??= fn($c) => deckgenMemberBuildScore($c);
    $main = [];
    $counts = [];
    $membersAdded = 0;

    $tierTargets = [
        'ramp4' => ['copies' => 8,  'picks' => 3],
        'low'   => ['copies' => 6,  'picks' => 2],
        'mid'   => ['copies' => 12, 'picks' => 4],
        'high'  => ['copies' => 14, 'picks' => 3],
        'top'   => ['copies' => 8,  'picks' => 2],
    ];

    foreach ($tierTargets as $tier => $cfg) {
        $membersAdded += deckgenFillCostTierPool(
            deckgenMembersInCostTier($memberPool, $tier),
            $cfg['copies'],
            $cfg['picks'],
            $main,
            $counts,
            $owned,
            $scoreFn
        );
    }

    $fillOrder = ['mid', 'high', 'top', 'ramp4', 'low'];
    $guard = 0;
    while ($membersAdded < DECKGEN_MEMBER_SLOTS && $guard++ < 800) {
        $progress = false;
        foreach ($fillOrder as $tier) {
            if ($membersAdded >= DECKGEN_MEMBER_SLOTS) {
                break 2;
            }
            $pool = deckgenMembersInCostTier($memberPool, $tier);
            usort($pool, fn($a, $b) => $scoreFn($b) <=> $scoreFn($a));
            foreach ($pool as $c) {
                $added = deckgenAddCopies($main, $c['card_no'], 1, $counts, $owned);
                if ($added > 0) {
                    $membersAdded += $added;
                    $progress = true;
                    break;
                }
            }
            if ($progress) {
                break;
            }
        }
        if (!$progress) {
            break;
        }
    }

    if ($membersAdded < DECKGEN_MEMBER_SLOTS) {
        $ranked = $memberPool;
        usort($ranked, fn($a, $b) => deckgenMemberCurveSortScore($b) <=> deckgenMemberCurveSortScore($a));
        $rankIdx = 0;
        $guard = 0;
        while ($membersAdded < DECKGEN_MEMBER_SLOTS && $guard++ < 800) {
            if ($rankIdx >= count($ranked)) {
                $rankIdx = 0;
            }
            $c = $ranked[$rankIdx++];
            $added = deckgenAddCopies($main, $c['card_no'], 1, $counts, $owned);
            if ($added > 0) {
                $membersAdded += $added;
            }
        }
    }

    while ($membersAdded > DECKGEN_MEMBER_SLOTS) {
        array_pop($main);
        $membersAdded--;
    }

    return $main;
}

function deckgenLiveBuildScore(array $live, array $colorCounts): int {
    $score = deckgenLiveFitScore($live, $colorCounts);
    $score += intval($live['score'] ?? 0) * 2;
    $score += count($live['abilities'] ?? []) * 4;
    $rarity = (string)($live['rarity'] ?? '');
    if (preg_match('/^(SEC|SECE|SECL|LLE?)/', $rarity) || str_contains($rarity, 'SEC')) {
        $score += 6;
    } elseif (preg_match('/^(R\+|P\+|PE\+|AR|L\+)/', $rarity)) {
        $score += 3;
    }
    return $score;
}

function deckgenPickGroupFromCollection(array $members, ?string $forced, array $owned): string {
    if ($forced !== null && $forced !== '' && deckgenGroupCanRamp($members, $forced)) {
        return $forced;
    }
    $valid = array_values(array_filter(
        DECKGEN_GROUPS,
        fn($g) => deckgenGroupCanRamp($members, $g)
    ));
    if (!empty($valid)) {
        usort($valid, function ($a, $b) use ($members, $owned) {
            return deckgenGroupOwnedScore($members, $b, $owned) <=> deckgenGroupOwnedScore($members, $a, $owned);
        });
        return $valid[0];
    }
    $best = 'Nijigasaki';
    $bestScore = -1;
    foreach (DECKGEN_GROUPS as $group) {
        $score = deckgenGroupOwnedScore($members, $group, $owned);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $group;
        }
    }
    return $best;
}

function deckgenGroupOwnedScore(array $members, string $group, array $owned): int {
    $score = 0;
    foreach ($members as $c) {
        if (($c['group'] ?? '') !== $group) {
            continue;
        }
        $qty = intval($owned[$c['card_no'] ?? ''] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $score += $qty * max(1, deckgenMemberBuildScore($c));
    }
    return $score;
}

function deckgenFilterOwnedPool(array $pool, array $owned): array {
    return array_values(array_filter($pool, function ($c) use ($owned) {
        return ($owned[$c['card_no'] ?? ''] ?? 0) > 0;
    }));
}

/** Starter/plain SD energy — fallback once fancy options are exhausted. */
function deckgenIsStandardBasicEnergy(array $card): bool {
    $no = trim($card['card_no'] ?? '');
    if ($no === '') {
        return false;
    }
    if (function_exists('tcgIsStarterBasicEnergyCard') && tcgIsStarterBasicEnergyCard($no)) {
        return true;
    }
    $nameJp = $card['name'] ?? '';
    $nameEn = $card['name_en'] ?? '';
    if (str_contains($nameJp, '無地') || str_contains($nameEn, 'Plain')) {
        return true;
    }
    if (preg_match('/^LL-E-\d+-SD$/i', $no)) {
        return true;
    }
    $plainName = preg_match('/^(Energy Card|Energy|エネルギー(カード)?)(\s|$|\()/iu', trim($nameEn . ' ' . $nameJp));
    return $plainName && (($card['rarity'] ?? '') === 'SD');
}

function deckgenEnergyBuildScore(array $card): int {
    if (deckgenIsStandardBasicEnergy($card)) {
        return 4;
    }
    $score = 100;
    $rarity = (string)($card['rarity'] ?? '');
    if (preg_match('/^(SEC|SECE|SECL|LLE?|RM)/', $rarity) || str_contains($rarity, 'SEC')) {
        $score += 24;
    } elseif (preg_match('/^(RE|L\+)/', $rarity)) {
        $score += 18;
    } elseif (preg_match('/^(PR|AR)/', $rarity)) {
        $score += 14;
    } elseif ($rarity !== '' && $rarity !== 'SD') {
        $score += 10;
    }
    $label = trim($card['name_en'] ?? $card['name'] ?? '');
    if ($label !== '' && !preg_match('/^(Energy Card|Energy|エネルギー)/iu', $label)) {
        $score += 16;
    }
    if (($card['group'] ?? '') === '') {
        $score += 6;
    }
    return $score;
}

function deckgenBuildEnergyDeck(array $energies, ?string $group, ?array $owned = null): array {
    $pool = $energies;
    if ($owned !== null) {
        $pool = deckgenFilterOwnedPool($pool, $owned);
    }
    if (empty($pool)) {
        return [];
    }

    if ($group !== null && $group !== '' && $group !== 'mixed') {
        $groupPool = array_values(array_filter($pool, fn($c) => ($c['group'] ?? '') === $group));
        if (!empty($groupPool)) {
            $pool = $groupPool;
        }
    }

    usort($pool, function ($a, $b) use ($owned) {
        $scoreDiff = deckgenEnergyBuildScore($b) <=> deckgenEnergyBuildScore($a);
        if ($scoreDiff !== 0) {
            return $scoreDiff;
        }
        $qtyA = $owned !== null ? intval($owned[$a['card_no'] ?? ''] ?? 0) : DECKGEN_MAX_ENERGY_COPIES;
        $qtyB = $owned !== null ? intval($owned[$b['card_no'] ?? ''] ?? 0) : DECKGEN_MAX_ENERGY_COPIES;
        if ($qtyA !== $qtyB) {
            return $qtyB <=> $qtyA;
        }
        return strcmp((string)($a['card_no'] ?? ''), (string)($b['card_no'] ?? ''));
    });

    $deck   = [];
    $counts = [];

    $tryAdd = function (array $c, int $want) use (&$deck, &$counts, $owned): int {
        $no = $c['card_no'] ?? '';
        if ($no === '') {
            return 0;
        }
        $have   = $counts[$no] ?? 0;
        $maxOwn = $owned !== null ? intval($owned[$no] ?? 0) : DECKGEN_MAX_ENERGY_COPIES;
        $room   = min(
            $want,
            DECKGEN_MAX_ENERGY_COPIES - $have,
            $maxOwn - $have,
            DECKGEN_ENERGY_SLOTS - count($deck)
        );
        for ($i = 0; $i < $room; $i++) {
            $deck[] = $no;
            $counts[$no] = ($counts[$no] ?? 0) + 1;
        }
        return $room;
    };

    // Pass 1: one copy of each unique energy (fancy first) before any duplicates.
    foreach ($pool as $c) {
        if (count($deck) >= DECKGEN_ENERGY_SLOTS) {
            break;
        }
        $tryAdd($c, 1);
    }

    // Pass 2: round-robin extras — spread copies before stacking the same card.
    $guard = 0;
    while (count($deck) < DECKGEN_ENERGY_SLOTS && $guard++ < 500) {
        $progress = false;
        foreach ($pool as $c) {
            if (count($deck) >= DECKGEN_ENERGY_SLOTS) {
                break;
            }
            if ($tryAdd($c, 1) > 0) {
                $progress = true;
            }
        }
        if (!$progress) {
            break;
        }
    }

    return $deck;
}

function deckgenPickCandidates(array $pool, int $pickCount, callable $scoreFn): array {
    if (empty($pool)) {
        return [];
    }
    $scored = [];
    foreach ($pool as $c) {
        $scored[] = ['card' => $c, 'score' => $scoreFn($c) + mt_rand(0, 4)];
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $out = [];
    for ($i = 0; $i < min($pickCount, count($scored)); $i++) {
        $out[] = $scored[$i]['card'];
    }
    return $out;
}

function deckgenGroupCanRamp(array $members, string $group): bool {
    $has4 = $has9 = $has15 = false;
    foreach ($members as $c) {
        if (($c['group'] ?? '') !== $group) {
            continue;
        }
        $cost = intval($c['cost'] ?? 0);
        if ($cost === 4) {
            $has4 = true;
        } elseif ($cost === 9) {
            $has9 = true;
        } elseif ($cost === 15) {
            $has15 = true;
        }
    }
    return $has4 && $has9 && $has15;
}

function deckgenPickGroup(array $members, ?string $forced): string {
    if ($forced !== null && $forced !== '' && deckgenGroupCanRamp($members, $forced)) {
        return $forced;
    }
    $valid = array_values(array_filter(
        DECKGEN_GROUPS,
        fn($g) => deckgenGroupCanRamp($members, $g)
    ));
    if (empty($valid)) {
        return 'Nijigasaki';
    }
    return $valid[array_rand($valid)];
}

function deckgenColorCountsFromMain(array $mainNos, array $cardMap): array {
    $colors = [];
    foreach ($mainNos as $no) {
        $c = $cardMap[$no] ?? null;
        if (!$c || ($c['card_type'] ?? '') !== 'メンバー') {
            continue;
        }
        foreach (deckgenMemberHeartColors($c) as $color) {
            if ($color === '') {
                continue;
            }
            $colors[$color] = ($colors[$color] ?? 0) + 1;
        }
    }
    return $colors;
}

function deckgenLiveFitScore(array $live, array $colorCounts): int {
    $req = deckgenLiveRequiredColors($live);
    if (empty($req)) {
        return 3;
    }
    $score = 0;
    foreach ($req as $color) {
        if (($colorCounts[$color] ?? 0) > 0) {
            $score += 3;
        } else {
            $score -= 4;
        }
    }
    return $score;
}

function deckgenPickLives(
    array $lives,
    ?string $group,
    array $colorCounts,
    ?array $owned = null,
    ?array $bucketTargets = null,
    ?callable $fitScoreFn = null
): array {
    if ($group !== null && $group !== '') {
        $pool = array_values(array_filter($lives, function ($c) use ($group) {
            $g = $c['group'] ?? '';
            return $g === '' || $g === $group;
        }));
        if (count($pool) < DECKGEN_LIVE_SLOTS) {
            $pool = array_values(array_filter($lives, fn($c) => ($c['group'] ?? '') === $group));
        }
    } else {
        $pool = array_values(array_filter($lives, fn($c) => ($c['card_no'] ?? '') !== ''));
    }
    if ($owned !== null) {
        $pool = deckgenFilterOwnedPool($pool, $owned);
    }
    if (empty($pool)) {
        return [];
    }

    $low  = [];
    $mid  = [];
    $high = [];
    $scoreLive = $fitScoreFn ?? fn($c) => deckgenLiveBuildScore($c, $colorCounts);
    foreach ($pool as $c) {
        $sc = intval($c['score'] ?? 0);
        $fit = $scoreLive($c);
        $entry = ['card' => $c, 'fit' => $fit];
        if ($sc <= 3) {
            $low[] = $entry;
        } elseif ($sc <= 6) {
            $mid[] = $entry;
        } else {
            $high[] = $entry;
        }
    }
    foreach (['low', 'mid', 'high'] as $bucket) {
        usort($$bucket, fn($a, $b) => ($b['fit'] + mt_rand(0, 2)) <=> ($a['fit'] + mt_rand(0, 2)));
    }

    $targets = $bucketTargets ?? ['low' => 4, 'mid' => 4, 'high' => 4];
    $picked  = [];
    $counts  = [];

    foreach ($targets as $bucket => $want) {
        $src   = $$bucket;
        $i     = 0;
        $added = 0;
        while ($added < $want && $i < count($src)) {
            $no = $src[$i]['card']['card_no'] ?? '';
            $i++;
            if ($no === '') {
                continue;
            }
            if (($counts[$no] ?? 0) >= DECKGEN_MAX_COPIES) {
                continue;
            }
            if ($owned !== null && ($counts[$no] ?? 0) >= ($owned[$no] ?? 0)) {
                continue;
            }
            $copies = min(
                DECKGEN_MAX_COPIES - ($counts[$no] ?? 0),
                $want - $added,
                rand(1, 2)
            );
            if ($owned !== null) {
                $copies = min($copies, max(0, ($owned[$no] ?? 0) - ($counts[$no] ?? 0)));
            }
            for ($j = 0; $j < $copies; $j++) {
                $picked[] = $no;
                $counts[$no] = ($counts[$no] ?? 0) + 1;
                $added++;
                if ($added >= $want) {
                    break;
                }
            }
        }
    }

    while (count($picked) < DECKGEN_LIVE_SLOTS) {
        $c  = $pool[array_rand($pool)];
        $no = $c['card_no'] ?? '';
        if ($no === '') {
            continue;
        }
        if (($counts[$no] ?? 0) >= DECKGEN_MAX_COPIES) {
            continue;
        }
        if ($owned !== null && ($counts[$no] ?? 0) >= ($owned[$no] ?? 0)) {
            continue;
        }
        $picked[] = $no;
        $counts[$no] = ($counts[$no] ?? 0) + 1;
    }

    return array_slice($picked, 0, DECKGEN_LIVE_SLOTS);
}

function deckgenPickEnergy(array $energies, ?string $group): string {
    $plain = array_values(array_filter($energies, function ($c) {
        return str_contains($c['name'] ?? '', '無地')
            || str_contains($c['name_en'] ?? '', 'Plain')
            || ($c['group'] ?? '') === '';
    }));
    if ($group === null || $group === '' || $group === 'mixed') {
        if (!empty($plain)) {
            return $plain[array_rand($plain)]['card_no'];
        }
        return $energies[array_rand($energies)]['card_no'] ?? 'LL-E-003-SD';
    }
    $groupPool = array_values(array_filter($energies, fn($c) => ($c['group'] ?? '') === $group));
    $pool = !empty($groupPool) ? $groupPool : $energies;
    usort($pool, function ($a, $b) {
        $score = fn($c) => (($c['rarity'] ?? '') === 'SD' ? 5 : 0)
            + (str_contains($c['name'] ?? '', '無地') ? 3 : 0)
            + (str_contains($c['name_en'] ?? '', 'Plain') ? 3 : 0);
        return $score($b) <=> $score($a);
    });
    return $pool[0]['card_no'] ?? ($energies[0]['card_no'] ?? 'LL-E-003-SD');
}

function generateRandomDeckLists(array $allCards, ?string $forcedGroup = null): array {
    $cardMap  = [];
    $members  = [];
    $lives    = [];
    $energies = [];
    foreach ($allCards as $c) {
        $no = $c['card_no'] ?? '';
        if ($no === '') {
            continue;
        }
        $cardMap[$no] = $c;
        $type = $c['card_type'] ?? '';
        if ($type === 'メンバー') {
            $members[] = $c;
        } elseif ($type === 'ライブ') {
            $lives[] = $c;
        } elseif ($type === 'エネルギー') {
            $energies[] = $c;
        }
    }

    $mixed = ($forcedGroup === null || $forcedGroup === '');
    if ($mixed) {
        $group        = 'mixed';
        $memberPool   = array_values(array_filter($members, fn($c) => ($c['group'] ?? '') !== ''));
        $liveGroup    = null;
        $nameEn       = 'Random Deck';
        $nameJp       = 'ランダムデッキ';
    } else {
        $group        = deckgenPickGroup($members, $forcedGroup);
        $memberPool   = array_values(array_filter($members, fn($c) => ($c['group'] ?? '') === $group));
        $liveGroup    = $group;
        $display      = deckgenGroupDisplay($group);
        $nameEn       = "Random ($display)";
        $nameJp       = "ランダム（$display）";
    }
    $main = deckgenBuildBalancedMemberMain($memberPool, null);
    $counts = deckgenRebuildCounts($main);
    $membersAdded = count($main);

    if ($membersAdded < DECKGEN_MEMBER_SLOTS) {
        throw new Exception('Could not assemble a random deck.');
    }

    $colorCounts = deckgenColorCountsFromMain($main, $cardMap);
    $liveNos     = deckgenPickLives($lives, $liveGroup, $colorCounts);
    $energyNo    = deckgenPickEnergy($energies, $mixed ? null : $group);
    $energyDeck  = array_fill(0, DECKGEN_ENERGY_SLOTS, $energyNo);

    return [
        'group'        => $group,
        'name_en'      => $nameEn,
        'name'         => $nameJp,
        'main_deck'    => array_merge($main, $liveNos),
        'energy_deck'  => $energyDeck,
        'member_count' => count($main),
        'live_count'   => count($liveNos),
    ];
}

function generateCollectionDeckLists(array $allCards, array $owned, ?string $forcedGroup = null, ?array $starterFallback = null): array {
    if (empty($owned)) {
        if ($starterFallback !== null) {
            return deckgenStarterBuildResult($starterFallback);
        }
        throw new Exception('Choose a starter deck first.');
    }

    $cardMap  = [];
    $members  = [];
    $lives    = [];
    $energies = [];
    foreach ($allCards as $c) {
        $no = $c['card_no'] ?? '';
        if ($no === '' || ($owned[$no] ?? 0) <= 0) {
            continue;
        }
        $cardMap[$no] = $c;
        $type = $c['card_type'] ?? '';
        if ($type === 'メンバー') {
            $members[] = $c;
        } elseif ($type === 'ライブ') {
            $lives[] = $c;
        } elseif ($type === 'エネルギー') {
            $energies[] = $c;
        }
    }

    if (count($members) < 1 || count($lives) < 1 || count($energies) < 1) {
        if ($starterFallback !== null) {
            return deckgenStarterBuildResult($starterFallback);
        }
        throw new Exception('Could not assemble a legal deck.');
    }

    if ($forcedGroup === 'mixed') {
        $group = 'mixed';
        $memberPool = $members;
    } else {
        $starterGroup = null;
        $mixed = ($forcedGroup === null || $forcedGroup === '');
        if (!$mixed) {
            $starterGroup = $forcedGroup;
        } else {
            $countsByGroup = [];
            foreach ($members as $c) {
                $g = $c['group'] ?? '';
                if ($g === '') {
                    continue;
                }
                $countsByGroup[$g] = ($countsByGroup[$g] ?? 0) + intval($owned[$c['card_no']] ?? 0);
            }
            if (!empty($countsByGroup)) {
                arsort($countsByGroup);
                $starterGroup = array_key_first($countsByGroup);
            }
        }

        $group = deckgenPickGroupFromCollection($members, $starterGroup, $owned);
        $memberPool = array_values(array_filter($members, fn($c) => ($c['group'] ?? '') === $group));
        if (count($memberPool) < 8) {
            $group = deckgenPickGroupFromCollection($members, null, $owned);
            $memberPool = array_values(array_filter($members, fn($c) => ($c['group'] ?? '') === $group));
        }
        if (empty($memberPool)) {
            $memberPool = $members;
            $group = 'mixed';
        }
    }

    $display = $group === 'mixed' ? 'Mixed' : deckgenGroupDisplay($group);
    $nameEn  = "Auto-built ($display)";

    $main = deckgenBuildBalancedMemberMain($memberPool, $owned);
    $membersAdded = count($main);

    if ($membersAdded < DECKGEN_MEMBER_SLOTS) {
        if ($starterFallback !== null) {
            return deckgenStarterBuildResult($starterFallback);
        }
        throw new Exception('Could not assemble a legal deck.');
    }

    while ($membersAdded > DECKGEN_MEMBER_SLOTS) {
        array_pop($main);
        $membersAdded--;
    }
    $counts = deckgenRebuildCounts($main);

    $liveGroup   = $group === 'mixed' ? null : $group;
    $colorCounts = deckgenColorCountsFromMain($main, $cardMap);
    $liveNos     = deckgenPickLives($lives, $liveGroup, $colorCounts, $owned);
    if (count($liveNos) < DECKGEN_LIVE_SLOTS) {
        if ($starterFallback !== null) {
            return deckgenStarterBuildResult($starterFallback);
        }
        throw new Exception('Could not assemble a legal deck.');
    }

    $energyDeck = deckgenBuildEnergyDeck($energies, $group === 'mixed' ? null : $group, $owned);
    if (count($energyDeck) < DECKGEN_ENERGY_SLOTS) {
        if ($starterFallback !== null) {
            return deckgenStarterBuildResult($starterFallback);
        }
        throw new Exception('Could not assemble a legal deck.');
    }

    return [
        'group'        => $group,
        'name_en'      => $nameEn,
        'name'         => $nameEn,
        'main_deck'    => array_merge($main, $liveNos),
        'energy_deck'  => $energyDeck,
        'member_count' => count($main),
        'live_count'   => count($liveNos),
        'summary'      => $display . ' · balanced curve · hearts + color-matched Lives',
    ];
}

function deckgenStarterBuildResult(array $starterLists): array {
    $mainDeck   = array_values($starterLists['main_deck'] ?? []);
    $energyDeck = array_values($starterLists['energy_deck'] ?? []);
    $label      = $starterLists['name'] ?? 'Starter Deck';
    return [
        'group'        => 'starter',
        'name_en'      => 'Auto-built (' . $label . ')',
        'name'         => 'Auto-built (' . $label . ')',
        'main_deck'    => $mainDeck,
        'energy_deck'  => $energyDeck,
        'member_count' => DECKGEN_MEMBER_SLOTS,
        'live_count'   => DECKGEN_LIVE_SLOTS,
        'summary'      => $label . ' · official starter list',
    ];
}

function previewRandomDeck(string $cardsFile, ?string $forcedGroup = null): array {
    if (!file_exists($cardsFile)) {
        throw new Exception('Card database not found');
    }
    $data = json_decode(file_get_contents($cardsFile), true);
    $gen  = generateRandomDeckLists($data['cards'] ?? [], $forcedGroup);
    return [
        'group'        => $gen['group'],
        'group_display'=> $gen['group'] === 'mixed' ? 'Mixed' : deckgenGroupDisplay($gen['group']),
        'name_en'      => $gen['name_en'],
        'members'      => $gen['member_count'],
        'lives'        => $gen['live_count'],
        'energy'       => DECKGEN_ENERGY_SLOTS,
        'main_total'   => count($gen['main_deck']),
    ];
}

function deckgenStarterKeyToGroup(?string $key): ?string {
    if ($key === null || $key === '') {
        return null;
    }
    return match ($key) {
        'nijigasaki' => 'Nijigasaki',
        'muse'       => "μ's",
        'sunshine'   => 'Sunshine',
        'liella'     => 'Superstar',
        'hasunosora' => 'Hasunosora',
        default      => null,
    };
}

function deckgenCpuMemberScore(array $card, string $tier): int {
    $score = deckgenMemberBuildScore($card);
    if (($card['rarity'] ?? '') !== 'SD') {
        $score += ($tier === 'hard') ? 6 : 3;
    }
    if ($tier === 'hard' && !empty($card['abilities'])) {
        $score += 6;
    } elseif ($tier === 'normal' && !empty($card['abilities'])) {
        $score += 3;
    }
    return $score;
}

function deckgenCpuLiveFitScore(array $live, array $colorCounts, string $tier): int {
    $score = deckgenLiveBuildScore($live, $colorCounts);
    $liveScore = intval($live['score'] ?? 0);
    if ($tier === 'hard') {
        $score += $liveScore * 2;
        if (!empty($live['abilities'])) {
            $score += 8;
        }
    } elseif ($tier === 'normal') {
        $score += $liveScore;
        if (!empty($live['abilities'])) {
            $score += 4;
        }
    }
    return $score;
}

function deckgenCpuMemberPool(array $memberPool, string $tier): array {
    if ($tier === 'easy') {
        return $memberPool;
    }
    $nonSd = array_values(array_filter(
        $memberPool,
        fn($c) => ($c['rarity'] ?? '') !== 'SD'
    ));
    if (count($nonSd) >= 24) {
        return $nonSd;
    }
    return $memberPool;
}

function generateCpuEasyDeckLists(array $starterDecks, ?string $avoidKey = null): array {
    $keys = array_keys($starterDecks);
    if (empty($keys)) {
        throw new Exception('No starter decks configured');
    }
    if ($avoidKey !== null && $avoidKey !== '' && count($keys) > 1) {
        $filtered = array_values(array_filter($keys, fn($k) => $k !== $avoidKey));
        if (!empty($filtered)) {
            $keys = $filtered;
        }
    }
    $key  = $keys[array_rand($keys)];
    $deck = $starterDecks[$key];
    $label = $deck['name_en'] ?? $deck['name'] ?? $key;
    return [
        'group'       => $key,
        'name_en'     => 'CPU · ' . $label,
        'name'        => 'CPU · ' . ($deck['name'] ?? $label),
        'main_deck'   => array_values($deck['main_deck'] ?? []),
        'energy_deck' => array_values($deck['energy_deck'] ?? []),
    ];
}

function generateEnhancedCpuDeckLists(array $allCards, string $tier): array {
    $cardMap  = [];
    $members  = [];
    $lives    = [];
    $energies = [];
    foreach ($allCards as $c) {
        $no = $c['card_no'] ?? '';
        if ($no === '') {
            continue;
        }
        $cardMap[$no] = $c;
        $type = $c['card_type'] ?? '';
        if ($type === 'メンバー') {
            $members[] = $c;
        } elseif ($type === 'ライブ') {
            $lives[] = $c;
        } elseif ($type === 'エネルギー') {
            $energies[] = $c;
        }
    }

    $group      = deckgenPickGroup($members, null);
    $memberPool = deckgenCpuMemberPool(
        array_values(array_filter($members, fn($c) => ($c['group'] ?? '') === $group)),
        $tier
    );
    if (empty($memberPool)) {
        throw new Exception('Could not build CPU deck');
    }

    $display = deckgenGroupDisplay($group);
    $tierLabel = ucfirst($tier);
    $nameEn    = "CPU · $tierLabel ($display)";

    $byCost = [];
    foreach ($memberPool as $c) {
        $byCost[intval($c['cost'] ?? 0)][] = $c;
    }

    $scoreFn = fn($c) => deckgenCpuMemberScore($c, $tier);

    $main         = [];
    $counts       = [];
    $membersAdded = 0;

    $cost4Picks = deckgenPickCandidates(
        $byCost[4] ?? [],
        rand(2, 3),
        $scoreFn
    );
    $ramp4Target = ($tier === 'hard') ? rand(10, 12) : rand(8, 11);
    foreach ($cost4Picks as $i => $c) {
        $share = intdiv($ramp4Target, max(1, count($cost4Picks)));
        $want  = $share + ($i === 0 ? ($ramp4Target % max(1, count($cost4Picks))) : 0);
        $membersAdded += deckgenAddCopies($main, $c['card_no'], $want, $counts);
    }

    $cost9Picks = deckgenPickCandidates($byCost[9] ?? [], rand(1, 2), $scoreFn);
    foreach ($cost9Picks as $i => $c) {
        if ($membersAdded >= DECKGEN_MEMBER_SLOTS) {
            break;
        }
        $want = ($i === 0) ? rand(5, min(8, DECKGEN_MAX_COPIES)) : rand(2, 4);
        $membersAdded += deckgenAddCopies($main, $c['card_no'], $want, $counts);
    }

    $cost15Picks = deckgenPickCandidates($byCost[15] ?? [], 1, $scoreFn);
    if (!empty($cost15Picks)) {
        $membersAdded += deckgenAddCopies(
            $main,
            $cost15Picks[0]['card_no'],
            rand(2, ($tier === 'hard') ? 4 : 3),
            $counts
        );
    }

    $fillers = array_values(array_filter($memberPool, function ($c) use ($counts) {
        $cost = intval($c['cost'] ?? 0);
        if ($cost < 2 || $cost > 6 || $cost === 4) {
            return false;
        }
        if (($counts[$c['card_no']] ?? 0) >= DECKGEN_MAX_COPIES) {
            return false;
        }
        return deckgenMemberHeartTotal($c) > 0;
    }));
    usort($fillers, fn($a, $b) => deckgenCpuMemberScore($b, $tier) <=> deckgenCpuMemberScore($a, $tier));

    $guard = 0;
    while ($membersAdded < DECKGEN_MEMBER_SLOTS && $guard++ < 500) {
        if (empty($fillers)) {
            break;
        }
        $topN = min(8, count($fillers));
        $c    = $fillers[array_rand(array_slice($fillers, 0, $topN))];
        $added = deckgenAddCopies($main, $c['card_no'], rand(1, 2), $counts);
        if ($added === 0) {
            $fillers = array_values(array_filter(
                $fillers,
                fn($x) => ($counts[$x['card_no']] ?? 0) < DECKGEN_MAX_COPIES
            ));
            continue;
        }
        $membersAdded += $added;
    }

    $ranked = $memberPool;
    usort($ranked, fn($a, $b) => deckgenCpuMemberScore($b, $tier) <=> deckgenCpuMemberScore($a, $tier));
    $guard = 0;
    $rankIdx = 0;
    while ($membersAdded < DECKGEN_MEMBER_SLOTS && $guard++ < 500) {
        if ($rankIdx >= count($ranked)) {
            $rankIdx = 0;
        }
        $c     = $ranked[$rankIdx++];
        $added = deckgenAddCopies($main, $c['card_no'], 1, $counts);
        if ($added > 0) {
            $membersAdded += $added;
        }
    }

    while ($membersAdded > DECKGEN_MEMBER_SLOTS) {
        array_pop($main);
        $membersAdded--;
    }
    $counts = deckgenRebuildCounts($main);

    $colorCounts = deckgenColorCountsFromMain($main, $cardMap);
    $liveTargets = ($tier === 'hard')
        ? ['low' => 2, 'mid' => 4, 'high' => 6]
        : ['low' => 3, 'mid' => 5, 'high' => 4];
    $liveFitFn = fn($c) => deckgenCpuLiveFitScore($c, $colorCounts, $tier) + mt_rand(0, 3);
    $liveNos   = deckgenPickLives($lives, $group, $colorCounts, null, $liveTargets, $liveFitFn);
    $energyNo  = deckgenPickEnergy($energies, $group);
    $energyDeck = array_fill(0, DECKGEN_ENERGY_SLOTS, $energyNo);

    return [
        'group'       => $group,
        'name_en'     => $nameEn,
        'name'        => $nameEn,
        'main_deck'   => array_merge($main, $liveNos),
        'energy_deck' => $energyDeck,
    ];
}

function generateCpuDeckLists(
    array $allCards,
    string $difficulty,
    ?string $groupHint,
    array $starterDecks
): array {
    $difficulty = in_array($difficulty, ['easy', 'normal', 'hard'], true) ? $difficulty : 'easy';
    if ($difficulty === 'easy') {
        return generateCpuEasyDeckLists($starterDecks, $groupHint);
    }
    return generateEnhancedCpuDeckLists($allCards, $difficulty);
}

function resolveCpuDeckLists(array $cardsData, string $difficulty, ?string $groupHint = null): array {
    $gen = generateCpuDeckLists(
        $cardsData['cards'] ?? [],
        $difficulty,
        $groupHint,
        $cardsData['starter_decks'] ?? []
    );
    return [
        'deck_choice' => 'cpu:' . (in_array($difficulty, ['easy', 'normal', 'hard'], true) ? $difficulty : 'easy'),
        'deck_label'  => $gen['name_en'],
        'main_nos'    => $gen['main_deck'],
        'energy_nos'  => $gen['energy_deck'],
    ];
}

function resolvePlayerDeckLists(array $cardsData, string $deckChoice, ?string $deckGroup = null): array {
    $decks = $cardsData['starter_decks'] ?? [];
    if ($deckChoice === 'random') {
        $gen = generateRandomDeckLists($cardsData['cards'] ?? [], $deckGroup);
        return [
            'deck_choice' => 'random',
            'deck_label'  => $gen['name_en'],
            'main_nos'    => $gen['main_deck'],
            'energy_nos'  => $gen['energy_deck'],
        ];
    }
    if (!isset($decks[$deckChoice])) {
        $deckChoice = array_key_first($decks) ?: 'nijigasaki';
    }
    $deck = $decks[$deckChoice];
    return [
        'deck_choice' => $deckChoice,
        'deck_label'  => $deck['name_en'] ?? $deck['name'] ?? $deckChoice,
        'main_nos'    => $deck['main_deck'],
        'energy_nos'  => $deck['energy_deck'],
    ];
}
