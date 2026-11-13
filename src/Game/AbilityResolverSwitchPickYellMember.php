<?php
/**
 * Choose Yell-revealed card — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchPickYellMember(
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
        case 'pick_yell_member':
            if (($ctx['phase'] ?? '') !== 'live_success') break;
            $yellPool = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            $candidates = array_values(array_filter(
                $yellPool,
                fn($c) => cardMatchesYellPick($c, $ab)
            ));
            if (empty($candidates)) break;
            // Always open the pick, even with one candidate (player input / immersion).
            $state['pending_prompt'] = [
                'type'        => 'pick_yell_member',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'prompt'      => 'Choose 1 card revealed by Yell to add to your hand.',
                'candidates'  => array_map('cardPromptSummary', $candidates),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a Yell card.');
            break;

    }
    return $state;
}
