<?php

declare(strict_types=1);

namespace LLTCG\Game;

/**
 * Shared numeric / no-prompt handlers registered on EffectRegistry.
 * High-frequency types migrate here; switch modules call through or fall back.
 */
final class EffectHandlers
{
    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $source
     * @param array<string,mixed> $ab
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $p
     * @return array<string,mixed>|null null = not handled
     */
    public static function tryHandle(
        array $state,
        string $pid,
        array $source,
        array $ab,
        array $ctx,
        string $type,
        array &$p,
        string $name
    ): ?array {
        return match ($type) {
            'draw' => self::draw($state, $pid, $ab, $name),
            'draw_if_success_lives' => self::drawIfSuccessLives($state, $pid, $ab, $p, $name),
            'draw_if_bonus_hearts_on_stage' => self::drawIfBonusHearts($state, $pid, $ab, $p, $name),
            'draw_if_wr_min' => self::drawIfWrMin($state, $pid, $ab, $p, $name),
            'grant_hearts' => self::grantHearts($state, $pid, $ab, $name),
            'grant_live_score_if_success' => self::grantLiveScoreIfSuccess($state, $pid, $ab, $p, $name),
            'blade_bonus' => self::bladeBonus($state, $pid, $source, $ab, $p, $name),
            'blade_per_hand_cards' => self::bladePerHandCards($state, $pid, $source, $ab, $ctx, $p, $name),
            'both_mill_deck_to_wr' => self::bothMillDeckToWr($state, $pid, $ab, $name),
            'energy_wait_from_deck_locked' => self::energyWaitFromDeckLocked($state, $pid, $ab, $name),
            'activate_energy_if_more_than_opp' => self::activateEnergyIfMoreThanOpp($state, $pid, $ab, $name),
            'live_score_if_more_energy_than_opp' => self::liveScoreIfMoreEnergyThanOpp($state, $pid, $source, $ab, $name),
            'live_success_score_if_energy_lead' => self::liveScoreIfEnergyLead($state, $pid, $source, $ab, $name),
            'if_baton_from_group' => self::ifBatonFromGroup($state, $pid, $source, $ab, $ctx, $name),
            'if_named_on_stage' => self::ifNamedOnStage($state, $pid, $source, $ab, $ctx, $p, $name),
            default => null,
        };
    }

    /**
     * PL!N-bp7-009 — each player puts the top N cards of their deck into the Waiting Room.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $ab
     */
    private static function bothMillDeckToWr(array $state, string $pid, array $ab, string $name): array
    {
        $n = max(1, intval($ab['count'] ?? 7));
        foreach (['p1', 'p2'] as $seat) {
            if (empty($state['players'][$seat]) || !is_array($state['players'][$seat])) {
                continue;
            }
            $milled = takeFromMainDeckTop($state, $seat, $n);
            if (empty($milled)) {
                continue;
            }
            $state = appendCardsToWaitingRoom($state, $seat, $milled);
            $state = addLog($state, $state['players'][$seat]['name'] .
                " — [$name] put " . count($milled) . ' card(s) from the top of their deck into the Waiting Room.');
            $state = bp7ResolveAutoSelfMilled($state, $seat, $milled);
        }
        return $state;
    }

    /**
     * PL!SP-bp7-007 / -017 / -027 — Energy deck → Wait; those Energy cards skip the
     * controller's next Active Phase.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $ab
     */
    private static function energyWaitFromDeckLocked(array $state, string $pid, array $ab, string $name): array
    {
        $n = max(1, intval($ab['count'] ?? 1));
        $placed = bp7EnergyDeckToWait($state, $pid, $n, true);
        if ($placed <= 0) {
            return addLog($state, $state['players'][$pid]['name'] .
                " — [$name] had no Energy cards left in the Energy deck.");
        }
        return addLog($state, $state['players'][$pid]['name'] .
            " — [$name] put $placed Energy card(s) from the Energy deck into Wait; " .
            'they do not activate during the next Active Phase.');
    }

    /**
     * PL!SP-bp7-007 — if you have more Energy than your opponent, activate N Energy.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $ab
     */
    private static function activateEnergyIfMoreThanOpp(array $state, string $pid, array $ab, string $name): array
    {
        $opp = ($pid === 'p1') ? 'p2' : 'p1';
        $mine = countEnergyInZone($state['players'][$pid] ?? []);
        if ($mine <= countEnergyInZone($state['players'][$opp] ?? [])) {
            return $state;
        }
        $want = max(1, intval($ab['count'] ?? 6));
        $done = 0;
        foreach ($state['players'][$pid]['energy_zone'] as $i => $e) {
            if ($done >= $want) {
                break;
            }
            if (!empty($e['active'])) {
                continue;
            }
            $state['players'][$pid]['energy_zone'][$i]['active'] = true;
            unset($state['players'][$pid]['energy_zone'][$i]['skip_activate_next_turn']);
            $done++;
        }
        return addLog($state, $state['players'][$pid]['name'] .
            " — [$name] activated $done Energy (more Energy than the opponent).");
    }

