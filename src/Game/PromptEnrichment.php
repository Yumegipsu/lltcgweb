<?php
/**
 * Prompt UI enrichment, PvP timeout / anti-softlock, and optional discard resolution.
 * Extracted from effects.php (Issue #36 Phase 15).
 */

/** Internal yes/no shell for optional_discard_prompt resolution (never omit choices). */
function buildInternalOptionalDiscardConfirmPrompt(
    array $state,
    string $owner,
    array $source,
    array $ab,
    string $sourceName,
    bool $liveStart
): array {
    return enrichSelfActivationPrompt($state, [
        'type'          => 'optional_discard_prompt',
        'owner'         => $owner,
        'responder'     => $owner,
        'source_id'     => $source['instance_id'] ?? '',
        'source_name'   => $sourceName,
        'prompt'        => $ab['prompt'] ?? ($liveStart ? 'Use optional Live Start effect?' : 'Use optional effect?'),
        'choices'       => ['yes', 'no'],
        'choice_labels' => ['Yes', 'No — Skip'],
        'ability'       => $ab,
        'live_start'    => $liveStart,
    ]);
}

function extractAbilityLineFromCardText(string $text, string $triggerLabel): ?string {
    foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (stripos($line, '[' . $triggerLabel . ']') !== false) {
            return $line;
        }
    }
    return null;
}

function describeThenEffect(array $then): string {
    if (empty($then)) {
        return '';
    }
    $type = $then['type'] ?? '';
    if ($type === 'blade_bonus') {
        return 'gain +' . intval($then['amount'] ?? 1) . ' Blade until this Live ends';
    }
    if ($type === 'blade_bonus_per_live_zone') {
        return 'gain +' . intval($then['amount'] ?? 1) . ' Blade for each Live card in your Live zone until this Live ends';
    }
    if ($type === 'live_score_bonus') {
        return 'gain +' . intval($then['amount'] ?? 1) . ' Live Score';
    }
    if ($type === 'draw') {
        return 'draw ' . intval($then['count'] ?? 1) . ' card(s)';
    }
    if ($type === 'look_reveal_filter' || $type === 'look_reveal_named') {
        $look = intval($then['look'] ?? 3);
        $pick = intval($then['pick'] ?? 1);
        return "look at the top $look cards of your deck and add $pick to your hand";
    }
    if ($type === 'activate_energy') {
        $max = intval($then['max'] ?? $then['count'] ?? 1);
        return 'activate up to ' . $max . ' Energy';
    }
    if ($type === 'add_from_waiting_room') {
        $filter = $then['filter'] ?? 'member';
        $count = intval($then['count'] ?? 1);
        return "add $count $filter card(s) from your Waiting Room to your hand";
    }
    return '';
}

function synthesizeAbilityEffectText(array $ab): string {
    $trigger = abilityTriggerLabel($ab['trigger'] ?? '');
    $type = $ab['type'] ?? '';
    $bracket = "[$trigger]";

    if ($type === 'optional_pay_energy') {
        $cost = intval($ab['cost'] ?? 0);
        $thenDesc = describeThenEffect($ab['then'] ?? []);
        return $thenDesc !== ''
            ? "$bracket You may pay $cost Energy: $thenDesc."
            : "$bracket You may pay $cost Energy for this effect.";
    }
    if ($type === 'optional_pay_energy_on_enter' || $type === 'optional_pay_energy_if_baton') {
        $cost = intval($ab['cost'] ?? 0);
        $thenDesc = describeThenEffect($ab['then'] ?? []);
        return $thenDesc !== ''
            ? "$bracket You may pay $cost Energy: $thenDesc."
            : "$bracket You may pay $cost Energy for this On Enter effect.";
    }
    if ($type === 'optional_discard_hand') {
        $n = intval($ab['discard'] ?? 1);
        $thenDesc = describeThenEffect($ab['then'] ?? []);
        return $thenDesc !== ''
            ? "$bracket Put $n card(s) from your hand into the Waiting Room: $thenDesc."
            : "$bracket Put $n card(s) from your hand into the Waiting Room.";
    }
    if (!empty($ab['prompt']) && is_string($ab['prompt'])) {
        return $bracket . ' ' . trim($ab['prompt']);
    }
    return $bracket . ' Optional effect — see card text.';
}

function abilityEffectTextFromSource(?array $source, array $ab, ?int $abilityIndex = null): string {
    if (!$source) {
        return synthesizeAbilityEffectText($ab);
    }
    $text = trim($source['text'] ?? '');
    if ($text === '') {
        return synthesizeAbilityEffectText($ab);
    }
    $trigger = $ab['trigger'] ?? 'live_start';
    $labels = [abilityTriggerLabel($trigger)];
    if ($trigger === 'on_enter_or_live_start') {
        $labels = ['Live Start', 'On Enter'];
    }
    foreach ($labels as $label) {
        $line = extractAbilityLineFromCardText($text, $label);
        if ($line !== null) {
            return $line;
        }
    }
    if ($abilityIndex !== null && !empty($source['abilities'][$abilityIndex]['prompt'])) {
        return '[' . abilityTriggerLabel($trigger) . '] ' . trim($source['abilities'][$abilityIndex]['prompt']);
    }
    return synthesizeAbilityEffectText($ab);
}

