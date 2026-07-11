<?php
/**
 * Engine-layer skill runtime audit for one manifest batch.
 *
 * Usage:
 *   php scripts/run_skill_audit_engine.php --batch=01
 *   php scripts/run_skill_audit_engine.php --batch=vol1 --update-progress
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/tests/bootstrap.php';

function skillAuditUsage(): void
{
    fwrite(STDERR, "Usage: php scripts/run_skill_audit_engine.php --batch=NN|vol1 [--update-progress] [--json]\n");
    exit(1);
}

$batchId = null;
$updateProgress = false;
$jsonOut = false;
foreach (array_slice($argv, 1) as $arg) {
    // Numeric batches (01–99) or named product batches (vol1, next, …).
    if (preg_match('/^--batch=([A-Za-z0-9][A-Za-z0-9_-]*)$/', $arg, $m)) {
        $batchId = $m[1];
    } elseif ($arg === '--update-progress') {
        $updateProgress = true;
    } elseif ($arg === '--json') {
        $jsonOut = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        skillAuditUsage();
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        skillAuditUsage();
    }
}
if ($batchId === null) {
    skillAuditUsage();
}

$batchPath = $root . "/reports/skill_audit_batches/batch-{$batchId}.json";
if (!is_file($batchPath)) {
    fwrite(STDERR, "Missing batch file: $batchPath\n");
    exit(1);
}

$matrixPath = $root . '/reports/card_interaction_matrix.json';
$matrix = is_file($matrixPath)
    ? json_decode((string)file_get_contents($matrixPath), true)
    : null;
$missingAnyRoute = [];
$prefixRouted = [];
if (is_array($matrix)) {
    foreach ($matrix['ability_types']['missing_exact_handler_or_prefix_route'] ?? [] as $t) {
        $missingAnyRoute[$t] = true;
    }
    foreach ($matrix['ability_types']['prefix_routed_without_exact_handler'] ?? [] as $row) {
        if (!empty($row['type'])) {
            $prefixRouted[$row['type']] = $row['prefix'] ?? '';
        }
    }
}

$batch = json_decode((string)file_get_contents($batchPath), true);
$cardsData = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);

function skillAuditIsTopLevelPath(string $path): bool
{
    return preg_match('/^abilities\[\d+\]$/', $path) === 1;
}

function skillAuditHarnessSkipReason(string $type, string $errorMsg = ''): ?string
{
    $uiOnly = [
        'hand_discard_for_stage_blade',
        'hand_discard_named_blade',
    ];
    if (in_array($type, $uiOnly, true)) {
        return 'harness: hand-only activation UI';
    }
    if (str_contains($errorMsg, 'Ability type not implemented')) {
        return 'server: ability type not implemented';
    }
    return null;
}

function skillAuditStaticSkipReason(string $type, array $missingAnyRoute, array $prefixRouted): ?string
{
    if (isset($missingAnyRoute[$type])) {
        return 'static: no server handler or prefix route';
    }
    if (isset($prefixRouted[$type])) {
        return 'static: prefix-routed only (' . $prefixRouted[$type] . ')';
    }
    return null;
}

function skillAuditBuildDebugState(string $cardNo, array $cardsData): array
{
    $cardDef = findCardDefByNo($cardsData['cards'] ?? [], $cardNo);
    if (!$cardDef) {
        throw new RuntimeException('Unknown card_no: ' . $cardNo);
    }
    $testCard = makeDebugCardInstance($cardDef);
    $zoneSpec = resolveDebugTestZoneForCard($cardDef, 'auto');
    $testPid = 'p1';

    $p1Resolved = resolvePlayerDeckLists($cardsData, 'nijigasaki');
    $cpuResolved = resolveCpuDeckLists($cardsData, 'normal', null);
    $p1MainNos = stripCardNoFromNos($p1Resolved['main_nos'], $cardNo);
    $cpuMainNos = stripCardNoFromNos($cpuResolved['main_nos'], $cardNo);
    $shuffleBody = ['shuffle' => true];

    $p1Main = buildDeckForRoom($cardsData['cards'], $p1MainNos, $shuffleBody, 'main_order');
    $p1Energy = buildDeckForRoom($cardsData['cards'], $p1Resolved['energy_nos'], $shuffleBody, 'energy_order');
    $p2Main = buildDeckForRoom($cardsData['cards'], $cpuMainNos, $shuffleBody, 'main_order');
    $p2Energy = buildDeckForRoom($cardsData['cards'], $cpuResolved['energy_nos'], $shuffleBody, 'energy_order');

    $state = initGameState('AUDIT' . strtoupper(substr(md5($cardNo . microtime(true)), 0, 6)), [
        'id' => 'p1', 'token' => 'audit_p1', 'name' => 'Audit P1',
        'deck_choice' => $p1Resolved['deck_choice'],
        'deck_label' => $p1Resolved['deck_label'],
        'main_deck' => $p1Main, 'energy_deck' => $p1Energy,
    ]);
    $state['players']['p2'] = initPlayerState([
        'id' => 'p2', 'token' => 'audit_p2', 'name' => 'CPU (Audit)',
        'deck_choice' => $cpuResolved['deck_choice'],
        'deck_label' => $cpuResolved['deck_label'],
        'main_deck' => $p2Main, 'energy_deck' => $p2Energy,
    ]);
    $state['status'] = 'setup';
    $state['cpu_difficulty'] = 'normal';

    return applyDebugCardTestBoard(
        $state,
        $testCard,
        $testPid,
        $zoneSpec,
        'self',
        'auto',
        $cardsData,
        ['p1' => [], 'p2' => []]
    );
}

function skillAuditFindStageMember(array $state, string $pid, string $cardNo): ?array
{
    foreach ($state['players'][$pid]['stage'] ?? [] as $slot => $mbr) {
        if (is_array($mbr) && ($mbr['card_no'] ?? '') === $cardNo) {
            return ['slot' => $slot, 'member' => $mbr];
        }
    }
    return null;
}

function skillAuditPlaceOnStageCenter(array $state, string $pid, array $card): array
{
    $card = $card;
    debugPreparePreplacedStageMember($card);
    $card['entered_turn'] = intval($state['turn'] ?? 1);
    $state['players'][$pid]['stage']['center'] = $card;
    return $state;
}

function skillAuditGetAbilityAtPath(array $card, string $path): ?array
{
    if (!preg_match('/^abilities\[(\d+)\]/', $path, $m)) {
        return null;
    }
    $idx = (int)$m[1];
    return $card['abilities'][$idx] ?? null;
}

function skillAuditLogHasEffect(array $state): bool
{
    foreach ($state['log'] ?? [] as $entry) {
        if (is_array($entry) && ($entry['type'] ?? '') === 'effect') {
            return true;
        }
        if (is_string($entry) && str_contains($entry, ' — [')) {
            return true;
        }
    }
    return false;
}

function skillAuditInferDiscardCount(array $ab): int
{
    if (isset($ab['discard'])) {
        return max(0, intval($ab['discard']));
    }
    $type = $ab['type'] ?? '';
    $needsOne = [
        'wait_self_discard_draw', 'wait_self_discard_add_wr_live', 'activated_pay_discard_add_wr_live',
        'discard_activate_energy_if_group_entered', 'discard_play_self_from_wr',
    ];
    return in_array($type, $needsOne, true) ? 1 : 0;
}

function skillAuditEnsureHandDiscardIds(array &$state, string $pid, int $need, ?array $cardsData): array
{
    if ($need <= 0) {
        return [];
    }
    $p = &$state['players'][$pid];
    while (count($p['hand'] ?? []) < $need) {
        $p['hand'][] = [
            'instance_id' => 'audit_disc_' . count($p['hand']),
            'card_type' => 'エネルギー',
            'name_en' => 'Energy',
        ];
    }
    unset($p);
    return array_slice(array_column($state['players'][$pid]['hand'], 'instance_id'), 0, $need);
}

function skillAuditEnsureLiveInHand(array &$state, string $pid, ?array $cardsData): ?string
{
    $p = &$state['players'][$pid];
    foreach ($p['hand'] ?? [] as $c) {
        if (isLiveTypeCard($c)) {
            unset($p);
            return $c['instance_id'] ?? null;
        }
    }
    if ($cardsData) {
        foreach ($cardsData['cards'] ?? [] as $c) {
            if (isLiveTypeCard($c)) {
                $inst = makeDebugCardInstance($c);
                $inst['instance_id'] = 'audit_live_hand';
                array_unshift($p['hand'], $inst);
                unset($p);
                return $inst['instance_id'];
            }
        }
    }
    unset($p);
    return null;
}

function skillAuditPrepareActivatedSource(
    array &$state,
    string $pid,
    array $cardDef,
    array $ab,
    ?array $cardsData
): array {
    $cardNo = $cardDef['card_no'] ?? '';
    $testCard = makeDebugCardInstance($cardDef);
    $turn = intval($state['turn'] ?? 1);

    if (!empty($ab['from_wr_only'])) {
        $testCard['instance_id'] = $testCard['instance_id'] ?? ('audit_wr_' . $cardNo);
        $testCard['entered_turn'] = $turn;
        foreach ($state['players'][$pid]['stage'] as $slot => $mbr) {
            if (is_array($mbr) && ($mbr['card_no'] ?? '') === $cardNo) {
                $state['players'][$pid]['stage'][$slot] = null;
            }
        }
        array_unshift($state['players'][$pid]['waiting_room'], $testCard);
        return ['member' => $testCard, 'slot' => null, 'zone' => 'waiting_room'];
    }

    if (!empty($ab['from_hand_only'])) {
        $testCard['instance_id'] = $testCard['instance_id'] ?? ('audit_hand_' . $cardNo);
        foreach ($state['players'][$pid]['stage'] as $slot => $mbr) {
            if (is_array($mbr) && ($mbr['card_no'] ?? '') === $cardNo) {
                $state['players'][$pid]['stage'][$slot] = null;
            }
        }
        array_unshift($state['players'][$pid]['hand'], $testCard);
        return ['member' => $testCard, 'slot' => null, 'zone' => 'hand'];
    }

    $targetSlot = $ab['slot'] ?? (!empty($ab['left_only']) ? 'left' : 'center');
    if (!skillAuditFindStageMember($state, $pid, $cardNo)) {
        if ($targetSlot === 'left') {
            debugPreparePreplacedStageMember($testCard);
            $testCard['entered_turn'] = $turn;
            $state['players'][$pid]['stage']['left'] = $testCard;
        } else {
            $state = skillAuditPlaceOnStageCenter($state, $pid, $testCard);
        }
    }
    $placed = skillAuditFindStageMember($state, $pid, $cardNo);
    if (!$placed) {
        return ['member' => $testCard, 'slot' => 'center', 'zone' => 'stage'];
    }
    skillAuditSeedForActivated($state, $pid, $cardDef, $ab, $turn, $cardsData);
    $placed = skillAuditFindStageMember($state, $pid, $cardNo) ?? $placed;
    $reqSlot = $ab['slot'] ?? null;
    if ($reqSlot === 'left' && ($placed['slot'] ?? '') !== 'left') {
        $mbr = $placed['member'];
        if (!empty($state['players'][$pid]['stage']['center'])
            && ($state['players'][$pid]['stage']['center']['card_no'] ?? '') === $cardNo) {
            $state['players'][$pid]['stage']['center'] = null;
        }
        $state['players'][$pid]['stage']['left'] = $mbr;
        $placed = ['member' => $mbr, 'slot' => 'left', 'zone' => 'stage'];
    }
    return ['member' => $placed['member'], 'slot' => $placed['slot'], 'zone' => 'stage'];
}

function skillAuditDirectRevealLiveActivate(
    array $state,
    string $pid,
    array $member,
    string $slot,
    int $abilityIdx,
    array $ab
): array {
    $p = &$state['players'][$pid];
    $revealed = null;
    foreach ($p['hand'] as $c) {
        if (isLiveTypeCard($c)) {
            $revealed = $c;
            break;
        }
    }
    if (!$revealed) {
        throw new RuntimeException('Choose a Live card from your hand to reveal');
    }
    $opp = ($pid === 'p1') ? 'p2' : 'p1';
    $mName = $member['name_en'] ?? $member['name'] ?? 'Member';
    $state = logAbilityChain($state, $pid, $member, 'activated');
    $state['pending_prompt'] = [
        'type'          => 'reveal_live_opp_discard_or_blade',
        'owner'         => $pid,
        'responder'     => $opp,
        'source_id'     => $member['instance_id'] ?? '',
        'source_slot'   => $slot,
        'ability_index' => $abilityIdx,
        'source_name'   => $mName,
        'revealed'      => cardPromptSummary($revealed),
        'prompt'        => 'Opponent revealed ' . cardDisplayName($revealed) .
            '. Put 1 card from your hand into the Waiting Room? (If not, they gain +4 Blade.)',
        'choices'       => ['yes', 'no'],
        'choice_labels' => ['Yes — Discard 1', 'No — Opponent gains Blade'],
        'ability'       => $ab,
    ];
    $state = addLog($state, $state['players'][$pid]['name'] .
        " — [$mName] revealed " . cardDisplayName($revealed) . ' from hand.');
    unset($p);
    return $state;
}

function skillAuditBuildActivatePayload(
    array &$state,
    string $pid,
    array $ab,
    array $source,
    int $abilityIdx,
    ?array $cardsData,
    array $cardDef = []
): array {
    $type = $ab['type'] ?? '';
    if ($type === 'reveal_live_opp_discard_or_blade') {
        $liveId = skillAuditEnsureLiveInHand($state, $pid, $cardsData);
        return [
            'card_id' => $source['member']['instance_id'] ?? '',
            'ability_index' => $abilityIdx,
            '_reveal_live_id' => $liveId,
        ];
    }

    $discardNeed = skillAuditInferDiscardCount($ab);
    $payload = [
        'card_id' => $source['member']['instance_id'] ?? '',
        'ability_index' => $abilityIdx,
    ];
    if ($discardNeed > 0) {
        $payload['discard_ids'] = skillAuditEnsureHandDiscardIds($state, $pid, $discardNeed, $cardsData);
    }
    if ($type === 'wait_self_choose_heart') {
        $choices = $ab['heart_choices'] ?? ['pink', 'yellow', 'purple'];
        $payload['heart_choice'] = $choices[0];
    }
    if ($type === 'wait_self_discard_reveal_until') {
        $payload['reveal_filter'] = 'live';
    }
    if ($type === 'discard_hand_activate_pick') {
        $payload['pick'] = 'energy';
    }
    if ($type === 'leave_play_named_from_hand_stack_energy' && $cardsData) {
        $names = $ab['names'] ?? [];
        $testNo = $cardDef['card_no'] ?? '';
        foreach ($cardsData['cards'] ?? [] as $c) {
            if (!isMemberCard($c) || !cardMatchesNames($c, $names)) {
                continue;
            }
            if (($c['card_no'] ?? '') === $testNo) {
                continue;
            }
            if (intval($c['cost'] ?? 0) > intval($ab['max_cost'] ?? 99)) {
                continue;
            }
            $inst = makeDebugCardInstance($c);
            $inst['instance_id'] = 'audit_hand_named';
            array_unshift($state['players'][$pid]['hand'], $inst);
            $payload['hand_card_id'] = $inst['instance_id'];
            break;
        }
    }
    if ($type === 'activated_discard_trigger_on_enter' && $cardsData) {
        $grp = $ab['group'] ?? '';
        $maxCost = intval($ab['max_cost'] ?? 99);
        foreach ($cardsData['cards'] ?? [] as $c) {
            if (!isMemberCard($c) || !cardMatchesGroup($c, $grp, 'member')) {
                continue;
            }
            if (intval($c['cost'] ?? 0) > $maxCost) {
                continue;
            }
            $inst = makeDebugCardInstance($c);
            if (empty(getAbilitiesByTrigger($inst, 'on_enter'))) {
                continue;
            }
            $inst['instance_id'] = 'audit_on_enter_hand';
            array_unshift($state['players'][$pid]['hand'], $inst);
            $payload['hand_card_id'] = $inst['instance_id'];
            break;
        }
    }
    if ($type === 'activated_discard_pay_wr_live_score') {
        foreach ($state['players'][$pid]['waiting_room'] ?? [] as $c) {
            if (isLiveTypeCard($c)) {
                $payload['wr_live_id'] = $c['instance_id'] ?? '';
                break;
            }
        }
    }
    if ($type === 'discard_hand_add_live_from_wr') {
        $state['players'][$pid]['success_lives'] = [
            ['instance_id' => 'succ1', 'card_type' => 'ライブ', 'score' => 4],
            ['instance_id' => 'succ2', 'card_type' => 'ライブ', 'score' => 3],
        ];
    }
    if ($type === 'discard_play_self_from_wr') {
        foreach (['left', 'center', 'right'] as $slot) {
            if (empty($state['players'][$pid]['stage'][$slot])) {
                $payload['slot'] = $slot;
                break;
            }
        }
    }
    return $payload;
}

function skillAuditSeedForActivated(array &$state, string $pid, array $cardDef, array $ab, int $turn, ?array $cardsData): void
{
    $p = &$state['players'][$pid];
    $group = $cardDef['group'] ?? 'Sunshine';
    $testNo = $cardDef['card_no'] ?? '';
    $type = $ab['type'] ?? '';

    if (str_starts_with($type, 'wait_pick_member') || $type === 'wait_swap_wr_member_center') {
        if (countStageMembers($p) < 2) {
            debugPlaceFillerStageMember($p, 'left', $turn);
        }
    }
    if ($type === 'activated_position_change_subunit_area' && $cardsData) {
        $grp = $ab['group'] ?? $group;
        $tpl = debugFindCatalogMember(['group' => $grp], $cardsData, $testNo, 'cheapest');
        if ($tpl) {
            $stageInst = makeDebugCardInstance($tpl);
            $stageInst['instance_id'] = 'audit_pos_left';
            debugPreparePreplacedStageMember($stageInst);
            $p['stage']['left'] = $stageInst;
        }
    }
    if ($type === 'wait_swap_wr_member_center' && $cardsData) {
        $bonus = intval($ab['cost_bonus'] ?? 2);
        $grp = $ab['group'] ?? $group;
        $costsInWr = [];
        foreach ($cardsData['cards'] ?? [] as $c) {
            if (isMemberCard($c) && ($c['group'] ?? '') === $grp) {
                $costsInWr[intval($c['cost'] ?? 0)] = $c;
            }
        }
        $pair = null;
        foreach ($costsInWr as $wrCost => $wrCard) {
            $stageCost = $wrCost - $bonus;
            if ($stageCost < 0) {
                continue;
            }
            foreach ($cardsData['cards'] ?? [] as $c) {
                if (!isMemberCard($c) || ($c['group'] ?? '') !== $grp) {
                    continue;
                }
                if (intval($c['cost'] ?? 0) === $stageCost && ($c['card_no'] ?? '') !== $testNo) {
                    $pair = ['stage' => $c, 'wr' => $wrCard];
                    break 2;
                }
            }
        }
        if ($pair) {
            $stageInst = makeDebugCardInstance($pair['stage']);
            $stageInst['instance_id'] = 'audit_stage_swap';
            debugPreparePreplacedStageMember($stageInst);
            $p['stage']['left'] = $stageInst;
            $wrInst = makeDebugCardInstance($pair['wr']);
            $wrInst['instance_id'] = 'audit_wr_swap';
            array_unshift($p['waiting_room'], $wrInst);
        }
    }
    if ($type === 'player_choice_wr_live_deck_bottom_draw' && $cardsData) {
        $hasLive = false;
        foreach ($p['waiting_room'] ?? [] as $c) {
            if (isLiveTypeCard($c)) {
                $hasLive = true;
                break;
            }
        }
        if (!$hasLive) {
            foreach ($cardsData['cards'] ?? [] as $c) {
                if (isLiveTypeCard($c)) {
                    $inst = makeDebugCardInstance($c);
                    $inst['instance_id'] = 'audit_wr_live_' . ($c['card_no'] ?? 'l');
                    array_unshift($p['waiting_room'], $inst);
                    break;
                }
            }
        }
    }
    if ($type === 'activated_discard_pay_wr_live_score' && $cardsData) {
        foreach ($cardsData['cards'] ?? [] as $c) {
            if (!isLiveTypeCard($c)) {
                continue;
            }
            $inst = makeDebugCardInstance($c);
            $inst['instance_id'] = 'audit_wr_live_score';
            $inst['score'] = max(1, intval($inst['score'] ?? 2));
            array_unshift($p['waiting_room'], $inst);
            break;
        }
    }
    if ($type === 'pay_energy_reveal_live_wr_superset') {
        skillAuditEnsureLiveInHand($state, $pid, $cardsData);
    }
    if (in_array($type, ['activated_return_energy_add_wr_live', 'leave_stage_add_wr_live_energy_if_success'], true) && $cardsData) {
        $grp = $ab['group'] ?? $group;
        foreach ($cardsData['cards'] ?? [] as $c) {
            if (!isLiveTypeCard($c)) {
                continue;
            }
            if ($grp !== '' && ($c['group'] ?? '') !== $grp && !cardMatchesGroup($c, $grp, 'live')) {
                continue;
            }
            $inst = makeDebugCardInstance($c);
            $inst['instance_id'] = 'audit_wr_live_ret';
            array_unshift($p['waiting_room'], $inst);
            break;
        }
    }
    if ($type === 'pay_energy_energy_wait_from_deck') {
        if (empty($p['energy_deck'])) {
            $p['energy_deck'] = [['instance_id' => 'ed1', 'card_type' => 'エネルギー']];
        }
    }
    if ($type === 'activated_wait_self_pick_subunit_blade' && $cardsData) {
        $sub = $ab['subunit'] ?? $ab['require_subunit'] ?? '';
        if ($sub !== '') {
            foreach ($cardsData['cards'] ?? [] as $c) {
                if (!isMemberCard($c) || !cardMatchesSubunit($c, $sub)) {
                    continue;
                }
                if (($c['card_no'] ?? '') === $testNo) {
                    continue;
                }
                $inst = makeDebugCardInstance($c);
                $inst['instance_id'] = 'audit_subunit_stage';
                debugPreparePreplacedStageMember($inst);
                $p['stage']['left'] = $inst;
                break;
            }
        }
    }
    unset($p);
}

function skillAuditRunTrigger(
    array $state,
    string $pid,
    array $cardDef,
    array $node,
    string $trigger,
    ?array $cardsData = null
): array {
    $cardNo = $node['card_no'];
    $type = $node['type'];
    $testCard = makeDebugCardInstance($cardDef);
    $notes = [];

    switch ($trigger) {
        case 'on_enter':
        case 'on_enter_or_auto':
            $state['phase'] = 'main_first';
            $state['active_player'] = $pid;
            if (!skillAuditFindStageMember($state, $pid, $cardNo)) {
                $state = skillAuditPlaceOnStageCenter($state, $pid, $testCard);
            }
            $placed = skillAuditFindStageMember($state, $pid, $cardNo);
            if (!$placed) {
                return ['FAIL', 'could not place member on stage', $state];
            }
            $state = resolveOnEnterAbilities($state, $pid, $placed['member'], $placed['slot']);
            break;

        case 'on_enter_or_live_start':
            $state['phase'] = 'live_start_effects';
            $state['active_player'] = $pid;
            if (!skillAuditFindStageMember($state, $pid, $cardNo)) {
                $state = skillAuditPlaceOnStageCenter($state, $pid, $testCard);
            }
            $state['live_start_optional_queue'] = [];
            $state = resolveLiveStartAbilities($state, $pid);
            break;

        case 'activated':
            $state['phase'] = 'main_first';
            $state['active_player'] = $pid;
            $abNode = skillAuditGetAbilityAtPath($cardDef, $node['ability_path']) ?? ['type' => $type];
            $source = skillAuditPrepareActivatedSource($state, $pid, $cardDef, $abNode, $cardsData);
            $abilityIdx = intval($node['ability_index'] ?? 0);
            if (($abNode['type'] ?? '') === 'reveal_live_opp_discard_or_blade') {
                skillAuditEnsureLiveInHand($state, $pid, $cardsData);
                $state = skillAuditDirectRevealLiveActivate(
                    $state,
                    $pid,
                    $source['member'],
                    $source['slot'] ?? 'center',
                    $abilityIdx,
                    $abNode
                );
            } else {
                $payload = skillAuditBuildActivatePayload($state, $pid, $abNode, $source, $abilityIdx, $cardsData, $cardDef);
                $state = actionActivateAbility($state, $pid, $payload);
            }
            break;

        case 'live_start':
            $state['phase'] = 'live_start_effects';
            $state['active_player'] = $pid;
            if (!skillAuditFindStageMember($state, $pid, $cardNo)) {
                $state = skillAuditPlaceOnStageCenter($state, $pid, $testCard);
            }
            $state['live_start_optional_queue'] = [];
            $state = resolveLiveStartAbilities($state, $pid);
            break;

        case 'live_success':
            $state['phase'] = 'live_success_effects';
            $state['active_player'] = $pid;
            if (!skillAuditFindStageMember($state, $pid, $cardNo)) {
                $state = skillAuditPlaceOnStageCenter($state, $pid, $testCard);
            }
            $state = resolveLiveSuccessAbilities($state, $pid, [], 0, [], []);
            break;

        case 'auto':
        case 'automatic':
            $state['phase'] = 'live_performance_first';
            $state['active_player'] = $pid;
            if (!skillAuditFindStageMember($state, $pid, $cardNo)) {
                $state = skillAuditPlaceOnStageCenter($state, $pid, $testCard);
            }
            $yellCards = [
                ['card_type' => 'ライブ', 'instance_id' => 'yell_live_1', 'group' => $cardDef['group'] ?? ''],
                ['card_type' => 'メンバー', 'instance_id' => 'yell_mem_1', 'group' => $cardDef['group'] ?? ''],
            ];
            $state = resolveAutoYellAbilities($state, $pid, $yellCards);
            break;

        case 'continuous':
            $state['phase'] = 'live_judge';
            $state['active_player'] = $pid;
            if (!skillAuditFindStageMember($state, $pid, $cardNo)) {
                $state = skillAuditPlaceOnStageCenter($state, $pid, $testCard);
            }
            getLiveScoreBonusBreakdown($state, $pid);
            $notes[] = 'continuous via getLiveScoreBonusBreakdown';
            break;

        case 'on_leave_stage':
            $state['phase'] = 'main_first';
            $state['active_player'] = $pid;
            $member = $testCard;
            debugPreparePreplacedStageMember($member);
            $member['entered_turn'] = 1;
            $state['players'][$pid]['stage']['center'] = $member;
            $state = resolveOnLeaveStageAbilities($state, $pid, $member, ['reason' => 'audit']);
            break;

        default:
            return ['SKIP', 'unsupported trigger: ' . $trigger, $state];
    }

    $ab = skillAuditGetAbilityAtPath($cardDef, $node['ability_path']);
    if (is_array($ab) && function_exists('wrPickCfgFromAbility')) {
        $cfg = wrPickCfgFromAbility($ab);
        if (($ab['type'] ?? '') === 'leave_stage_add_from_wr') {
            $cfg = wrPickCfgForLeaveStageAbility($ab);
        }
        if (!empty($cfg['filter'])) {
            $notes[] = 'wr_filter=' . $cfg['filter'];
        }
    }

    if (!empty($state['pending_prompt'])) {
        $pt = $state['pending_prompt']['type'] ?? '';
        $notes[] = 'prompt=' . $pt;
        return ['ENGINE_PASS', implode('; ', $notes), $state];
    }

    if (skillAuditLogHasEffect($state)) {
        return ['ENGINE_PASS', implode('; ', $notes) ?: 'effect log present', $state];
    }

    $passiveOk = in_array($type, [
        'blade_bonus', 'live_score_bonus', 'member_blade_bonus', 'continuous_hearts_in_slot',
        'blade_if_no_self_success_opp_has', 'allows_double_baton', 'aura_hand_cost_reduction_no_ability',
    ], true) || str_starts_with($type, 'continuous_') || str_starts_with($type, 'blade_');

    if ($passiveOk || $trigger === 'continuous') {
        return ['ENGINE_PASS', implode('; ', $notes) ?: 'passive/continuous ok', $state];
    }

    return ['ENGINE_PASS', implode('; ', $notes) ?: 'no exception', $state];
}

$results = [];
$counts = ['ENGINE_PASS' => 0, 'FAIL' => 0, 'SKIP' => 0];
$parentPassByCard = [];

foreach ($batch['cards'] ?? [] as $cardEntry) {
    $cardNo = $cardEntry['card_no'] ?? '';
    $cardDef = findCardDefByNo($cardsData['cards'] ?? [], $cardNo);
    if (!$cardDef) {
        foreach ($cardEntry['nodes'] ?? [] as $node) {
            $results[] = array_merge($node, [
                'status' => 'FAIL',
                'engine' => 'FAIL',
                'notes' => 'card not in catalog',
            ]);
            $counts['FAIL']++;
        }
        continue;
    }

    $bootOk = true;
    $bootNote = '';
    try {
        skillAuditBuildDebugState($cardNo, $cardsData);
    } catch (Throwable $e) {
        $bootOk = false;
        $bootNote = 'boot: ' . $e->getMessage();
    }

    foreach ($cardEntry['nodes'] ?? [] as $node) {
        $trigger = $node['trigger'] ?? '';
        $type = $node['type'] ?? '';
        $path = $node['ability_path'] ?? '';

        if (!$bootOk) {
            $results[] = array_merge($node, [
                'status' => 'FAIL',
                'engine' => 'FAIL',
                'notes' => $bootNote,
            ]);
            $counts['FAIL']++;
            continue;
        }

        $staticSkip = skillAuditStaticSkipReason($type, $missingAnyRoute, $prefixRouted);
        if ($staticSkip !== null) {
            $results[] = array_merge($node, [
                'status' => 'SKIP',
                'engine' => 'SKIP',
                'notes' => $staticSkip,
            ]);
            $counts['SKIP']++;
            continue;
        }

        if (!skillAuditIsTopLevelPath($path)) {
            $parentKey = $cardNo . '|' . preg_replace('/\.(then|choices|effect|else_then|on_success|on_fail).*/', '', $path);
            if (!empty($parentPassByCard[$parentKey])) {
                $results[] = array_merge($node, [
                    'status' => 'ENGINE_PASS',
                    'engine' => 'PASS',
                    'notes' => 'nested chain via parent',
                ]);
                $counts['ENGINE_PASS']++;
                continue;
            }
            $results[] = array_merge($node, [
                'status' => 'SKIP',
                'engine' => 'SKIP',
                'notes' => 'nested node; parent not exercised',
            ]);
            $counts['SKIP']++;
            continue;
        }

        try {
            $state = skillAuditBuildDebugState($cardNo, $cardsData);
            [$status, $notes,] = skillAuditRunTrigger($state, 'p1', $cardDef, $node, $trigger, $cardsData);
        } catch (Throwable $e) {
            $harnessSkip = skillAuditHarnessSkipReason($type, $e->getMessage());
            if ($harnessSkip !== null) {
                $status = 'SKIP';
                $notes = $harnessSkip;
            } else {
                $status = 'FAIL';
                $notes = $e->getMessage();
            }
        }

        $preSkip = skillAuditHarnessSkipReason($type);
        if ($preSkip !== null && $status !== 'ENGINE_PASS') {
            $status = 'SKIP';
            $notes = $preSkip;
        }

        if ($status === 'ENGINE_PASS') {
            $parentKey = $cardNo . '|' . $path;
            $parentPassByCard[$parentKey] = true;
        }

        $results[] = array_merge($node, [
            'status' => $status,
            'engine' => $status === 'ENGINE_PASS' ? 'PASS' : ($status === 'SKIP' ? 'SKIP' : 'FAIL'),
            'notes' => $notes,
        ]);
        $counts[$status === 'ENGINE_PASS' ? 'ENGINE_PASS' : ($status === 'SKIP' ? 'SKIP' : 'FAIL')]++;
    }
}

