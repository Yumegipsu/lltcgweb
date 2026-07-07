<?php
/**
 * On-enter stage effects — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchOnEnter(
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
        case 'on_enter_if_named_activate_add_wr':
            if (!stageHasNamedMember($p, $ab['names'] ?? [])) break;
            $activated = activateEnergyForPlayer($p, intval($ab['activate'] ?? 1));
            $cfg = wrPickCfgFromAbility($ab);
            $count = intval($ab['count'] ?? 1);
            $added = addFromWaitingRoomWithChoice($state, $pid, $source, $ab, $ctx, $cfg, $count);
            if ($added === null) {
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] activated $activated Energy; choose a card from Waiting Room.");
                break;
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] activated $activated Energy; added $added card(s) from Waiting Room.");
            break;



        case 'on_enter_side_area':
            $slot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
            $state = applyOnEnterSideEffect($state, $pid, $p, $name, $ab, $slot);
            break;

        case 'on_enter_draw_swap_area':
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn.");
            if (!empty($state['pending_prompt'])) break;
            $slots = [];
            $mySlot = $ctx['slot'] ?? findMemberSlot($p, $source['instance_id'] ?? '');
            foreach (['center', 'left', 'right'] as $s) {
                if ($s !== $mySlot) $slots[] = $s;
            }
            $state['pending_prompt'] = [
                'type'          => 'on_enter_draw_swap_area',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_slot'   => $mySlot,
                'source_name'   => $name,
                'slots'         => $slots,
                'prompt'        => 'Choose an area to move this Member to (swap if occupied).',
                'ability'       => $ab,
            ];
            break;


    }
    return $state;
}
