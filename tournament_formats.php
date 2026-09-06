<?php
/**
 * Tournament Phase 3 — Swiss / double-elim / best-of series helpers.
 */

/** @return array{p1_wins:int,p2_wins:int,best_of:int,games:list<array<string,mixed>>,stats_recorded?:bool} */
function tcgTournamentDecodeMatchMeta(?string $json): array {
    $raw = json_decode((string)$json, true);
    if (!is_array($raw)) {
        $raw = [];
    }
    $bestOf = (int)($raw['best_of'] ?? 1);
    if ($bestOf !== 3) {
        $bestOf = 1;
    }
    $games = is_array($raw['games'] ?? null) ? $raw['games'] : [];
    return [
        'p1_wins' => max(0, (int)($raw['p1_wins'] ?? 0)),
        'p2_wins' => max(0, (int)($raw['p2_wins'] ?? 0)),
        'best_of' => $bestOf,
        'games' => $games,
        'stats_recorded' => !empty($raw['stats_recorded']),
    ];
}

/** @param array<string,mixed> $meta */
function tcgTournamentEncodeMatchMeta(array $meta): string {
    return json_encode($meta, JSON_UNESCAPED_UNICODE) ?: '{}';
}

function tcgTournamentSwissRoundCount(int $playerCount): int {
    $n = max(2, $playerCount);
    $rounds = (int)ceil(log($n, 2));
    return max(3, min(5, $rounds));
}

/** Top-cut size after Swiss: ≤8 → final (top 2); ≥9 showed up → top 4. */
function tcgTournamentSwissPlayoffSize(int $showedUp): int {
    return $showedUp >= 9 ? 4 : 2;
}

/**
 * Pair players for a Swiss round. Prefer similar records; avoid rematches when possible.
 *
 * @param list<string> $playerIds
 * @param array<string,array{wins:int,losses:int}> $records
 * @param list<array{0:string,1:string}> $priorPairs unordered pairs already played
 * @return list<array{p1:?string,p2:?string,bye:?string}>
 */
function tcgTournamentBuildSwissPairings(array $playerIds, array $records, array $priorPairs = []): array {
    $playerIds = array_values(array_filter(array_map('strval', $playerIds), static fn($id) => $id !== ''));
    usort($playerIds, static function ($a, $b) use ($records) {
        $wa = (int)($records[$a]['wins'] ?? 0);
        $wb = (int)($records[$b]['wins'] ?? 0);
        if ($wa !== $wb) {
            return $wb <=> $wa;
        }
        $la = (int)($records[$a]['losses'] ?? 0);
        $lb = (int)($records[$b]['losses'] ?? 0);
        if ($la !== $lb) {
            return $la <=> $lb;
        }
        return strcmp($a, $b);
    });

    $played = [];
    foreach ($priorPairs as $pair) {
        $a = (string)($pair[0] ?? '');
        $b = (string)($pair[1] ?? '');
        if ($a === '' || $b === '') {
            continue;
        }
        $key = $a < $b ? ($a . '|' . $b) : ($b . '|' . $a);
        $played[$key] = true;
    }

    $pairings = [];
    $remaining = $playerIds;
    while (count($remaining) >= 2) {
        $p1 = array_shift($remaining);
        $pickIdx = null;
        foreach ($remaining as $i => $cand) {
            $key = $p1 < $cand ? ($p1 . '|' . $cand) : ($cand . '|' . $p1);
            if (!isset($played[$key])) {
                $pickIdx = $i;
                break;
            }
        }
        if ($pickIdx === null) {
            $pickIdx = 0;
        }
        $p2 = $remaining[$pickIdx];
        array_splice($remaining, $pickIdx, 1);
        $pairings[] = ['p1' => $p1, 'p2' => $p2, 'bye' => null];
    }
    if (count($remaining) === 1) {
        $bye = $remaining[0];
        $pairings[] = ['p1' => $bye, 'p2' => null, 'bye' => $bye];
    }
    return $pairings;
}

/**
 * @param list<array<string,mixed>> $matches
 * @return array<string,array{wins:int,losses:int}>
 */
function tcgTournamentRecordsFromMatches(array $matches, ?string $sideOnly = null): array {
    $out = [];
    foreach ($matches as $m) {
        if ((string)($m['status'] ?? '') !== 'done') {
            continue;
        }
        if ($sideOnly !== null && (string)($m['bracket_side'] ?? '') !== $sideOnly) {
            continue;
        }
        $w = (string)($m['winner_discord_id'] ?? '');
        $p1 = (string)($m['p1_discord_id'] ?? '');
        $p2 = (string)($m['p2_discord_id'] ?? '');
        if ($w === '') {
            continue;
        }
        foreach ([$p1, $p2] as $pid) {
            if ($pid === '') {
                continue;
            }
            if (!isset($out[$pid])) {
                $out[$pid] = ['wins' => 0, 'losses' => 0];
            }
        }
        $out[$w]['wins']++;
        $loser = ($w === $p1) ? $p2 : (($w === $p2) ? $p1 : '');
        if ($loser !== '') {
            $out[$loser]['losses']++;
        }
    }
    return $out;
}

