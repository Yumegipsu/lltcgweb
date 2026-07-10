<?php
/**
 * Baton Touch conditional effects — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchBaton(
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
        case 'if_baton_lower_cost':
            if (memberBatonFromLowerCostSubunit($source, $ab['baton_subunit'] ?? '')) {
                $then = $ab['then'] ?? [];
                if (!empty($then)) {
                    $thenType = $then['type'] ?? '';
                    if (in_array($thenType, [
                        'blade_bonus', 'hearts_and_blade_bonus', 'live_score_bonus',
                    ], true)) {
                        $state = applyModifierEffect($state, $pid, $then);
                        if ($thenType === 'blade_bonus') {
                            $state = addLog($state, $state['players'][$pid]['name'] .
                                ' — [' . $name . '] gained +' . intval($then['amount'] ?? 0) .
                                ' Blade until Live ends (Baton Touch).');
                        } else {
                            $state = addLog($state, $state['players'][$pid]['name'] .
                                ' — [' . $name . '] Baton Touch effect resolved.');
                        }
                    } else {
                        $state = resolveAbilityEffect($state, $pid, $source, $then, $ctx);
                    }
                }
            }
            break;

        case 'if_baton_wr_add_live_not_self':
            if (empty($source['entered_via_baton'])) break;
            $batonId = $source['baton_wr_member_id'] ?? '';
            if ($batonId === '') break;
            $batonCard = null;
            foreach ($p['waiting_room'] as $c) {
                if (($c['instance_id'] ?? '') === $batonId) {
                    $batonCard = $c;
                    break;
                }
            }
            if ($batonCard && cardNameMatchesList($batonCard, $ab['exclude_names'] ?? [])) {
                break;
            }
            if (!$batonCard || !cardMatchesGroup($batonCard, $ab['group'] ?? '', 'member')) break;
            $cfg = wrPickCfgFromAbility(array_merge($ab, ['filter' => 'live']));
            $added = addFromWaitingRoomWithChoice($state, $pid, $source, $ab, $ctx, $cfg, 1);
            if ($added === null) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] choose a Live card from Waiting Room (Baton Touch).");
                break;
            }
            if ($added > 0) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added 1 Live card from Waiting Room (Baton Touch).");
            }
            break;

        case 'if_baton_wr_group_to_hand':
            if (empty($source['entered_via_baton'])) break;
            $batonId = $source['baton_wr_member_id'] ?? '';
            if ($batonId === '') break;
            $picked = null;
            $rest = [];
            foreach ($p['waiting_room'] as $c) {
                if (!$picked && ($c['instance_id'] ?? '') === $batonId
                    && cardMatchesGroup($c, $ab['group'] ?? '', $ab['filter'] ?? 'member')) {
                    $picked = $c;
                } else {
                    $rest[] = $c;
                }
            }
            if ($picked) {
                $p['waiting_room'] = $rest;
                $p['hand'][] = $picked;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . cardDisplayName($picked) . ' from Baton Touch to hand.');
            }
            break;


        case 'allows_double_baton':
            break;

        case 'if_double_baton_group_bonus':
            if (intval($source['baton_count'] ?? 0) < intval($ab['min_baton'] ?? 2)) break;
            $group = $ab['group'] ?? '';
            $batonGroups = $source['baton_member_groups'] ?? [];
            $groupCount = count(array_filter($batonGroups, fn($g) => $g === $group));
            if ($groupCount < intval($ab['min_baton'] ?? 2)) break;
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 2));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (double Baton Touch).");
            $p = &$state['players'][$pid];
            $maxCost = intval($ab['max_cost'] ?? 4);
            $eligible = array_values(array_filter(
                $p['waiting_room'] ?? [],
                fn($c) => cardMatchesWrPick($c, [
                    'group' => $group,
                    'filter' => 'member',
                    'max_cost' => $maxCost,
                ])
            ));
            $emptySlots = [];
            foreach (['left', 'center', 'right'] as $s) {
                if (empty($p['stage'][$s])) {
                    $emptySlots[] = $s;
                }
            }
            if (empty($eligible) || empty($emptySlots)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no matching WR Member or empty Stage area.");
                break;
            }
            // Only auto-resolve when there is no real choice (1 member + 1 slot).
            if (count($eligible) === 1 && count($emptySlots) === 1) {
                $played = $eligible[0];
                foreach ($p['waiting_room'] as $i => $c) {
                    if (($c['instance_id'] ?? '') === ($played['instance_id'] ?? '')) {
                        array_splice($p['waiting_room'], $i, 1);
                        break;
                    }
                }
                $played['active'] = true;
                $played['entered_turn'] = intval($state['turn'] ?? 1);
                $p['stage'][$emptySlots[0]] = $played;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put ' . cardDisplayName($played) .
                    ' from Waiting Room onto Stage.');
                $state = resolveOnEnterAbilities($state, $pid, $played, $emptySlots[0]);
                break;
            }
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $groupLabel = $group === 'Superstar' ? 'Liella!' : ($group !== '' ? $group : '');
            $state['pending_prompt'] = [
                'type'        => 'ssd1_play_wr_empty',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_name' => $name,
                'step'        => 'pick_wr',
                'candidates'  => array_map('cardPromptSummary', $eligible),
                'slots'       => $emptySlots,
                'ability'     => array_merge($ab, [
                    'type' => 'optional_play_wr_empty_slot',
                    'group' => $group,
                    'max_cost' => $maxCost,
                    'filter' => 'member',
                ]),
                'prompt'      => 'Choose 1' . ($groupLabel !== '' ? " $groupLabel" : '') .
                    ' Member (cost ≤' . $maxCost . ') from your Waiting Room.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose WR Member to play.");
            break;

    }
    return $state;
}
