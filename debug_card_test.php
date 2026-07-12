<?php
/**
 * Guest-only debug harness: start a CPU match with one card placed for effect testing.
 * Client must send debug_mode=true (only exposed when ?debug is on).
 */
require_once __DIR__ . '/experiment_decks.php';
require_once __DIR__ . '/deckgen.php';

function assertDebugCardTestAllowed(array $body): void {
    if (empty($body['debug_mode'])) {
        throw new Exception('Debug card test requires debug_mode');
    }
}

function findCardDefByNo(array $cards, string $cardNo): ?array {
    foreach ($cards as $c) {
        if (($c['card_no'] ?? '') === $cardNo) {
            return $c;
        }
    }
    return null;
}

function makeDebugCardInstance(array $cardDef): array {
    $card = $cardDef;
    $card['instance_id'] = uniqid('card_', true);
    $card['active'] = true;
    $card['debug_test_card'] = true;
    return $card;
}

/** Remove up to $max copies of card_no from a deck list (card instances). */
function stripCardNoFromInstances(array $deck, string $cardNo, int $max = 99): array {
    $removed = 0;
    $out = [];
    foreach ($deck as $c) {
        if ($removed < $max && ($c['card_no'] ?? '') === $cardNo) {
            $removed++;
            continue;
        }
        $out[] = $c;
    }
    return $out;
}

function stripCardNoFromNos(array $nos, string $cardNo, int $max = 99): array {
    $removed = 0;
    $out = [];
    foreach ($nos as $no) {
        if ($removed < $max && $no === $cardNo) {
            $removed++;
            continue;
        }
        $out[] = $no;
    }
    return $out;
}

/** Remove all deck/hand/WR copies of card_no; optionally keep one instance by instance_id. */
function debugPurgeStrayTestCopies(array &$player, string $cardNo, ?string $preserveInstanceId = null): void {
    if ($cardNo === '') {
        return;
    }
    foreach (['hand', 'waiting_room', 'main_deck'] as $zone) {
        if (!isset($player[$zone]) || !is_array($player[$zone])) {
            continue;
        }
        $player[$zone] = array_values(array_filter(
            $player[$zone],
            function ($c) use ($cardNo, $preserveInstanceId) {
                if (!is_array($c) || ($c['card_no'] ?? '') !== $cardNo) {
                    return true;
                }
                return $preserveInstanceId !== null
                    && ($c['instance_id'] ?? '') === $preserveInstanceId;
            }
        ));
    }
}

function debugFillEnergyZone(array &$player, int $count = 12, ?int $activeCount = null): void {
    $deck = $player['energy_deck'] ?? [];
    $player['energy_zone'] = [];
    if ($count < 1 || empty($deck)) {
        return;
    }
    [$drawn, $deck] = drawCards($deck, min($count, count($deck)));
    $player['energy_deck'] = $deck;
    $active = $activeCount === null ? count($drawn) : max(0, min($activeCount, count($drawn)));
    foreach ($drawn as $i => $e) {
        $e['active'] = ($i < $active);
        $player['energy_zone'][] = $e;
    }
}

const DEBUG_SETUP_GROUPS = ["μ's", 'Hasunosora', 'Nijigasaki', 'Sunshine', 'Superstar'];

function parseDebugOptionalInt($raw, ?int $default = null): ?int {
    if ($raw === null || $raw === '') {
        return $default;
    }
    if (!is_numeric($raw)) {
        return $default;
    }
    return max(0, intval($raw));
}

/** Parse one stage slot spec: auto (null), empty, group:X, card:ID, any_member. */
function parseDebugStageSlotSpec($raw): ?array {
    if ($raw === null) {
        return null;
    }
    $raw = trim((string)$raw);
    if ($raw === '' || $raw === 'auto') {
        return null;
    }
    if ($raw === 'empty') {
        return ['mode' => 'empty'];
    }
    if ($raw === 'any_member' || $raw === 'member') {
        return ['mode' => 'any_member'];
    }
    if (str_starts_with($raw, 'group:')) {
        return ['mode' => 'group', 'group' => substr($raw, 6)];
    }
    if (str_starts_with($raw, 'subunit:')) {
        return ['mode' => 'subunit', 'subunit' => substr($raw, 8)];
    }
    if (str_starts_with($raw, 'card:')) {
        return ['mode' => 'card', 'card_no' => trim(substr($raw, 5))];
    }
    if (preg_match('/^PL![A-Za-z0-9!+\-_.]+$/', $raw)) {
        return ['mode' => 'card', 'card_no' => $raw];
    }
    return null;
}

function parseDebugPlayerSetup($raw): array {
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    $energyTotal = parseDebugOptionalInt($raw['energy_total'] ?? null, null);
    if ($energyTotal !== null) {
        $out['energy_total'] = $energyTotal;
    }
    if (array_key_exists('energy_active', $raw) && $raw['energy_active'] !== '' && $raw['energy_active'] !== null) {
        $out['energy_active'] = parseDebugOptionalInt($raw['energy_active'], null);
    }
    $handSize = parseDebugOptionalInt($raw['hand_size'] ?? null, null);
    if ($handSize !== null) {
        $out['hand_size'] = $handSize;
    }
    $handCards = [];
    foreach ($raw['hand_cards'] ?? [] as $no) {
        $no = trim((string)$no);
        if ($no !== '') {
            $handCards[] = $no;
        }
    }
    if (!empty($handCards)) {
        $out['hand_cards'] = $handCards;
    }
    foreach (['left', 'center', 'right'] as $slot) {
        $key = 'stage_' . $slot;
        if (!array_key_exists($key, $raw)) {
            continue;
        }
        $spec = parseDebugStageSlotSpec($raw[$key]);
        if ($spec !== null) {
            $out['stage'][$slot] = $spec;
        }
    }
    return $out;
}

function parseDebugBoardSetup(array $body): array {
    $raw = $body['setup'] ?? $body['player_setup'] ?? null;
    if (!is_array($raw)) {
        return ['p1' => [], 'p2' => []];
    }
    return [
        'p1' => parseDebugPlayerSetup($raw['p1'] ?? []),
        'p2' => parseDebugPlayerSetup($raw['p2'] ?? []),
    ];
}

function debugTakeCardByNo(array &$player, string $cardNo, ?array $cardsData): ?array {
    foreach (['hand', 'main_deck', 'waiting_room', 'energy_zone', 'energy_deck'] as $zone) {
        if (!isset($player[$zone]) || !is_array($player[$zone])) {
            continue;
        }
        if ($zone === 'stage') {
            continue;
        }
        foreach ($player[$zone] as $i => $c) {
            if (!is_array($c) || ($c['card_no'] ?? '') !== $cardNo) {
                continue;
            }
            $card = $player[$zone][$i];
            array_splice($player[$zone], $i, 1);
            return $card;
        }
    }
    if ($cardsData === null) {
        return null;
    }
    $def = findCardDefByNo($cardsData['cards'] ?? [], $cardNo);
    return $def ? makeDebugCardInstance($def) : null;
}

/** Preplaced stage Members are already on Stage — not "played this turn" for Baton/overplay rules. */
function debugPreparePreplacedStageMember(array &$card): void {
    $card['active'] = true;
    unset($card['entered_turn'], $card['entered_this_turn'], $card['entered_via_baton']);
}

function debugClearStageSlot(array &$player, string $slot): void {
    $existing = $player['stage'][$slot] ?? null;
    if ($existing) {
        $player['waiting_room'][] = $existing;
    }
    $player['stage'][$slot] = null;
}

function debugResolveStageSlotCard(
    array &$player,
    array $spec,
    ?array $cardsData,
    array $excludeNos
): ?array {
    if (($spec['mode'] ?? '') === 'empty') {
        return null;
    }
    if (($spec['mode'] ?? '') === 'card') {
        $no = trim((string)($spec['card_no'] ?? ''));
        if ($no === '' || in_array($no, $excludeNos, true)) {
            return null;
        }
        return debugTakeCardByNo($player, $no, $cardsData);
    }
    $filter = ['group' => '', 'subunit' => ''];
    if (($spec['mode'] ?? '') === 'group') {
        $filter['group'] = (string)($spec['group'] ?? '');
    } elseif (($spec['mode'] ?? '') === 'subunit') {
        $filter['subunit'] = (string)($spec['subunit'] ?? '');
    }
    return debugTakeMemberForSetupFilter($player, $filter, $excludeNos, $cardsData, 'cheapest');
}

