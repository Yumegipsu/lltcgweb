<?php
/**
 * Pay Energy cost effects — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchPayEnergy(
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
        case 'pay_energy_reveal_live_wr_superset':
            if (!empty($state['pending_prompt'])) break;
            $lives = array_values(array_filter(
                $p['hand'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            if (empty($lives)) break;
            $state['pending_prompt'] = [
                'type'        => 'pay_energy_reveal_live_wr_superset',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_id'   => $source['instance_id'] ?? '',
                'source_name' => $name,
                'ability_idx' => $ctx['ability_index'] ?? 0,
                'slot'        => $ctx['slot'] ?? null,
                'step'        => 'reveal_hand_live',
                'pay_cost'    => intval($ab['cost'] ?? 2),
                'candidates'  => array_map('cardPromptSummary', $lives),
                'prompt'      => 'Pay ' . intval($ab['cost'] ?? 2) .
                    ' Energy and reveal 1 Live card from your hand: add 1 Live from Waiting Room whose name contains it?',
            ];
            break;

        case 'pay_energy_play_wr_empty':
            break;

        // Live Success optional: "You may pay N Energy: add matching WR card to hand."
        // (Activated uses of this type are handled in ActivateAbility.php.)
        case 'pay_energy_add_from_wr':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            if (($ctx['phase'] ?? '') !== 'live_success') {
                break;
            }
            $cfg = wrPickCfgFromAbility($ab);
            $need = max(1, intval($ab['count'] ?? 1));
            if (wrPickMatchCount($p, $cfg, $need) < $need) {
                break;
            }
            $cost = intval($ab['cost'] ?? 0);
            if ($cost > 0 && countActiveEnergyInZone($p) < $cost) {
                break;
            }
            $filterLabel = wrPickFilterLabel($cfg['filter'] ?? 'member');
            $groupLabel = trim((string)($cfg['group'] ?? ''));
            $what = $groupLabel !== '' ? "$groupLabel $filterLabel" : $filterLabel;
            $state['pending_prompt'] = [
                'type'          => 'optional_pay_energy_add_from_wr',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_slot'   => $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? ''),
                'source_name'   => $name,
                'prompt'        => 'Pay ' . $cost . " Energy: add 1 $what card from your Waiting Room to your hand?",
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Pay', 'No — Skip'],
                'ability'       => $ab,
                'pay_cost'      => $cost,
                'wr_pick_cfg'   => $cfg,
                'ability_index' => intval($ctx['ability_index'] ?? $ctx['ability_idx'] ?? 0),
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] optional Live Success (pay Energy → Waiting Room).");
            break;

    }
    return $state;
}
