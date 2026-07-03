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

        case 'block_effect_member_activate_turn':
            $state['block_effect_member_activate'] = true;
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] Members cannot become Active by effects this turn.");
            break;

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



        case 'reveal_per_both_stage_member':
            $members = countBothStagesMembers($state);
            $top = array_splice($p['main_deck'], 0, min($members, count($p['main_deck'])));
            $liveCount = 0;
            foreach ($top as $c) {
                if (($c['card_type'] ?? '') === 'ライブ') $liveCount++;
            }
            if ($liveCount > 0) {
                $bonus = $liveCount * intval($ab['score_per_live'] ?? 1);
                bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', $bonus);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] revealed $liveCount Live card(s); score +$bonus.");
            }
            if (!empty($top)) {
                $p['waiting_room'] = array_merge($p['waiting_room'], $top);
            }
            break;



        case 'other_member_blade_if_plain_live':
            if (liveZoneHasPlainLive($p['live_zone'])) {
                $n = applyMemberBladeBonus($state, $pid, [
                    'amount'             => intval($ab['amount'] ?? 2),
                    'max_members'        => intval($ab['max_members'] ?? 1),
                    'exclude_source_id'  => $source['instance_id'] ?? '',
                ]);
                if ($n > 0) {
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        " — [$name] $n other Member(s) gained +" . intval($ab['amount'] ?? 2) . ' Blade until Live ends.');
                }
            }
            break;

        case 'pick_yell_member':
            if (($ctx['phase'] ?? '') !== 'live_success') break;
            $yellPool = $ctx['yell_cards'] ?? $p['_pending_yell_wr'] ?? [];
            $candidates = array_values(array_filter(
                $yellPool,
                fn($c) => cardMatchesYellPick($c, $ab)
            ));
            if (empty($candidates)) break;
            if (count($candidates) === 1) {
                $picked = $candidates[0];
                $ownerP['hand'][] = $picked;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added ' . cardDisplayName($picked) . ' from Yell to hand.');
                break;
            }
            $state['pending_prompt'] = [
                'type'        => 'pick_yell_member',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'prompt'      => 'Choose 1 card revealed by Yell to add to your hand.',
                'candidates'  => array_map('cardPromptSummary', $candidates),
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose a Yell card.');
            break;

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






        case 'treat_as_subunits':
            break;

        case 'pick_wr_members_deck_top_by_opp_wait':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $need = countOppWaitMembers($state, $opp);
            if ($need <= 0) {
                break;
            }
            $candidates = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => cardMatchesGroup($c, $ab['group'] ?? '', $ab['filter'] ?? 'member')
            ));
            if (empty($candidates)) {
                break;
            }
            $pick = min($need, count($candidates));
            if (count($candidates) > $pick) {
                $state['pending_prompt'] = [
                    'type'          => 'pick_wr_members_deck_top',
                    'owner'         => $pid,
                    'responder'     => $pid,
                    'source_name'   => $name,
                    'prompt'        => "Choose up to $pick Nijigasaki Member(s) from Waiting Room for deck top.",
                    'candidates'    => array_map('cardPromptSummary', $candidates),
                    'pick_count'    => $pick,
                    'ability'       => $ab,
                ];
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] choose up to $pick Member(s) from Waiting Room.");
                break;
            }
            $picked = array_slice($candidates, 0, $pick);
            $pickIds = array_map(fn($c) => $c['instance_id'] ?? '', $picked);
            $p['waiting_room'] = array_values(array_filter(
                $p['waiting_room'],
                fn($c) => !in_array($c['instance_id'] ?? '', $pickIds, true)
            ));
            $p['main_deck'] = array_merge(array_reverse($picked), $p['main_deck']);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] put $pick Member(s) from Waiting Room on deck top.");
            break;

        case 'turn_one_live_score_member_blade':
            if (intval($state['turn'] ?? 1) !== 1) {
                break;
            }
            bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['score'] ?? 1));
            $n = applyMemberBladeBonus($state, $pid, [
                'group'        => $ab['group'] ?? 'Nijigasaki',
                'amount'       => intval($ab['blade'] ?? 1),
                'max_members'  => intval($ab['max_members'] ?? 1),
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] score +1; $n Member(s) gained +" . intval($ab['blade'] ?? 1) . ' Blade (turn 1).');
            break;


        case 'add_self_to_hand_if_winning_yell':
            if (empty($ctx['yell_cards'])) break;
            $inYell = false;
            foreach ($ctx['yell_cards'] as $yc) {
                if (($yc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $inYell = true;
                    break;
                }
            }
            if (!$inYell) break;
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $myScore = array_sum(array_column($p['live_zone'] ?? [], 'score')) + getLiveScoreBonus($state, $pid);
            $oppScore = array_sum(array_column($state['players'][$opp]['live_zone'] ?? [], 'score'))
                + getLiveScoreBonus($state, $opp);
            if ($myScore > $oppScore) {
                $p['hand'][] = $source;
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] added itself to hand (winning Live score, revealed by Yell).');
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

        case 'opp_may_discard_or_modifier':
            if (!empty($state['pending_prompt'])) {
                break;
            }
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            $state['pending_prompt'] = [
                'type'          => 'opp_may_discard_or_modifier',
                'owner'         => $pid,
                'responder'     => $opp,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'prompt'        => 'Put 1 Live card from your hand into the Waiting Room? (If not, opponent gains +1 total Live Score.)',
                'choices'       => ['yes', 'no'],
                'choice_labels' => ['Yes — Discard Live', 'No — Opponent gains Live Score'],
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] On Enter: opponent may discard a Live card.");
            break;

        case 'member_hearts_as_blade':
            foreach ($p['stage'] as $slot => &$mbr) {
                if ($mbr && ($mbr['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                    $mbr['hearts_as_blade_color'] = $ab['color'] ?? 'blue';
                    $p['stage'][$slot] = $mbr;
                    break;
                }
            }
            unset($mbr);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] printed hearts treated as ' .
                ucfirst($ab['color'] ?? 'blue') . ' Blade hearts until this Live ends.');
            break;

        case 'negate_self_live_success_if_group_hearts':
            $heartColor = (string)($ab['heart_color'] ?? '');
            if (sumGroupStageHearts($p, $ab['group'] ?? 'Sunshine', $heartColor)
                >= intval($ab['min_hearts'] ?? 6)) {
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        $lc['live_success_negated'] = true;
                        break;
                    }
                }
                unset($lc);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] Live Success ability negated (Aqours stage hearts).');
            }
            break;

        case 'block_success_live_on_tie':
            $state = initLiveModifiers($state);
            $state['live_modifiers']['both']['block_success_live_on_tie'] = true;
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] if Live scores tie, neither player adds Success Lives this turn.');
            break;

        case 'reduce_yell_reveal_count':
            if (!empty($ab['requires_other_members'])) {
                $others = 0;
                foreach ($p['stage'] as $mbr) {
                    if ($mbr && ($mbr['instance_id'] ?? '') !== ($source['instance_id'] ?? '')) {
                        $others++;
                    }
                }
                if ($others < 1) break;
            }
            $state = initLiveModifiers($state);
            $state['live_modifiers'][$pid]['yell_reveal_reduction'] =
                intval($state['live_modifiers'][$pid]['yell_reveal_reduction'] ?? 0)
                + intval($ab['amount'] ?? 8);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Yell reveal count reduced by ' . intval($ab['amount'] ?? 8) . ' until Live ends.');
            break;

        case 'pick_wr_distinct_lives_opp_choice':
            if (!empty($state['pending_prompt'])) break;
            $lives = array_values(array_filter(
                $p['waiting_room'] ?? [],
                fn($c) => ($c['card_type'] ?? '') === 'ライブ'
            ));
            $byName = [];
            foreach ($lives as $c) {
                $label = $c['name_en'] ?? $c['name'] ?? '';
                if ($label !== '' && !isset($byName[$label])) {
                    $byName[$label] = $c;
                }
            }
            $distinct = array_values($byName);
            if (count($distinct) < intval($ab['count'] ?? 2)) break;
            $state['pending_prompt'] = [
                'type'          => 'pick_wr_distinct_lives_opp_choice',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => array_map('cardPromptSummary', $distinct),
                'pick_count'    => intval($ab['count'] ?? 2),
                'prompt'        => 'Choose ' . intval($ab['count'] ?? 2) .
                    ' Live cards with different names from your Waiting Room.',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose Waiting Room Lives for opponent to pick.');
            break;

        case 'pick_named_members_grant_blade':
            if (!empty($state['pending_prompt'])) break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
                foreach ($ab['names'] ?? [] as $n) {
                    if ($label === $n || str_contains($label, $n)) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => true]);
                        break;
                    }
                }
                if (($mbr['group'] ?? '') === ($ab['group'] ?? '')) {
                    $already = false;
                    foreach ($candidates as $c) {
                        if (($c['slot'] ?? '') === $slot) { $already = true; break; }
                    }
                    if (!$already) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => false]);
                    }
                }
            }
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'          => 'pick_named_members_grant_blade',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'candidates'    => $candidates,
                'named_list'    => $ab['names'] ?? [],
                'blade'         => intval($ab['blade'] ?? 1),
                'prompt'        => 'Choose 1 named Member, then 1 other Liella! Member for +Blade.',
                'step'          => 'pick_named',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose Members for +Blade.');
            break;

        case 'pick_named_members_grant_hearts':
            if (!empty($state['pending_prompt'])) break;
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr) continue;
                $label = $mbr['name_en'] ?? $mbr['name'] ?? '';
                foreach ($ab['names'] ?? [] as $n) {
                    if ($label === $n || str_contains($label, $n)) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => true]);
                        break;
                    }
                }
                if (($mbr['group'] ?? '') === ($ab['group'] ?? '')) {
                    $already = false;
                    foreach ($candidates as $c) {
                        if (($c['slot'] ?? '') === $slot) { $already = true; break; }
                    }
                    if (!$already) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot, 'named' => false]);
                    }
                }
            }
            if (count($candidates) < 2) break;
            $state['pending_prompt'] = [
                'type'          => 'pick_named_members_grant_hearts',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_name'   => $name,
                'candidates'    => $candidates,
                'named_list'    => $ab['names'] ?? [],
                'hearts'        => $ab['hearts'] ?? [],
                'prompt'        => 'Choose 1 named Member, then 1 other Liella! Member for bonus hearts.',
                'step'          => 'pick_named',
                'ability'       => $ab,
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] choose Members for bonus hearts.');
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

        case 'formation_rotate_all':
            if (!stageAllMembersInSubunit($p, $ab['requires_subunit_only'] ?? '')) break;
            spBp2MarkEffectAreaMove($state, $source);
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
                    $from = $before[$mbr['instance_id'] ?? ''] ?? $s;
                    spBp2ApplyMovedByGroupEffect($mbr, $state);
                    $state['players'][$id]['stage'][$s] = $mbr;
                    if ($from !== $s) {
                        $state = resolveAutoAreaMoveAbilities($state, $id, $mbr['instance_id'] ?? '', $from);
                        if (!empty($state['pending_prompt'])) {
                            spBp2ClearEffectAreaMove($state);
                            return $state;
                        }
                    }
                }
            }
            spBp2ClearEffectAreaMove($state);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] both players rotated Stage formation.');
            break;


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
            $state['pending_prompt'] = [
                'type'        => 'buff_member_matching_discarded_group',
                'owner'       => $pid,
                'responder'   => $pid,
                'source_name' => $name,
                'candidates'  => $candidates,
                'hearts'      => $ab['hearts'] ?? [['color' => 'pink', 'count' => 1]],
                'prompt'      => 'Choose 1 Member on your Stage with the same group as the discarded card.',
            ];
            break;


        case 'treat_group_stage_hearts_as':
            $state = initLiveModifiers($state);
            $grp = $ab['group'] ?? '';
            $color = $ab['color'] ?? 'pink';
            if ($grp !== '') {
                $state['live_modifiers'][$pid]['group_hearts_as'][$grp] = $color;
            }
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] $grp Member hearts treated as $color until Live ends.");
            break;

        case 'treat_pick_group_member_hearts_as':
            if (!empty($state['pending_prompt'])) break;
            $grp = $ab['group'] ?? 'Hasunosora';
            $candidates = [];
            foreach ($p['stage'] as $slot => $mbr) {
                if (!$mbr || ($mbr['group'] ?? '') !== $grp) continue;
                $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
            }
            if (empty($candidates)) break;
            $state['pending_prompt'] = [
                'type'          => 'treat_pick_group_member_hearts_as',
                'owner'         => $pid,
                'responder'     => $pid,
                'source_id'     => $source['instance_id'] ?? '',
                'source_name'   => $name,
                'color'         => $ab['color'] ?? 'pink',
                'candidates'    => $candidates,
                'prompt'        => "Choose 1 $grp Member — until this Live ends, all hearts on that Member are treated as " .
                    ($ab['color'] ?? 'pink') . ' ♡.',
            ];
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] choose a Member for heart treatment.");
            break;

    }
    return $state;
}
