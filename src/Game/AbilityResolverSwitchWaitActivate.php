<?php
/**
 * Opponent-wait modifiers and member activation ability cases — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchWaitActivate(
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
        case 'wait_opponent_max_blade':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $waited = waitOpponentStageByMaxBlade(
                $state,
                $opp,
                intval($ab['max_blade'] ?? 1),
                isset($ab['pick_count']) ? intval($ab['pick_count']) : null,
                $pid
            );
            if ($waited > 0) {
                $state = addLog($state, $state['players'][$opp]['name'] .
                    " — $waited Member(s) put into Wait ([$name]).");
            }
            break;

        case 'wait_opp_if_distinct_subunit':
            if (countDistinctNamedSubunit($p, $ab['subunit'] ?? '') >= intval($ab['min_distinct'] ?? 2)) {
                $opp = ($pid === 'p1') ? 'p2' : 'p1';
                $waited = waitOpponentStageByCost(
                    $state,
                    $opp,
                    intval($ab['max_cost'] ?? 4),
                    isset($ab['pick_count']) ? intval($ab['pick_count']) : null,
                    $pid
                );
                if ($waited > 0) {
                    $state = addLog($state, $state['players'][$opp]['name'] .
                        " — $waited Member(s) put into Wait ([$name]).");
                }
            }
            break;

        case 'wait_opponent_active':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $waited = waitOpponentActiveMembers($state, $opp, intval($ab['count'] ?? 1), $pid);
            if ($waited > 0) {
                $state = addLog($state, $state['players'][$opp]['name'] .
                    " — $waited active Member(s) put into Wait ([$name]).");
            }
            break;

        case 'wait_opponent_stage_max_cost':
            $state = beginWaitOpponentStagePick(
                $state,
                $pid,
                $name,
                $ab,
                $source['instance_id'] ?? '',
                ($ctx['phase'] ?? '') === 'live_start'
                    || ($state['phase'] ?? '') === 'live_start_effects'
            );
            break;

        case 'wait_opp_max_original_hearts':
            $state = beginWaitOpponentStagePick(
                $state,
                $pid,
                $name,
                array_merge($ab, [
                    'max_original_hearts' => intval($ab['max_original_hearts'] ?? 3),
                    'pick_count' => intval($ab['pick_count'] ?? 1),
                ]),
                $source['instance_id'] ?? '',
                ($ctx['phase'] ?? '') === 'live_start'
                    || ($state['phase'] ?? '') === 'live_start_effects'
            );
            break;

        case 'wait_opp_max_original_blade':
            $state = beginWaitOpponentStagePick(
                $state,
                $pid,
                $name,
                array_merge($ab, [
                    'max_original_blade' => intval(
                        $ab['max_original_blade'] ?? $ab['max_original_blades'] ?? 3
                    ),
                    'pick_count' => intval($ab['pick_count'] ?? 1),
                ]),
                $source['instance_id'] ?? '',
                ($ctx['phase'] ?? '') === 'live_start'
                    || ($state['phase'] ?? '') === 'live_start_effects'
            );
            break;

        case 'wait_opp_max_original_blade_if_stage_group':
            if (!stageAllMembersInGroup($p, $ab['group'] ?? '')) {
                break;
            }
            $state = beginWaitOpponentStagePick(
                $state,
                $pid,
                $name,
                array_merge($ab, [
                    'max_original_blade' => intval($ab['max_original_blade'] ?? 2),
                    'pick_count' => intval($ab['pick_count'] ?? 1),
                ]),
                $source['instance_id'] ?? '',
                ($ctx['phase'] ?? '') === 'live_start'
                    || ($state['phase'] ?? '') === 'live_start_effects'
            );
            break;

        case 'activate_subunit_members':
            $activated = activateSubunitMembers($p, $ab['subunit'] ?? '', intval($ab['max'] ?? 1));
            if ($activated > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated " . ($ab['subunit'] ?? '') . ' Member(s).');
            }
            break;

        case 'activate_one_member':
            $activated = activateMembersByEffect($state, $p, 1);
            if ($activated > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated Member(s).");
            }
            break;

        case 'activate_energy_if_success':
            if (sumSuccessLiveScores($p) >= intval($ab['min_success_score_sum'] ?? 6)) {
                $activated = activateEnergyForPlayer($p, intval($ab['count'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated Energy (Success Live score threshold met).");
            }
            break;

        case 'activate_all_members':
            $activated = activateMembersByEffect($state, $p, 99);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated $activated Member(s).");
            break;

        case 'activate_members':
            $max = intval($ab['max'] ?? 1);
            $group = (string)($ab['group'] ?? '');
            $candidates = listActivatableStageMembers($p, $group);
            if (empty($candidates)) {
                break;
            }
            if ($max <= 1 && count($candidates) === 1) {
                $activated = activateStageMemberByInstanceId($p, (string)($candidates[0]['instance_id'] ?? ''));
                if ($activated > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] activated $activated Member(s).");
                }
                break;
            }
            if ($max <= 1 && count($candidates) > 1) {
                if (!empty($state['pending_prompt'])) {
                    break;
                }
                $state['pending_prompt'] = [
                    'type'          => 'activate_members_pick',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_id'     => $source['instance_id'] ?? '',
                    'source_name'   => $name,
                    'candidates'    => $candidates,
                    'max'           => $max,
                    'group'         => $group,
                    'prompt'        => $group !== ''
                        ? "Choose 1 $group Member to activate."
                        : 'Choose 1 Member to activate.',
                    'ability'       => $ab,
                    'live_start'    => ($ctx['phase'] ?? '') === 'live_start'
                        || ($state['phase'] ?? '') === 'live_start_effects',
                ];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] choose a Member to activate.");
                break;
            }
            $activated = activateMembersByEffect($state, $p, $max, $group);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated $activated Member(s).");
            break;

        case 'activate_if_baton_to_wr':
            $incoming = $ctx['baton_incoming'] ?? null;
            if ($incoming) {
                mergeCardCatalogFields($incoming);
                if (!isMemberCard($incoming)) {
                    break;
                }
                $fromCost = intval($incoming['cost'] ?? 0);
                $fromGroup = $incoming['group'] ?? '';
            } else {
                if (empty($source['entered_via_baton'])) {
                    break;
                }
                $fromCost = intval($source['baton_from_cost'] ?? -1);
                $fromGroup = $source['baton_from_group'] ?? '';
            }
            if ($fromCost < intval($ab['min_baton_cost'] ?? 10)) {
                break;
            }
            if (($ab['group'] ?? '') !== '' && $fromGroup !== ($ab['group'] ?? '')) {
                break;
            }
            $want = intval($ab['count'] ?? 2);
            $activated = activateEnergyForPlayer($p, $want);
            $msg = $state['players'][$pid]['name'] .
                " — [$name] activated $activated Energy (Baton Touch to Waiting Room).";
            if ($activated < $want) {
                $msg .= ' (' . ($want - $activated) . ' already active.)';
            }
            $state = addLog($state, $msg);
            break;

        case 'activate_energy':
            $activated = activateEnergyForPlayer($p, intval($ab['count'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated $activated Energy.");
            break;

        case 'activate_subunit_from_wait_score':
            $subunit = $ab['subunit'] ?? '';
            $activated = activateSubunitFromWait($p, $subunit);
            if ($activated > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated $subunit Member(s) from Wait.");
            }
            if ($activated >= intval($ab['min_activated'] ?? 3)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    " ($activated Members activated from Wait).");
            }
            break;

        case 'activate_energy_if_other_group':
            $group = $ab['group'] ?? '';
            $hasOther = false;
            foreach ($p['stage'] as $mbr) {
                if (!$mbr) continue;
                if (($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) continue;
                if (($mbr['group'] ?? '') === $group) {
                    $hasOther = true;
                    break;
                }
            }
            if ($hasOther) {
                $activated = activateEnergyForPlayer($p, intval($ab['count'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated Energy.");
            }
            break;

        case 'activate_energy_up_to_if_distinct_subunit':
            if (countDistinctNamedSubunit($p, $ab['subunit'] ?? '')
                < intval($ab['min_distinct'] ?? 2)) {
                break;
            }
            if (!empty($state['pending_prompt'])) break;
            $max = intval($ab['max'] ?? 6);
            $state['pending_prompt'] = [
                'type'          => 'activate_energy_up_to',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => "Activate up to $max Energy?",
                'max'           => $max,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] activate Energy (choose amount).');
            break;

    }
    return $state;
}