function debugApplyPlayerBoardSetup(
    array &$player,
    array $setup,
    int $turn,
    ?array $cardsData,
    array $excludeNos
): array {
    $summary = [];
    if (empty($setup)) {
        return $summary;
    }

    if (isset($setup['energy_total']) || array_key_exists('energy_active', $setup)) {
        $energyTotal = intval($setup['energy_total'] ?? DEBUG_DEFAULT_ENERGY);
        $energyActive = array_key_exists('energy_active', $setup) ? $setup['energy_active'] : null;
        debugFillEnergyZone($player, max(0, $energyTotal), $energyActive);
        $activeLabel = $energyActive === null ? 'all active' : ($energyActive . ' active');
        $summary[] = 'Energy: ' . count($player['energy_zone'] ?? [])
            . ' in zone (' . $activeLabel . ')';
    }

    if (isset($setup['hand_size'])) {
        $handSize = max(0, intval($setup['hand_size']));
        $player['hand'] = [];
        [$drawn, $deck] = drawCards($player['main_deck'] ?? [], $handSize);
        $player['hand'] = $drawn;
        $player['main_deck'] = $deck;
        $summary[] = 'Hand size: ' . count($player['hand']);
    }
    foreach ($setup['hand_cards'] ?? [] as $cardNo) {
        $card = debugTakeCardByNo($player, $cardNo, $cardsData);
        if ($card) {
            array_unshift($player['hand'], $card);
            $summary[] = 'Hand: added ' . $cardNo;
        } else {
            $summary[] = 'Hand: could not find ' . $cardNo;
        }
    }

    foreach ($setup['stage'] ?? [] as $slot => $spec) {
        if (!in_array($slot, ['left', 'center', 'right'], true)) {
            continue;
        }
        $existing = $player['stage'][$slot] ?? null;
        if (is_array($existing) && !empty($existing['debug_test_card'])) {
            continue;
        }
        debugClearStageSlot($player, $slot);
        if (($spec['mode'] ?? '') === 'empty') {
            $summary[] = 'Stage ' . $slot . ': cleared';
            continue;
        }
        $card = debugResolveStageSlotCard($player, $spec, $cardsData, $excludeNos);
        if (!$card) {
            $summary[] = 'Stage ' . $slot . ': could not place (' . ($spec['mode'] ?? '') . ')';
            continue;
        }
        if (!isMemberCard($card)) {
            $player['waiting_room'][] = $card;
            $summary[] = 'Stage ' . $slot . ': not a Member — sent to WR';
            continue;
        }
        debugPreparePreplacedStageMember($card);
        $player['stage'][$slot] = $card;
        $summary[] = 'Stage ' . $slot . ': ' . debugCardSetupLabel($card);
    }

    return $summary;
}

/** Deal opening hand from main deck (default 6, like preparation). */
function debugDealOpeningHand(array &$player, int $count = 6): void {
    [$drawn, $deck] = drawCards($player['main_deck'] ?? [], $count);
    $player['hand'] = $drawn;
    $player['main_deck'] = $deck;
}

/** Pull cards from main deck into Waiting Room (generic padding). */
function debugSeedWaitingRoom(array &$player, int $count = 4): void {
    if ($count < 1) {
        return;
    }
    [$wr, $deck] = drawCards($player['main_deck'] ?? [], min($count, count($player['main_deck'] ?? [])));
    $player['waiting_room'] = array_merge($player['waiting_room'] ?? [], $wr);
    $player['main_deck'] = $deck;
}

function debugWrHintKey(array $hint): string {
    return implode('|', [
        $hint['group'] ?? '',
        $hint['filter'] ?? '',
        $hint['subunit'] ?? '',
        intval($hint['count'] ?? 1),
    ]);
}

function debugMergeWrHint(array &$hints, array $hint): void {
    $key = debugWrHintKey($hint);
    if (!isset($hints[$key])) {
        $hints[$key] = $hint;
        return;
    }
    $hints[$key]['count'] = max(
        intval($hints[$key]['count'] ?? 1),
        intval($hint['count'] ?? 1)
    );
}

/** WR targets implied by activated / on-enter effect types (mirrors activatedAbilityWrBlockReason). */
function debugCollectWrHintFromNode(array $ab, array &$hints): void {
    $type = $ab['type'] ?? '';
    if ($type === '' || $type === 'continuous' || $type === 'treat_as_subunits') {
        return;
    }
    $hint = null;
    switch ($type) {
        case 'shuffle_named_from_waiting':
            $hint = ['group' => '', 'filter' => 'member', 'count' => 1];
            break;
        case 'discard_hand_add_live_from_wr':
            $hint = [
                'group'  => $ab['group'] ?? '',
                'filter' => $ab['filter'] ?? 'live',
                'count'  => max(1, intval($ab['count'] ?? 1)),
            ];
            break;
        case 'discard_cost_add_live_subunit':
        case 'wait_self_discard_add_wr_live':
        case 'activated_pay_discard_add_wr_live':
            $hint = [
                'group'  => $ab['group'] ?? ($type === 'activated_pay_discard_add_wr_live' ? 'Nijigasaki' : ''),
                'filter' => $ab['filter'] ?? 'live',
                'count'  => max(1, intval($ab['count'] ?? 1)),
            ];
            break;
        case 'wait_self_add_wr':
        case 'leave_stage_add_from_wr':
            $hint = [
                'group'  => $ab['group'] ?? '',
                'filter' => $ab['filter'] ?? ($type === 'leave_stage_add_from_wr' ? 'live' : ''),
                'count'  => max(1, intval($ab['count'] ?? 1)),
            ];
            break;
        case 'pay_energy_add_from_wr':
            $hint = [
                'group'    => $ab['group'] ?? '',
                'filter'   => $ab['filter'] ?? 'member',
                'count'    => max(1, intval($ab['count'] ?? 1)),
                'max_cost' => isset($ab['max_cost']) ? intval($ab['max_cost']) : null,
            ];
            break;
        case 'pay_energy_add_live_zone_from_wr':
            $hint = [
                'group'  => $ab['group'] ?? '',
                'filter' => $ab['filter'] ?? 'live',
                'count'  => 1,
            ];
            break;
        case 'pay_leave_stage_play_wr_member':
        case 'pay_energy_play_wr_empty':
            $hint = [
                'group'    => $ab['group'] ?? '',
                'filter'   => 'member',
                'subunit'  => $ab['subunit'] ?? '',
                'count'    => 1,
                'max_cost' => isset($ab['max_cost']) ? intval($ab['max_cost']) : ($type === 'pay_energy_play_wr_empty' ? 2 : null),
            ];
            break;
        case 'add_from_wr':
        case 'add_from_waiting_room':
            if (($ab['filter'] ?? '') !== '' || ($ab['group'] ?? '') !== '') {
                $hint = [
                    'group'  => $ab['group'] ?? '',
                    'filter' => $ab['filter'] ?? 'member',
                    'count'  => max(1, intval($ab['count'] ?? 1)),
                ];
            }
            break;
        default:
            break;
    }
    if ($hint !== null) {
        debugMergeWrHint($hints, $hint);
    }
}

function debugExtractWrHints(array $card): array {
    $hints = [];
    foreach ($card['abilities'] ?? [] as $ab) {
        if (!is_array($ab)) {
            continue;
        }
        debugWalkAbilityNodes($ab, function (array $node) use (&$hints) {
            debugCollectWrHintFromNode($node, $hints);
        });
    }
    return array_values($hints);
}

function debugFormatWrHint(array $hint): string {
    $parts = [max(1, intval($hint['count'] ?? 1)) . ' card(s)'];
    $filter = $hint['filter'] ?? '';
    if ($filter === 'live') {
        $parts[] = 'Live';
    } elseif ($filter === 'member') {
        $parts[] = 'Member';
    }
    if (!empty($hint['group'])) {
        $parts[] = $hint['group'];
    }
    if (!empty($hint['subunit'])) {
        $parts[] = $hint['subunit'];
    }
    if (isset($hint['max_cost'])) {
        $parts[] = 'cost ≤ ' . intval($hint['max_cost']);
    }
    return implode(' ', $parts) . ' in Waiting Room';
}

