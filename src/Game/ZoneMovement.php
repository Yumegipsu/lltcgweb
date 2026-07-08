<?php
/**
 * Card zone movement helpers extracted from effects.php.
 */

function drawCardInstances(array &$p, int $count): array {
    $drawn = [];
    for ($i = 0; $i < $count; $i++) {
        if (empty($p['main_deck'])) {
            break;
        }
        $c = array_shift($p['main_deck']);
        $p['hand'][] = $c;
        $drawn[] = $c;
    }
    return $drawn;
}

function discardHandCardsByIds(array &$p, array $ids): array {
    $moved = [];
    $idSet = array_flip($ids);
    $p['hand'] = array_values(array_filter($p['hand'], function ($c) use ($idSet, &$moved, &$p) {
        $iid = $c['instance_id'] ?? '';
        if (isset($idSet[$iid])) {
            $p['waiting_room'][] = $c;
            $moved[] = $c;
            return false;
        }
        return true;
    }));
    return $moved;
}

function discardFromHandByIds(array &$p, array $ids, ?array &$notifyState = null, ?string $notifyPid = null): int {
    $moved = [];
    $count = 0;
    $p['hand'] = array_values(array_filter($p['hand'], function ($c) use ($ids, &$count, &$moved, &$p) {
        if (in_array($c['instance_id'] ?? '', $ids, true)) {
            $p['waiting_room'][] = $c;
            $moved[] = $c;
            $count++;
            return false;
        }
        return true;
    }));
    if ($count > 0 && $notifyState !== null && $notifyPid !== null) {
        hsPb1NotifyHandDiscard($notifyState, $notifyPid);
        if (function_exists('spBp5NotifyCardsToWr')) {
            $notifyState = spBp5NotifyCardsToWr($notifyState, $notifyPid, $moved);
        }
    }
    return $count;
}

/** Append cards to WR and fire main-phase auto hooks (Ren Hazuki bp5-005, etc.). */
function appendCardsToWaitingRoom(array &$state, string $pid, array $cards): array {
    if (empty($cards)) {
        return $state;
    }
    $p = &$state['players'][$pid];
    foreach ($cards as $c) {
        $p['waiting_room'][] = $c;
    }
    if (function_exists('spBp5NotifyCardsToWr')) {
        $state = spBp5NotifyCardsToWr($state, $pid, $cards);
    }
    return $state;
}

/** Shuffle all Waiting Room cards into main deck when the deck is empty (deck refresh). */
function refreshMainDeckFromWaitingRoom(array &$state, string $pid): int {
    $p = &$state['players'][$pid];
    if (!empty($p['main_deck'])) {
        return 0;
    }
    $wr = $p['waiting_room'] ?? [];
    if (empty($wr)) {
        return 0;
    }
    $count = count($wr);
    $p['main_deck'] = $wr;
    $p['waiting_room'] = [];
    shuffle($p['main_deck']);
    $p['_deck_refreshed_turn'] = intval($state['turn'] ?? 0);
    $name = $p['name'] ?? 'Player';
    $state = addLog(
        $state,
        "$name — Deck refresh: shuffled $count card(s) from Waiting Room into a new deck.",
        'action'
    );
    return $count;
}

/** Draw from main deck to hand, refreshing from Waiting Room when the deck runs out. */
function drawCardsForPlayer(array &$state, string $pid, int $count): int {
    $p = &$state['players'][$pid];
    $drawn = 0;
    for ($i = 0; $i < $count; $i++) {
        if (empty($p['main_deck'])) {
            if (refreshMainDeckFromWaitingRoom($state, $pid) <= 0) {
                break;
            }
        }
        if (empty($p['main_deck'])) {
            break;
        }
        $p['hand'][] = array_shift($p['main_deck']);
        $drawn++;
    }
    return $drawn;
}

/** Draw to hand with per-card effect log + deck→hand animation (for draw-then-pick prompts). */
function drawCardsForPlayerWithEffectLog(
    array &$state,
    string $pid,
    string $sourceName,
    int $count
): array {
    $p = &$state['players'][$pid];
    $drawnCards = [];
    for ($i = 0; $i < $count; $i++) {
        if (empty($p['main_deck'])) {
            if (refreshMainDeckFromWaitingRoom($state, $pid) <= 0) {
                break;
            }
        }
        if (empty($p['main_deck'])) {
            break;
        }
        $c = array_shift($p['main_deck']);
        $p['hand'][] = $c;
        $drawnCards[] = $c;
        $state = logEffectDraw($state, $pid, $sourceName, $c,
            [animSpec($c['instance_id'], 'main_deck', 'hand', $pid)]);
    }
    return $drawnCards;
}

/** Draw from main deck (not to hand), refreshing from Waiting Room when empty. */
function drawMainDeckCards(array &$state, string $pid, int $count): array {
    $p = &$state['players'][$pid];
    $drawn = [];
    for ($i = 0; $i < $count; $i++) {
        if (empty($p['main_deck'])) {
            if (refreshMainDeckFromWaitingRoom($state, $pid) <= 0) {
                break;
            }
        }
        if (empty($p['main_deck'])) {
            break;
        }
        $drawn[] = array_shift($p['main_deck']);
    }
    return $drawn;
}

