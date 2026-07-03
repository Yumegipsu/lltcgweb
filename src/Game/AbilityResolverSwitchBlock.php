<?php
/**
 * Block opponent effects / tie Live Success — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchBlock(
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
        case 'block_effect_member_activate_turn':
            $state['block_effect_member_activate'] = true;
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Members cannot become Active by effects this turn.");
            break;

        case 'block_success_live_on_tie':
            $state = initLiveModifiers($state);
            $state['live_modifiers']['both']['block_success_live_on_tie'] = true;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] if Live scores tie, neither player adds Success Lives this turn.');
            break;

    }
    return $state;
}