    /**
     * PL!SP-bp7-027 `then` — this card's score +N while you hold the Energy lead.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $source
     * @param array<string,mixed> $ab
     */
    private static function liveScoreIfMoreEnergyThanOpp(
        array $state,
        string $pid,
        array $source,
        array $ab,
        string $name
    ): array {
        $opp = ($pid === 'p1') ? 'p2' : 'p1';
        if (countEnergyInZone($state['players'][$pid] ?? [])
            <= countEnergyInZone($state['players'][$opp] ?? [])) {
            return $state;
        }
        bp7BumpSelfScore(
            $state,
            $pid,
            $source,
            intval($ab['amount'] ?? 1),
            $name,
            'more Energy than the opponent'
        );
        return $state;
    }

    /**
     * PL!SP-bp7-024 — this card's score +N when your Energy lead is at least `min_lead`.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $source
     * @param array<string,mixed> $ab
     */
    private static function liveScoreIfEnergyLead(
        array $state,
        string $pid,
        array $source,
        array $ab,
        string $name
    ): array {
        $opp = ($pid === 'p1') ? 'p2' : 'p1';
        $lead = countEnergyInZone($state['players'][$pid] ?? [])
            - countEnergyInZone($state['players'][$opp] ?? []);
        $min = max(1, intval($ab['min_lead'] ?? 2));
        if ($lead < $min) {
            return $state;
        }
        bp7BumpSelfScore(
            $state,
            $pid,
            $source,
            intval($ab['amount'] ?? 1),
            $name,
            "Energy lead of $min or more"
        );
        return $state;
    }

    /**
     * PL!S-bp7-004 — gate the nested `then` on entering via Baton Touch from a group Member.
     * `baton_member_groups` records every replaced Member (double Baton Touch).
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $source
     * @param array<string,mixed> $ab
     * @param array<string,mixed> $ctx
     */
    private static function ifBatonFromGroup(
        array $state,
        string $pid,
        array $source,
        array $ab,
        array $ctx,
        string $name
    ): array {
        $group = (string)($ab['group'] ?? '');
        $groups = $source['baton_member_groups'] ?? [];
        if (!is_array($groups) || empty($groups)) {
            $groups = [(string)($source['baton_from_group'] ?? '')];
        }
        $ok = empty($source['entered_via_baton']) ? false : ($group === '' || in_array($group, $groups, true));
        if (!$ok) {
            return $state;
        }
        $state = addLog($state, $state['players'][$pid]['name'] .
            " — [$name] entered via Baton Touch from " . ($group !== '' ? $group : 'a Member') . '.');
        return bp7ResolveThen($state, $pid, $source, $ab, $ctx);
    }

