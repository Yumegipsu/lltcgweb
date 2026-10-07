<?php
/**
 * Pick named stage Members then grant blade/hearts — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchPickNamedMembersGrant(
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
        case 'pick_named_members_grant_blade':
            if (!empty($state['pending_prompt'])) break;
            $bladeAmt = intval($ab['blade'] ?? 1);
            $maxMembers = intval($ab['max_members'] ?? 0);
            // Memories (bp7-025): only 1 named Member gets Blade — not the Dazzling Game
            // "named + another Liella" two-step (#259).
            $namedOnly = $maxMembers === 1;
            $namedCandidates = [];
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
                $isNamed = false;
                foreach ($ab['names'] ?? [] as $n) {
                    if ($label === $n || str_contains($label, $n)) {
                        $isNamed = true;
                        break;
                    }
                }
                if ($isNamed) {
                    $row = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => true]);
                    $namedCandidates[] = $row;
                    $candidates[] = $row;
                }
                if ($namedOnly) {
                    continue;
                }
                if (($mbr['group'] ?? '') === ($ab['group'] ?? '')) {
                    $already = false;
                    foreach ($candidates as $c) {
                        if (($c['slot'] ?? '') === $slot) { $already = true; break; }
                    }
                    if (!$already) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => false]);
                    }
                }
            }
            $namedCount = count($namedCandidates);
            if ($namedCount < 1) {
                break;
            }
            if ($namedOnly) {
                if ($namedCount === 1) {
                    $slot = (string)($namedCandidates[0]['slot'] ?? '');
                    if ($slot !== '' && !empty($p['stage'][$slot])) {
                        $p['stage'][$slot]['live_blade_bonus'] =
                            intval($p['stage'][$slot]['live_blade_bonus'] ?? 0) + $bladeAmt;
                        $state = addLog($state, $state['players'][$pid]['name'] .
                            " — [$name] +" . $bladeAmt . ' Blade on ' .
                            cardDisplayName($p['stage'][$slot]) . ' until this Live ends.');
                    }
                    break;
                }
                $state['pending_prompt'] = [
                    'type'          => 'pick_named_members_grant_blade',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_id'     => $source['instance_id'] ?? '',
                    'source_name'   => $name,
                    'candidates'    => $namedCandidates,
                    'named_list'    => $ab['names'] ?? [],
                    'blade'         => $bladeAmt,
                    'max_members'   => 1,
                    'prompt'        => 'Choose 1 named Member for +Blade until this Live ends.',
                    'step'          => 'pick_named',
                    'ability'       => $ab,
                ];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] choose a named Member for +Blade.');
                break;
            }
            // Need a named pick and a distinct second Stage Member (group / other named).
            // Otherwise the second-step UI softlocks with an empty picker (#68).
            if (count($candidates) < 2) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'pick_named_members_grant_blade',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'candidates'    => $candidates,
                'named_list'    => $ab['names'] ?? [],
                'blade'         => $bladeAmt,
                'prompt'        => 'Choose 1 named Member, then 1 other Liella! Member for +Blade.',
                'step'          => 'pick_named',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose Members for +Blade.');
            break;

        case 'pick_named_members_grant_hearts':
            if (!empty($state['pending_prompt'])) break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
                foreach ($ab['names'] ?? [] as $n) {
                    if ($label === $n || str_contains($label, $n)) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => true]);
                        break;
                    }
                }
                if (($mbr['group'] ?? '') === ($ab['group'] ?? '')) {
                    $already = false;
                    foreach ($candidates as $c) {
                        if (($c['slot'] ?? '') === $slot) { $already = true; break; }
                    }
                    if (!$already) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => false]);
                    }
                }
            }
            $namedCount = 0;
            foreach ($candidates as $c) {
                if (!empty($c['named'])) {
                    $namedCount++;
                }
            }
            if ($namedCount < 1 || count($candidates) < 2) {
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'pick_named_members_grant_hearts',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => $candidates,
                'named_list'    => $ab['names'] ?? [],
                'hearts'        => $ab['hearts'] ?? [],
                'prompt'        => 'Choose 1 named Member, then 1 other Liella! Member for bonus hearts.',
                'step'          => 'pick_named',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose Members for bonus hearts.');
            break;


    }
    return $state;
}
