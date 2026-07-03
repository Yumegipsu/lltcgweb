<?php
/**
 * Opponent may discard Live or grant Live Score — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchOppMayDiscard(
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
        case 'opp_may_discard_or_modifier':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $state['pending_prompt'] = [
                'type'          => 'opp_may_discard_or_modifier',
                'owner'         => $pid,
                'responder'     => $opp,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put 1 Live card from your hand into the Waiting Room? (If not, opponent gains +1 total Live Score.)',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Discard Live', 'No — Opponent gains Live Score'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] On Enter: opponent may discard a Live card.");
            break;

    }
    return $state;
}
