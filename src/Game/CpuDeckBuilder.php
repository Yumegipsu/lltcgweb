<?php

declare(strict_types=1);

/**
 * CPU decks for Normal / Hard / Expert: "Starter+".
 *
 * A starter deck is a proven plan (cost ladder, color-matched Lives, a few synergies). Generated decks that tried to
 * out-engineer them lost to the plain starters in CPU-vs-CPU duels, so these decks keep a starter's skeleton and swap
 * cards for strictly better printings. How many cards get swapped is the deck-side difficulty knob.
 */
final class CpuDeckBuilder
{
    private const WILD = ['', 'any', 'gray', 'all', 'wild', 'all2'];

    /** @return array<int,string> blade-heart colors of a Member (draw / score icons are not colors) */
    private static function bladeHeartColors(array $c): array
    {
        $out = [];
        foreach (($c['blade_hearts'] ?? []) as $bh) {
            $t = is_string($bh) ? $bh : (string)($bh['type'] ?? $bh['color'] ?? '');
            if ($t !== '' && $t !== 'draw' && $t !== 'score') {
                $out[] = $t;
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Starter+ decks: keep a starter deck's proven plan (cost ladder, color-matched Lives) and swap cards for strictly
    // better printings. A card B dominates A when it costs no more, has at least A's hearts in every color, at least
    // A's blade, blade hearts and abilities, and is better somewhere.
    // ---------------------------------------------------------------------------------------------------------------

    /** @return array<string,int> color => heart count */
    private static function heartMap(array $c): array
    {
        $m = [];
        foreach (($c['hearts'] ?? []) as $h) {
            $col = (string)($h['color'] ?? '');
            $m[$col] = ($m[$col] ?? 0) + intval($h['count'] ?? 1);
        }
        return $m;
    }

    /** @return array<string,int> blade-heart color => count */
    private static function bladeHeartMap(array $c): array
    {
        $m = [];
        foreach (self::bladeHeartColors($c) as $col) {
            $m[$col] = ($m[$col] ?? 0) + 1;
        }
        return $m;
    }

    /** Positive gain when $b strictly dominates $a as a Member, 0 otherwise. */
    public static function memberUpgradeGain(array $a, array $b): float
    {
        $costA = intval($a['cost'] ?? 0);
        $costB = intval($b['cost'] ?? 0);
        if ($costB > $costA) {
            return 0.0;
        }
        $gain = 0.3 * ($costA - $costB);
        $ha = self::heartMap($a);
        $hb = self::heartMap($b);
        foreach ($ha as $col => $n) {
            if (($hb[$col] ?? 0) < $n) {
                return 0.0;
            }
        }
        foreach ($hb as $col => $n) {
            $gain += 2.0 * max(0, $n - ($ha[$col] ?? 0));
        }
        $bladeDiff = intval($b['blade'] ?? 0) - intval($a['blade'] ?? 0);
        if ($bladeDiff < 0) {
            return 0.0;
        }
        $gain += $bladeDiff;
        $ba = self::bladeHeartMap($a);
        $bb = self::bladeHeartMap($b);
        foreach ($ba as $col => $n) {
            if (($bb[$col] ?? 0) < $n) {
                return 0.0;
            }
        }
        foreach ($bb as $col => $n) {
            $gain += 1.5 * max(0, $n - ($ba[$col] ?? 0));
        }
        $abDiff = count($b['abilities'] ?? []) - count($a['abilities'] ?? []);
        if ($abDiff < 0) {
            return 0.0;
        }
        $gain += 0.5 * $abDiff;
        return $gain;
    }

    /** Positive gain when Live $b is a strictly easier / higher scoring version of $a using the same colors. */
    public static function liveUpgradeGain(array $a, array $b, array $deckColors): float
    {
        $sa = intval($a['score'] ?? 0);
        $sb = intval($b['score'] ?? 0);
        $ra = self::liveNeed($a);
        $rb = self::liveNeed($b);
        if ($sb < $sa) {
            return 0.0;
        }
        foreach ($rb as $col => $n) {
            if (!in_array($col, self::WILD, true) && !in_array($col, $deckColors, true)) {
                return 0.0;
            }
        }
        if (array_sum($rb) > array_sum($ra)) {
            return 0.0;
        }
        foreach ($ra as $col => $n) {
            if (!in_array($col, self::WILD, true) && ($rb[$col] ?? 0) > $n) {
                return 0.0;
            }
        }
        $gain = 2.0 * ($sb - $sa) + 0.5 * (array_sum($ra) - array_sum($rb));
        return $gain;
    }

    /** @return array<string,int> */
    private static function liveNeed(array $c): array
    {
        $m = [];
        foreach (($c['required_hearts'] ?? $c['hearts'] ?? []) as $h) {
            $col = (string)($h['color'] ?? '');
            $m[$col] = ($m[$col] ?? 0) + intval($h['count'] ?? 1);
        }
        return $m;
    }

    /**
     * Upgrade a starter list.
     *
     * @param array<int,string> $starterMain  60 card numbers
     * @param array<string,array<string,mixed>> $cardMap
     * @param array<int,array<string,mixed>> $allMembers
     * @param array<int,array<string,mixed>> $allLives
     * @param float $aggr share of distinct cards that get upgraded (0..1)
     * @return array<int,string>
     */
    public static function upgradeStarter(array $starterMain, array $cardMap, array $allMembers, array $allLives, float $aggr): array
    {
        $order = [];
        foreach ($starterMain as $no) {
            if (!in_array($no, $order, true)) {
                $order[] = $no;
            }
        }
        shuffle($order);
        $counts = deckgenRebuildCounts($starterMain);
        $deckColors = [];
        foreach ($starterMain as $no) {
            $c = $cardMap[$no] ?? null;
            if ($c && ($c['card_type'] ?? '') === 'メンバー') {
                foreach (($c['hearts'] ?? []) as $h) {
                    $deckColors[(string)($h['color'] ?? '')] = true;
                }
            }
        }
        $deckColors = array_keys($deckColors);
        $main = $starterMain;
        foreach ($order as $no) {
            if (mt_rand(0, 999) >= (int)round($aggr * 1000)) {
                continue;
            }
            $orig = $cardMap[$no] ?? null;
            if (!$orig) {
                continue;
            }
            $isLive = ($orig['card_type'] ?? '') === 'ライブ';
            $pool = $isLive ? $allLives : $allMembers;
            $group = (string)($orig['group'] ?? '');
            $cands = [];
            foreach ($pool as $cand) {
                $cno = (string)$cand['card_no'];
                if ($cno === $no || ($group !== '' && ($cand['group'] ?? '') !== $group && ($cand['group'] ?? '') !== '')) {
                    continue;
                }
                $g = $isLive ? self::liveUpgradeGain($orig, $cand, $deckColors) : self::memberUpgradeGain($orig, $cand);
                if ($g > 0.0) {
                    $cands[] = [$g + mt_rand(0, 8) / 10.0, $cand];
                }
            }
            if (!$cands) {
                continue;
            }
            usort($cands, static fn($x, $y) => $y[0] <=> $x[0]);
            $positions = array_keys(array_filter($main, static fn($n) => $n === $no));
            $ci = 0;
            foreach ($positions as $pos) {
                while ($ci < min(3, count($cands))) {
                    $cno = (string)$cands[$ci][1]['card_no'];
                    $without = $main;
                    array_splice($without, $pos, 1);
                    $cc = deckgenRebuildCounts($without);
                    if (deckgenIdentityCount($cc, $cno) < DECKGEN_MAX_COPIES && deckgenLovecaCapCopies($without, $cno, 1) >= 1) {
                        $main[$pos] = $cno;
                        break;
                    }
                    $ci++;
                }
                if ($ci >= min(3, count($cands))) {
                    break;
                }
            }
        }
        return $main;
    }
}
