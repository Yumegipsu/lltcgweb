<?php
/**
 * Live modifier state and applyModifierEffect dispatch — extracted from effects.php.
 */

// ─────────────────────────────────────────────
// Live modifiers (until this Live ends)
// ─────────────────────────────────────────────

function initLiveModifiers(array $state): array {
    if (!isset($state['live_modifiers'])) {
        $state['live_modifiers'] = ['p1' => liveModifierDefaults(), 'p2' => liveModifierDefaults()];
    }
    return $state;
}

function liveModifierDefaults(): array {
    return [
        'score_bonus'              => 0,
        'blade_bonus'              => 0,
        'both_stages_blade'        => 0,
        'bonus_hearts'             => [],
        'blade_per_live_zone'      => 0,
        'yell_reveal_reduction'    => 0,
        'blade_per_hand_divisor'   => 0,
        'blade_per_hand_amount'    => 0,
        'cannot_live'              => false,
        // Until-Live Always auras: [{group, blade, source…}] — Rin PL!-pb2-005 (#218).
        'stage_group_blade'        => [],
    ];
}

function addBonusHeartsToModifier(array &$state, string $pid, array $heartsSpec): void {
    $state = initLiveModifiers($state);
    foreach ($heartsSpec as $h) {
        $color = is_array($h) ? ($h['color'] ?? '') : (string)$h;
        $count = is_array($h) ? intval($h['count'] ?? 1) : 1;
        for ($i = 0; $i < $count; $i++) {
            $state['live_modifiers'][$pid]['bonus_hearts'][] = $color;
        }
    }
}

function getBonusHeartsFlat(array $state, string $pid): array {
    $state = initLiveModifiers($state);
    return $state['live_modifiers'][$pid]['bonus_hearts'] ?? [];
}

function clearLiveModifiers(array $state): array {
    $state['live_modifiers'] = ['p1' => liveModifierDefaults(), 'p2' => liveModifierDefaults()];
    foreach (['p1', 'p2'] as $pid) {
        $p = &$state['players'][$pid];
        $wrIds = array_column($p['waiting_room'] ?? [], 'instance_id');
        foreach ($p['stage'] as $slot => &$mbr) {
            if (!$mbr) continue;
            $iid = $mbr['instance_id'] ?? '';
            if ($iid !== '' && in_array($iid, $wrIds, true)) {
                $p['stage'][$slot] = null;
                continue;
            }
            unset(
                $mbr['bonus_hearts'],
                $mbr['live_blade_bonus'],
                $mbr['live_score_bonus'],
                $mbr['live_cost_bonus'],
                $mbr['live_cost_override'],
                $mbr['live_start_negated'],
                $mbr['printed_blade_override'],
                $mbr['replaced_hearts'],
                $mbr['hearts_treat_as'],
                $mbr['bp7_hearts_override'],
                $mbr['bp7_live_zone_ability_used'],
                $mbr['on_enter_or_live_start_fired']
            );
        }
        unset($mbr, $p);
    }
    unset($state['pending_prompt']);
    unset(
        $state['_last_yell_score_icons'],
        $state['_last_yell_live_count'],
        $state['_last_yell_cards']
    );
    foreach (['p1', 'p2'] as $pid) {
        unset($state['_last_yell_live_count_' . $pid], $state['_last_yell_score_icons_' . $pid]);
    }
    return $state;
}

function getStageBladeBonus(array $state, string $pid): int {
    $state = initLiveModifiers($state);
    $bonus = intval($state['live_modifiers'][$pid]['blade_bonus'] ?? 0);
    $perLive = intval($state['live_modifiers'][$pid]['blade_per_live_zone'] ?? 0);
    if ($perLive > 0) {
        $bonus += count($state['players'][$pid]['live_zone'] ?? []) * $perLive;
    }
    return $bonus;
}

function getBothStagesBladeBonus(array $state): int {
    $state = initLiveModifiers($state);
    $a = intval($state['live_modifiers']['p1']['both_stages_blade'] ?? 0);
    $b = intval($state['live_modifiers']['p2']['both_stages_blade'] ?? 0);
    return max($a, $b);
}

/** Source Member for “＋N Blade until this Live ends” (Wait nullifies that Member’s Yell blades). */
function liveModifierSourceCard(array $state, string $pid, array $source = []): array {
    if (($source['instance_id'] ?? '') !== '') {
        return $source;
    }
    if (!empty($state['_mod_source']) && is_array($state['_mod_source'])
        && ($state['_mod_source']['instance_id'] ?? '') !== '') {
        return $state['_mod_source'];
    }
    $pr = $state['pending_prompt'] ?? null;
    $iid = is_array($pr) ? (string)($pr['source_id'] ?? $pr['source_instance_id'] ?? '') : '';
    if ($iid === '') {
        return [];
    }
    if (function_exists('findSourceCard')) {
        $found = findSourceCard($state, $pid, $iid);
        if (is_array($found) && ($found['instance_id'] ?? '') !== '') {
            return $found;
        }
    }
    return ['instance_id' => $iid];
}