function debugCardMatchesWrHint(array $card, array $hint): bool {
    $cfg = [
        'group'   => $hint['group'] ?? '',
        'filter'  => $hint['filter'] ?? '',
        'subunit' => $hint['subunit'] ?? '',
    ];
    if (isset($hint['max_cost'])) {
        $cfg['max_cost'] = intval($hint['max_cost']);
    }
    if (($cfg['filter'] ?? '') === '' && ($cfg['group'] ?? '') === '') {
        return true;
    }
    return cardMatchesWrPick($card, $cfg);
}

function debugTakeCardForWrHint(
    array &$player,
    array $hint,
    array $excludeNos,
    ?array $cardsData = null
): ?array {
    foreach (['main_deck'] as $zone) {
        foreach ($player[$zone] ?? [] as $i => $c) {
            if (!debugCardMatchesWrHint($c, $hint)) {
                continue;
            }
            if (in_array($c['card_no'] ?? '', $excludeNos, true)) {
                continue;
            }
            $card = $player[$zone][$i];
            array_splice($player[$zone], $i, 1);
            return $card;
        }
    }
    if ($cardsData === null) {
        return null;
    }
    $pool = [];
    foreach ($cardsData['cards'] ?? [] as $c) {
        if (!debugCardMatchesWrHint($c, $hint)) {
            continue;
        }
        if (in_array($c['card_no'] ?? '', $excludeNos, true)) {
            continue;
        }
        $pool[] = $c;
    }
    if (empty($pool)) {
        return null;
    }
    return makeDebugFillerMember($pool[array_rand($pool)]);
}

/** Seed WR with cards that satisfy the test card's skill prerequisites, then optional padding. */
function debugSeedWaitingRoomForCard(
    array &$player,
    array $testCard,
    ?array $cardsData,
    int $padding = 2
): array {
    $summary = [];
    $hints = debugExtractWrHints($testCard);
    $player['waiting_room'] = [];
    $testNo = $testCard['card_no'] ?? '';
    $usedNos = $testNo !== '' ? [$testNo] : [];
    foreach ($hints as $hint) {
        $need = max(1, intval($hint['count'] ?? 1));
        $placed = 0;
        for ($i = 0; $i < $need; $i++) {
            $card = debugTakeCardForWrHint($player, $hint, $usedNos, $cardsData);
            if (!$card) {
                break;
            }
            $usedNos[] = $card['card_no'] ?? '';
            $player['waiting_room'][] = $card;
            $placed++;
        }
        if ($placed > 0) {
            $summary[] = 'WR +' . $placed . ' — ' . debugFormatWrHint($hint);
        } elseif (!empty($hints)) {
            $summary[] = 'WR could not find — ' . debugFormatWrHint($hint);
        }
    }
    if ($padding > 0) {
        debugSeedWaitingRoom($player, $padding);
    }
    return $summary;
}

function debugLiveZoneHintKey(array $hint): string {
    return implode('|', [
        intval($hint['min_count'] ?? 0),
        $hint['group'] ?? '',
        $hint['filter'] ?? 'live',
    ]);
}

function debugCollectLiveZoneHintFromNode(array $ab, array &$hints): void {
    if (($ab['trigger'] ?? '') !== 'continuous') {
        return;
    }
    $type = $ab['type'] ?? '';
    $hint = null;
    if ($type === 'blade_if_live_zone_group_live') {
        $hint = [
            'min_count' => intval($ab['min_count'] ?? 3),
            'group'     => $ab['group'] ?? 'Nijigasaki',
            'filter'    => 'live',
            'need_group_live' => true,
        ];
    } elseif ($type === 'blade_if_live_zone_min') {
        $hint = [
            'min_count' => intval($ab['min_live_cards'] ?? 2),
            'group'     => '',
            'filter'    => 'live',
        ];
    } elseif ($type === 'continuous_mus_blade_if_live_zone') {
        $hint = [
            'min_count' => intval($ab['min_count'] ?? 3),
            'group'     => $ab['group'] ?? 'μ\'s',
            'filter'    => 'live',
            'need_group_live' => true,
        ];
    }
    if ($hint === null) {
        return;
    }
    $key = debugLiveZoneHintKey($hint);
    if (!isset($hints[$key])) {
        $hints[$key] = $hint;
        return;
    }
    $hints[$key]['min_count'] = max(
        intval($hints[$key]['min_count'] ?? 0),
        intval($hint['min_count'] ?? 0)
    );
}

function debugExtractLiveZoneHints(array $card): array {
    $hints = [];
    foreach ($card['abilities'] ?? [] as $ab) {
        if (!is_array($ab)) {
            continue;
        }
        if (($ab['trigger'] ?? '') === 'continuous') {
            debugCollectLiveZoneHintFromNode($ab, $hints);
        }
        debugWalkAbilityNodes($ab, function (array $node) use (&$hints) {
            debugCollectLiveZoneHintFromNode($node, $hints);
        });
    }
    return array_values($hints);
}

function debugFormatLiveZoneHint(array $hint): string {
    $n = intval($hint['min_count'] ?? 0);
    $group = $hint['group'] ?? '';
    if ($group !== '') {
        return $n . '+ Live storage card(s) including 1+ ' . $group . ' Live';
    }
    return $n . '+ Live storage card(s)';
}

function debugTakeLiveForZoneHint(
    array &$player,
    array $hint,
    bool $requireGroup,
    array $excludeNos,
    ?array $cardsData
): ?array {
    $cfg = [
        'group'  => $requireGroup ? ($hint['group'] ?? '') : '',
        'filter' => $hint['filter'] ?? 'live',
    ];
    foreach ($player['main_deck'] ?? [] as $i => $c) {
        if (!isLiveTypeCard($c)) {
            continue;
        }
        if ($cfg['group'] !== '' && ($c['group'] ?? '') !== $cfg['group']) {
            continue;
        }
        if (in_array($c['card_no'] ?? '', $excludeNos, true)) {
            continue;
        }
        $card = $player['main_deck'][$i];
        array_splice($player['main_deck'], $i, 1);
        return $card;
    }
    if ($cardsData === null) {
        return null;
    }
    $pool = [];
    foreach ($cardsData['cards'] ?? [] as $c) {
        if (!isLiveTypeCard($c)) {
            continue;
        }
        if ($cfg['group'] !== '' && ($c['group'] ?? '') !== $cfg['group']) {
            continue;
        }
        if (in_array($c['card_no'] ?? '', $excludeNos, true)) {
            continue;
        }
        $pool[] = $c;
    }
    if (empty($pool)) {
        return null;
    }
    return makeDebugCardInstance($pool[array_rand($pool)]);
}

function debugSeedLiveZoneForCard(
    array &$player,
    array $testCard,
    ?array $cardsData
): array {
    $summary = [];
    $hints = debugExtractLiveZoneHints($testCard);
    if (empty($hints)) {
        return $summary;
    }
    $need = 0;
    $group = '';
    $needGroupLive = false;
    foreach ($hints as $hint) {
        $need = max($need, intval($hint['min_count'] ?? 0));
        if (!empty($hint['group'])) {
            $group = (string)$hint['group'];
        }
        if (!empty($hint['need_group_live'])) {
            $needGroupLive = true;
        }
    }
    if ($need < 1) {
        return $summary;
    }
    $zone = [];
    $usedNos = [$testCard['card_no'] ?? ''];
    $hint = ['group' => $group, 'filter' => 'live', 'min_count' => $need];
    if ($needGroupLive && $group !== '') {
        $first = debugTakeLiveForZoneHint($player, $hint, true, $usedNos, $cardsData);
        if ($first) {
            $first['revealed'] = true;
            $first['live_slot'] = 0;
            $zone[] = $first;
            $usedNos[] = $first['card_no'] ?? '';
        }
    }
    while (count($zone) < $need) {
        $card = debugTakeLiveForZoneHint($player, $hint, false, $usedNos, $cardsData);
        if (!$card) {
            break;
        }
        $card['revealed'] = true;
        $card['live_slot'] = count($zone);
        $zone[] = $card;
        $usedNos[] = $card['card_no'] ?? '';
    }
    if (!empty($zone)) {
        $player['live_zone'] = $zone;
        foreach ($hints as $h) {
            $summary[] = debugFormatLiveZoneHint($h);
        }
    }
    return $summary;
}

