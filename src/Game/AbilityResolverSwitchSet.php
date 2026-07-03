<?php
/**
 * Set Live score / center printed stats / required hearts — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchSet(
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
        case 'set_live_score_if_yell_or_excess':
            $noBlade = true;
            foreach ($state['_last_yell_cards'] ?? [] as $yc) {
                if (!empty($yc['blade_hearts'])) {
                    $noBlade = false;
                    break;
                }
            }
            $excessOk = intval($ctx['excess_hearts'] ?? 0) >= intval($ab['min_excess_hearts'] ?? 2);
            if ($noBlade || $excessOk) {
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        $lc['score'] = intval($ab['score'] ?? 4);
                        break;
                    }
                }
                unset($lc);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score set to ' . intval($ab['score'] ?? 4) . '.');
            }
            break;

        case 'set_center_group_hearts':
            $center = $p['stage']['center'] ?? null;
            if ($center && ($center['group'] ?? '') === ($ab['group'] ?? '')) {
                $cnt = intval($ab['heart_count'] ?? 3);
                $center['printed_heart_override'] = $cnt;
                $p['stage']['center'] = $center;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Center Member printed hearts set to $cnt.");
            }
            break;

        case 'set_center_group_blades':
            $center = $p['stage']['center'] ?? null;
            if ($center && ($center['group'] ?? '') === ($ab['group'] ?? '')) {
                $cnt = intval($ab['blade_count'] ?? 3);
                $center['printed_blade_override'] = $cnt;
                $p['stage']['center'] = $center;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Center Member printed Blades set to $cnt.");
            }
            break;


        case 'set_required_hearts_if_distinct_group':
            if (countDistinctGroupStageWr(
                $p,
                $ab['group'] ?? '',
                $ab['filter'] ?? 'member'
            ) < intval($ab['min_distinct'] ?? 5)) {
                break;
            }
            foreach ($p['live_zone'] as &$lc) {
                if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $lc['required_hearts'] = $ab['hearts'] ?? [];
                    break;
                }
            }
            unset($lc);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Required Hearts modified (distinct group).');
            break;

    }
    return $state;
}
