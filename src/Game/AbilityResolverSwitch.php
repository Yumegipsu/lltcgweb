<?php
/**
 * Core ability type switch — extracted from AbilityResolver.php.
 */

require_once __DIR__ . '/AbilityResolverSwitchOptional.php';
require_once __DIR__ . '/AbilityResolverSwitchLiveStart.php';
require_once __DIR__ . '/AbilityResolverSwitchLive.php';
require_once __DIR__ . '/AbilityResolverSwitchDeckLook.php';
require_once __DIR__ . '/AbilityResolverSwitchScore.php';
require_once __DIR__ . '/AbilityResolverSwitchWaitActivate.php';
require_once __DIR__ . '/AbilityResolverSwitchYell.php';
require_once __DIR__ . '/AbilityResolverSwitchChooseHeart.php';
require_once __DIR__ . '/AbilityResolverSwitchReduceHearts.php';
require_once __DIR__ . '/AbilityResolverSwitchMandatoryDiscard.php';
require_once __DIR__ . '/AbilityResolverSwitchBlade.php';
require_once __DIR__ . '/AbilityResolverSwitchGrant.php';
require_once __DIR__ . '/AbilityResolverSwitchAddFromWr.php';
require_once __DIR__ . '/AbilityResolverSwitchPlayerChoice.php';
require_once __DIR__ . '/AbilityResolverSwitchBaton.php';
require_once __DIR__ . '/AbilityResolverSwitchEnergyWait.php';
require_once __DIR__ . '/AbilityResolverSwitchSet.php';
require_once __DIR__ . '/AbilityResolverSwitchMemberBlade.php';
require_once __DIR__ . '/AbilityResolverSwitchOnEnter.php';
require_once __DIR__ . '/AbilityResolverSwitchTreat.php';
require_once __DIR__ . '/AbilityResolverSwitchPickNamedMembersGrant.php';
require_once __DIR__ . '/AbilityResolverSwitchPayEnergy.php';
require_once __DIR__ . '/AbilityResolverSwitchBlock.php';
require_once __DIR__ . '/AbilityResolverSwitchOppMayDiscard.php';
require_once __DIR__ . '/AbilityResolverSwitchPickWr.php';
require_once __DIR__ . '/AbilityResolverSwitchReveal.php';
require_once __DIR__ . '/AbilityResolverSwitchPickYellMember.php';
require_once __DIR__ . '/AbilityResolverSwitchFormationDiscarded.php';
require_once __DIR__ . '/AbilityResolverSwitchMemberHeartsLiveSuccess.php';
require_once __DIR__ . '/AbilityResolverSwitchYellAdjunct.php';
require_once __DIR__ . '/AbilityResolverSwitchWrMemberStage.php';
require_once __DIR__ . '/AbilityResolverSwitchLiveScoreMemberBlade.php';
require_once __DIR__ . '/AbilityResolverSwitchPositionWrLive.php';

