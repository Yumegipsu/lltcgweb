<?php
/**
 * Yell-phase modifiers and heart-waive prompt — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchYell(
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
        case 'yell_hearts_wildcard':
        case 'yell_heart_score_bonus':
        case 'waive_one_required_heart_color':
            if (!stageHasGroupMember($p, $ab['requires_stage_group'] ?? '')) break;
            if (!empty($state['pending_prompt'])) break;
            $choices = $ab['colors'] ?? ['pink', 'green', 'blue'];
            $state['pending_prompt'] = [
                'type'          => 'waive_required_heart_color',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Choose 1 required heart color you do not need for this Live.',
                'choices'       => $choices,
                'choice_labels' => array_map(fn($c) => ucfirst($c) . ' ♡ waived', $choices),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a heart color to waive.');
            break;
        case 'yell_blades_to_blue':
            $state = initLiveModifiers($state);
            $state['live_modifiers'][$pid]['yell_blades_to_blue'] = true;
            // Official Q&A (VIVID WORLD × Rin PL!-bp8-005): the recolor that resolves last decides the color.
            unset($state['live_modifiers'][$pid]['yell_blades_to_color']);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Yell Blade hearts become Blue until Live ends.');
            break;
        case 'yell_all_heart_types_score_bonus':
            $yellCards = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            if (yellMembersHaveAllHeartColors($yellCards)) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['amount'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] score +' . intval($ab['amount'] ?? 1) . ' (all heart colors in Yell).');
            }
            break;
        case 'yell_blades_to_color':
            $state = initLiveModifiers($state);
            $color = $ab['color'] ?? 'purple';
            $state['live_modifiers'][$pid]['yell_blades_to_color'] = $color;
            unset($state['live_modifiers'][$pid]['yell_blades_to_blue']);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Yell Blade hearts become ' . ucfirst($color) . ' until Live ends.');
            break;
    }
    return $state;
}
