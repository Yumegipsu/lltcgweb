<?php
/**
 * Blade bonus / conditional blade effects — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchBlade(
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
        case 'blade_per_discarded_pick_member':
            if (!empty($state['pending_prompt'])) break;
            $discarded = intval($ctx['discarded_count'] ?? 0);
            if ($discarded <= 0) break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                if (($ab['group'] ?? '') !== '' && ($mbr['group'] ?? '') !== ($ab['group'] ?? '')) continue;
                $candidates[] = cardPromptSummary($mbr) + ['slot' => $slot];
            }
            if (empty($candidates)) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] no matching Member on Stage for +Blade (discarded $discarded).");
                break;
            }
            if (count($candidates) === 1) {
                $bonus = intval($ab['amount'] ?? 3) * $discarded;
                $slot = $candidates[0]['slot'] ?? '';
                if ($slot !== '' && !empty($p['stage'][$slot])) {
                    $p['stage'][$slot]['live_blade_bonus'] =
                        intval($p['stage'][$slot]['live_blade_bonus'] ?? 0) + $bonus;
                }
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] chosen Member gains +$bonus Blade.");
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'blade_per_discarded_pick_member',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Choose 1 Member on your Stage to gain Blade.',
                'candidates'    => $candidates,
                'discarded'     => $discarded,
                'ability'       => $ab,
            ];
            break;
        case 'blade_bonus':
            $state = applyModifierEffect($state, $pid, $ab);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] gains +' . intval($ab['amount'] ?? 1) . ' Blade until this Live ends.');
            break;
        case 'blade_per_hand_cards':
            $state = initLiveModifiers($state);
            $state['live_modifiers'][$pid]['blade_per_hand_divisor'] = max(1, intval($ab['per_cards'] ?? 2));
            $state['live_modifiers'][$pid]['blade_per_hand_amount'] = intval($ab['amount'] ?? 1);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] +1 Blade per ' . intval($ab['per_cards'] ?? 2) . ' cards in hand until Live ends.');
            break;
        case 'blade_bonus_if_moved_in_slot':
            $needSlot = $ab['slot'] ?? '';
            $mySlot = findMemberSlot($p, $source['instance_id'] ?? '');
            if ($needSlot !== '' && $mySlot === $needSlot && !empty($source['moved_this_turn'])) {
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'blade_bonus',
                    'amount' => intval($ab['amount'] ?? 2),
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] gained +' . intval($ab['amount'] ?? 2) .
                    ' Blade until Live ends (moved in slot).');
            }
            break;
        case 'blade_if_entered_or_moved':
            if (!empty($ab['heart_color'])) {
                addBonusHeartsToModifier($state, $pid, [[
                    'color' => $ab['heart_color'],
                    'count' => 1,
                ]]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] gained 1 ' . ucfirst($ab['heart_color']) .
                    ' heart until Live ends.');
            } else {
                $state = applyModifierEffect($state, $pid, [
                    'type'   => 'blade_bonus',
                    'amount' => intval($ab['amount'] ?? 1),
                ]);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] gained +' . intval($ab['amount'] ?? 1) . ' Blade until Live ends.');
            }
            break;
    }
    return $state;
}