/**
 * Sort player ids by Swiss (or overall) record: wins desc, losses asc, then id.
 *
 * @param list<string> $playerIds
 * @param array<string,array{wins:int,losses:int}> $records
 * @return list<string>
 */
function tcgTournamentSortByRecord(array $playerIds, array $records): array {
    $playerIds = array_values(array_filter(array_map('strval', $playerIds), static fn($id) => $id !== ''));
    usort($playerIds, static function ($a, $b) use ($records) {
        $wa = (int)($records[$a]['wins'] ?? 0);
        $wb = (int)($records[$b]['wins'] ?? 0);
        if ($wa !== $wb) {
            return $wb <=> $wa;
        }
        $la = (int)($records[$a]['losses'] ?? 0);
        $lb = (int)($records[$b]['losses'] ?? 0);
        if ($la !== $lb) {
            return $la <=> $lb;
        }
        return strcmp($a, $b);
    });
    return $playerIds;
}

/**
 * Opponents faced in completed matches (byes excluded).
 *
 * @param list<array<string,mixed>> $matches
 * @return list<string>
 */
function tcgTournamentOpponentsFromMatches(string $playerId, array $matches, ?string $sideOnly = 'swiss'): array {
    $playerId = (string)$playerId;
    if ($playerId === '') {
        return [];
    }
    $seen = [];
    $out = [];
    foreach ($matches as $m) {
        if ((string)($m['status'] ?? '') !== 'done') {
            continue;
        }
        if ($sideOnly !== null && (string)($m['bracket_side'] ?? '') !== $sideOnly) {
            continue;
        }
        $p1 = (string)($m['p1_discord_id'] ?? '');
        $p2 = (string)($m['p2_discord_id'] ?? '');
        if ($p2 === '') {
            continue; // bye
        }
        $opp = '';
        if ($p1 === $playerId) {
            $opp = $p2;
        } elseif ($p2 === $playerId) {
            $opp = $p1;
        }
        if ($opp === '' || isset($seen[$opp])) {
            continue;
        }
        $seen[$opp] = true;
        $out[] = $opp;
    }
    return $out;
}

/**
 * Opponent match-win % (OMW): average of opponents' win rates (wins / games).
 * Byes ignored. Players with no opponents get 0.
 *
 * @param array<string,array{wins:int,losses:int}> $records
 * @param list<array<string,mixed>> $matches
 */
function tcgTournamentOmwPercent(
    string $playerId,
    array $records,
    array $matches,
    ?string $sideOnly = 'swiss'
): float {
    $opps = tcgTournamentOpponentsFromMatches($playerId, $matches, $sideOnly);
    if ($opps === []) {
        return 0.0;
    }
    $sum = 0.0;
    foreach ($opps as $opp) {
        $w = (int)($records[$opp]['wins'] ?? 0);
        $l = (int)($records[$opp]['losses'] ?? 0);
        $games = $w + $l;
        $sum += $games > 0 ? ($w / $games) : 0.0;
    }
    return $sum / count($opps);
}

/**
 * Swiss standing order: wins desc, losses asc, OMW desc, then id.
 *
 * @param list<string> $playerIds
 * @param array<string,array{wins:int,losses:int}> $records
 * @param list<array<string,mixed>> $matches
 * @return list<string>
 */
function tcgTournamentSortBySwissStanding(
    array $playerIds,
    array $records,
    array $matches,
    ?string $sideOnly = 'swiss'
): array {
    $playerIds = array_values(array_filter(array_map('strval', $playerIds), static fn($id) => $id !== ''));
    $omw = [];
    foreach ($playerIds as $id) {
        $omw[$id] = tcgTournamentOmwPercent($id, $records, $matches, $sideOnly);
    }
    usort($playerIds, static function ($a, $b) use ($records, $omw) {
        $wa = (int)($records[$a]['wins'] ?? 0);
        $wb = (int)($records[$b]['wins'] ?? 0);
        if ($wa !== $wb) {
            return $wb <=> $wa;
        }
        $la = (int)($records[$a]['losses'] ?? 0);
        $lb = (int)($records[$b]['losses'] ?? 0);
        if ($la !== $lb) {
            return $la <=> $lb;
        }
        $oa = (float)($omw[$a] ?? 0.0);
        $ob = (float)($omw[$b] ?? 0.0);
        if (abs($oa - $ob) > 0.0000001) {
            return $ob <=> $oa;
        }
        return strcmp($a, $b);
    });
    return $playerIds;
}

