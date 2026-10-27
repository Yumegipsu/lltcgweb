<?php
/**
 * Heart-treatment effects — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchTreat(
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
        case 'treat_as_subunits':
            break;

        case 'treat_group_stage_hearts_as':
            $state = initLiveModifiers($state);
            $grp = $ab['group'] ?? '';
            $color = $ab['color'] ?? 'pink';
            if ($grp !== '') {
                $state['live_modifiers'][$pid]['group_hearts_as'][$grp] = $color;
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] $grp Member hearts treated as $color until Live ends.");
            break;

        case 'treat_pick_group_member_hearts_as':
            if (!empty($state['pending_prompt'])) break;
            $grp = $ab['group'] ?? 'Hasunosora';
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || !cardInGroup($mbr, $grp)) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($candidates)) break;
            $color = $ab['color'] ?? 'pink';
            // Single Hasunosora on Stage — apply immediately (no pick UI).
            if (count($candidates) === 1) {
                $slot = $candidates[0]['slot'];
                $p['stage'][$slot]['hearts_treat_as'] = $color;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] ' .
                    ($p['stage'][$slot]['name_en'] ?? $p['stage'][$slot]['name'] ?? 'Member') .
                    " hearts treated as $color until Live ends.");
                break;
            }
            $state['pending_prompt'] = [
                'type'          => 'treat_pick_group_member_hearts_as',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'color'         => $color,
                'candidates'    => $candidates,
                'prompt'        => "Choose 1 $grp Member — until this Live ends, all hearts on that Member are treated as " .
                    $color . ' ♡.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose a Member for heart treatment.");
            break;

    }
    return $state;
}