/** Place one filler Member on stage (no energy cost) so opponent/target skills have a board. */
function debugPlaceFillerStageMember(array &$player, string $slot = 'center', int $turn = 1): bool {
    foreach ($player['hand'] ?? [] as $i => $c) {
        if (!isMemberCard($c)) {
            continue;
        }
        $m = $player['hand'][$i];
        array_splice($player['hand'], $i, 1);
        debugPreparePreplacedStageMember($m);
        $player['stage'][$slot] = $m;
        return true;
    }
    return false;
}

function makeDebugFillerMember(array $cardDef): array {
    $card = $cardDef;
    $card['instance_id'] = uniqid('card_', true);
    $card['active'] = true;
    return $card;
}

function parseDebugStageSeedMode(string $raw): string {
    $mode = strtolower(trim($raw));
    if (!in_array($mode, ['auto', 'mine', 'opponent', 'both', 'off'], true)) {
        return 'auto';
    }
    return $mode;
}

function debugIsOpponentStageAbilityType(string $type): bool {
    static $types = [
        'wait_opponent_stage_max_cost',
        'opp_pick_stage_active',
        'put_opponent_active_into_wait',
    ];
    if (in_array($type, $types, true)) {
        return true;
    }
    return str_contains($type, 'opponent_stage') || str_contains($type, 'opponent_active');
}

function debugIsSelfStageAbilityType(string $type, array $ab): bool {
    if (debugIsOpponentStageAbilityType($type)) {
        return false;
    }
    static $types = [
        'draw_per_stage_discard',
        'draw_per_stage_group_discard',
        'score_per_distinct_group_stage',
        'live_score_if_full_stage_distinct_group',
        'grant_live_success_yell_live_score_if_full_stage',
        'score_if_stage_group_cost_min',
        'live_score_if_stage_group_hearts_total',
        'live_start_all_stage_group_bonus',
        'blade_per_stage_excl_subunit',
        'blade_per_other_subunit',
        'activate_energy_if_other_group',
        'activate_energy_if_other_subunit',
        'draw_if_stage_cost_min',
        'draw_if_stage_cost_less_than_opp',
        'draw_if_stage_cost_less_surveil',
        'position_change_off_center',
        'wait_opp_if_distinct_subunit',
        'optional_wait_self_energy_subunit',
        'activate_subunit_members',
        'score_if_distinct_subunits_on_stage',
        'live_score_per_stage_wait_member',
        'reduce_hearts_if_named_cost_pair',
        'score_if_named_stage_slots',
        'waive_one_required_heart_color',
    ];
    if (in_array($type, $types, true)) {
        return true;
    }
    if (!empty($ab['requires_stage_group']) || !empty($ab['requires_full_stage'])) {
        return true;
    }
    if (!empty($ab['min_stage_members']) || !empty($ab['requires_other_stage_member'])) {
        return true;
    }
    if (!empty($ab['requires_subunit_cost_min'])) {
        return true;
    }
    if (!empty($ab['requires_other_stage_cost'])) {
        return true;
    }
    return false;
}

/** Recurse ability trees (`then`, `choices[].effect`, etc.). */
function debugWalkAbilityNodes(array $node, callable $fn): void {
    if (!empty($node['type'])) {
        $fn($node);
    }
    foreach (['then', 'else_then', 'on_fail', 'on_success'] as $key) {
        if (!isset($node[$key]) || !is_array($node[$key])) {
            continue;
        }
        $sub = $node[$key];
        if (isset($sub['type'])) {
            debugWalkAbilityNodes($sub, $fn);
        } elseif (array_is_list($sub)) {
            foreach ($sub as $item) {
                if (is_array($item)) {
                    debugWalkAbilityNodes($item, $fn);
                }
            }
        }
    }
    foreach ($node['choices'] ?? [] as $choice) {
        if (!is_array($choice)) {
            continue;
        }
        if (isset($choice['effect']) && is_array($choice['effect'])) {
            debugWalkAbilityNodes($choice['effect'], $fn);
        }
    }
}

/** Baton-to-WR leave effects (e.g. Kaho SD) need the test Member on Stage + a qualifying partner in hand. */
function debugMemberMatchesSetupFilter(array $card, array $filter, string $excludeNo = ''): bool {
    if (!isMemberCard($card)) {
        return false;
    }
    if ($excludeNo !== '' && ($card['card_no'] ?? '') === $excludeNo) {
        return false;
    }
    $group = $filter['group'] ?? '';
    if ($group !== '' && ($card['group'] ?? '') !== $group) {
        return false;
    }
    $subunit = $filter['subunit'] ?? '';
    if ($subunit !== '' && !cardMatchesSubunit($card, $subunit)) {
        return false;
    }
    $excludeSub = $filter['exclude_subunit'] ?? '';
    if ($excludeSub !== '' && cardMatchesSubunit($card, $excludeSub)) {
        return false;
    }
    $minCost = $filter['min_cost'] ?? ($filter['min_baton_cost'] ?? null);
    if ($minCost !== null && intval($card['cost'] ?? 0) < intval($minCost)) {
        return false;
    }
    if (isset($filter['max_cost']) && intval($card['cost'] ?? 0) > intval($filter['max_cost'])) {
        return false;
    }
    if (isset($filter['exact_cost']) && intval($card['cost'] ?? 0) !== intval($filter['exact_cost'])) {
        return false;
    }
    return true;
}

function debugCardSetupLabel(array $card): string {
    return ($card['name_en'] ?? $card['name'] ?? $card['card_no'] ?? 'Member')
        . ' (cost ' . ($card['cost'] ?? '?') . ')';
}

function debugFindCatalogMember(
    array $filter,
    ?array $cardsData,
    string $excludeNo,
    string $prefer = 'cheapest'
): ?array {
    if ($cardsData === null) {
        return null;
    }
    $pool = [];
    foreach ($cardsData['cards'] ?? [] as $c) {
        if (debugMemberMatchesSetupFilter($c, $filter, $excludeNo)) {
            $pool[] = $c;
        }
    }
    if (empty($pool)) {
        return null;
    }
    usort($pool, function ($a, $b) use ($prefer) {
        $ca = intval($a['cost'] ?? 0);
        $cb = intval($b['cost'] ?? 0);
        return $prefer === 'highest_below' ? ($cb - $ca) : ($ca - $cb);
    });
    return $pool[0];
}

function debugTakeMemberForSetupFilter(
    array &$player,
    array $filter,
    array $excludeNos,
    ?array $cardsData = null,
    string $prefer = 'cheapest'
): ?array {
    foreach (['hand', 'main_deck'] as $zone) {
        foreach ($player[$zone] ?? [] as $i => $c) {
            $no = $c['card_no'] ?? '';
            if (in_array($no, $excludeNos, true)) {
                continue;
            }
            if (!debugMemberMatchesSetupFilter($c, $filter, '')) {
                continue;
            }
            $card = $player[$zone][$i];
            array_splice($player[$zone], $i, 1);
            return $card;
        }
    }
    $def = debugFindCatalogMember($filter, $cardsData, $excludeNos[0] ?? '', $prefer);
    if (!$def) {
        return null;
    }
    return makeDebugFillerMember($def);
}

function debugInsertCardIntoHand(array &$player, array $card): void {
    array_unshift($player['hand'], $card);
    while (count($player['hand']) > DEBUG_OPENING_HAND) {
        array_unshift($player['main_deck'], array_pop($player['hand']));
    }
}

function debugFirstEmptyStageSlot(array $player, ?string $avoidSlot = null): ?string {
    foreach (['center', 'left', 'right'] as $slot) {
        if ($avoidSlot !== null && $slot === $avoidSlot) {
            continue;
        }
        if (empty($player['stage'][$slot])) {
            return $slot;
        }
    }
    return null;
}

function debugExtractBatonLeaveSetup(array $card): ?array {
    $best = null;
    foreach ($card['abilities'] ?? [] as $ab) {
        if (!is_array($ab)) {
            continue;
        }
        debugWalkAbilityNodes($ab, function (array $node) use (&$best) {
            if (($node['trigger'] ?? '') !== 'on_leave_stage') {
                return;
            }
            if (($node['type'] ?? '') !== 'activate_if_baton_to_wr') {
                return;
            }
            $minCost = intval($node['min_baton_cost'] ?? 10);
            $group = (string)($node['group'] ?? '');
            if ($best === null || $minCost > intval($best['min_baton_cost'] ?? 0)) {
                $best = [
                    'group' => $group,
                    'min_baton_cost' => $minCost,
                ];
            }
        });
    }
    return $best;
}