function resolveAbilityEffectSwitch(
    array $state,
    string $pid,
    array $source,
    array $ab,
    array $ctx,
    string $type,
    array &$p,
    string $name
): array {
    if (str_starts_with($type, 'optional_')) {
        $result = tryResolveAbilityEffectSwitchOptional($state, $pid, $source, $ab, $ctx, $type, $p, $name);
        if ($result !== null) {
            return $result;
        }
    }

    if (str_starts_with($type, 'live_start_')) {
        return tryResolveAbilityEffectSwitchLiveStart($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'live_') && !str_starts_with($type, 'live_start_')) {
        return tryResolveAbilityEffectSwitchLive($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (preg_match('/^(draw_|look_|deck_|mill_|surveil_)/', $type)) {
        return tryResolveAbilityEffectSwitchDeckLook($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'score_')) {
        return tryResolveAbilityEffectSwitchScore($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'wait_') || str_starts_with($type, 'activate_')) {
        return tryResolveAbilityEffectSwitchWaitActivate($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'yell_') || $type === 'waive_one_required_heart_color') {
        return tryResolveAbilityEffectSwitchYell($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'choose_heart_') || $type === 'choose_required_heart_pair_gray') {
        return tryResolveAbilityEffectSwitchChooseHeart($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'reduce_hearts_') || $type === 'reduce_required_hearts_if_blade') {
        return tryResolveAbilityEffectSwitchReduceHearts($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (in_array($type, [
        'hearts_if_combined_energy',
        'live_score_if_opp_success_total',
        'on_self_wait_draw_discard',
        'optional_named_live_zone_from_wr_on_hand',
        'member_blade_on_live_zone_faceup',
        'cannot_live_if_solo_stage',
        'blade_bonus_if_center',
        'cost_bonus_if_min_energy',
        'live_score_bonus_if_min_energy',
        'mandatory_discard_look_reveal',
    ], true)) {
        return tryResolveAbilityEffectSwitchMandatoryDiscard($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'blade_')) {
        return tryResolveAbilityEffectSwitchBlade($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'grant_')) {
        return tryResolveAbilityEffectSwitchGrant($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'add_from_wr')
        || str_starts_with($type, 'add_wr_live')
        || $type === 'discard_add_from_wr'
        || $type === 'both_add_wr_live_to_hand') {
        return tryResolveAbilityEffectSwitchAddFromWr($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'opponent_')
        || str_starts_with($type, 'player_choice')) {
        return tryResolveAbilityEffectSwitchPlayerChoice($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'if_baton_')
        || $type === 'allows_double_baton'
        || str_starts_with($type, 'if_double_baton_')) {
        return tryResolveAbilityEffectSwitchBaton($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'energy_wait_')
        || str_starts_with($type, 'both_energy_wait_')
        || str_starts_with($type, 'opp_energy_wait_')) {
        return tryResolveAbilityEffectSwitchEnergyWait($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'set_')) {
        return tryResolveAbilityEffectSwitchSet($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'member_blade_bonus')) {
        return tryResolveAbilityEffectSwitchMemberBlade($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'on_enter_')) {
        return tryResolveAbilityEffectSwitchOnEnter($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'treat_')) {
        return tryResolveAbilityEffectSwitchTreat($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'pick_named_members_grant_')) {
        return tryResolveAbilityEffectSwitchPickNamedMembersGrant($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'pay_energy_')) {
        return tryResolveAbilityEffectSwitchPayEnergy($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'block_')) {
        return tryResolveAbilityEffectSwitchBlock($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if ($type === 'opp_may_discard_or_modifier') {
        return tryResolveAbilityEffectSwitchOppMayDiscard($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'pick_wr_')) {
        return tryResolveAbilityEffectSwitchPickWr($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (str_starts_with($type, 'reveal_')) {
        return tryResolveAbilityEffectSwitchReveal($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if ($type === 'pick_yell_member') {
        return tryResolveAbilityEffectSwitchPickYellMember($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (in_array($type, [
        'formation_rotate_all',
        'buff_member_matching_discarded_group',
    ], true)) {
        return tryResolveAbilityEffectSwitchFormationDiscarded($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (in_array($type, [
        'member_hearts_as_blade',
        'negate_self_live_success_if_group_hearts',
    ], true)) {
        return tryResolveAbilityEffectSwitchMemberHeartsLiveSuccess($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (in_array($type, [
        'add_self_to_hand_if_winning_yell',
        'reduce_yell_reveal_count',
    ], true)) {
        return tryResolveAbilityEffectSwitchYellAdjunct($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (in_array($type, [
        'both_wr_member_to_empty_stage',
        'play_wr_members_combined_cost',
    ], true)) {
        return tryResolveAbilityEffectSwitchWrMemberStage($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (in_array($type, [
        'other_member_blade_if_plain_live',
        'turn_one_live_score_member_blade',
    ], true)) {
        return tryResolveAbilityEffectSwitchLiveScoreMemberBlade($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    if (in_array($type, [
        'wr_live_deck_top_draw_if_opp_wait',
        'position_change_off_center',
    ], true)) {
        return tryResolveAbilityEffectSwitchPositionWrLive($state, $pid, $source, $ab, $ctx, $type, $p, $name);
    }

    switch ($type) {
        case 'add_from_waiting_room':
            $candidates = array_values(array_filter($p['waiting_room'], function ($c) use ($ab) {
                if (($ab['filter'] ?? '') === 'member') {
                    return ($c['card_type'] ?? '') === 'メンバー';
                }
                return true;
            }));
            $take = min(intval($ab['count'] ?? 1), count($candidates));
            if ($take > 0) {
                $picked = array_slice($candidates, 0, $take);
                $pickedIds = array_column($picked, 'instance_id');
                $p['waiting_room'] = array_values(array_filter(
                    $p['waiting_room'],
                    fn($c) => !in_array($c['instance_id'] ?? '', $pickedIds, true)
                ));
                $p['hand'] = array_merge($p['hand'], $picked);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] added $take Member(s) from Waiting Room to hand.");
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
                $group = $ab['group'] ?? 'μ\'s';
                $scored = count(array_filter(
                    $p['success_lives'] ?? [],
                    fn($c) => ($c['group'] ?? '') === $group && intval($c['score'] ?? 0) > 0
                ));
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
