<?php

declare(strict_types=1);

/**
 * Live-race pick used by tests and as the spec for client cpuLiveRaceAdvice.
 * Public board only: stage hearts, successes, visible Live storage — never a hidden hand.
 */
final class CpuLiveRace
{
    /**
     * @param array{
     *   my_success?:int,
     *   opp_success?:int,
     *   opp_can_clear?:bool,
     *   candidates?:list<array{id:string,score:int,clearable:bool}>
     * } $sit
     * @return array{ids:list<string>,reason:string}
     */
    public static function pick(array $sit): array
    {
        $my = (int)($sit['my_success'] ?? 0);
        $opp = (int)($sit['opp_success'] ?? 0);
        $oppCan = !empty($sit['opp_can_clear']);
        $cands = is_array($sit['candidates'] ?? null) ? $sit['candidates'] : [];
        $clearable = [];
        $blocked = [];
        foreach ($cands as $c) {
            if (!is_array($c) || ($c['id'] ?? '') === '') {
                continue;
            }
            if (!empty($c['clearable'])) {
                $clearable[] = $c;
            } else {
                $blocked[] = $c;
            }
        }
        usort($clearable, static fn($a, $b) => ((int)($b['score'] ?? 0)) <=> ((int)($a['score'] ?? 0)));
        usort($blocked, static fn($a, $b) => ((int)($b['score'] ?? 0)) <=> ((int)($a['score'] ?? 0)));

        if ($clearable) {
            if ($my >= 2) {
                return [
                    'ids' => [(string)$clearable[0]['id']],
                    'reason' => 'take_clear_at_two',
                ];
            }
            if ($oppCan && count($clearable) > 1) {
                return [
                    'ids' => [(string)$clearable[0]['id']],
                    'reason' => 'higher_score_both_clear',
                ];
            }
            return [
                'ids' => [(string)$clearable[0]['id']],
                'reason' => 'commit_clear',
            ];
        }

        if ($opp >= 2 && !empty($sit['bluff'])) {
            if ($blocked) {
                return ['ids' => [(string)$blocked[0]['id']], 'reason' => 'bluff_pressure'];
            }
        }
        return ['ids' => [], 'reason' => 'no_clear'];
    }
}
