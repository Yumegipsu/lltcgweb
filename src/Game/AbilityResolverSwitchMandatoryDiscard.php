<?php
/**
 * On Enter mandatory discard fall-through group — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchMandatoryDiscard(
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
        case 'hearts_if_combined_energy':
        case 'live_score_if_opp_success_total':
        case 'on_self_wait_draw_discard':
        case 'optional_named_live_zone_from_wr_on_hand':
        case 'member_blade_on_live_zone_faceup':
        case 'cannot_live_if_solo_stage':
        case 'blade_bonus_if_center':
        case 'cost_bonus_if_min_energy':
        case 'live_score_bonus_if_min_energy':
        case 'mandatory_discard_look_reveal':
            if (!empty($state['pending_prompt'])) break;
            $state['pending_prompt'] = [
                'type'          => 'mandatory_discard_look_reveal',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put 1 card from your hand into the Waiting Room (required).',
                'discard_count' => intval($ab['discard'] ?? 1),
                'ability'       => $ab,
            ];
            $phaseLabel = (($ctx['phase'] ?? '') === 'activated') ? 'Activated' : 'On Enter';
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] mandatory discard for $phaseLabel.");
            break;

    }
    return $state;
}