function debugExtractBatonEnterSetups(array $card): array {
    $setups = [];
    $testCost = intval($card['cost'] ?? 0);
    if ($testCost < 1) {
        return [];
    }
    foreach ($card['abilities'] ?? [] as $ab) {
        if (!is_array($ab)) {
            continue;
        }
        debugWalkAbilityNodes($ab, function (array $node) use (&$setups, $testCost) {
            $trigger = $node['trigger'] ?? '';
            if (!in_array($trigger, ['on_enter', 'on_enter_or_auto', 'on_enter_or_live_start'], true)) {
                return;
            }
            $type = $node['type'] ?? '';
            if ($type !== 'optional_pay_energy_if_baton' && $type !== 'if_baton_lower_cost') {
                return;
            }
            $filter = [
                'subunit' => (string)($node['baton_subunit'] ?? ''),
                'max_cost' => max(0, $testCost - 1),
            ];
            $setups[json_encode($filter)] = $filter;
        });
    }
    return array_values($setups);
}

function debugFormatBatonLeaveHint(array $setup): string {
    $parts = ['cost ≥ ' . intval($setup['min_baton_cost'] ?? $setup['min_cost'] ?? 10)];
    if (!empty($setup['group'])) {
        $parts[] = $setup['group'];
    }
    if (!empty($setup['subunit'])) {
        $parts[] = $setup['subunit'];
    }
    $parts[] = 'Member in hand to Baton Touch onto test Member on Stage';
    return implode(' ', $parts);
}

function debugFormatBatonEnterHint(array $filter): string {
    $parts = [];
    if (!empty($filter['subunit'])) {
        $parts[] = $filter['subunit'];
    }
    if (isset($filter['max_cost'])) {
        $parts[] = 'cost ≤ ' . intval($filter['max_cost']);
    } else {
        $parts[] = 'lower-cost';
    }
    $parts[] = 'Member on your Stage (Baton Touch test card from hand onto them)';
    return implode(' ', $parts);
}

function debugSeedHandForSetupFilter(
    array &$player,
    array $filter,
    ?array $cardsData,
    string $excludeNo,
    string $prefer = 'cheapest'
): ?string {
    foreach ($player['hand'] ?? [] as $c) {
        if (debugMemberMatchesSetupFilter($c, $filter, $excludeNo)) {
            return null;
        }
    }
    $card = debugTakeMemberForSetupFilter($player, $filter, [$excludeNo], $cardsData, $prefer);
    if (!$card) {
        return null;
    }
    debugInsertCardIntoHand($player, $card);
    return debugCardSetupLabel($card);
}

function debugSeedStageOccupantForBatonEnter(
    array &$player,
    array $filter,
    ?array $cardsData,
    string $excludeNo,
    int $turn,
    ?string $avoidSlot = null
): ?string {
    $slot = debugFirstEmptyStageSlot($player, $avoidSlot);
    if ($slot === null) {
        return null;
    }
    $card = debugTakeMemberForSetupFilter($player, $filter, [$excludeNo], $cardsData, 'highest_below');
    if (!$card) {
        return null;
    }
    debugPreparePreplacedStageMember($card);
    $player['stage'][$slot] = $card;
    return debugCardSetupLabel($card) . ' on ' . $slot;
}

function debugExtractAllSetupHints(array $card): array {
    $hints = array_map('debugFormatStageHint', debugExtractStageHints($card));
    $batonLeave = debugExtractBatonLeaveSetup($card);
    if ($batonLeave !== null) {
        $hints[] = debugFormatBatonLeaveHint($batonLeave);
    }
    foreach (debugExtractBatonEnterSetups($card) as $batonEnter) {
        $hints[] = debugFormatBatonEnterHint($batonEnter);
    }
    return $hints;
}

function debugStageHintKey(array $hint): string {
    return implode('|', [
        $hint['target'] ?? 'self',
        $hint['group'] ?? '',
        $hint['subunit'] ?? '',
        $hint['exclude_subunit'] ?? '',
        $hint['filter'] ?? 'member',
        !empty($hint['full_stage']) ? '1' : '0',
        isset($hint['max_cost']) ? (string)$hint['max_cost'] : '',
        isset($hint['min_cost']) ? (string)$hint['min_cost'] : '',
    ]);
}

function debugMergeStageHint(array &$hints, array $hint): void {
    $key = debugStageHintKey($hint);
    if (!isset($hints[$key])) {
        $hints[$key] = $hint;
        return;
    }
    $hints[$key]['min'] = max(intval($hints[$key]['min'] ?? 1), intval($hint['min'] ?? 1));
    $hints[$key]['max'] = max(intval($hints[$key]['max'] ?? 3), intval($hint['max'] ?? 3));
    if (!empty($hint['full_stage'])) {
        $hints[$key]['full_stage'] = true;
        $hints[$key]['min'] = 3;
        $hints[$key]['max'] = 3;
    }
}

function debugCollectStageHintFromNode(array $ab, array &$hints): void {
    $type = $ab['type'] ?? '';
    if ($type === '' || $type === 'continuous' || $type === 'treat_as_subunits') {
        return;
    }

    $stageRelated = debugIsSelfStageAbilityType($type, $ab) || debugIsOpponentStageAbilityType($type);
    if (!$stageRelated) {
        return;
    }

    $target = debugIsOpponentStageAbilityType($type) ? 'opponent' : 'self';
    if (($ab['target'] ?? '') === 'opponent') {
        $target = 'opponent';
    }

    $hint = [
        'target' => $target,
        'group' => '',
        'subunit' => '',
        'exclude_subunit' => '',
        'filter' => $ab['filter'] ?? 'member',
        'min' => 1,
        'max' => 3,
        'max_cost' => null,
        'min_cost' => null,
        'full_stage' => false,
    ];

    if (!empty($ab['requires_stage_group'])) {
        $hint['group'] = (string)$ab['requires_stage_group'];
    }
    if (!empty($ab['group'])) {
        $hint['group'] = (string)$ab['group'];
    }
    if (!empty($ab['subunit'])) {
        $hint['subunit'] = (string)$ab['subunit'];
    }
    if (!empty($ab['exclude_subunit'])) {
        $hint['exclude_subunit'] = (string)$ab['exclude_subunit'];
    }
    if (!empty($ab['requires_subunit_cost_min']) && is_array($ab['requires_subunit_cost_min'])) {
        $req = $ab['requires_subunit_cost_min'];
        if (!empty($req['subunit'])) {
            $hint['subunit'] = (string)$req['subunit'];
        }
        if (isset($req['min_cost'])) {
            $hint['min_cost'] = intval($req['min_cost']);
        }
    }
    if (!empty($ab['min_stage_members'])) {
        $n = intval($ab['min_stage_members']);
        $hint['min'] = max($hint['min'], $n);
        $hint['max'] = max($hint['max'], min(3, $n));
    }
    if (!empty($ab['min_distinct'])) {
        $n = intval($ab['min_distinct']);
        $hint['min'] = max($hint['min'], $n);
        $hint['max'] = max($hint['max'], min(3, $n));
    }
    if (!empty($ab['requires_full_stage'])
        || $type === 'live_score_if_full_stage_distinct_group'
        || $type === 'grant_live_success_yell_live_score_if_full_stage') {
        $hint['full_stage'] = true;
        $hint['min'] = 3;
        $hint['max'] = 3;
    }
    if ($type === 'wait_opponent_stage_max_cost') {
        $hint['max_cost'] = intval($ab['max_cost'] ?? 9);
        $pick = isset($ab['pick_count']) ? intval($ab['pick_count']) : 3;
        $hint['min'] = max(1, min($pick, 3));
        $hint['max'] = 3;
        $hint['filter'] = 'member';
    }
    if ($type === 'draw_per_stage_discard') {
        $hint['group'] = '';
        $hint['subunit'] = '';
        $hint['min'] = 1;
        $hint['max'] = 3;
    }
    if (!empty($ab['requires_other_stage_member'])) {
        $hint['min'] = max($hint['min'], 1);
        $hint['max'] = max($hint['max'], 2);
    }
    if (!empty($ab['requires_other_stage_cost'])) {
        $cost = intval($ab['requires_other_stage_cost']);
        $hint['min_cost'] = $cost;
        $hint['max_cost'] = $cost;
        $hint['min'] = max($hint['min'], 1);
        $hint['max'] = max($hint['max'], 1);
    }
    if ($type === 'draw_if_stage_cost_min') {
        $hint['min_cost'] = intval($ab['min_cost'] ?? 13);
        $hint['min'] = max($hint['min'], 1);
        $hint['max'] = max($hint['max'], 1);
    }
    if ($type === 'score_if_stage_group_cost_min') {
        if (!empty($ab['group'])) {
            $hint['group'] = (string)$ab['group'];
        }
        $hint['min_cost'] = intval($ab['min_cost'] ?? 0);
        $hint['min'] = max($hint['min'], 1);
        $hint['max'] = max($hint['max'], 1);
    }
    if ($type === 'activate_energy_if_other_subunit') {
        $hint['subunit'] = (string)($ab['subunit'] ?? '');
        $hint['min'] = max($hint['min'], 1);
        $hint['max'] = max($hint['max'], 1);
    }
    if ($type === 'position_change_off_center' && !empty($ab['group'])) {
        $hint['group'] = (string)$ab['group'];
        $hint['min'] = max($hint['min'], 1);
        $hint['max'] = max($hint['max'], 2);
    }

    if ($hint['group'] === '' && $hint['subunit'] === '' && !$hint['full_stage']
        && $hint['min_cost'] === null && $hint['max_cost'] === null
        && $type !== 'draw_per_stage_discard' && $type !== 'wait_opponent_stage_max_cost'
        && empty($ab['requires_other_stage_member'])) {
        return;
    }

    debugMergeStageHint($hints, $hint);
}

