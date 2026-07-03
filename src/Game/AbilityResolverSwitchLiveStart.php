<?php
/**
 * Live Start ability type cases — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchLiveStart(
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
        case 'live_start_surveil':
            $look = intval($ab['look'] ?? 3);
            $top = array_splice($p['main_deck'], 0, min($look, count($p['main_deck'])));
            if (count($top) <= 1) {
                if (count($top) === 1) {
                    $p['hand'][] = $top[0];
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at deck top.");
            } else {
                $state = startSurveilArrangePrompt($state, $pid, $name, $top, null, $source['instance_id'] ?? '');
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at top " . count($top) . ' — arrange them.');
            }
            break;

        case 'live_start_match_success_heart':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $lives = array_values(array_filter(
                $p['live_zone'] ?? [],
                fn($c) => $c && cardMatchesGroup($c, $ab['group'] ?? '', 'live')
            ));
            if (empty($lives)) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'pick_live_match_success_heart',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $lives),
                'ability'       => $ab,
                'prompt'        => 'Choose 1 Nijigasaki Live card in your Live.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a Live card.');
            break;

        case 'live_start_center_cost_choice':
            if (!empty($state['pending_prompt'])) break;
            $group = $ab['group'] ?? 'Sunshine';
            $center = $p['stage']['center'] ?? null;
            if (!$center || ($center['group'] ?? '') !== $group) break;
            if (getEffectiveStageMemberCost($state, $pid, $center) < intval($ab['min_center_cost'] ?? 9)) {
                break;
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $hasBlade = !empty(listStageMemberChoices($p));
            $hasWait = !empty(listOppStageMembersByMaxCost(
                $state,
                $opp,
                intval($ab['wait_opp_max_cost'] ?? 4)
            ));
            if (!$hasBlade && !$hasWait) break;
            $choices = [];
            $labels = [];
            if ($hasBlade) {
                $choices[] = 'blade';
                $labels[] = 'Until this Live ends, 1 Member on your Stage gains +' .
                    intval($ab['blade_amount'] ?? 2) . ' Blade.';
            }
            if ($hasWait) {
                $choices[] = 'wait_opp';
                $labels[] = 'Put 1 opponent Stage Member with cost ' .
                    intval($ab['wait_opp_max_cost'] ?? 4) . ' or less into Wait.';
            }
            $state['pending_prompt'] = [
                'type'          => 'live_start_center_cost_choice',
                'step'          => 'pick_mode',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Choose one:',
                'choices'       => $choices,
                'choice_labels' => $labels,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start: choose an effect.');
            break;

        case 'live_start_pay_or_discard':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'live_start_pay_or_discard',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Pay ' . intval($ab['cost'] ?? 2) . ' Energy, or put ' .
                    intval($ab['discard'] ?? 2) . ' cards from your hand into the Waiting Room?',
                'choices'       => ['pay', 'discard'],
                'choice_labels' => [
                    'Pay ' . intval($ab['cost'] ?? 2) . ' Energy',
                    'Discard ' . intval($ab['discard'] ?? 2),
                ],
                'ability'       => $ab,
                'pay_cost'      => intval($ab['cost'] ?? 2),
                'discard_count' => intval($ab['discard'] ?? 2),
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start (pay or discard).');
            break;

        case 'live_start_edel_choice':
            if (!empty($state['pending_prompt'])) break;
            $subunit = $ab['subunit'] ?? 'Edel Note';
            $canPlay = false;
            foreach ($p['waiting_room'] as $c) {
                if (cardMatchesWrPick($c, [
                    'subunit'  => $subunit,
                    'filter'   => 'member',
                    'max_cost' => intval($ab['max_cost'] ?? 4),
                ])) {
                    $canPlay = true;
                    break;
                }
            }
            foreach (['left', 'center', 'right'] as $slot) {
                if (empty($p['stage'][$slot])) {
                    $canPlay = $canPlay && true;
                    break;
                }
            }
            $hasEmpty = false;
            foreach (['left', 'center', 'right'] as $slot) {
                if (empty($p['stage'][$slot])) {
                    $hasEmpty = true;
                    break;
                }
            }
            $canPlay = $canPlay && $hasEmpty;
            $choices = ['reduce'];
            $labels = ['Reduce required purple hearts by 1.'];
            if ($canPlay) {
                array_unshift($choices, 'play');
                array_unshift($labels, 'Play 1 Edel Note Member (cost ≤' .
                    intval($ab['max_cost'] ?? 4) . ') from Waiting Room into an empty area.');
            }
            $state['pending_prompt'] = [
                'type'          => 'live_start_edel_choice',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'choices'       => $choices,
                'choice_labels' => $labels,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start: choose an effect.');
            break;
    }
    return $state;
}
