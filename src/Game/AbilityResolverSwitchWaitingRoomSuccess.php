<?php
/**
 * WR members to hand + success-scored live score bonus — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchWaitingRoomSuccess(
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
        case 'add_from_waiting_room':
            $cfg = wrPickCfgFromAbility($ab);
            $candidates = wrCandidatesMatching($p, $cfg);
            if (!empty($candidates)) {
                $filter = $cfg['filter'] ?? 'member';
                $state['pending_prompt'] = [
                    'type'          => 'pick_wr_to_hand',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_id'     => $source['instance_id'] ?? '',
                    'source_slot'   => $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? ''),
                    'source_name'   => $name,
                    'prompt'        => 'Choose 1 ' . wrPickFilterLabel($filter) .
                        ' card from your Waiting Room to add to your hand.',
                    'candidates'    => array_map('cardPromptSummary', $candidates),
                    'ability'       => $ab,
                    'wr_pick_cfg'   => $cfg,
                    'pick_count'    => 1,
                ];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] choose a card from Waiting Room.");
            }
            break;

        case 'success_scored_live_score_bonus':
            if (!abilitySlotAllowed($ab, $ctx, $p, $source)) {
                break;
            }
            $slot = findMemberSlot($p, $source['instance_id'] ?? '');
            if (!empty($ab['center_only']) && $slot !== 'center') {
                break;
            }
            $targetScores = $ab['scores'] ?? null;
            if ($targetScores !== null) {
                $hasOne = false;
                $hasFive = false;
                foreach ($p['success_lives'] ?? [] as $c) {
                    $sc = intval($c['score'] ?? 0);
                    if ($sc === 1) $hasOne = true;
                    if ($sc === 5) $hasFive = true;
                }
                if ($hasOne && $hasFive) {
                    $amount = intval($ab['amount_two'] ?? 2);
                } elseif ($hasOne || $hasFive) {
                    $amount = intval($ab['amount_one'] ?? 1);
                } else {
                    break;
                }
            } else {
                // Umi Sonoda (PL!-pb1-004): count μ's Success Lives with Score +1 blade heart
                // (yell_score_icon / special_heart icon_score), not merely score > 0.
                $group = $ab['group'] ?? 'μ\'s';
                $scored = 0;
                foreach ($p['success_lives'] ?? [] as $c) {
                    if (!cardInGroup($c, $group)) {
                        continue;
                    }
                    if (cardYellScoreIconCount($c) > 0) {
                        $scored++;
                    }
                }
                if ($scored >= 2) {
                    $amount = intval($ab['amount_two'] ?? 2);
                } elseif ($scored >= 1) {
                    $amount = intval($ab['amount_one'] ?? 1);
                } else {
                    break;
                }
            }
            $state = applyModifierEffect($state, $pid, [
                'type'   => 'live_score_bonus',
                'amount' => $amount,
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Success Live score bonus +$amount until this Live ends.");
            break;

    }
    return $state;
}