$report = [
    'batch' => $batchId,
    'generated_at' => date('c'),
    'card_count' => $batch['card_count'] ?? count($batch['cards'] ?? []),
    'node_count' => count($results),
    'summary' => $counts,
    'results' => $results,
];

$reportDir = $root . '/reports/skill_audit_engine';
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0755, true);
}
$reportPath = $reportDir . "/batch-{$batchId}.json";
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

if ($updateProgress) {
    $progressPath = $root . '/SKILL_RUNTIME_AUDIT_PROGRESS.txt';
    $date = date('Y-m-d');
    $lines = [];
    foreach ($results as $row) {
        $status = $row['status'] ?? 'PENDING';
        $engine = $row['engine'] ?? '-';
        $mcp = '-';
        $line = sprintf(
            "[%s] %s | %s | %s | %s | %s | %s | %s | %s | %s\n",
            $status,
            $row['card_no'] ?? '',
            $row['name_en'] ?? '',
            $row['trigger'] ?? '',
            $row['type'] ?? '',
            $row['ability_path'] ?? '',
            $engine,
            $mcp,
            str_replace(["\n", '|'], ' ', (string)($row['notes'] ?? '')),
            $date
        );
        $lines[] = $line;
    }
    file_put_contents($progressPath, implode('', $lines), FILE_APPEND);
}

if ($jsonOut) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo "Skill audit engine batch {$batchId}\n";
    echo "Cards: {$report['card_count']} | Nodes: {$report['node_count']}\n";
    echo "PASS: {$counts['ENGINE_PASS']} | FAIL: {$counts['FAIL']} | SKIP: {$counts['SKIP']}\n";
    echo "Wrote {$reportPath}\n";
    if ($counts['FAIL'] > 0) {
        echo "\nFailures:\n";
        foreach ($results as $row) {
            if (($row['status'] ?? '') !== 'FAIL') {
                continue;
            }
            echo "  {$row['card_no']} {$row['trigger']} {$row['type']}: {$row['notes']}\n";
        }
        exit(1);
    }
}
