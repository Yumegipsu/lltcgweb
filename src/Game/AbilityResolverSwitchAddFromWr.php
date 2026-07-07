<?php
/**
 * Add cards from Waiting Room — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchAddFromWr(
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
        case 'add_from_wr_max_cost':
            $cfg = wrPickCfgFromAbility($ab);
            $count = intval($ab['count'] ?? 1);
            $added = addFromWaitingRoomWithChoice($state, $pid, $source, $ab, $ctx, $cfg, $count);
            if ($added === null) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] choose a " . wrPickFilterLabel($cfg['filter'] ?? 'member') . ' card from Waiting Room.');
                break;
            }
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $added Member(s) from Waiting Room.");
            } else {
                $maxCost = intval($ab['max_cost'] ?? 2);
                $group = $ab['group'] ?? '';
                $groupLabel = $group !== '' ? $group . ' ' : '';
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no matching {$groupLabel}Member (cost ≤$maxCost) in Waiting Room.");
            }
            break;

        case 'add_from_wr_if_success_count':
            if (count($p['success_lives'] ?? []) >= intval($ab['min_success_count'] ?? 2)) {
                $cfg = wrPickCfgFromAbility($ab);
                $count = intval($ab['count'] ?? 1);
                $added = addFromWaitingRoomWithChoice($state, $pid, $source, $ab, $ctx, $cfg, $count);
                if ($added === null) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] choose a Live card from Waiting Room.");
                    break;
                }
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added $added Live card(s) from Waiting Room (2+ Success Lives).");
                }
            }
            break;

        case 'discard_add_from_wr':
            return startEffectDiscardHandPrompt(
                $state,
                $pid,
                $name,
                intval($ab['discard'] ?? 1),
                '',
                ['then' => [
                    'type'   => 'add_from_wr',
                    'group'  => $ab['group'] ?? '',
                    'filter' => $ab['filter'] ?? 'member',
                    'count'  => intval($ab['count'] ?? 1),
                ]]
            );

        case 'add_from_wr':
            $cfg = wrPickCfgFromAbility($ab);
            if (isset($ab['min_score'])) {
                $cfg['min_score'] = intval($ab['min_score']);
            }
            if (isset($ab['min_live_score'])) {
                $cfg['min_live_score'] = intval($ab['min_live_score']);
            }
            $count = intval($ab['count'] ?? 1);
            $added = addFromWaitingRoomWithChoice($state, $pid, $source, $ab, $ctx, $cfg, $count);
            if ($added === null) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] choose a card from Waiting Room.");
                break;
            }
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $added card(s) from Waiting Room.");
            }
            break;

        case 'both_add_wr_live_to_hand':
            foreach (['p1', 'p2'] as $id) {
                $pl = &$state['players'][$id];
                $cfg = ['group' => '', 'filter' => 'live'];
                $added = addFromWaitingRoomWithChoice($state, $id, $source, $ab, $ctx, $cfg, 1);
                if ($added === null) {
                    $state = addLog($state, $state['players'][$id]['name'] .
                        " — [$name] choose a Live card from Waiting Room.");
                    break;
                }
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$id]['name'] .
                        " — [$name] added 1 Live card from Waiting Room to hand.");
                }
                unset($pl);
            }
            break;

        case 'add_wr_live_if_opp_hand_ahead':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $diff = count($state['players'][$opp]['hand'] ?? []) - count($p['hand'] ?? []);
            if ($diff >= intval($ab['min_hand_diff'] ?? 2)) {
                $cfg = wrPickCfgFromAbility(array_merge($ab, ['filter' => 'live']));
                $count = intval($ab['count'] ?? 1);
                $added = addFromWaitingRoomWithChoice($state, $pid, $source, $ab, $ctx, $cfg, $count);
                if ($added === null) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] choose a Live card from Waiting Room.");
                    break;
                }
                if ($added > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] added $added Live card(s) from Waiting Room (opponent hand +$diff).");
                }
            }
            break;

        case 'add_wr_live_if_min_energy':
            if (countEnergyInZone($p) < intval($ab['min_energy'] ?? 11)) {
                break;
            }
            $cfg = wrPickCfgFromAbility(array_merge($ab, ['filter' => 'live']));
            $count = intval($ab['count'] ?? 1);
            $added = addFromWaitingRoomWithChoice($state, $pid, $source, $ab, $ctx, $cfg, $count);
            if ($added === null) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] choose a Live card from Waiting Room.");
                break;
            }
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $added Live card(s) from Waiting Room.");
            }
            break;

    }
    return $state;
}
