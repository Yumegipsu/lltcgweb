<?php
/**
 * Stage formation rotate + post-discard group buff — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchFormationDiscarded(
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
        case 'formation_rotate_all':
            if (!stageAllMembersInSubunit($p, $ab['requires_subunit_only'] ?? '')) break;
            spBp2MarkEffectAreaMove($state, $source);
            $moved = [];
            foreach (['p1', 'p2'] as $id) {
                $before = [];
                foreach (['center', 'left', 'right'] as $s) {
                    $mbr = $state['players'][$id]['stage'][$s] ?? null;
                    if ($mbr) {
                        $before[$mbr['instance_id'] ?? ''] = $s;
                    }
                }
                formationRotatePlayerStage($state['players'][$id]['stage']);
                foreach (['center', 'left', 'right'] as $s) {
                    $mbr = $state['players'][$id]['stage'][$s] ?? null;
                    if (!$mbr) {
                        continue;
                    }
                    $iid = (string)($mbr['instance_id'] ?? '');
                    $from = $before[$iid] ?? $s;
                    if ($from === $s) {
                        continue;
                    }
                    // Stamp every mover before area-move autos. The first auto
                    // clears the Liella mark, so a later Member (13-cost Chisato)
                    // would otherwise miss Live Success +1.
                    spBp2ApplyMovedByGroupEffect($mbr, $state);
                    $state['players'][$id]['stage'][$s] = $mbr;
                    $moved[] = ['id' => $id, 'iid' => $iid, 'from' => $from];
                }
            }
            foreach ($moved as $mv) {
                if (!empty($state['pending_prompt'])) {
                    spBp2ClearEffectAreaMove($state);
                    return $state;
                }
                $state = resolveAutoAreaMoveAbilities($state, $mv['id'], $mv['iid'], $mv['from']);
            }
            spBp2ClearEffectAreaMove($state);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] both players rotated Stage formation.');
            break;


        case 'buff_member_matching_discarded_group':
            if (!empty($state['pending_prompt'])) break;
            $discGroup = $ctx['discarded_group'] ?? '';
            if ($discGroup === '') break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || ($mbr['group'] ?? '') !== $discGroup) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($candidates)) break;
            $hearts = $ab['hearts'] ?? [['color' => 'pink', 'count' => 1]];
            // Single match — apply immediately (avoids a one-button softlock UI).
            if (count($candidates) === 1) {
                $slot = $candidates[0]['slot'];
                $mbr = &$p['stage'][$slot];
                addBonusHeartsToMember($mbr, $hearts);
                unset($mbr);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] granted bonus heart(s) until Live ends.");
                break;
            }
            $state['pending_prompt'] = [
                'type'        => 'buff_member_matching_discarded_group',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'candidates'  => $candidates,
                'hearts'      => $hearts,
                'prompt'      => 'Choose 1 Member on your Stage with the same group as the discarded card.',
            ];
            break;


    }
    return $state;
}