/**
 * Prior unordered pairs from completed matches (for rematch avoidance).
 *
 * @param list<array<string,mixed>> $matches
 * @param bool $includeWinnersSide Swiss playoffs use bracket_side=winners and must
 *   stay excluded so Swiss re-pair is not blocked. Double-elim (2 lives) stores
 *   every round as winners — pass true so those pairs count.
 * @return list<array{0:string,1:string}>
 */
function tcgTournamentPriorPairsFromMatches(array $matches, bool $includeWinnersSide = false): array {
    $pairs = [];
    foreach ($matches as $m) {
        $side = (string)($m['bracket_side'] ?? 'swiss');
        if (!$includeWinnersSide && $side === 'winners') {
            // Swiss playoff rematches are fine / expected; don't block Swiss pairing.
            continue;
        }
        $p1 = (string)($m['p1_discord_id'] ?? '');
        $p2 = (string)($m['p2_discord_id'] ?? '');
        if ($p1 !== '' && $p2 !== '') {
            $pairs[] = [$p1, $p2];
        }
    }
    return $pairs;
}

/**
 * Preview empty bracket slots for UI before the event starts.
 *
 * @return list<array{round:int,bracket_slot:int,label:string,bracket_side:string}>
 */
function tcgTournamentBracketPreview(int $playerCap, string $format = 'single_elim', ?int $playoffSize = null): array {
    $format = in_array($format, ['single_elim', 'double_elim', 'double_elim_bracket', 'swiss'], true)
        ? $format
        : 'single_elim';
    $n = max(2, $playerCap);
    $out = [];
    if ($format === 'swiss') {
        $rounds = tcgTournamentSwissRoundCount($n);
        $slots = (int)ceil($n / 2);
        for ($r = 1; $r <= $rounds; $r++) {
            for ($s = 0; $s < $slots; $s++) {
                $out[] = [
                    'round' => $r,
                    'bracket_slot' => $s,
                    'label' => 'Swiss R' . $r,
                    'bracket_side' => 'swiss',
                ];
            }
        }
        $cut = $playoffSize !== null && in_array($playoffSize, [2, 4], true)
            ? $playoffSize
            : tcgTournamentSwissPlayoffSize($n);
        // Never preview a top-4 when the estimated field is ≤8.
        if ($n < 9) {
            $cut = 2;
        }
        if ($cut === 2) {
            $out[] = [
                'round' => 1,
                'bracket_slot' => 0,
                'label' => '',
                'bracket_side' => 'winners',
            ];
        } else {
            $out[] = [
                'round' => 1,
                'bracket_slot' => 0,
                'label' => '',
                'bracket_side' => 'winners',
            ];
            $out[] = [
                'round' => 1,
                'bracket_slot' => 1,
                'label' => '',
                'bracket_side' => 'winners',
            ];
            $out[] = [
                'round' => 2,
                'bracket_slot' => 0,
                'label' => '',
                'bracket_side' => 'winners',
            ];
        }
        return $out;
    }
    if ($format === 'double_elim') {
        // 2-lives re-pair: show swiss-like round slots (no fake W/L tree).
        $rounds = max(3, (int)ceil(log(max(2, $n), 2)));
        $slots = (int)ceil($n / 2);
        for ($r = 1; $r <= $rounds; $r++) {
            for ($s = 0; $s < $slots; $s++) {
                $out[] = [
                    'round' => $r,
                    'bracket_slot' => $s,
                    'label' => 'Round ' . $r,
                    'bracket_side' => 'winners',
                ];
            }
        }
        return $out;
    }
    $size = tcgTournamentBracketSize($n);
    $round = 1;
    for ($slots = (int)($size / 2); $slots >= 1; $slots = (int)($slots / 2), $round++) {
        $label = $slots === 1
            ? ($format === 'double_elim_bracket' ? 'Winners Final' : 'Final')
            : ($slots === 2 ? 'Semifinals' : ('Round of ' . ($slots * 2)));
        for ($s = 0; $s < $slots; $s++) {
            $out[] = [
                'round' => $round,
                'bracket_slot' => $s,
                'label' => $label,
                'bracket_side' => 'winners',
            ];
        }
    }
    if ($format === 'double_elim_bracket') {
        $lCounts = tcgTournamentClassicDeLosersRoundCounts($size);
        foreach ($lCounts as $i => $slots) {
            $lr = $i + 1;
            $label = ($i === count($lCounts) - 1) ? 'Losers Final' : ('Losers R' . $lr);
            for ($s = 0; $s < $slots; $s++) {
                $out[] = [
                    'round' => $lr,
                    'bracket_slot' => $s,
                    'label' => $label,
                    'bracket_side' => 'losers',
                ];
            }
        }
        $out[] = [
            'round' => 1,
            'bracket_slot' => 0,
            'label' => 'Grand Final',
            'bracket_side' => 'grand_final',
        ];
        $out[] = [
            'round' => 2,
            'bracket_slot' => 0,
            'label' => 'GF Reset',
            'bracket_side' => 'grand_final',
        ];
    }
    return $out;
}

