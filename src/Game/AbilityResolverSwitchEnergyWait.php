<?php
/**
 * Put Energy from deck into Wait — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchEnergyWait(
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
        case 'both_energy_wait_from_deck':
            foreach (['p1', 'p2'] as $id) {
                $pl = &$state['players'][$id];
                if (putEnergyFromDeckInWait($pl)) {
                    $state = addLog($state, $state['players'][$id]['name'] .
                        " — [$name] put 1 Energy into Wait.");
                }
                unset($pl);
            }
            break;



        case 'opp_energy_wait_from_deck':
            if (!empty($ab['skip_if_negated'])) {
                foreach ($p['live_zone'] as $lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')
                        && !empty($lc['live_success_negated'])) {
                        break 2;
                    }
                }
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (putEnergyFromDeckInWait($state['players'][$opp])) {
                $state = addLog($state, $state['players'][$opp]['name'] .
                    ' — [' . $name . '] put 1 Energy from Energy deck into Wait.');
            }
            break;

        case 'energy_wait_if_group_only_min_energy':
            if (stageAllMembersInGroup($p, $ab['group'] ?? '')
                && countEnergyInZone($p) >= intval($ab['min_energy'] ?? 7)) {
                $n = intval($ab['count'] ?? 1);
                for ($i = 0; $i < $n; $i++) {
                    putEnergyFromDeckInWait($p, $state, $pid);
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put $n Energy into Wait (Liella! only, Energy threshold).");
            }
            break;

        case 'energy_wait_if_baton_group_min_energy':
            if (empty($source['entered_via_baton'])) break;
            $batonGroup = $source['baton_from_group'] ?? '';
            if ($batonGroup !== ($ab['group'] ?? '')) break;
            if (countEnergyInZone($p) < intval($ab['min_energy'] ?? 7)) break;
            $n = intval($ab['count'] ?? 2);
            for ($i = 0; $i < $n; $i++) {
                putEnergyFromDeckInWait($p, $state, $pid);
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] put $n Energy into Wait (Baton Touch + Energy).");
            break;



        case 'energy_wait_from_deck':
            $n = intval($ab['count'] ?? 1);
            $placed = 0;
            for ($i = 0; $i < $n; $i++) {
                if (putEnergyFromDeckInWait($p, $state, $pid)) $placed++;
            }
            if ($placed > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] put $placed Energy into Wait.");
            }
            break;


    }
    return $state;
}
