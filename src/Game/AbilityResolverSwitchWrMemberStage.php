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
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            // Effect controller chooses first, then opponent — each picks their own WR Member.
            $state = continueBothWrMemberToEmptyStage(
                $state,
                $pid,
                $name,
                $ab,
                [$pid, $opp],
                (string)($source['instance_id'] ?? '')
            );
            break;

        case 'play_wr_members_combined_cost':
            if (!empty($state['pending_prompt'])) break;
            $cands = wrCandidatesMatching($p, [
                'filter' => 'member',
                'group'  => $ab['group'] ?? '',
            ]);
            if (empty($cands)) break;
            $emptySlots = array_values(array_filter(
                ['left', 'center', 'right'],
                fn($slot) => empty($p['stage'][$slot])
            ));
            if (empty($emptySlots)) break;
            $state['pending_prompt'] = [
                'type'               => 'play_wr_members_combined_cost',
                'owner'              => $pid,
                'responder'          => $pid,
                'source_name'        => $name,
                'max_combined_cost'  => intval($ab['max_combined_cost'] ?? 4),
                'max_count'          => intval($ab['count'] ?? 2),
                'candidates'         => array_map('cardPromptSummary', $cands),
                'slots'              => $emptySlots,
                'prompt'             => 'Choose up to ' . intval($ab['count'] ?? 2) .
                    ' Member(s) from Waiting Room (combined cost ≤' .
                    intval($ab['max_combined_cost'] ?? 4) . ') to put on Stage in Wait.',
                'ability'            => $ab,
            ];
            break;

    }
    return $state;
}

/**
 * Open (or auto-resolve) the next eligible player's WR → empty Stage (in Wait) pick.
 *
 * @param list<string> $remaining player ids still to act, current first
 */
function continueBothWrMemberToEmptyStage(
    array $state,
    string $effectOwner,
    string $sourceName,
    array $ability,
    array $remaining,
    string $sourceId = ''
): array {
    $maxCost = intval($ability['max_cost'] ?? 2);
    while (!empty($remaining)) {
        $cur = array_shift($remaining);
        $pl = &$state['players'][$cur];
        $eligible = listWrMembersByMaxCost($pl, $maxCost);
        $slots = listEmptyStageSlots($pl);
        unset($pl);
        if (empty($eligible) || empty($slots)) {
            $state = addLog($state, $state['players'][$cur]['name'] .
                ' — [' . $sourceName . '] no Member (cost ≤' . $maxCost .
                ') in Waiting Room or no empty Stage area.');
            continue;
        }
        if (count($eligible) === 1 && count($slots) === 1) {
            $placed = putChosenWrMemberToEmptyStageWait(
                $state['players'][$cur],
                (string)($eligible[0]['instance_id'] ?? ''),
                $slots[0],
                $state,
                true
            );
            if ($placed) {
                $m = $placed['member'];
                notifyMemberEnteredStage($state, $cur, $m);
                $state = addLog($state, $state['players'][$cur]['name'] .
                    ' — [' . $sourceName . '] put ' . ($m['name_en'] ?? $m['name']) .
                    ' from Waiting Room onto Stage in Wait.');
            }
            continue;
        }
        $state['pending_prompt'] = [
            'type'          => 'both_wr_member_to_empty_stage',
            'step'          => 'pick_wr',
            'owner'         => $effectOwner,
            'responder'     => $cur,
            'remaining'     => array_values($remaining),
            'source_id'     => $sourceId,
            'source_name'   => $sourceName,
            'candidates'    => array_map('cardPromptSummary', $eligible),
            'slots'         => $slots,
            'max_cost'      => $maxCost,
            'ability'       => $ability,
            'prompt'        => 'Choose 1 Member card with cost ' . $maxCost .
                ' or less from your Waiting Room to put into an empty Stage area in Wait.',
        ];
        $state['seq']++;
        return $state;
    }
    return $state;
}
