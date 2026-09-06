<?php
/**
 * Winning-yell self to hand + yell reveal reduction — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchYellAdjunct(
    array $state,
    string $pid,
    array $source,
    array $ab,
    array $ctx,
    string $type,
    array &$p,
    string $name
): array {
    switch ($type) {
        case 'add_self_to_hand_if_winning_yell':
            if (empty($ctx['yell_cards'])) break;
            $inYell = false;
            foreach ($ctx['yell_cards'] as $yc) {
                if (($yc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $inYell = true;
                    break;
                }
            }
            if (!$inYell) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $myScore = array_sum(array_column($p['live_zone'] ?? [], 'score')) + getLiveScoreBonus($state, $pid);
            $oppScore = array_sum(array_column($state['players'][$opp]['live_zone'] ?? [], 'score'))
                + getLiveScoreBonus($state, $opp);
            if ($myScore > $oppScore) {
                $p['hand'][] = $source;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added itself to hand (winning Live score, revealed by Yell).');
            }
            break;

        case 'reduce_yell_reveal_count':
            $minOther = intval($ab['min_other_members'] ?? 0);
            if (!empty($ab['requires_other_members']) || $minOther > 0) {
                $need = max(1, $minOther);
                $others = 0;
                foreach ($p['stage'] as $mbr) {
                    if ($mbr && ($mbr['instance_id'] ?? '') !== ($source['instance_id'] ?? '')) {
                        $others++;
                    }
                }
                if ($others < $need) {
                    break;
                }
            }
            $amount = intval($ab['amount'] ?? $ab['reduce'] ?? 8);
            $state = initLiveModifiers($state);
            $state['live_modifiers'][$pid]['yell_reveal_reduction'] =
                intval($state['live_modifiers'][$pid]['yell_reveal_reduction'] ?? 0)
                + $amount;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Yell reveal count reduced by ' . $amount . ' until Live ends.');
            break;

    }
    return $state;
}