function isSelfActivationPromptType(string $type): bool {
    static $types = [
        'optional_live_start',
        'optional_pay_energy_on_enter',
        'optional_pay_energy_if_baton',
        'optional_pay_energy_live_success',
        'optional_pay_energy_up_to',
        'optional_discard_hand',
        'optional_discard_surveil',
        'optional_discard_add_from_wr',
        'optional_discard_named',
        'optional_discard_same_group',
        'optional_discard_prompt',
        'optional_discard_blade_draw_if_live',
        'optional_wait_self_wait_opp',
        'optional_wait_self_add_wr',
        'optional_wait_self',
        'optional_wait_self_center_blade',
        'optional_wr_member_reenter',
        'optional_pay_play_hand_member',
        'optional_negate_member_live_start_add_wr',
        'optional_reveal_live_deck_bottom_surveil',
        'optional_wr_member_deck_top_blade',
        'optional_success_live_swap',
        'optional_success_wr_live_swap',
        'optional_return_member_energy',
        'optional_wait_subunit_opp_pick_active',
        'optional_wait_group_member_draw_discard',
        'optional_position_change_all_muse',
        'optional_formation_change_group',
        'live_start_pay_or_discard',
    ];
    return in_array($type, $types, true);
}

function enrichSelfActivationPrompt(array $state, array $prompt): array {
    return enrichAbilityContextPrompt($state, $prompt, true);
}

function enrichAbilityContextPrompt(array $state, array $prompt, bool $selfActivationOnly = false): array {
    $type = $prompt['type'] ?? '';
    $owner = $prompt['owner'] ?? '';
    $responder = $prompt['responder'] ?? '';
    if ($owner === '' || $responder !== $owner) {
        return $prompt;
    }
    if ($selfActivationOnly && !isSelfActivationPromptType($type)) {
        return $prompt;
    }
    if (!empty($prompt['effect_text'])) {
        return $prompt;
    }
    $ab = $prompt['ability'] ?? [];
    if (empty($ab)) {
        return $prompt;
    }
    $sourceId = $prompt['source_id'] ?? '';
    $source = $sourceId !== '' ? findSourceCard($state, $owner, $sourceId) : null;
    if (!$source && $sourceId !== '') {
        $source = findLiveStartSourceCard($state, $owner, $sourceId);
    }
    if (!$source && $sourceId !== '') {
        $p = $state['players'][$owner] ?? [];
        foreach (array_merge(
            $p['hand'] ?? [],
            $p['waiting_room'] ?? [],
            array_values(array_filter($p['stage'] ?? [])),
            $p['live_zone'] ?? []
        ) as $c) {
            if (($c['instance_id'] ?? '') === $sourceId) {
                $source = $c;
                break;
            }
        }
    }
    $prompt['effect_text'] = abilityEffectTextFromSource(
        $source,
        $ab,
        isset($prompt['ability_index']) ? intval($prompt['ability_index']) : null
    );
    $prompt['trigger_label'] = abilityTriggerLabel($ab['trigger'] ?? '');
    return $prompt;
}

/** Prompt types that are always optional (no mandatory phase timer). */
function isOptionalPromptType(string $type): bool {
    if (str_starts_with($type, 'optional_')) {
        return true;
    }
    static $optionalTypes = [
        'look_top_optional_wr',
        'opp_may_discard_or_modifier',
        'reveal_live_opp_discard_or_blade',
        'pick_surveil_heart_threshold',
    ];
    return in_array($type, $optionalTypes, true);
}

/** True when a pending prompt must be answered (not skip/no-only optional activation). */
function isMandatorySkillPrompt(array $prompt): bool {
    if (!empty($prompt['optional'])) {
        return false;
    }
    $type = $prompt['type'] ?? '';
    if (isOptionalPromptType($type)) {
        return false;
    }
    $choices = $prompt['choices'] ?? [];
    if (!empty($choices)) {
        $mandatory = array_values(array_diff($choices, ['skip', 'no', 'cancel']));
        if (empty($mandatory) && (in_array('skip', $choices, true) || in_array('no', $choices, true))) {
            return false;
        }
    }
    return true;
}

