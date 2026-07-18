<?php
/**
 * Hand/deck reveal effects — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchReveal(
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
        case 'reveal_hand_look_live_if_no_live':
            if (!empty($ab['requires_other_stage_member'])
                && !stageHasOtherMember($p, $source['instance_id'] ?? '')) {
                break;
            }
            $handSummary = implode(', ', array_map(
                fn($c) => cardDisplayName($c),
                $p['hand'] ?? []
            ));
            $hasLive = !empty(array_filter(
                $p['hand'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] revealed hand ($handSummary).");
            if (!$hasLive) {
                $picked = lookRevealGroup(
                    $p,
                    intval($ab['look'] ?? 5),
                    $ab['group'] ?? 'Nijigasaki',
                    $ab['filter'] ?? 'live',
                    intval($ab['pick'] ?? 1)
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] looked at deck top; added $picked Live card(s) to hand.");
            }
            break;

        case 'reveal_per_both_stage_member':
            $members = countBothStagesMembers($state);
            $top = array_splice($p['main_deck'], 0, min($members, count($p['main_deck'])));
            $liveCount = 0;
            foreach ($top as $c) {
                if (isLiveTypeCard($c)) $liveCount++;
            }
            $bonus = $liveCount * intval($ab['score_per_live'] ?? 1);
            if ($liveCount > 0) {
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $bonus);
            }
            $revealedCount = count($top);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] revealed $revealedCount card(s), including $liveCount Live card(s); score +$bonus.");
            if (!empty($top)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $top);
            }
            break;



        case 'reveal_hand_member_cost_live_score':
            if (!empty($state['pending_prompt'])) break;
            $members = array_values(array_filter(
                $p['hand'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'メンバー'
            ));
            if (empty($members)) break;
            $state['pending_prompt'] = [
                'type'          => 'reveal_hand_member_cost_live_score',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $members),
                'milestones'    => $ab['milestones'] ?? [10, 20, 30, 40, 50],
                'prompt'        => 'Reveal any number of Member cards from your hand (combined cost 10/20/30/40/50 for +1 Live Score).',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] reveal hand Members (choose).');
            break;

    }
    return $state;
}