/** Take cards from the top of main deck (mills, reveals), refreshing when empty. */
function takeFromMainDeckTop(array &$state, string $pid, int $count): array {
    if ($count <= 0) {
        return [];
    }
    $p = &$state['players'][$pid];
    $taken = [];
    while (count($taken) < $count) {
        if (empty($p['main_deck'])) {
            if (refreshMainDeckFromWaitingRoom($state, $pid) <= 0) {
                break;
            }
        }
        $need = $count - count($taken);
        $batch = array_splice($p['main_deck'], 0, min($need, count($p['main_deck'])));
        if (empty($batch)) {
            break;
        }
        $taken = array_merge($taken, $batch);
    }
    return $taken;
}

function activateEnergyForPlayer(array &$p, int $max): int {
    $n = 0;
    foreach ($p['energy_zone'] as &$e) {
        if ($n >= $max) break;
        if (!($e['active'] ?? false)) {
            $e['active'] = true;
            $n++;
        }
    }
    unset($e);
    return $n;
}

function countActiveEnergyInZone(array $p): int {
    return count(array_filter($p['energy_zone'] ?? [], fn($e) => $e['active'] ?? false));
}

function affordableEnergyForBatonPlay(array $p, ?array $occupant, ?array $incoming = null): int {
    return countActiveEnergyInZone($p);
}

function computeMemberPlayCostWithBaton(array $state, string $pid, array $card, ?array $occupant): int {
    $cost = getEffectiveHandCost($state, $pid, $card);
    if ($occupant) {
        $cost = max(0, $cost - getEffectiveStageMemberCost($state, $pid, $occupant));
    }
    return $cost;
}

function payEnergyCost(array &$p, int $cost, array $preferIds = []): bool {
    return count(payEnergyCostIds($p, $cost, $preferIds)) >= $cost;
}

function payEnergyCostIds(array &$p, int $cost, array $preferIds = []): array {
    if ($cost <= 0) {
        return [];
    }
    $paidIds = [];
    $preferIds = array_values(array_filter($preferIds));
    if (!empty($preferIds)) {
        foreach ($p['energy_zone'] as &$e) {
            if (count($paidIds) >= $cost) {
                break;
            }
            $id = $e['instance_id'] ?? '';
            if ($id !== '' && in_array($id, $preferIds, true) && ($e['active'] ?? false)) {
                $e['active'] = false;
                $paidIds[] = $id;
            }
        }
        unset($e);
    }
    if (count($paidIds) < $cost) {
        foreach ($p['energy_zone'] as &$e) {
            if (count($paidIds) >= $cost) {
                break;
            }
            $id = $e['instance_id'] ?? '';
            if ($id !== '' && ($e['active'] ?? false)) {
                $e['active'] = false;
                $paidIds[] = $id;
            }
        }
        unset($e);
    }
    if (count($paidIds) < $cost) {
        return [];
    }
    return $paidIds;
}

function wrPickMatchCount(array $p, array $cfg, int $need = 1): int {
    $count = 0;
    foreach ($p['waiting_room'] ?? [] as &$c) {
        hydrateWrCardForPick($c);
        if (cardMatchesWrPick($c, $cfg)) {
            $count++;
            if ($count >= $need) {
                unset($c);
                return $count;
            }
        }
    }
    unset($c);
    return $count;
}

function wrCandidatesMatching(array $p, array $cfg): array {
    $out = [];
    foreach ($p['waiting_room'] ?? [] as &$c) {
        hydrateWrCardForPick($c);
        if (cardMatchesWrPick($c, $cfg)) {
            $out[] = $c;
        }
    }
    unset($c);
    return $out;
}

function wrPickCfgFromAbility(array $ab): array {
    $cfg = ['group' => $ab['group'] ?? '', 'filter' => $ab['filter'] ?? 'member'];
    if (isset($ab['max_cost'])) {
        $cfg['max_cost'] = intval($ab['max_cost']);
    }
    if (isset($ab['max_live_score'])) {
        $cfg['max_live_score'] = intval($ab['max_live_score']);
    }
    if (isset($ab['min_required_hearts'])) {
        $cfg['min_required_hearts'] = intval($ab['min_required_hearts']);
    }
    if (!empty($ab['min_required_heart_color'])) {
        $cfg['min_required_heart_color'] = (string)$ab['min_required_heart_color'];
    }
    return $cfg;
}

/** leave_stage_add_from_wr defaults to Live when filter omitted; other WR picks default to Member. */
function wrPickCfgForLeaveStageAbility(array $ab): array {
    $cfg = wrPickCfgFromAbility($ab);
    if (($ab['type'] ?? '') === 'leave_stage_add_from_wr' && !isset($ab['filter'])) {
        $cfg['filter'] = 'live';
    }
    return $cfg;
}

function wrPickFilterLabel(string $filter): string {
    if ($filter === 'live') {
        return 'Live';
    }
    if ($filter === 'member') {
        return 'Member';
    }
    return 'card';
}
