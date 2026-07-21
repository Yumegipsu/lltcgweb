<?php
/**
 * Performance score conditional ability cases — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchScore(
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
        case 'score_if_live_zone_group':
            $cnt = countLiveZoneGroup($p['live_zone'], $ab['group'] ?? 'μ\'s');
            if ($cnt >= intval($ab['min_count'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . " ($cnt μ's cards in Live).");
            }
            break;

        case 'score_if_live_zone_min':
            $cnt = countLiveCardsInZone($p['live_zone'] ?? []);
            if ($cnt >= intval($ab['min_count'] ?? 3)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    " ($cnt Live card(s) in storage).");
            }
            break;

        case 'score_if_success_lives':
            if (count($p['success_lives'] ?? []) >= intval($ab['min_success'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (2+ Success Lives).');
            }
            break;

        case 'score_if_no_excess_hearts':
            if (intval($ctx['excess_hearts'] ?? -1) === 0) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] Live success with no excess hearts; score +' . intval($ab['amount'] ?? 1) . '.');
            }
            break;

        case 'score_if_center_blade':
            $center = $p['stage']['center'] ?? null;
            if ($center && ($center['group'] ?? '') === ($ab['group'] ?? 'μ\'s')) {
                $blade = getMemberBlade($center, $state, $pid, 'center');
                if ($blade >= intval($ab['min_blade'] ?? 9)) {
                    bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 2));
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] score +' . intval($ab['amount'] ?? 2) . ' (Center Blade ' . $blade . '+).');
                }
            }
            break;

        case 'score_if_stage_hearts_more':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $mine = countStageHearts($p);
            $theirs = countStageHearts($state['players'][$opp]);
            if ($mine > $theirs) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] Live success ($mine vs $theirs stage hearts); score +" . intval($ab['amount'] ?? 1) . '.');
            }
            break;

        case 'score_if_subunit_only_no_success':
            if (empty($p['success_lives'])
                && stageAllMembersInSubunit($p, $ab['subunit'] ?? '')) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (lily white only, no Success Lives).');
            }
            break;

        case 'score_if_distinct_subunit':
            if (countDistinctNamedSubunit($p, $ab['subunit'] ?? '') >= intval($ab['min_distinct'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (2+ distinct ' . ($ab['subunit'] ?? '') . ' Members).');
            }
            break;

        case 'score_if_stage_blade':
            if (totalStageBlade($p) >= intval($ab['min_blade'] ?? 10)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (Stage Blade ' . totalStageBlade($p) . '+).');
            }
            break;

        case 'score_per_distinct_group_stage':
            $cnt = countDistinctNamedGroupOnStage(
                $p,
                $ab['group'] ?? '',
                $ab['filter'] ?? 'member'
            );
            if ($cnt > 0) {
                $bonus = $cnt * intval($ab['amount'] ?? 1);
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $bonus);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +$bonus ($cnt distinct " . ($ab['group'] ?? '') . ' Members).');
            }
            break;

        case 'score_per_distinct_heart_colors':
            // e.g. Solitude Rain: +N score per distinct heart color among group Members on Stage.
            $colors = [];
            $group = $ab['group'] ?? '';
            $filter = $ab['filter'] ?? 'member';
            foreach ($p['stage'] as $mbr) {
                if (!$mbr) {
                    continue;
                }
                if ($group !== '' && !cardMatchesGroup($mbr, $group, $filter)) {
                    continue;
                }
                foreach ($mbr['hearts'] ?? [] as $h) {
                    $color = (string)($h['color'] ?? '');
                    if ($color === '' || $color === 'any') {
                        continue;
                    }
                    $colors[$color] = true;
                }
                foreach ($mbr['bonus_hearts'] ?? [] as $bh) {
                    $color = is_array($bh) ? (string)($bh['color'] ?? '') : (string)$bh;
                    if ($color === '' || $color === 'any') {
                        continue;
                    }
                    $colors[$color] = true;
                }
            }
            $cnt = count($colors);
            if ($cnt > 0) {
                $bonus = $cnt * intval($ab['amount'] ?? 1);
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $bonus);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +$bonus ($cnt distinct heart color(s)" .
                    ($group !== '' ? " among $group Members" : '') . ').');
            }
            break;

        case 'score_if_named_stage_slots':
            if (stageNamedSlotsMatch($p, $ab['slots'] ?? [])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (named Members in position).');
            }
            break;

        case 'score_per_named_success_live':
            $cnt = countNamedSuccessLives($p, $ab['name'] ?? '');
            if ($cnt > 0) {
                $bonus = $cnt * intval($ab['score_per'] ?? 2);
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $bonus);
                $inc = $cnt * intval($ab['hearts_increase'] ?? 3);
                $incColor = $ab['hearts_increase_color'] ?? 'any';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($incColor === 'gray') {
                            $lc['hearts_increase_gray'] = intval($lc['hearts_increase_gray'] ?? 0) + $inc;
                        } else {
                            $lc['hearts_increase'] = intval($lc['hearts_increase'] ?? 0) + $inc;
                        }
                        break;
                    }
                }
                unset($lc);
                $incLabel = $incColor === 'gray' ? "$inc Gray Hearts" : "$inc hearts";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +$bonus; required $incLabel (EMOTION in Success).");
            }
            break;

        case 'score_if_wr_distinct_live_count':
            $distinct = countDistinctWrLives($p, $ab['group'] ?? '');
            $amt = 0;
            if ($distinct >= intval($ab['min_6'] ?? 6)) {
                $amt = intval($ab['amount_6'] ?? 2);
            } elseif ($distinct >= intval($ab['min_4'] ?? 4)) {
                $amt = intval($ab['amount_4'] ?? 1);
            }
            if ($amt > 0) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $amt);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] score +$amt ($distinct distinct WR Lives).");
            }
            break;

        case 'score_if_deck_refreshed':
            if (intval($p['_deck_refreshed_turn'] ?? -1) === intval($state['turn'] ?? 0)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 2) . ' (deck refreshed this turn).');
            }
            break;

        case 'score_if_stage_member_hearts':
            if (!empty($state['pending_prompt'])) break;
            $group = $ab['group'] ?? 'Sunshine';
            $checkBlades = !empty($ab['min_blades']);
            $threshold = $checkBlades
                ? intval($ab['min_blades'])
                : intval($ab['min_hearts'] ?? 6);
            // Card text: choose any Aqours Member, then check Blades/hearts.
            $eligible = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || ($mbr['group'] ?? '') !== $group) continue;
                $eligible[] = ['slot' => $slot, 'summary' => cardPromptSummary($mbr)];
            }
            if (empty($eligible)) break;
            $amount = intval($ab['amount'] ?? 1);
            $state['pending_prompt'] = [
                'type'         => 'score_if_stage_member_hearts',
                'owner'        => $pid,
                'responder'    => $pid,
                'source_id'    => $source['instance_id'] ?? '',
                'source_name'  => $name,
                'candidates'   => $eligible,
                'amount'       => $amount,
                'group'        => $group,
                'check_blades' => $checkBlades,
                'min_blades'   => $checkBlades ? $threshold : 0,
                'min_hearts'   => $checkBlades ? 0 : $threshold,
                'prompt'       => $checkBlades
                    ? ('Choose 1 Aqours Member. If that Member has ' . $threshold .
                        '+ Blades, this card\'s score +' . $amount . '.')
                    : ('Choose 1 Aqours Member. If that Member has ' . $threshold .
                        '+ hearts, this card\'s score +' . $amount . '.'),
            ];
            break;

        case 'score_if_group_stage_hearts':
            $heartColor = (string)($ab['heart_color'] ?? '');
            if (sumGroupStageHearts($p, $ab['group'] ?? 'Sunshine', $heartColor)
                >= intval($ab['min_hearts'] ?? 10)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 2) . ' (Aqours stage hearts).');
            }
            break;

        case 'score_if_group_stage_hearts_opp_no_excess':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $heartColor = (string)($ab['heart_color'] ?? '');
            if (sumGroupStageHearts($p, $ab['group'] ?? 'Sunshine', $heartColor)
                    >= intval($ab['min_hearts'] ?? 4)
                && !empty($state['_live_success_no_excess'][$opp])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 2));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 2) .
                    ' (Aqours hearts + opponent no excess).');
            }
            break;

        case 'score_if_fewer_success_lives':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (count($p['success_lives'] ?? [])
                < count($state['players'][$opp]['success_lives'] ?? [])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (fewer Success Lives).');
            }
            break;

        case 'score_if_hand_more_than_opp':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (count($p['hand']) > count($state['players'][$opp]['hand'] ?? [])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (more cards in hand).');
            }
            break;

        case 'score_if_center_cost_higher':
            $mine = $p['stage']['center'] ?? null;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $theirs = $state['players'][$opp]['stage']['center'] ?? null;
            if ($mine && $theirs
                && ($mine['group'] ?? '') === ($ab['group'] ?? '')
                && getEffectiveStageMemberCost($state, $pid, $mine)
                    > getEffectiveStageMemberCost($state, $opp, $theirs)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (Center cost higher).');
            }
            break;

        case 'score_if_center_group_moved':
            $center = $p['stage']['center'] ?? null;
            if ($center && ($center['group'] ?? '') === ($ab['group'] ?? '')
                && !empty($center['moved_this_turn'])) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (Center moved).');
            }
            break;

        case 'score_if_yell_distinct_members':
            $yell = $state['_last_yell_cards'] ?? $p['yell_cards'] ?? [];
            if (countDistinctYellMembers($yell, $ab['group'] ?? '')
                >= intval($ab['min_distinct'] ?? 5)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (Yell Members).');
            }
            break;

        case 'score_if_active_energy':
            $active = count(array_filter(
                $p['energy_zone'] ?? [],
                fn($e) => $e['active'] ?? false
            ));
            if ($active > 0) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (active Energy).');
            }
            break;

        case 'score_if_min_energy':
            if (countEnergyInZone($p) >= intval($ab['min_energy'] ?? 12)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (Energy).');
            }
            break;

        case 'score_if_all_energy_active':
            if (allEnergyActive($p)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (all Energy active).');
            }
            break;

        case 'score_if_distinct_subunits_on_stage':
            if (countDistinctSubunitsOnStage($p, $ab['requires_group'] ?? '') >= intval($ab['min_count'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (distinct subunits on Stage).');
            }
            break;

        case 'score_if_distinct_name_and_cost':
            if (countDistinctNamesAndCostsOnStage($p) >= intval($ab['min_count'] ?? 3)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (distinct names and costs).');
            }
            break;

        case 'score_if_stage_group_cost_min':
            if (countStageGroupMinCost($p, $ab['group'] ?? '', intval($ab['min_cost'] ?? 10))
                >= intval($ab['min_count'] ?? 2)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) .
                    ' (' . ($ab['group'] ?? '') . ' cost ' . intval($ab['min_cost'] ?? 10) . '+).');
            }
            break;

    }
    return $state;
}