/** Default resolution when a PvP phase timer expires during a pending skill prompt. */
function buildTimeoutPromptResolution(array $state, string $pid, array $prompt): array {
    $type = $prompt['type'] ?? '';
    $owner = $prompt['owner'] ?? $pid;
    $ownerP = $state['players'][$owner] ?? $state['players'][$pid];

    switch ($type) {
        case 'surveil_arrange':
            $looked = $state['surveil_stash'] ?? [];
            $ids = array_column($looked, 'instance_id');
            if (empty($ids)) {
                $ids = array_column($prompt['looked_cards'] ?? [], 'instance_id');
            }
            return ['choice' => 'confirm', 'top_ids' => $ids, 'wr_ids' => []];

        case 'look_top_optional_wr':
            return ['choice' => 'no'];

        case 'optional_wait_group_member_draw_discard':
            if (($prompt['step'] ?? '') === 'pick_member') {
                $id = $prompt['stage_members'][0]['instance_id'] ?? '';
                return $id !== '' ? ['member_id' => $id] : ['choice' => 'no'];
            }
            return ['choice' => 'no'];

        case 'effect_discard_hand':
            $need = intval($prompt['count'] ?? 1);
            $hand = $ownerP['hand'] ?? [];
            $ids = array_slice(array_column($hand, 'instance_id'), 0, $need);
            if (($prompt['pick_mode'] ?? '') === 'deck_top') {
                return ['card_ids' => $ids];
            }
            return ['discard_ids' => $ids];

        case 'mandatory_discard_look_reveal':
        case 'sbp5_draw_deck_bottom':
        case 'sbp6_discard_after_draw':
            $need = intval($prompt['count'] ?? $prompt['bottom_count'] ?? $prompt['discard_count'] ?? 1);
            $ids = array_slice(array_column($ownerP['hand'] ?? [], 'instance_id'), 0, $need);
            return ['discard_ids' => $ids];

        case 'optional_live_start':
            return ['choice' => 'no'];

        case 'optional_discard_prompt':
        case 'optional_discard_mill_wr_add_member':
        case 'optional_pay_energy_on_enter':
        case 'optional_pay_energy_if_baton':
        case 'optional_pay_energy_live_success':
        case 'optional_pay_play_hand_member':
        case 'optional_discard_blade_draw_if_live':
        case 'optional_success_live_swap':
        case 'pick_surveil_heart_threshold':
        case 'spbp5_pay_energy_score':
        case 'live_success_pay_choice_wr_add':
            if (($prompt['step'] ?? '') === 'confirm') {
                return ['choice' => 'no'];
            }
            return ['choice' => 'no'];

        case 'blade_per_discarded_pick_member':
            $id = $prompt['candidates'][0]['instance_id'] ?? null;
            return $id ? ['card_id' => $id] : ['choice' => 'skip'];

        case 'pick_judge_success_live': {
            $cands = $prompt['candidates'] ?? [];
            usort($cands, fn($a, $b) => intval($b['score'] ?? 0) <=> intval($a['score'] ?? 0));
            $id = $cands[0]['instance_id'] ?? null;
            return $id ? ['card_id' => $id] : [];
        }

        case 'pick_looked_deck_hand':
            if (!empty($prompt['optional'])) {
                return ['choice' => 'skip'];
            }
            $id = ($prompt['eligible_ids'] ?? [])[0] ?? null;
            return $id ? ['card_id' => $id] : ['choice' => 'skip'];

        case 'pick_wr_to_hand':
        case 'pick_wr_leave_stage_add': {
            $ability = $prompt['ability'] ?? [];
            $cfg = $prompt['wr_pick_cfg'] ?? (
                ($prompt['type'] ?? '') === 'pick_wr_leave_stage_add'
                    ? wrPickCfgForLeaveStageAbility($ability)
                    : wrPickCfgFromAbility($ability)
            );
            $filter = (string)($cfg['filter'] ?? 'member');
            $cands = $prompt['candidates'] ?? [];
            $pool = $cands;
            if ($filter === 'member') {
                $members = array_values(array_filter(
                    $cands,
                    fn($c) => ($c['card_type'] ?? '') === 'メンバー' || ($c['card_type_en'] ?? '') === 'Member'
                ));
                if (!empty($members)) {
                    $pool = $members;
                }
            } elseif ($filter === 'live') {
                $lives = array_values(array_filter(
                    $cands,
                    fn($c) => ($c['card_type'] ?? '') === 'ライブ' || ($c['card_type_en'] ?? '') === 'Live'
                ));
                if (!empty($lives)) {
                    $pool = $lives;
                }
            }
            $id = $pool[count($pool) - 1]['instance_id'] ?? $pool[0]['instance_id'] ?? null;
            return $id ? ['card_id' => $id] : [];
        }

        case 'optional_success_wr_live_swap':
            if (($prompt['step'] ?? '') === 'confirm') {
                return ['choice' => 'no'];
            }
            $cands = $prompt['candidates'] ?? [];
            if (($prompt['step'] ?? '') === 'pick_success_live') {
                usort($cands, fn($a, $b) => intval($a['score'] ?? 0) <=> intval($b['score'] ?? 0));
            } else {
                usort($cands, fn($a, $b) => intval($b['score'] ?? 0) <=> intval($a['score'] ?? 0));
            }
            $id = $cands[0]['instance_id'] ?? null;
            return $id ? ['card_id' => $id] : ['choice' => 'skip'];

        case 'optional_success_live_swap':
            if (!empty($prompt['optional'])) {
                return ['choice' => 'skip'];
            }
            $id = ($prompt['eligible_ids'] ?? [])[0] ?? ($prompt['candidates'][0]['instance_id'] ?? null);
            return $id ? ['card_id' => $id] : ['choice' => 'skip'];

        case 'optional_wr_live_deck_bottom':
        case 'live_success_yell_live_deck_bottom':
            return ['choice' => 'skip'];

        case 'live_start_pay_or_discard':
            $choices = $prompt['choices'] ?? ['pay', 'discard'];
            foreach ($choices as $choiceKey) {
                if ($choiceKey === 'pay') {
                    $cost = intval($prompt['pay_cost'] ?? 2);
                    $ae = count(array_filter($ownerP['energy_zone'] ?? [], 'energyChipActive'));
                    if ($ae >= $cost) {
                        return ['choice' => 'pay'];
                    }
                    continue;
                }
                if ($choiceKey === 'discard') {
                    $need = intval($prompt['discard_count'] ?? 2);
                    $ids = array_slice(array_column($ownerP['hand'] ?? [], 'instance_id'), 0, $need);
                    return ['choice' => 'discard', 'discard_ids' => $ids];
                }
            }
            return ['choice' => $choices[0] ?? 'pay'];

        case 'opp_may_discard_or_modifier':
        case 'reveal_live_opp_discard_or_blade':
            return ['choice' => 'no'];

        case 'player_choice':
            $keys = $prompt['choices'] ?? [];
            return ['choice' => $keys[0] ?? 'skip'];

        case 'wait_opponent_stage_pick':
            $slot = $prompt['candidates'][0]['slot'] ?? '';
            return $slot !== '' ? ['slot' => $slot] : ['choice' => 'skip'];

        default:
            if (!isMandatorySkillPrompt($prompt)) {
                $choices = $prompt['choices'] ?? [];
                if (in_array('skip', $choices, true)) {
                    return ['choice' => 'skip'];
                }
                if (in_array('no', $choices, true)) {
                    return ['choice' => 'no'];
                }
            }
            $choices = $prompt['choices'] ?? [];
            if (!empty($choices)) {
                return ['choice' => $choices[0]];
            }
            if (!empty($prompt['eligible_ids'][0])) {
                return ['card_id' => $prompt['eligible_ids'][0]];
            }
            if (!empty($prompt['candidates'][0]['instance_id'])) {
                return ['card_id' => $prompt['candidates'][0]['instance_id']];
            }
            if (!empty($prompt['stage_members'][0]['instance_id'])) {
                return ['member_id' => $prompt['stage_members'][0]['instance_id']];
            }
            return ['choice' => 'confirm'];
    }
}

