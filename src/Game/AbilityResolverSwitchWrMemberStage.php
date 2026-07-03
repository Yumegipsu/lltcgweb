<?php
/**
 * WR member to empty stage + combined-cost WR play prompt — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchWrMemberStage(
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
        case 'both_wr_member_to_empty_stage':
            $maxCost = intval($ab['max_cost'] ?? 2);
            foreach (['p1', 'p2'] as $id) {
                $placed = putWrMemberToEmptyStageWait($state['players'][$id], $maxCost);
                if ($placed) {
                    $m = $placed['member'];
                    $state = addLog($state, $state['players'][$id]['name'] .
                        ' — [' . $name . '] put ' . ($m['name_en'] ?? $m['name']) .
                        ' from Waiting Room onto Stage in Wait.');
                }
            }
            break;






        case 'play_wr_members_combined_cost':
            if (!empty($state['pending_prompt'])) break;
            $cands = array_values(array_filter($p['waiting_room'], function ($c) use ($ab) {
                return ($c['card_type'] ?? '') === 'メンバー'
                    && cardMatchesGroup($c, $ab['group'] ?? '', 'member');
            }));
            if (empty($cands)) break;
            $state['pending_prompt'] = [
                'type'               => 'play_wr_members_combined_cost',
                'owner'              => $pid,
                'responder'          => $pid,
                'source_name'        => $name,
                'max_combined_cost'  => intval($ab['max_combined_cost'] ?? 4),
                'max_count'          => intval($ab['count'] ?? 2),
                'prompt'             => 'Choose Member(s) from Waiting Room (combined cost ≤' .
                    intval($ab['max_combined_cost'] ?? 4) . ') to put on Stage in Wait.',
                'ability'            => $ab,
            ];
            break;

    }
    return $state;
}