    /**
     * PL!SP-bp7-026 `then` — resolve only while a named Member is on your Stage.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $source
     * @param array<string,mixed> $ab
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $p
     */
    private static function ifNamedOnStage(
        array $state,
        string $pid,
        array $source,
        array $ab,
        array $ctx,
        array $p,
        string $name
    ): array {
        $names = $ab['names'] ?? [];
        if (!is_array($names) || empty($names) || !stageHasNamedMember($p, $names)) {
            return $state;
        }
        return bp7ResolveThen($state, $pid, $source, $ab, $ctx);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $ab */
    private static function draw(array $state, string $pid, array $ab, string $name): array
    {
        $n = max(1, intval($ab['draw'] ?? $ab['count'] ?? 1));
        $drawn = drawCardsForPlayer($state, $pid, $n);
        return addLog($state, $state['players'][$pid]['name'] . " — [$name] drew $drawn.");
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $ab @param array<string,mixed> $p */
    private static function drawIfSuccessLives(array $state, string $pid, array $ab, array $p, string $name): array
    {
        $succ = $p['success_lives'] ?? [];
        if (!empty($ab['group'])) {
            $succ = array_values(array_filter(
                $succ,
                static fn($c) => cardInGroup($c, ($ab['group'] ?? ''))
            ));
        }
        if (!empty($succ)) {
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (Success Live area not empty).");
        }
        return $state;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $ab @param array<string,mixed> $p */
    private static function drawIfBonusHearts(array $state, string $pid, array $ab, array $p, string $name): array
    {
        if (function_exists('stageHasMemberWithExtraHearts') && stageHasMemberWithExtraHearts($p)) {
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (Member with bonus hearts on Stage).");
        }
        return $state;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $ab @param array<string,mixed> $p */
    private static function drawIfWrMin(array $state, string $pid, array $ab, array $p, string $name): array
    {
        if (count($p['waiting_room'] ?? []) >= intval($ab['min_wr'] ?? 10)) {
            $drawn = drawCardsForPlayer($state, $pid, intval($ab['draw'] ?? 1));
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] drew $drawn (Waiting Room has " . intval($ab['min_wr'] ?? 10) . "+ cards).");
        }
        return $state;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $ab */
    private static function grantHearts(array $state, string $pid, array $ab, string $name): array
    {
        if (!empty($ab['hearts'])) {
            addBonusHeartsToModifier($state, $pid, $ab['hearts']);
            $state = addLog($state, $state['players'][$pid]['name'] .
                " — [$name] gained bonus heart(s) until this Live ends.");
        }
        return $state;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $ab @param array<string,mixed> $p */
    private static function grantLiveScoreIfSuccess(
        array $state,
        string $pid,
        array $ab,
        array $p,
        string $name
    ): array {
        $succCount = count($p['success_lives'] ?? []);
        $scoreSum = sumSuccessLiveScores($p);
        if ($succCount >= intval($ab['min_success_count'] ?? 1)
            && $scoreSum <= intval($ab['max_success_score_sum'] ?? 1)) {
            $state = applyModifierEffect($state, $pid, [
                'type'   => 'live_score_bonus',
                'amount' => intval($ab['amount'] ?? 1),
            ]);
            $state = addLog($state, $state['players'][$pid]['name'] .
                ' — [' . $name . '] Live total score +' . intval($ab['amount'] ?? 1) . ' until Live ends.');
        }
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $source
     * @param array<string,mixed> $ab
     * @param array<string,mixed> $p
     */
    private static function bladeBonus(
        array $state,
        string $pid,
        array $source,
        array $ab,
        array &$p,
        string $name
    ): array {
        $bladeAmt = intval($ab['amount'] ?? 1);
        $srcSlot = findMemberSlot($p, $source['instance_id'] ?? '');
        if ($srcSlot !== '' && !empty($p['stage'][$srcSlot])) {
            $p['stage'][$srcSlot]['live_blade_bonus'] =
                intval($p['stage'][$srcSlot]['live_blade_bonus'] ?? 0) + $bladeAmt;
        } else {
            $state = applyModifierEffect($state, $pid, $ab);
        }
        return addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $name . '] gains +' . $bladeAmt . ' Blade until this Live ends.');
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $source
     * @param array<string,mixed> $ab
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $p
     */
    private static function bladePerHandCards(
        array $state,
        string $pid,
        array $source,
        array $ab,
        array $ctx,
        array &$p,
        string $name
    ): array {
        if (($ab['trigger'] ?? '') === 'live_start' || ($ctx['phase'] ?? '') === 'live_start') {
            $handCount = count($p['hand'] ?? []);
            $div = max(1, intval($ab['per_cards'] ?? 2));
            $bonus = intdiv($handCount, $div) * intval($ab['amount'] ?? 1);
            if ($bonus > 0) {
                $srcSlot = findMemberSlot($p, $source['instance_id'] ?? '');
                if ($srcSlot !== '' && !empty($p['stage'][$srcSlot])) {
                    $p['stage'][$srcSlot]['live_blade_bonus'] =
                        intval($p['stage'][$srcSlot]['live_blade_bonus'] ?? 0) + $bonus;
                } else {
                    $state = applyModifierEffect($state, $pid, [
                        'type'   => 'blade_bonus',
                        'amount' => $bonus,
                    ]);
                }
            }
            return addLog($state, $state['players'][$pid]['name'] .
                " — [$name] gains +$bonus Blade until Live ends (+1 per " .
                intval($ab['per_cards'] ?? 2) . " cards in hand at Live Start, hand was $handCount).");
        }
        $state = initLiveModifiers($state);
        $state['live_modifiers'][$pid]['blade_per_hand_divisor'] = max(1, intval($ab['per_cards'] ?? 2));
        $state['live_modifiers'][$pid]['blade_per_hand_amount'] = intval($ab['amount'] ?? 1);
        return addLog($state, $state['players'][$pid]['name'] .
            ' — [' . $name . '] +1 Blade per ' . intval($ab['per_cards'] ?? 2) . ' cards in hand until Live ends.');
    }
}