function autoResolvePendingPromptForTimeout(array $state, string $pid): array {
    $prompt = $state['pending_prompt'] ?? null;
    if (!$prompt || ($prompt['responder'] ?? '') !== $pid) {
        return $state;
    }
    try {
        $data = buildTimeoutPromptResolution($state, $pid, $prompt);
        $src = $prompt['source_name'] ?? ($prompt['type'] ?? 'effect');
        $state = addLog($state, ($state['players'][$pid]['name'] ?? $pid) .
            " — Time expired; auto-resolved [{$src}].", 'info');
        return actionResolvePrompt($state, $pid, $data);
    } catch (Throwable $e) {
        return $state;
    }
}

/** Force-clear a stuck skill prompt and advance the effect phase queue (no effect applied). */
function forceDismissPendingPromptForPlayer(array $state, string $pid, string $logPrefix = 'Dismissed'): array {
    $prompt = $state['pending_prompt'] ?? null;
    if (!$prompt || ($prompt['responder'] ?? '') !== $pid) {
        return $state;
    }
    $src = $prompt['source_name'] ?? ($prompt['type'] ?? 'effect');
    $state = addLog($state, ($state['players'][$pid]['name'] ?? $pid) .
        " — {$logPrefix} [{$src}] (no effect).", 'info');
    unset($state['pending_prompt'], $state['surveil_stash'], $state['_surveil_chain']);
    $state['seq']++;
    return finishPromptEffects($state);
}

function playerLooksLikeCpu(array $player): bool {
    $name = (string)($player['name'] ?? '');
    return str_contains($name, 'CPU') || str_contains($name, '🤖');
}