function debugExtractStageHints(array $card): array {
    $hints = [];
    foreach ($card['abilities'] ?? [] as $ab) {
        if (!is_array($ab)) {
            continue;
        }
        debugWalkAbilityNodes($ab, function (array $node) use (&$hints) {
            debugCollectStageHintFromNode($node, $hints);
        });
    }
    return array_values($hints);
}

function debugFormatStageHint(array $hint): string {
    $parts = [];
    if (!empty($hint['full_stage'])) {
        $parts[] = 'full stage (3)';
    } else {
        $min = intval($hint['min'] ?? 1);
        $max = intval($hint['max'] ?? 3);
        $parts[] = $min === $max ? ($min . ' Member(s)') : ($min . '-' . $max . ' Member(s)');
    }
    if (!empty($hint['group'])) {
        $parts[] = $hint['group'];
    }
    if (!empty($hint['subunit'])) {
        $parts[] = $hint['subunit'];
    }
    if (!empty($hint['exclude_subunit'])) {
        $parts[] = 'not ' . $hint['exclude_subunit'];
    }
    if (isset($hint['max_cost'])) {
        $parts[] = 'cost ≤ ' . intval($hint['max_cost']);
    }
    if (isset($hint['min_cost'])) {
        $parts[] = 'cost ≥ ' . intval($hint['min_cost']);
    }
    $side = ($hint['target'] ?? 'self') === 'opponent' ? 'opponent stage' : 'your stage';
    return implode(' ', $parts) . ' on ' . $side;
}

function debugPickRandomStageCount(array $hint, int $emptySlots): int {
    if ($emptySlots < 1) {
        return 0;
    }
    if (!empty($hint['full_stage'])) {
        return min(3, $emptySlots);
    }
    $min = max(1, intval($hint['min'] ?? 1));
    $max = max($min, min(3, intval($hint['max'] ?? 3), $emptySlots));
    if ($min >= $max) {
        return $min;
    }
    return random_int($min, $max);
}

function debugMemberMatchesStageHint(array $card, array $hint): bool {
    return debugMemberMatchesSetupFilter($card, [
        'group' => $hint['group'] ?? '',
        'subunit' => $hint['subunit'] ?? '',
        'exclude_subunit' => $hint['exclude_subunit'] ?? '',
        'min_cost' => $hint['min_cost'] ?? null,
        'max_cost' => $hint['max_cost'] ?? null,
    ]);
}

function debugTakeMemberForStageHint(array &$player, array $hint, array $excludeNos, ?array $cardsData = null): ?array {
    foreach (['hand', 'main_deck'] as $zone) {
        foreach ($player[$zone] ?? [] as $i => $c) {
            if (!debugMemberMatchesStageHint($c, $hint)) {
                continue;
            }
            if (in_array($c['card_no'] ?? '', $excludeNos, true)) {
                continue;
            }
            $card = $player[$zone][$i];
            array_splice($player[$zone], $i, 1);
            return $card;
        }
    }
    if ($cardsData === null) {
        return null;
    }
    $pool = [];
    foreach ($cardsData['cards'] ?? [] as $c) {
        if (!debugMemberMatchesStageHint($c, $hint)) {
            continue;
        }
        if (in_array($c['card_no'] ?? '', $excludeNos, true)) {
            continue;
        }
        $pool[] = $c;
    }
    if (empty($pool)) {
        return null;
    }
    return makeDebugFillerMember($pool[array_rand($pool)]);
}

function debugPlaceStageMembersForHint(
    array &$player,
    array $hint,
    int $turn,
    ?array $cardsData = null
): int {
    $slots = ['left', 'center', 'right'];
    $empty = array_values(array_filter($slots, fn($s) => empty($player['stage'][$s])));
    if (empty($empty)) {
        return 0;
    }
    $count = debugPickRandomStageCount($hint, count($empty));
    $placed = 0;
    $usedNos = [];
    for ($i = 0; $i < $count && !empty($empty); $i++) {
        $card = debugTakeMemberForStageHint($player, $hint, $usedNos, $cardsData);
        if (!$card) {
            break;
        }
        $usedNos[] = $card['card_no'] ?? '';
        $slot = array_shift($empty);
        debugPreparePreplacedStageMember($card);
        $player['stage'][$slot] = $card;
        $placed++;
    }
    return $placed;
}

function debugStageSeedTargetPids(string $mode, array $hint, string $testPid, string $otherPid): array {
    if ($mode === 'mine') {
        return [$testPid];
    }
    if ($mode === 'opponent') {
        return [$otherPid];
    }
    if ($mode === 'both') {
        return [$testPid, $otherPid];
    }
    if ($mode === 'auto') {
        return [($hint['target'] ?? 'self') === 'opponent' ? $otherPid : $testPid];
    }
    return [];
}

function debugApplyStageSeeding(
    array &$state,
    array $testCard,
    string $testPid,
    string $stageSeed,
    int $turn,
    ?array $cardsData = null
): array {
    $other = $testPid === 'p1' ? 'p2' : 'p1';
    $hints = debugExtractStageHints($testCard);
    $summary = [];

    if ($stageSeed === 'off' || empty($hints)) {
        debugPlaceFillerStageMember($state['players'][$other], 'center', $turn);
        if ($stageSeed !== 'off' && empty($hints)) {
            $summary[] = 'No stage requirements detected — placed 1 filler Member on opponent Center.';
        } elseif ($stageSeed === 'off') {
            $summary[] = 'Stage seeding off — placed 1 filler Member on opponent Center.';
        }
        return $summary;
    }

    foreach ($hints as $hint) {
        foreach (debugStageSeedTargetPids($stageSeed, $hint, $testPid, $other) as $pid) {
            $placed = debugPlaceStageMembersForHint($state['players'][$pid], $hint, $turn, $cardsData);
            if ($placed > 0) {
                $who = $state['players'][$pid]['name'] ?? $pid;
                $summary[] = $who . ': placed ' . $placed . ' — ' . debugFormatStageHint($hint);
            }
        }
    }

    $hasAnyStage = false;
    foreach (['p1', 'p2'] as $pid) {
        if (countStageMembers($state['players'][$pid]) > 0) {
            $hasAnyStage = true;
            break;
        }
    }
    if (!$hasAnyStage) {
        debugPlaceFillerStageMember($state['players'][$other], 'center', $turn);
        $summary[] = 'Could not match requirements from decks — placed 1 filler Member on opponent Center.';
    }

    return $summary;
}