/** Put until-Live Blade on the source Member when she is on Stage. Live cards stay player-wide. */
function applyUntilLiveBladeToSourceMember(array &$state, string $pid, int $amount, array $source = []): bool {
    if ($amount === 0) {
        return false;
    }
    $src = liveModifierSourceCard($state, $pid, $source);
    $iid = (string)($src['instance_id'] ?? '');
    if ($iid === '' || empty($state['players'][$pid])) {
        return false;
    }
    $slot = findMemberSlot($state['players'][$pid], $iid);
    if ($slot === '' || empty($state['players'][$pid]['stage'][$slot])) {
        return false;
    }
    $state['players'][$pid]['stage'][$slot]['live_blade_bonus'] =
        intval($state['players'][$pid]['stage'][$slot]['live_blade_bonus'] ?? 0) + $amount;
    return true;
}

/** Until-Live hearts on the source Member when she is on Stage (Hime bp5-006, Zenhoui checks). */
function applyUntilLiveHeartsToSourceMember(array &$state, string $pid, array $heartsSpec, array $source = []): bool {
    if ($heartsSpec === []) {
        return false;
    }
    $src = liveModifierSourceCard($state, $pid, $source);
    if (!isMemberCard($src)) {
        return false;
    }
    $iid = (string)($src['instance_id'] ?? '');
    if ($iid === '' || empty($state['players'][$pid])) {
        return false;
    }
    $slot = findMemberSlot($state['players'][$pid], $iid);
    if ($slot === '' || empty($state['players'][$pid]['stage'][$slot])) {
        return false;
    }
    addBonusHeartsToMember($state['players'][$pid]['stage'][$slot], $heartsSpec);
    return true;
}

function applyModifierEffect(array $state, string $pid, array $effect, array $source = []): array {
    $state = initLiveModifiers($state);
    $type = $effect['type'] ?? '';
    switch ($type) {
        case 'live_score_bonus':
            $state['live_modifiers'][$pid]['score_bonus'] += intval($effect['amount'] ?? 0);
            break;
        case 'blade_bonus':
            $amt = intval($effect['amount'] ?? 0);
            if (!applyUntilLiveBladeToSourceMember($state, $pid, $amt, $source)) {
                $state['live_modifiers'][$pid]['blade_bonus'] += $amt;
            }
            break;
        case 'blade_bonus_per_discarded':
            $amt = intval($effect['amount'] ?? 1) * intval($effect['discarded'] ?? 0);
            if (!applyUntilLiveBladeToSourceMember($state, $pid, $amt, $source)) {
                $state['live_modifiers'][$pid]['blade_bonus'] += $amt;
            }
            break;
        case 'blade_bonus_per_success':
            $succ = count($state['players'][$pid]['success_lives'] ?? []);
            $amt = intval($effect['amount'] ?? 1) * $succ;
            if (!applyUntilLiveBladeToSourceMember($state, $pid, $amt, $source)) {
                $state['live_modifiers'][$pid]['blade_bonus'] += $amt;
            }
            break;
        case 'blade_bonus_per_live_zone':
            $state['live_modifiers'][$pid]['blade_per_live_zone'] +=
                intval($effect['amount'] ?? 1);
            break;
        case 'member_blade_bonus':
            applyMemberBladeBonus($state, $pid, $effect);
            break;
        case 'both_stages_blade_bonus':
            foreach (['p1', 'p2'] as $id) {
                $state['live_modifiers'][$id]['both_stages_blade'] += intval($effect['amount'] ?? 0);
            }
            break;
        case 'hearts_and_blade_bonus':
            if (!empty($effect['hearts'])) {
                addBonusHeartsToModifier($state, $pid, $effect['hearts']);
            }
            if (!empty($effect['blade'])) {
                $b = intval($effect['blade']);
                if (!applyUntilLiveBladeToSourceMember($state, $pid, $b, $source)) {
                    $state['live_modifiers'][$pid]['blade_bonus'] += $b;
                }
            }
            break;
        case 'grant_bonus_hearts':
            if (!empty($effect['hearts'])) {
                if (!applyUntilLiveHeartsToSourceMember($state, $pid, $effect['hearts'], $source)) {
                    addBonusHeartsToModifier($state, $pid, $effect['hearts']);
                }
            }
            break;
        case 'blade_bonus_per_paid':
            $amt = intval($effect['amount'] ?? 1) * intval($effect['paid'] ?? 0);
            if (!applyUntilLiveBladeToSourceMember($state, $pid, $amt, $source)) {
                $state['live_modifiers'][$pid]['blade_bonus'] += $amt;
            }
            break;
    }
    return $state;
}

function isLiveModifierEffectType(string $type): bool {
    return in_array($type, [
        'live_score_bonus',
        'blade_bonus',
        'blade_bonus_per_discarded',
        'blade_bonus_per_success',
        'blade_bonus_per_live_zone',
        'member_blade_bonus',
        'both_stages_blade_bonus',
        'hearts_and_blade_bonus',
        'grant_bonus_hearts',
        'blade_bonus_per_paid',
    ], true);
}