/** Anti-softlock: skip the current skill prompt without applying its effect. */
function actionAntiSoftlockSkipPrompt(array $state, string $pid): array {
    if (($state['status'] ?? '') !== 'playing') {
        throw new Exception('Game is not in progress');
    }
    $prompt = $state['pending_prompt'] ?? null;
    if (!$prompt || ($prompt['responder'] ?? '') !== $pid) {
        throw new Exception('No skill prompt to skip');
    }
    $isCpu = playerLooksLikeCpu($state['players'][$pid] ?? []);
    $dismissLabel = $isCpu ? 'CPU hung on skill; auto-skipped' : 'Anti-softlock';
    if (in_array($prompt['type'] ?? '', ['pick_wr_to_hand', 'pick_wr_leave_stage_add'], true)) {
        foreach ($prompt['candidates'] ?? [] as $cand) {
            $id = $cand['instance_id'] ?? '';
            if ($id === '') {
                continue;
            }
            try {
                return actionResolvePrompt($state, $pid, ['card_id' => $id]);
            } catch (Throwable $ignored) {
            }
        }
    }
    if (!isMandatorySkillPrompt($prompt)) {
        try {
            $state = actionResolvePrompt($state, $pid, ['choice' => 'no']);
            if (empty($state['pending_prompt']) || ($state['pending_prompt']['responder'] ?? '') !== $pid) {
                $state = addLog($state, ($state['players'][$pid]['name'] ?? $pid) .
                    ($isCpu
                        ? ' — CPU hung on skill; auto-skipped optional effect.'
                        : ' — Anti-softlock: skipped optional skill.'), 'info');
                return $state;
            }
        } catch (Throwable $ignored) {
        }
    }
    return forceDismissPendingPromptForPlayer($state, $pid, $dismissLabel);
}

/**
 * Resolve optional_discard_prompt (yes/no + discard_ids). Shared by actionResolvePrompt,
 * optional_live_start, and resolveAbilityEffect confirm paths.
 * When $deferFinish is true, caller must run finishLiveStartEffects / finishPromptEffects.
 */