const DEBUG_OPENING_HAND = 6;
const DEBUG_DEFAULT_ENERGY = 12;

function parseDebugTestZone(string $raw): array {
    $z = strtolower(trim($raw));
    $map = [
        'hand'  => ['type' => 'hand'],
        'live'  => ['type' => 'live'],
        'stage' => ['type' => 'stage', 'slot' => 'center'],
    ];
    if (!isset($map[$z])) {
        throw new Exception('Invalid zone — use hand, stage, or live');
    }
    return $map[$z];
}

/** True if any non-continuous ability uses one of the given triggers. */
function debugCardHasTrigger(array $card, array $triggers): bool {
    foreach ($card['abilities'] ?? [] as $ab) {
        if (!is_array($ab)) {
            continue;
        }
        $found = false;
        debugWalkAbilityNodes($ab, function (array $node) use ($triggers, &$found) {
            if ($found) {
                return;
            }
            $t = $node['trigger'] ?? '';
            if ($t === 'continuous') {
                return;
            }
            if (in_array($t, $triggers, true)) {
                $found = true;
            }
        });
        if ($found) {
            return true;
        }
    }
    return false;
}

/**
 * Live cards → Live storage. Members with [Activated] → Stage (Center).
 * Other Members → hand unless their only skills are Live-phase (live_start / live_success).
 */
function inferDebugTestStartZone(array $card): string {
    if (isLiveTypeCard($card)) {
        return 'live';
    }
    if (debugExtractBatonLeaveSetup($card) !== null) {
        return 'stage';
    }
    $mainTriggers = ['on_enter', 'on_enter_or_auto', 'on_enter_or_live_start', 'activated', 'auto', 'on_wait'];
    $liveTriggers = ['live_start', 'live_success'];
    $hasMain = debugCardHasTrigger($card, $mainTriggers);
    $hasLive = debugCardHasTrigger($card, $liveTriggers);
    if ($hasLive && !$hasMain) {
        return 'live';
    }
    if (isMemberCard($card) && debugCardHasTrigger($card, ['activated'])) {
        return 'stage';
    }
    return 'hand';
}

function resolveDebugTestZoneForCard(array $card, string $raw): array {
    $raw = strtolower(trim($raw));
    if ($raw === '' || $raw === 'auto') {
        $raw = inferDebugTestStartZone($card);
    }
    if (isLiveTypeCard($card)) {
        if ($raw !== 'live') {
            throw new Exception('Live cards must start in Live storage (cannot be played to stage or hand)');
        }
        $raw = 'live';
    } elseif (isMemberCard($card) && $raw === 'live' && inferDebugTestStartZone($card) !== 'live') {
        throw new Exception('This Member cannot start in Live storage — use hand or stage');
    } elseif (isMemberCard($card) && $raw === 'stage' && inferDebugTestStartZone($card) === 'live') {
        throw new Exception('This Member must start in Live storage for Live-phase skills');
    }
    return parseDebugTestZone($raw);
}

function placeDebugTestCard(array &$player, array $zoneSpec, array $card, int $turn): void {
    $cardNo = $card['card_no'] ?? '';
    $instanceId = $card['instance_id'] ?? null;
    switch ($zoneSpec['type']) {
        case 'hand':
            debugPurgeStrayTestCopies($player, $cardNo, $instanceId);
            $card['entered_turn'] = $turn;
            $hand = $player['hand'] ?? [];
            if (count($hand) >= DEBUG_OPENING_HAND) {
                $overflow = array_pop($hand);
                array_unshift($player['main_deck'], $overflow);
            }
            array_unshift($hand, $card);
            while (count($hand) > DEBUG_OPENING_HAND) {
                array_unshift($player['main_deck'], array_pop($hand));
            }
            $player['hand'] = $hand;
            break;
        case 'stage':
            debugPurgeStrayTestCopies($player, $cardNo, $instanceId);
            $slot = $zoneSpec['slot'];
            $existing = $player['stage'][$slot] ?? null;
            if (is_array($existing) && ($existing['card_no'] ?? '') === $cardNo
                && ($existing['instance_id'] ?? '') !== $instanceId) {
                $player['waiting_room'][] = $existing;
            }
            debugPreparePreplacedStageMember($card);
            $player['stage'][$slot] = $card;
            break;
        case 'waiting_room':
            $card['entered_turn'] = $turn;
            $player['waiting_room'] = array_merge([$card], $player['waiting_room'] ?? []);
            break;
        case 'live':
            $card['entered_turn'] = $turn;
            $card['revealed'] = false;
            $card['live_slot'] = 0;
            $player['live_zone'] = [$card];
            break;
    }
}

function applyDebugCardTestBoard(
    array $state,
    array $testCard,
    string $testPid,
    array $zoneSpec,
    string $controller,
    string $stageSeed = 'auto',
    ?array $cardsData = null,
    array $boardSetup = ['p1' => [], 'p2' => []]
): array {
    $other = $testPid === 'p1' ? 'p2' : 'p1';
    $first = ($controller === 'cpu') ? 'p2' : 'p1';
    $turn = intval($state['turn'] ?? 1);

    foreach (['p1', 'p2'] as $pid) {
        $p = &$state['players'][$pid];
        $p['hand'] = [];
        $p['live_zone'] = [];
        $p['waiting_room'] = [];
        $p['success_lives'] = [];
        $p['stage'] = ['left' => null, 'center' => null, 'right' => null];
        $p['ready_mulligan'] = true;
        $p['main_deck'] = stripCardNoFromInstances($p['main_deck'] ?? [], $testCard['card_no']);
        debugDealOpeningHand($p, DEBUG_OPENING_HAND);
        debugFillEnergyZone($p, DEBUG_DEFAULT_ENERGY);
        unset($p);
    }

    $testCardNo = $testCard['card_no'] ?? '';
    $excludeByPid = ['p1' => [], 'p2' => []];
    $excludeByPid[$testPid][] = $testCardNo;

    $wrSummary = debugSeedWaitingRoomForCard($state['players'][$testPid], $testCard, $cardsData, 2);
    debugSeedWaitingRoom($state['players'][$other], 2);

    placeDebugTestCard($state['players'][$testPid], $zoneSpec, $testCard, $turn);
    $liveSummary = ($zoneSpec['type'] !== 'live')
        ? debugSeedLiveZoneForCard($state['players'][$testPid], $testCard, $cardsData)
        : [];
    $stageSummary = debugApplyStageSeeding($state, $testCard, $testPid, $stageSeed, $turn, $cardsData);

    $batonSetup = debugExtractBatonLeaveSetup($testCard);
    $batonEnterSetups = debugExtractBatonEnterSetups($testCard);
    $batonSummary = [];
    $avoidSlot = ($zoneSpec['type'] === 'stage') ? ($zoneSpec['slot'] ?? 'center') : null;

    if ($zoneSpec['type'] === 'hand') {
        foreach ($batonEnterSetups as $enterFilter) {
            $line = debugSeedStageOccupantForBatonEnter(
                $state['players'][$testPid],
                $enterFilter,
                $cardsData,
                $testCardNo,
                $turn,
                $avoidSlot
            );
            if ($line !== null) {
                $batonSummary[] = 'Baton Touch target on Stage: ' . $line;
            }
        }
    }

    if ($batonSetup !== null && $zoneSpec['type'] === 'stage') {
        $line = debugSeedHandForSetupFilter(
            $state['players'][$testPid],
            $batonSetup,
            $cardsData,
            $testCardNo,
            'cheapest'
        );
        if ($line !== null) {
            $batonSummary[] = 'Added Baton partner to hand: ' . $line;
        } else {
            $batonSummary[] = 'Hand already has a suitable Baton partner.';
        }
    }

    foreach (['p1', 'p2'] as $pid) {
        $custom = $boardSetup[$pid] ?? [];
        if (empty($custom)) {
            continue;
        }
        $lines = debugApplyPlayerBoardSetup(
            $state['players'][$pid],
            $custom,
            $turn,
            $cardsData,
            $excludeByPid[$pid]
        );
        foreach ($lines as $line) {
            $who = $state['players'][$pid]['name'] ?? $pid;
            $state = addLog($state, 'Debug setup (' . $who . '): ' . $line);
        }
    }

    $state['first_player'] = $first;
    $state['active_player'] = $first;
    unset($state['coin_flip']);

    $state['debug_card_test'] = [
        'card_no' => $testCard['card_no'] ?? '',
        'test_pid' => $testPid,
        'controller' => $controller,
        'zone' => $zoneSpec['type'] . (isset($zoneSpec['slot']) ? '_' . $zoneSpec['slot'] : ''),
        'instance_id' => $testCard['instance_id'] ?? '',
        'stage_seed' => $stageSeed,
        'stage_seed_hints' => debugExtractAllSetupHints($testCard),
        'wr_seed_hints' => array_map('debugFormatWrHint', debugExtractWrHints($testCard)),
        'live_zone_seed_hints' => array_map('debugFormatLiveZoneHint', debugExtractLiveZoneHints($testCard)),
        'live_zone_preseed' => !empty($liveSummary),
        'baton_leave_setup' => $batonSetup ? debugFormatBatonLeaveHint($batonSetup) : null,
        'baton_enter_setups' => array_map('debugFormatBatonEnterHint', $batonEnterSetups),
    ];
    $state['mode'] = 'debug_card_test';
    $state['cpu_solo'] = true;

    $name = $testCard['name_en'] ?? $testCard['name'] ?? $testCard['card_no'];
    $state = addLog($state, 'Debug card test — ' . $name . ' (' . ($testCard['card_no'] ?? '') . ')');
    $state = addLog($state, 'Debug setup: ' . DEBUG_OPENING_HAND . '-card hands, skill-ready Waiting Room, full main decks, 12 active Energy.');
    foreach ($wrSummary as $line) {
        $state = addLog($state, 'Debug WR: ' . $line);
    }
    foreach ($liveSummary as $line) {
        $state = addLog($state, 'Debug Live storage: ' . $line);
    }
    foreach ($stageSummary as $line) {
        $state = addLog($state, 'Debug stage: ' . $line);
    }
    foreach ($batonSummary as $line) {
        $state = addLog($state, 'Debug baton: ' . $line);
    }
    if ($zoneSpec['type'] === 'stage') {
        if ($batonSetup) {
            $state = addLog($state, 'Debug: test Member on Stage (Center) — Baton Touch a '
                . ($batonSetup['group'] !== '' ? $batonSetup['group'] . ' ' : '')
                . 'Member with cost ≥' . intval($batonSetup['min_baton_cost'] ?? 10)
                . ' from hand onto them.');
        } else {
            $state = addLog($state, 'Debug: test Member placed on Stage (Center) — [Activated] skills are ready in Main Phase.');
        }
    } elseif ($zoneSpec['type'] === 'hand' && !empty($batonEnterSetups)) {
        $state = addLog($state, 'Debug: test Member in hand — Baton Touch onto the seeded Stage Member to trigger On Enter.');
    }
    $state = addLog($state, '--- Turn 1 ---');

    if ($zoneSpec['type'] === 'live') {
        $state['phase'] = 'live_set';
        $state['live_ready'] = ['p1' => false, 'p2' => false];
        $state['active_player'] = $testPid;
        $state = addLog($state, '=== LIVE Phase ===');
        $state = addLog($state, 'LIVE Phase: place 0–3 cards (Live or Member) face-down in Live storage (draw 1 per card placed), then end LIVE Phase.');
    } else {
        $state['phase'] = 'main_first';
        $state = addLog($state, possessiveName($state['players'][$first]['name']) . ' turn — Main Phase (debug setup).');
    }

    $state['status'] = 'playing';
    $state['seq']++;
    return $state;
}

