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
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'          => 'pick_named_members_grant_blade',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'candidates'    => $candidates,
                'named_list'    => $ab['names'] ?? [],
                'blade'         => intval($ab['blade'] ?? 1),
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
            if (count($candidates) < 2) break;
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