function tcgTournamentIsClassicDoubleElim(string $format): bool {
    return $format === 'double_elim_bracket';
}

/** log2 of power-of-two bracket size. */
function tcgTournamentClassicDeWinnersRounds(int $bracketSize): int {
    $size = tcgTournamentBracketSize($bracketSize);
    return max(1, (int)round(log($size, 2)));
}

/**
 * Match counts per losers round (Challonge-style).
 * e.g. size 8 → [2,2,1,1]; size 4 → [1,1]; size 16 → [4,4,2,2,1,1]
 *
 * @return list<int>
 */
function tcgTournamentClassicDeLosersRoundCounts(int $bracketSize): array {
    $size = tcgTournamentBracketSize($bracketSize);
    $k = tcgTournamentClassicDeWinnersRounds($size);
    $totalRounds = 2 * max(1, $k - 1);
    $counts = [];
    $n = max(1, (int)($size / 4));
    while (count($counts) < $totalRounds) {
        $counts[] = $n;
        if (count($counts) >= $totalRounds) {
            break;
        }
        $counts[] = $n;
        $n = (int)($n / 2);
        if ($n < 1) {
            break;
        }
    }
    return array_slice($counts, 0, $totalRounds);
}

/**
 * Destination for a match winner.
 * @return array{side:string,round:int,slot:int,seat:int}|null seat 0=p1, 1=p2
 */
function tcgTournamentClassicDeWinnerDest(int $bracketSize, string $side, int $round, int $slot): ?array {
    $size = tcgTournamentBracketSize($bracketSize);
    $side = (string)$side;
    $round = max(1, $round);
    $slot = max(0, $slot);

    if ($side === 'grand_final') {
        return null; // handled separately (reset / finish)
    }
    if ($side === 'winners') {
        $wRounds = tcgTournamentClassicDeWinnersRounds($size);
        if ($round < $wRounds) {
            return [
                'side' => 'winners',
                'round' => $round + 1,
                'slot' => (int)floor($slot / 2),
                'seat' => $slot % 2,
            ];
        }
        // Winners final champ → GF1 as p1 (WB seat)
        return ['side' => 'grand_final', 'round' => 1, 'slot' => 0, 'seat' => 0];
    }
    if ($side === 'losers') {
        $counts = tcgTournamentClassicDeLosersRoundCounts($size);
        $idx = $round - 1;
        if ($idx < 0 || $idx >= count($counts)) {
            return null;
        }
        if ($idx === count($counts) - 1) {
            // Losers final champ → GF1 as p2 (LB seat)
            return ['side' => 'grand_final', 'round' => 1, 'slot' => 0, 'seat' => 1];
        }
        $cur = (int)$counts[$idx];
        $next = (int)$counts[$idx + 1];
        if ($next === $cur) {
            return ['side' => 'losers', 'round' => $round + 1, 'slot' => $slot, 'seat' => 0];
        }
        return [
            'side' => 'losers',
            'round' => $round + 1,
            'slot' => (int)floor($slot / 2),
            'seat' => $slot % 2,
        ];
    }
    return null;
}

/**
 * Where a winners-bracket loser drops into the losers bracket.
 * @return array{side:string,round:int,slot:int,seat:int}|null
 */
function tcgTournamentClassicDeLoserDrop(int $bracketSize, int $winnersRound, int $slot): ?array {
    $size = tcgTournamentBracketSize($bracketSize);
    $wr = max(1, $winnersRound);
    $slot = max(0, $slot);
    $wRounds = tcgTournamentClassicDeWinnersRounds($size);
    if ($wr > $wRounds) {
        return null;
    }
    if ($wr === 1) {
        return [
            'side' => 'losers',
            'round' => 1,
            'slot' => (int)floor($slot / 2),
            'seat' => $slot % 2,
        ];
    }
    // W2 → L2, W3 → L4, …
    $lr = 2 * ($wr - 1);
    $counts = tcgTournamentClassicDeLosersRoundCounts($size);
    if ($lr < 1 || $lr > count($counts)) {
        return null;
    }
    return [
        'side' => 'losers',
        'round' => $lr,
        'slot' => $slot,
        'seat' => 1,
    ];
}
