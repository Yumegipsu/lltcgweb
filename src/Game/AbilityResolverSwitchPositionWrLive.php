<?php
/**
 * WR Live deck-top/draw + off-center position change — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchPositionWrLive(
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
        case 'wr_live_deck_top_draw_if_opp_wait':
            $group = $ab['group'] ?? 'μ\'s';
            $candidates = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => cardMatchesGroup($c, $group, 'live')
            ));
            if (count($candidates) > 1) {
                $state['pending_prompt'] = [
                    'type'          => 'pick_wr_live_deck_top',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_name'   => $name,
                    'prompt'        => 'Choose 1 μ\'s Live card from your Waiting Room to put on top of your deck.',
                    'candidates'    => array_map('cardPromptSummary', $candidates),
                    'ability'       => $ab,
                ];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] choose a Live card from Waiting Room.');
                break;
            }
            if (count($candidates) === 1) {
                putWrCardOnDeckTop($p, $candidates[0]['instance_id'] ?? '');
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] put ' .
                    ($candidates[0]['name_en'] ?? $candidates[0]['name']) . ' on top of deck.');
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (stageHasWaitMember($state, $opp)) {
                $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] drew $drawn (opponent has a Member in Wait).");
            }
            break;

        case 'position_change_off_center':
            $group = $ab['group'] ?? 'μ\'s';
            $minBlades = intval($ab['min_original_blades'] ?? 0);
            $hasMin = $minBlades > 0
                ? stageHasGroupMemberMinBlades($p, $group, $minBlades)
                : stageHasGroupMemberMinHearts($p, $group, intval($ab['min_hearts'] ?? 5));
            if (!$hasMin) {
                if (positionChangeOffCenter($p, $source['instance_id'] ?? '')) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] position-changed off Center.");
                }
            }
            break;



    }
    return $state;
}