function resolveOptionalDiscardPromptChoice(
    array $state,
    string $owner,
    array $prompt,
    string $choice,
    array $data,
    bool $deferFinish = false
): array {
    $promptAbility = $prompt['ability'] ?? [];
    $ownerP = &$state['players'][$owner];

        if ($choice === 'skip' || $choice === 'cancel') {
            $choice = 'no';
        }
        if (!isset(['yes' => true, 'no' => true][$choice])) {
            throw new Exception('Invalid choice');
        }
        if ($choice === 'yes') {
            $energyCost = intval($promptAbility['energy_cost'] ?? 0);
            if ($energyCost > 0 && !payEnergyCost($ownerP, $energyCost)) {
                throw new Exception("Need $energyCost active Energy");
            }
            $then = $promptAbility['then'] ?? [];
            if (!optionalDiscardThenViable($ownerP, $then)) {
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional effect (deck empty).');
                unset($state['pending_prompt']);
                if (!$deferFinish) {
                    $state['seq']++;
                    if (!empty($prompt['live_start'])) {
                        return finishLiveStartEffects($state);
                    }
                    $state = finishPromptEffects($state);
                }
                return $state;
            }
            $maxDiscard = intval($promptAbility['max_discard'] ?? 0);
            $need = $maxDiscard > 0 ? $maxDiscard : intval($promptAbility['discard'] ?? 1);
            $ids = $data['discard_ids'] ?? [];
            if ($maxDiscard > 0) {
                if (count($ids) > $maxDiscard) {
                    throw new Exception("Must select at most $maxDiscard card(s) to discard");
                }
            } elseif ($need > 0 && count($ids) !== $need) {
                throw new Exception("Must select exactly $need card(s) to discard");
            }
            if (!empty($ids)) {
                $discardedCards = takeDiscardedHandCards($ownerP, $ids);
            } else {
                $discardedCards = [];
            }
            $then = $promptAbility['then'] ?? [];
            if (($then['type'] ?? '') === 'draw_equal_discarded') {
                $drawn = drawCardsForPlayer($state, $owner, count($ids));
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] discarded " . count($ids) .
                    " and drew $drawn.");
            } elseif (($then['type'] ?? '') === 'wait_opponent_stage_max_cost') {
                $opp = ($owner === 'p1') ? 'p2' : 'p1';
                $maxCost = intval($then['max_cost'] ?? 4);
                $pickCount = isset($then['pick_count']) ? intval($then['pick_count']) : null;
                $waited = waitOpponentStageByCost(
                    $state,
                    $opp,
                    $maxCost,
                    $pickCount,
                    $owner
                );
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] discarded $need; " .
                    ($waited > 0
                        ? "$waited opponent Stage Member" . ($waited === 1 ? '' : 's') . ' put into Wait.'
                        : 'no opponent Stage Members matched; none put into Wait.'));
            } elseif (($then['type'] ?? '') === 'look_reveal_filter'
                || ($then['type'] ?? '') === 'look_reveal_group'
                || ($then['type'] ?? '') === 'look_reveal_named') {
                $state = beginLookRevealPick(
                    $state,
                    $owner,
                    $prompt['source_name'] ?? 'Member',
                    $ownerP,
                    $then
                );
                if (!empty($state['pending_prompt'])) {
                    $state['seq']++;
                    return $state;
                }
            } elseif (($then['type'] ?? '') === 'blade_bonus') {
                $state = applyModifierEffect($state, $owner, $then);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] gained +' .
                    intval($then['amount'] ?? 0) . ' Blade until Live ends.');
            } elseif (($then['type'] ?? '') === 'blade_bonus_named_extra') {
                $state = applyModifierEffect($state, $owner, ['type' => 'blade_bonus', 'amount' => intval($then['amount'] ?? 1)]);
                $named = $then['named'] ?? '';
                foreach ($discardedCards as $dc) {
                    if (cardNameKey($dc) === $named || str_contains(cardNameKey($dc), $named)) {
                        $state = applyModifierEffect($state, $owner, [
                            'type'   => 'blade_bonus',
                            'amount' => intval($then['extra_amount'] ?? 1),
                        ]);
                        break;
                    }
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] gained Blade until Live ends.');
            } elseif (($then['type'] ?? '') === 'live_start_self_cost_plus_check') {
                $srcId = $prompt['source_id'] ?? '';
                foreach ($ownerP['stage'] as $s => &$mbr) {
                    if ($mbr && ($mbr['instance_id'] ?? '') === $srcId) {
                        $mbr['live_cost_bonus'] = intval($mbr['live_cost_bonus'] ?? 0) + intval($then['amount'] ?? 6);
                        break;
                    }
                }
                unset($mbr);
                $mySum = sumStageMemberCost($ownerP, $state, $owner);
                $opp = ($owner === 'p1') ? 'p2' : 'p1';
                $oppSum = sumStageMemberCost($state['players'][$opp], $state, $opp);
                if ($mySum > $oppSum) {
                    $heartColor = $then['heart_color'] ?? $promptAbility['heart_color'] ?? 'pink';
                    addBonusHeartsToModifier($state, $owner, [['color' => $heartColor, 'count' => 1]]);
                    $state = applyModifierEffect($state, $owner, ['type' => 'blade_bonus', 'amount' => 1]);
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . '] cost increased until Live ends.');
            } elseif (($then['type'] ?? '') === 'pick_subunit_member_heart'
                || ($then['type'] ?? '') === 'pick_group_member_heart') {
                $candidates = [];
                foreach ($ownerP['stage'] as $slot => $mbr) {
                    if (!$mbr) continue;
                    $ok = ($then['type'] ?? '') === 'pick_subunit_member_heart'
                        ? cardMatchesSubunit($mbr, $then['subunit'] ?? '')
                        : (($mbr['group'] ?? '') === ($then['group'] ?? ''));
                    if ($ok) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
                    }
                }
                if (empty($candidates)) {
                    $state = addLog($state, $state['players'][$owner]['name'] .
                        ' — [' . ($prompt['source_name'] ?? 'Member') . '] no matching Member on Stage.');
                } elseif (count($candidates) === 1) {
                    applyNamedMemberHeartsBlade($state, $owner, $candidates[0]['instance_id'], $then);
                    $state = addLog($state, $state['players'][$owner]['name'] .
                        ' — [' . ($prompt['source_name'] ?? 'Member') . '] granted bonus hearts.');
                } else {
                    $state['pending_prompt'] = [
                        'type'        => 'pick_member_grant_hearts',
                        'owner'       => $owner,
                        'responder'   => $owner,
                        'source_name' => $prompt['source_name'] ?? 'Member',
                        'candidates'  => $candidates,
                        'hearts'      => $then['hearts'] ?? [],
                        'prompt'      => 'Choose 1 Member for bonus hearts until Live ends.',
                    ];
                    $state['seq']++;
                    return $state;
                }
            } elseif (($then['type'] ?? '') === 'pick_member_heart_blade') {
                $candidates = [];
                foreach ($ownerP['stage'] as $slot => $mbr) {
                    if ($mbr) {
                        $candidates[] = array_merge(cardPromptSummary($mbr), ['slot' => $slot]);
                    }
                }
                if (count($candidates) === 1) {
                    applyNamedMemberHeartsBlade($state, $owner, $candidates[0]['instance_id'], $then);
                } elseif (count($candidates) > 1) {
                    $state['pending_prompt'] = [
                        'type'        => 'pick_member_grant_hearts',
                        'owner'       => $owner,
                        'responder'   => $owner,
                        'source_name' => $prompt['source_name'] ?? 'Member',
                        'candidates'  => $candidates,
                        'hearts'      => $then['hearts'] ?? [],
                        'blade'       => intval($then['blade'] ?? 1),
                        'prompt'      => 'Choose 1 Member for bonus hearts and Blade.',
                    ];
                    $state['seq']++;
                    return $state;
                }
            } elseif (($then['type'] ?? '') === 'add_live_and_member_from_wr') {
                $liveAdded = addFromWaitingRoomFiltered($ownerP, '', 'live', 1);
                $memAdded = addFromWaitingRoomFiltered($ownerP, '', 'member', 1);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [" . ($prompt['source_name'] ?? 'Member') . "] added $liveAdded Live and $memAdded Member from WR.");
            } elseif (($then['type'] ?? '') === 'add_from_wr') {
                $added = addFromWaitingRoomFiltered(
                    $ownerP,
                    $then['group'] ?? '',
                    $then['filter'] ?? '',
                    intval($then['count'] ?? 1),
                    null,
                    array_filter(['subunit' => $then['subunit'] ?? ''])
                );
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] added $added card(s) from Waiting Room.");
            } elseif (sSd1IsEffectType($then['type'] ?? '')) {
                $source = findSourceCard($state, $owner, $prompt['source_id'] ?? '');
                if ($source) {
                    $state = sSd1ResolveEffect($state, $owner, $source, $then, []);
                    if (!empty($state['pending_prompt'])) {
                        $state['seq']++;
                        return $state;
                    }
                }
            } elseif (($then['type'] ?? '') === 'member_blade_bonus') {
                if (!empty($then['all_other'])) {
                    $then['max_members'] = 99;
                    $then['exclude_source_id'] = $prompt['source_id'] ?? '';
                }
                $n = applyMemberBladeBonus($state, $owner, $then);
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Member') . "] discarded $need; $n Member(s) gained +" .
                    intval($then['amount'] ?? 0) . ' Blade.');
            } elseif (($then['type'] ?? '') === 'other_member_heart') {
                $n = applyOtherMemberHeartBonus(
                    $state,
                    $owner,
                    $prompt['source_id'] ?? '',
                    $then['color'] ?? 'yellow',
                    intval($then['max_members'] ?? 1)
                );
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [" . ($prompt['source_name'] ?? 'Member') . "] discarded $need; $n Member(s) gained a heart.");
            } elseif (($then['type'] ?? '') === 'pick_yell_member') {
                $yellPool = $ownerP['_pending_yell_wr'] ?? [];
                $candidates = array_values(array_filter(
                    $yellPool,
                    fn($c) => cardMatchesYellPick($c, $then)
                ));
                if (!empty($candidates)) {
                    $pickPrompt = ($then['filter'] ?? '') === 'member_or_live'
                        ? 'Choose 1 Member (cost ≤2) or Live (score ≤2) revealed by Yell to add to your hand.'
                        : 'Choose 1 μ\'s Member revealed by Yell to add to your hand.';
                    $state['pending_prompt'] = [
                        'type'        => 'pick_yell_member',
                        'owner'       => $owner,
                        'responder'   => $owner,
                        'source_name' => $prompt['source_name'] ?? 'Live',
                        'prompt'      => $pickPrompt,
                        'candidates'  => array_map('cardPromptSummary', $candidates),
                        'ability'     => $then,
                    ];
                    $state['seq']++;
                    return $state;
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    ' — [' . ($prompt['source_name'] ?? 'Live') . '] no μ\'s Members among Yell cards.');
            } elseif (($then['type'] ?? '') === 'buff_named_stage_member') {
                $discardedMember = null;
                foreach ($discardedCards as $c) {
                    if (($c['card_type'] ?? '') === 'メンバー') {
                        $discardedMember = $c;
                        break;
                    }
                }
                if ($discardedMember) {
                    $nameKey = cardNameKey($discardedMember);
                    $candidates = stageMembersMatchingName($ownerP, $nameKey);
                    if (count($candidates) === 1) {
                        applyNamedMemberHeartsBlade($state, $owner, $candidates[0]['instance_id'], $then);
                        $state = addLog($state, $state['players'][$owner]['name'] .
                            ' — [' . ($prompt['source_name'] ?? 'Member') .
                            "] buffed Member matching $nameKey.");
                    } elseif (count($candidates) > 1) {
                        $state['pending_prompt'] = [
                            'type'          => 'pick_same_name_member',
                            'owner'         => $owner,
                            'responder'     => $owner,
                            'source_name'   => $prompt['source_name'] ?? 'Member',
                            'prompt'        => 'Choose 1 Member on your Stage with the same name as the discarded Member.',
                            'stage_members' => $candidates,
                            'ability'       => $then,
                        ];
                        $state['seq']++;
                        return $state;
                    } else {
                        $state = addLog($state, $state['players'][$owner]['name'] .
                            ' — [' . ($prompt['source_name'] ?? 'Member') .
                            '] no matching-name Member on Stage.');
                    }
                } else {
                    $state = addLog($state, $state['players'][$owner]['name'] .
                        ' — [' . ($prompt['source_name'] ?? 'Member') .
                        '] discarded card was not a Member.');
                }
            } elseif (($then['type'] ?? '') === 'buff_member_matching_discarded_group') {
                $discGroup = '';
                foreach ($discardedCards as $dc) {
                    $discGroup = $dc['group'] ?? '';
                    if ($discGroup !== '') break;
                }
                if ($discGroup === '') {
                    $state = addLog($state, $state['players'][$owner]['name'] .
                        ' — [' . ($prompt['source_name'] ?? 'Member') . '] could not match discarded group.');
                } else {
                    $source = findSourceCard($state, $owner, $prompt['source_id'] ?? '');
                    if ($source) {
                        $state = resolveAbilityEffect($state, $owner, $source, $then, [
                            'discarded_group' => $discGroup,
                            'phase'           => !empty($prompt['live_start']) ? 'live_start' : 'on_enter',
                        ]);
                        if (!empty($state['pending_prompt'])) {
                            $state['seq']++;
                            return $state;
                        }
                    }
                }
            } elseif (($then['type'] ?? '') === 'live_cost_from_subunit_pick') {
                $source = findSourceCard($state, $owner, $prompt['source_id'] ?? '');
                if ($source) {
                    $state = resolveAbilityEffect($state, $owner, $source, $then, [
                        'phase' => !empty($prompt['live_start']) ? 'live_start' : 'on_enter',
                    ]);
                    if (!empty($state['pending_prompt'])) {
                        $state['seq']++;
                        return $state;
                    }
                }
            } elseif (($then['type'] ?? '') === 'energy_wait_from_deck') {
                $placed = 0;
                for ($i = 0; $i < intval($then['count'] ?? 1); $i++) {
                    if (putEnergyFromDeckInWait($ownerP, $state, $owner)) $placed++;
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [" . ($prompt['source_name'] ?? 'Member') . "] put $placed Energy into Wait.");
            } elseif (($then['type'] ?? '') === 'draw_until_hand') {
                $target = intval($then['target'] ?? 5);
                $drawn = 0;
                while (count($ownerP['hand']) < $target && !empty($ownerP['main_deck'])) {
                    $ownerP['hand'][] = array_shift($ownerP['main_deck']);
                    $drawn++;
                }
                $state = addLog($state, $state['players'][$owner]['name'] .
                    " — [" . ($prompt['source_name'] ?? 'Member') . "] drew $drawn (hand size " .
                    count($ownerP['hand']) . ').');
            } elseif (($then['type'] ?? '') === 'choose_heart_other_member') {
                $choices = $then['heart_choices'] ?? ['yellow', 'pink', 'purple', 'green', 'blue', 'red'];
                $state['pending_prompt'] = [
                    'type'            => 'choose_heart_other_member',
                    'owner'           => $owner,
                    'responder'       => $owner,
                    'source_id'       => $prompt['source_id'] ?? '',
                    'source_name'     => $prompt['source_name'] ?? 'Member',
                    'prompt'          => 'Choose a heart color for another Member on your Stage.',
                    'choices'         => $choices,
                    'choice_labels'   => array_map(fn($c) => ucfirst($c) . ' ♡', $choices),
                    'ability'         => $then,
                    'after_live_start'=> !empty($prompt['live_start']),
                ];
                $state['seq']++;
                return $state;
            } elseif (($then['type'] ?? '') === 'blade_per_discarded_pick_member') {
                $source = findSourceCard($state, $owner, $prompt['source_id'] ?? '');
                if ($source) {
                    $state = resolveAbilityEffect($state, $owner, $source, $then, [
                        'discarded_count' => count($ids),
                        'phase'           => !empty($prompt['live_start']) ? 'live_start' : ($prompt['phase'] ?? ''),
                    ]);
                    if (!empty($state['pending_prompt'])) {
                        $state['seq']++;
                        return $state;
                    }
                }
            }
        } else {
            $state = addLog($state, $state['players'][$owner]['name'] .
                ' — [' . ($prompt['source_name'] ?? 'Member') . '] skipped optional effect.');
        }
        if (!empty($state['pending_prompt'])
            && ($state['pending_prompt']['type'] ?? '') !== 'optional_discard_prompt') {
            if (!$deferFinish) {
                $state['seq']++;
            }
            return $state;
        }
        unset($state['pending_prompt']);
        if (isset($prompt['ability_index'])) {
            $slot = $prompt['source_slot'] ?? '';
            if ($slot !== '' && !empty($ownerP['stage'][$slot])) {
                markAbilityUsed($ownerP['stage'][$slot], intval($prompt['ability_index']));
            }
        }
        if ($deferFinish) {
            return $state;
        }
        $state['seq']++;
        if (!empty($prompt['live_start'])) {
            return finishLiveStartEffects($state);
        }
        $state = finishPromptEffects($state);
        return $state;

}
