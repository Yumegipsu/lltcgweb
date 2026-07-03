<?php
/**
 * Opponent text/choice and player choice prompts — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchPlayerChoice(
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
        case 'opponent_text_answer':
            if (!empty($state['pending_prompt'])) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $textFields = buildOpponentTextAnswerPromptFields($ab);
            $state['pending_prompt'] = [
                'type'          => 'opponent_text_answer',
                'owner'         => $pid,
                'responder'     => $opp,
                'source_name'   => $name,
                'prompt'        => $textFields['prompt'],
                'outcome_hints' => $textFields['outcome_hints'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] asks opponent: "' . $textFields['prompt'] . '"');
            break;

        case 'opponent_choice':
            if (!empty($state['pending_prompt'])) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $choiceFields = buildPlayerChoicePromptFields($ab);
            $isLiveStart = ($ctx['phase'] ?? '') === 'live_start'
                || ($state['phase'] ?? '') === 'live_start_effects';
            $state['pending_prompt'] = [
                'type'          => 'opponent_choice',
                'owner'         => $pid,
                'responder'     => $opp,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'live_start'    => $isLiveStart,
                'prompt'        => $choiceFields['prompt'],
                'choices'       => array_keys($ab['choices'] ?? []),
                'choice_labels' => $choiceFields['choice_labels'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] opponent must choose an effect.');
            break;

        case 'player_choice':
            if (!empty($state['pending_prompt'])) break;
            $choiceFields = buildPlayerChoicePromptFields($ab);
            $isLiveStart = ($ctx['phase'] ?? '') === 'live_start'
                || ($state['phase'] ?? '') === 'live_start_effects';
            $state['pending_prompt'] = enrichAbilityContextPrompt($state, [
                'type'          => 'player_choice',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'live_start'    => $isLiveStart,
                'prompt'        => $choiceFields['prompt'],
                'choices'       => array_keys($ab['choices'] ?? []),
                'choice_labels' => $choiceFields['choice_labels'],
                'ability'       => $ab,
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose one effect.');
            break;

        case 'player_choice_wr_live_deck_bottom_draw':
            if (!empty($state['pending_prompt'])) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $selfLives = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            $oppLives = array_values(array_filter(
                $state['players'][$opp]['waiting_room'],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (empty($selfLives) && empty($oppLives)) break;
            $choices = [];
            $labels = [];
            if (!empty($selfLives)) {
                $choices[] = 'self';
                $labels[] = 'Yourself';
            }
            if (!empty($oppLives)) {
                $choices[] = 'opponent';
                $labels[] = 'Opponent';
            }
            $state['pending_prompt'] = [
                'type'          => 'player_choice_wr_live_deck_bottom_draw',
                'step'          => 'pick_player',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'prompt'        => 'Choose yourself or your opponent: put 1 Live from that player\'s Waiting Room on the bottom of their deck (then draw ' .
                    intval($ab['draw'] ?? 1) . ').',
                'choices'       => $choices,
                'choice_labels' => $labels,
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live Start: choose a player.');
            break;

    }
    return $state;
}