function apiDebugCardTestStart(array $body): array {
    assertDebugCardTestAllowed($body);

    $cardNo = trim((string)($body['card_no'] ?? ''));
    if ($cardNo === '') {
        throw new Exception('card_no is required');
    }

    $controller = strtolower(trim((string)($body['controller'] ?? 'self')));
    if (!in_array($controller, ['self', 'cpu'], true)) {
        throw new Exception('controller must be self or cpu');
    }

    $testPid = $controller === 'cpu' ? 'p2' : 'p1';

    $cpuDiff = in_array($body['cpu_difficulty'] ?? '', ['easy', 'normal', 'hard', 'expert'], true)
        ? $body['cpu_difficulty'] : 'normal';

    $cardsData = json_decode(file_get_contents(CARDS_FILE), true);
    $cardDef = findCardDefByNo($cardsData['cards'] ?? [], $cardNo);
    if (!$cardDef) {
        throw new Exception('Unknown card_no: ' . $cardNo);
    }

    $zoneSpec = resolveDebugTestZoneForCard($cardDef, (string)($body['zone'] ?? 'auto'));
    $stageSeed = parseDebugStageSeedMode((string)($body['stage_seed'] ?? 'auto'));
    $boardSetup = parseDebugBoardSetup($body);

    $testCard = makeDebugCardInstance($cardDef);

    $roomId = strtoupper(substr(md5(uniqid('dbg', true)), 0, 6));
    $p1Token = generateToken();
    $p2Token = generateToken();
    $playerName = htmlspecialchars($body['name'] ?? 'Debug Tester', ENT_QUOTES);

    $p1Resolved = resolvePlayerDeckLists($cardsData, 'nijigasaki');
    $cpuResolved = resolveCpuDeckLists($cardsData, $cpuDiff, null);

    $p1MainNos = stripCardNoFromNos($p1Resolved['main_nos'], $cardNo);
    $cpuMainNos = stripCardNoFromNos($cpuResolved['main_nos'], $cardNo);

    $bodyShuffle = array_merge($body, ['shuffle' => true]);

    $p1Main = buildDeckForRoom($cardsData['cards'], $p1MainNos, $bodyShuffle, 'main_order');
    $p1Energy = buildDeckForRoom($cardsData['cards'], $p1Resolved['energy_nos'], $bodyShuffle, 'energy_order');
    $p2Main = buildDeckForRoom($cardsData['cards'], $cpuMainNos, $bodyShuffle, 'main_order');
    $p2Energy = buildDeckForRoom($cardsData['cards'], $cpuResolved['energy_nos'], $bodyShuffle, 'energy_order');

    $state = initGameState($roomId, [
        'id' => 'p1', 'token' => $p1Token, 'name' => $playerName,
        'deck_choice' => $p1Resolved['deck_choice'],
        'deck_label' => $p1Resolved['deck_label'],
        'main_deck' => $p1Main, 'energy_deck' => $p1Energy,
    ]);

    $state['players']['p2'] = initPlayerState([
        'id' => 'p2', 'token' => $p2Token, 'name' => 'CPU (Debug)',
        'deck_choice' => $cpuResolved['deck_choice'],
        'deck_label' => $cpuResolved['deck_label'],
        'main_deck' => $p2Main, 'energy_deck' => $p2Energy,
    ]);

    $state['status'] = 'setup';
    $state['cpu_difficulty'] = $cpuDiff;
    $state = addLog($state, 'Debug card test room created.');
    $state = applyDebugCardTestBoard($state, $testCard, $testPid, $zoneSpec, $controller, $stageSeed, $cardsData, $boardSetup);
    $state['seq']++;

    saveGame($roomId, $state);

    $hints = debugExtractAllSetupHints($cardDef);
    return [
        'room_id' => $roomId,
        'player_token' => $p1Token,
        'cpu_token' => $p2Token,
        'player_id' => 'p1',
        'card_no' => $cardNo,
        'controller' => $controller,
        'zone' => $state['debug_card_test']['zone'] ?? '',
        'start_zone' => inferDebugTestStartZone($cardDef),
        'stage_seed' => $stageSeed,
        'stage_seed_hints' => $hints,
        'setup_hints' => $hints,
        'wr_seed_hints' => array_map('debugFormatWrHint', debugExtractWrHints($cardDef)),
        'live_zone_seed_hints' => array_map('debugFormatLiveZoneHint', debugExtractLiveZoneHints($cardDef)),
        'baton_leave_hint' => ($baton = debugExtractBatonLeaveSetup($cardDef))
            ? debugFormatBatonLeaveHint($baton) : null,
        'baton_enter_hints' => array_map('debugFormatBatonEnterHint', debugExtractBatonEnterSetups($cardDef)),
        'message' => 'Debug card test started',
    ];
}
