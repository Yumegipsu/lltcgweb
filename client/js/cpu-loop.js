/** CPU AI loop — extracted from index.html (overhaul Part 2A) */
function cpuActiveEnergy(cpu) {
  return (cpu?.energy_zone || []).filter(energyChipActive).length;
}

function cpuCostNearLadderAnchor(cost, anchor) {
  const c = cost || 0;
  if (anchor === 4) return c >= 3 && c <= 5;
  if (anchor === 9) return c >= 8 && c <= 10;
  if (anchor === 15) return c >= 13 && c <= 16;
  return Math.abs(c - anchor) <= 1;
}

function cpuStageBatonAnchors(cpu) {
  const out = [];
  for (const slot of ['center', 'left', 'right']) {
    const m = cpu?.stage?.[slot];
    if (!m) continue;
    out.push({ slot, member: m, cost: stageMemberEffectiveCost(m, cpu) });
  }
  return out;
}

function cpuMaxStageCost(cpu) {
  const costs = cpuStageBatonAnchors(cpu).map(a => a.cost);
  return costs.length ? Math.max(...costs) : 0;
}

function cpuBatonLadderPlan(turn, maxStageCost, cpu) {
  const t = turn || 1;
  const max = maxStageCost || 0;
  if (t <= 2) {
    if (max < 4) return { phase: 'setup4', playTarget: 4, batonFrom: null, batonTo: null };
    return { phase: 'hold4', playTarget: 4, batonFrom: 4, batonTo: 9 };
  }
  if (t === 3) {
    if (max >= 4 && max < 9) return { phase: 'baton49', playTarget: 9, batonFrom: 4, batonTo: 9 };
    if (max < 4) return { phase: 'setup4', playTarget: 4, batonFrom: null, batonTo: null };
    if (max >= 9 && max < 15) return { phase: 'baton915', playTarget: 15, batonFrom: 9, batonTo: 15 };
    return { phase: 'ace', playTarget: 15, batonFrom: 9, batonTo: 15 };
  }
  if (max >= 9 && max < 15) return { phase: 'baton915', playTarget: 15, batonFrom: 9, batonTo: 15 };
  if (max >= 4 && max < 9) return { phase: 'baton49', playTarget: 9, batonFrom: 4, batonTo: 9 };
  if (max < 4) return { phase: 'setup4', playTarget: 4, batonFrom: null, batonTo: null };
  return { phase: 'ace', playTarget: 15, batonFrom: 9, batonTo: 15 };
}

function cpuIsMulliganAnchorMember(c, tier) {
  if (!isCpuMemberCard(c)) return false;
  const cost = c.cost || 0;
  if (cpuCostNearLadderAnchor(cost, 4) || cpuCostNearLadderAnchor(cost, 9)) return true;
  if (cpuTierHardPlus(tier) && cpuCostNearLadderAnchor(cost, 15)) return true;
  return false;
}

function cpuPreferredBatonSlotOrder(cpu, incoming, tier = null, hand = null) {
  const order = ['center', 'left', 'right'];
  const incCost = incoming?.cost || 0;
  const scoreSlot = (slot) => {
    const existing = cpu?.stage?.[slot];
    if (!existing) return 50;
    let sc = 0;
    const existCost = stageMemberEffectiveCost(existing, cpu);
    if (cpuCostNearLadderAnchor(incCost, 9) && cpuCostNearLadderAnchor(existCost, 4)) sc += 8;
    if (cpuCostNearLadderAnchor(incCost, 15) && cpuCostNearLadderAnchor(existCost, 9)) sc += 10;
    if (tier && tier !== 'easy' && hand) {
      sc += cpuScoreBatonBoardDelta(incoming, existing, slot, cpu, hand, tier) * 0.35;
    } else {
      sc += ((incoming?.blade || 0) - (existing.blade || 0)) * 0.4;
      sc += (cpuMemberHeartCount(incoming) - cpuMemberHeartCount(existing)) * 0.5;
    }
    return sc;
  };
  return [...order].sort((a, b) => scoreSlot(b) - scoreSlot(a));
}

function cpuMemberHeartCount(m) {
  if (!m || typeof memberEffectiveHeartGroups !== 'function') {
    return (m?.hearts || []).reduce((n, hg) => n + (hg.count || 1), 0);
  }
  return memberEffectiveHeartGroups(m).reduce((n, hg) => n + (hg.count || 1), 0);
}

/** Heart-deficit colors across hand Lives + live-zone (upcoming) Lives. */
function cpuNeededHeartColors(cpu, opts = null) {
  const needed = {};
  const { targets } = cpuUpcomingLiveTargets(cpu, opts);
  const pool = stageHeartPool(cpu);
  const used = pool.slice();
  targets.forEach(live => {
    const req = sortHeartRequirements(cpuLiveRequiredHearts(live) || []);
    for (const r of req) {
      const color = normalizeHeartColor(r.color);
      let need = r.count || 1;
      if (color === 'any') continue;
      for (let i = used.length - 1; i >= 0 && need > 0; i--) {
        if (used[i] === color) {
          used.splice(i, 1);
          need--;
        }
      }
      if (need > 0) needed[color] = (needed[color] || 0) + need;
    }
  });
  return needed;
}

/** Lives that still need hearts: hand + live storage/deck the CPU already sees. */
function cpuUpcomingLiveTargets(cpu, opts = null) {
  const liveCtx = cpuHandLiveContext(cpu, opts);
  const zone = (cpu?.live_zone || []).filter(c => c && isCpuLiveCard(c));
  const pool = liveCtx.pool || stageHeartPool(cpu);
  const targets = [...(liveCtx.unviableLives || [])];
  const seen = new Set(targets.map(c => c.instance_id).filter(Boolean));
  const zoneUnviable = [];
  zone.forEach(c => {
    if (c.instance_id && seen.has(c.instance_id)) return;
    if (!cpuCheckHearts(pool, cpuLiveRequiredHearts(c))) {
      zoneUnviable.push(c);
      targets.push(c);
      if (c.instance_id) seen.add(c.instance_id);
    }
  });
  return { targets, liveCtx, zoneLives: zone, zoneUnviable };
}

function cpuLiveUnlockAtSlot(member, cpu, tier, hand, slot) {
  if (tier === 'easy' || !member || !slot) return 0;
  const { targets } = cpuUpcomingLiveTargets(cpu, { tier });
  if (!targets.length) return 0;
  const pool = cpuSimHeartPoolAfterMember(cpu, member, slot);
  const livePriority = (live) => {
    const printed = live.score || 0;
    const meta = typeof cpuMetaLiveWeight === 'function'
      ? cpuMetaLiveWeight(live, tier, cpuWinPressure(cpu)) : 0;
    return printed * 2 + meta;
  };
  let unlocked = 0;
  let bestPri = 0;
  targets.forEach(live => {
    if (cpuCheckHearts(pool, cpuLiveRequiredHearts(live))) {
      unlocked++;
      bestPri = Math.max(bestPri, livePriority(live));
    }
  });
  if (!unlocked) return 0;
  return cpuLiveUnlockBonusValue(unlocked, Math.max(0, bestPri / 2.2), tier);
}

/**
 * Board delta for replacing `existing` with `incoming` on `slot` (baton or hard replace).
 * Favors heart/blade upgrades and colors that unlock hand or live-zone Lives.
 */
function cpuScoreBatonBoardDelta(incoming, existing, slot, cpu, hand, tier) {
  if (!incoming || !existing) return 0;
  const hard = cpuTierHardPlus(tier);
  const mul = hard ? 1 : tier === 'normal' ? 0.85 : 0.55;
  let delta = 0;
  const bladeDelta = (incoming.blade || 0) - (existing.blade || 0);
  delta += bladeDelta * (hard ? 1.45 : 1.0) * mul;

  const heartDelta = cpuMemberHeartCount(incoming) - cpuMemberHeartCount(existing);
  delta += heartDelta * (hard ? 1.9 : 1.25) * mul;

  const unlockBefore = cpuLiveUnlockAtSlot(existing, cpu, tier, hand, slot);
  const unlockAfter = cpuLiveUnlockAtSlot(incoming, cpu, tier, hand, slot);
  const unlockGain = Math.max(0, unlockAfter - unlockBefore);
  delta += unlockGain * (hard ? 1.15 : 1);

  const needed = cpuNeededHeartColors(cpu, { tier });
  const addColors = (m, sign) => {
    if (typeof memberEffectiveHeartGroups !== 'function') {
      (m?.hearts || []).forEach(hg => {
        const c = normalizeHeartColor(hg.color);
        if (needed[c]) delta += sign * (needed[c] || 0) * (hard ? 1.6 : 1.1) * mul * (hg.count || 1);
      });
      return;
    }
    memberEffectiveHeartGroups(m).forEach(hg => {
      const c = normalizeHeartColor(hg.color);
      if (!needed[c]) return;
      delta += sign * needed[c] * (hard ? 1.6 : 1.1) * mul * (hg.count || 1);
    });
  };
  addColors(incoming, 1);
  addColors(existing, -0.65);

  const incCost = incoming.cost || 0;
  const existCost = stageMemberEffectiveCost(existing, cpu);
  const ladderUp = (cpuCostNearLadderAnchor(existCost, 4) && cpuCostNearLadderAnchor(incCost, 9))
    || (cpuCostNearLadderAnchor(existCost, 9) && cpuCostNearLadderAnchor(incCost, 15));
  const qualityUp = bladeDelta > 0 || heartDelta > 0 || unlockGain > 0.4;
  if (ladderUp) {
    delta += qualityUp
      ? (hard ? 2.4 : 1.5) * mul
      : (hard ? 0.45 : 0.25) * mul;
  }

  // Do not baton for ladder alone when the board gets worse.
  if (bladeDelta < 0 && heartDelta <= 0 && unlockGain <= 0.2) {
    delta -= (hard ? 5.2 : 3.6) * mul;
  } else if (bladeDelta === 0 && heartDelta === 0 && unlockGain <= 0.2 && !ladderUp) {
    delta -= (hard ? 2.2 : 1.5) * mul;
  }
  return delta;
}

function cpuMemberBatonLadderBonus(c, cpu, hand, tier, s, read) {
  if (!s || tier === 'easy' || !isCpuMemberCard(c)) return 0;
  const turn = s.turn || 1;
  const rawCost = c.cost || 0;
  const ec = effectiveCost(c, hand ?? cpu.hand);
  const ae = cpuActiveEnergy(cpu);
  const maxStage = cpuMaxStageCost(cpu);
  const plan = cpuBatonLadderPlan(turn, maxStage, cpu);
  const mul = cpuTierHardPlus(tier) ? 1 : 0.78;
  let bonus = 0;
  const behind = read && (read.successCount ?? 0) > (cpu.success_lives?.length ?? 0);
  const liveCtx = cpuHandLiveContext(cpu);
  const wantsBoard = liveCtx.liveInHand.length > 0 || behind;

  if ((plan.phase === 'setup4' || plan.phase === 'hold4') && cpuStageHasEmptySlot(cpu)) {
    if (cpuCostNearLadderAnchor(rawCost, 4)) bonus += (cpuTierHardPlus(tier) ? 3.2 : 2.4) * mul;
    else if (rawCost >= 7 && turn <= 2) bonus -= cpuTierHardPlus(tier) ? 1.4 : 0.9;
  }

  if (plan.phase === 'baton49' || (turn >= 3 && maxStage >= 4 && maxStage < 9)) {
    if (cpuCostNearLadderAnchor(rawCost, 9)) {
      const anchor4 = cpuStageBatonAnchors(cpu).find(a => cpuCostNearLadderAnchor(a.cost, 4));
      if (anchor4) {
        const batonPay = Math.max(0, ec - anchor4.cost);
        if (batonPay <= ae) bonus += (cpuTierHardPlus(tier) ? 3.4 : 2.0) * mul;
        else if (batonPay <= ae + 1) bonus += (cpuTierHardPlus(tier) ? 1.8 : 0.95) * mul;
      } else if (ae >= ec) bonus += (cpuTierHardPlus(tier) ? 1.6 : 0.85) * mul;
    }
  }

  if (plan.phase === 'baton915' || (turn >= 4 && maxStage >= 9)) {
    if (cpuCostNearLadderAnchor(rawCost, 15)) {
      const anchor9 = cpuStageBatonAnchors(cpu).find(a => cpuCostNearLadderAnchor(a.cost, 9));
      if (anchor9) {
        const batonPay = Math.max(0, ec - anchor9.cost);
        if (batonPay <= ae) bonus += (cpuTierHardPlus(tier) ? 3.8 : 2.3) * mul;
        else if (batonPay <= ae + 1) bonus += (cpuTierHardPlus(tier) ? 2.0 : 1.1) * mul;
      }
    }
  }

  if (wantsBoard && cpuCostNearLadderAnchor(rawCost, plan.playTarget)) {
    bonus += (cpuTierHardPlus(tier) ? 1.4 : 0.95) * mul;
  }
  if (behind && (c.blade || 0) >= 3 && cpuCostNearLadderAnchor(rawCost, plan.playTarget)) {
    bonus += cpuTierHardPlus(tier) ? 1.2 : 0.75;
  }

  (c.abilities || []).forEach(ab => {
    if (ab.trigger === 'on_live_success' || ab.trigger === 'on_enter') {
      if (wantsBoard && cpuCostNearLadderAnchor(rawCost, plan.playTarget)) {
        bonus += cpuTierHardPlus(tier) ? 0.55 : 0.35;
      }
    }
  });

  return bonus;
}

function cpuAbilityLadderEnergyBonus(type, cpu, tier, s) {
  if (!s || tier === 'easy' || !type) return 0;
  const plan = cpuBatonLadderPlan(s.turn || 1, cpuMaxStageCost(cpu), cpu);
  if (plan.phase !== 'baton49' && plan.phase !== 'baton915') return 0;
  const t = type;
  if (t.includes('activate_energy') || t === 'energy_wait_from_deck' || t === 'pay_energy_draw'
      || t === 'optional_pay_energy' || t.includes('energy')) {
    const ae = cpuActiveEnergy(cpu);
    const need = plan.phase === 'baton915' ? 6 : 5;
    if (ae < need) return cpuTierHardPlus(tier) ? 1.35 : 0.85;
  }
  return 0;
}

function cpuTierAbilityMul(tier) {
  return { easy: 0.45, normal: 1, hard: 1.35, expert: 1.55 }[tier] || 1;
}

function cpuAbilityBaseScores() {
  return {
    draw: 3.2, draw_cards: 3.2, draw_and_discard: 2.8, draw_discard: 2.8,
    baton_enter_draw_discard: 3, draw_per_stage_discard: 2.6, draw_if_stage_cost_min: 2.4,
    draw_if_stage_cost_less_than_opp: 2.5, draw_if_stage_cost_less_surveil: 3.2,
    draw_per_stage_group_discard: 2.7,
    deck_surveil: 3.4, look_reveal_filter: 4.2, look_reveal_group: 3.8, look_reveal_named: 3.8,
    look_reveal_live_score_plus: 4, look_deck_top_arrange: 4.5, look_top_optional_wr: 2.8,
    add_from_wr: 3.8, add_from_wr_max_cost: 3.5, add_from_waiting_room: 3.6,
    leave_stage_add_from_wr: 3.6, pay_energy_add_from_wr: 3.5, discard_hand_add_live_from_wr: 4,
    wait_self_add_wr: 3.2, wait_self_discard_add_wr_live: 3.8, shuffle_named_from_waiting: 2.8,
    optional_discard_add_from_wr: 3.5, pay_energy_play_wr_empty: 3.2, pay_leave_stage_play_wr_member: 3,
    wait_opponent_stage_max_cost: 4, wait_self_draw_discard: 3.5, wait_self_draw_discard_activate: 4,
    wait_self_discard_draw: 3.2, optional_wait_self_wait_opp: 2.8, optional_wait_subunit_opp_active: 3.2,
    wait_self_choose_heart: 2.6, put_opponent_active_into_wait: 3.8,
    activated_wait_opp_reduce_cost_per_group: 3, blade_bonus: 2.6, member_blade_bonus: 2.8,
    live_score_bonus: 3.2, hand_discard_for_stage_blade: 2.4, blade_if_entered_or_moved: 2.2,
    blade_per_opp_wait: 3, blade_per_other_subunit: 2.8, blade_per_stage_excl_subunit: 2.6,
    hearts_and_blade_bonus: 2.8, activate_energy: 2.8, pay_energy_draw: 3.2,
    pay_energy_surveil: 3.4, optional_pay_energy: 2.4, energy_wait_from_deck: 2.6,
    activate_energy_if_success: 2.2, activate_energy_if_other_group: 2.4,
    activate_energy_if_other_subunit: 2.4, activate_subunit_members: 2.6,
    hand_cost_reduction: 2, if_baton_lower_cost: 1.8,
    continuous_hearts_in_slot: 2.2, continuous_mus_blade_if_live_zone: 2.4, yell_hearts_wildcard: 2,
    hearts_if_min_energy: 1.8, on_enter_side_area: 2.4, optional_live_start: 3,
    live_success_pick_yell_card: 2.8, optional_success_live_swap: 3.2, score_if_stage_member_hearts: 2.4,
    score_if_live_zone_min: 3.4, score_if_live_zone_group: 3.2,
    blade_if_live_zone_group_live: 3.5, blade_if_live_zone_min: 3.2,
    activated_swap_area_member: 2.6, activated_pay_discard_add_wr_live: 3.6, activated_swap_area: 2.4,
    activated_leave_play_wr_same_slot: 3.4, activated_pick_on_enter_ability: 2.8,
    discard_cost_add_live_subunit: 3.2, optional_discard_prompt: 2.2, optional_discard_hand: 2,
    optional_discard_surveil: 3.2, optional_wait_self_surveil: 3.4, optional_wait_self_look_reveal: 3.6,
    optional_discard_look_reveal_subunit: 3.4, optional_stage_reposition: 2, optional_pay_play_hand_member: 3.5,
    optional_wr_member_reenter: 3, mill_deck_to_wr: 2, optional_wait_self: 2.6, optional_wait_self_add_wr: 3,
    optional_wait_members_draw: 3.2, optional_pay_energy_on_enter: 2.6, optional_pay_energy_if_baton: 2.4,
    optional_wait_self_discard_look_reveal_group: 3.8, optional_wait_discard_look_reveal_group: 3.6,
    optional_pay_energy_add_from_wr: 3.6, optional_ema_punch_ask: 2.8,
    optional_opp_wr_members_to_deck_bottom_then_wait: 3.4,
    mill_fill_wr_optional_live_deck_top: 3.2, pr_vol9_wait_opp_max_blade: 3.8,
    choose_heart_per_success: 2, player_choice: 2.4, reveal_top_live_score: 2.8,
    live_start_all_stage_group_bonus: 3, live_score_if_full_stage_distinct_group: 3.2,
    live_score_if_stage_group_hearts_total: 2.8, live_score_per_stage_wait_member: 2.6,
    grant_live_success_yell_live_score_if_full_stage: 3.4,
    pick_stage_member: 2.8,
  };
}

function cpuLookupAbilityBase(type) {
  if (!type) return 1.2;
  const table = cpuAbilityBaseScores();
  if (table[type] != null) return table[type];
  if (type.includes('surveil') || type.includes('look_reveal') || type.includes('look_deck')) return 3.4;
  if (type.includes('draw')) return 2.8;
  if (type.includes('add_from_wr') || type.includes('waiting') || type.includes('_wr_')) return 3.2;
  if (type.includes('wait_opp') || type.includes('wait_opponent')) return 3.4;
  if (type.includes('blade')) return 2.4;
  if (type.includes('live_score') || type.includes('live_success')) return 2.8;
  if (type.includes('pay_energy')) return 2.4;
  if (type.includes('discard')) return 1.8;
  if (type.includes('heart')) return 2;
  if (type.includes('energy')) return 2.2;
  if (type.startsWith('optional_')) return 2;
  if (type.startsWith('activated_')) return 2.2;
  if (type.startsWith('wait_self_')) return 2.8;
  return 1.2;
}

function cpuAbilityCtx(cpu, tier, read, winPressure, ae, sit) {
  return {
    cpu, tier, read, winPressure,
    ae: ae ?? (cpu?.energy_zone || []).filter(energyChipActive).length,
    sit, hand: cpu?.hand || [],
  };
}

function cpuScoreAbilityType(ab, tier, ctx) {
  if (!ab?.type) return 0;
  const { read, winPressure, cpu, ae, sit } = ctx || {};
  const t = ab.type;
  let score = cpuLookupAbilityBase(t) * cpuTierAbilityMul(tier);
  if (ab.then) score += cpuScoreThenEffect(ab.then, tier) * 0.55;
  const discardCost = ab.discard || ab.max_discard || 0;
  if (discardCost) score -= discardCost * (cpuTierHardPlus(tier) ? 0.28 : 0.38);
  const payCost = ab.cost || 0;
  if (payCost) {
    score -= payCost * (cpuTierHardPlus(tier) ? 0.14 : 0.22);
    if (ae != null && ae < payCost) score -= 2.5;
  }
  if (read) {
    if (t.includes('wait') && read.activeStage.length) {
      score += read.activeStage.length * (cpuTierHardPlus(tier) ? 0.5 : 0.32);
    }
    if (t === 'wait_opponent_stage_max_cost' || ab.then?.type === 'wait_opponent_stage_max_cost') {
      const maxCost = ab.max_cost ?? ab.then?.max_cost ?? 4;
      const hits = read.activeStage.filter(x => x.cost <= maxCost).length;
      score += hits * (cpuTierHardPlus(tier) ? 1.15 : 0.65);
    }
    if ((t.includes('draw') || t.includes('surveil') || t.includes('look_reveal')) && (sit?.mustCatchUp || sit?.behind)) {
      score += cpuTierHardPlus(tier) ? 1.1 : 0.6;
    }
    if (t.includes('blade') && read.oppRichBoard) score += cpuTierHardPlus(tier) ? 0.85 : 0.4;
    if (t.includes('live_score') && (winPressure >= 0.45 || read.successCount >= 1)) {
      score += cpuTierHardPlus(tier) ? 1 : 0.55;
    }
    if ((t.includes('wr') || t.includes('waiting')) && (cpu?.waiting_room || []).length) {
      score += Math.min(3, (cpu.waiting_room || []).length) * (cpuTierHardPlus(tier) ? 0.35 : 0.2);
    }
  }
  if (winPressure >= 0.45 && (t.includes('draw') || t.includes('blade') || t.includes('live_score'))) {
    score += winPressure * (cpuTierHardPlus(tier) ? 1.2 : 0.65);
  }
  if (tier !== 'easy' && cpu) {
    score += cpuAbilityLadderEnergyBonus(t, cpu, tier, G.gameState);
  }
  if (ab.trigger === 'continuous') score *= 0.82;
  return score;
}

function cpuScoreMemberPassiveAbilities(c, tier, ctx) {
  let bonus = 0;
  (c.abilities || []).forEach(ab => {
    const tr = ab.trigger || '';
    if (tr === 'on_enter') bonus += cpuScoreAbilityType(ab, tier, ctx);
    else if (tr === 'continuous' || tr === 'on_baton') bonus += cpuScoreAbilityType(ab, tier, ctx) * 0.78;
    else if (tr === 'on_live_success') bonus += cpuScoreAbilityType(ab, tier, ctx) * 0.65;
  });
  return bonus;
}

function cpuScoreLiveCardAbilities(c, tier, ctx) {
  let bonus = 0;
  (c.abilities || []).forEach(ab => {
    const tr = ab.trigger || '';
    if (tr === 'on_live_start' || tr === 'on_set') bonus += cpuScoreAbilityType(ab, tier, ctx) * 0.95;
    else if (tr === 'continuous' || tr === 'on_judge') bonus += cpuScoreAbilityType(ab, tier, ctx) * 0.75;
  });
  return bonus;
}

function cpuScoreMember(c, cpu, hand, stageColors, tier, read = null, s = null) {
  const state = s ?? G.gameState;
  const novelty = cpuMemberNovelty(c, stageColors);
  const ec = effectiveCost(c, hand);
  const blade = c.blade || 0;
  const winPressure = cpuWinPressure(cpu);
  const sit = read ? {
    mustCatchUp: (read.successCount ?? 0) > (cpu.success_lives?.length ?? 0),
    behind: (read.successCount ?? 0) > (cpu.success_lives?.length ?? 0),
  } : null;
  const ctx = cpuAbilityCtx(cpu, tier, read, winPressure, null, sit);
  let score = novelty * (cpuTierHardPlus(tier) ? 4.5 : 3) + blade * (cpuTierHardPlus(tier) ? 1.2 : 0.85) - ec * 0.35;
  score += cpuScoreMemberPassiveAbilities(c, tier, ctx);
  if (typeof cpuMetaMemberWeight === 'function') {
    score += cpuMetaMemberWeight(c, tier);
  }
  if (typeof cpuPolicyCardBonus === 'function') {
    score += cpuPolicyCardBonus(c, 'members', tier);
  }
  if (typeof cpuPolicyPlayBias === 'function') {
    score += cpuPolicyPlayBias(c, tier, sit);
  }
  if (typeof cpuActionNetAdjust === 'function') {
    score += cpuActionNetAdjust(tier, cpuActionNetSeat(cpu, read, state, {
      kind: 'play_member',
      cost: ec,
      blade,
      canClear: false,
    }));
  }
  if (cpuTierHardPlus(tier) && (cpu.success_lives || []).length >= 2) score += ec * 0.15;
  if (read) {
    if (read.oppRichBoard || read.totalBlade >= 5) score += blade * (cpuTierHardPlus(tier) ? 0.45 : 0.28);
    const ladderPlan = tier !== 'easy' && state
      ? cpuBatonLadderPlan(state.turn || 1, cpuMaxStageCost(cpu), cpu) : null;
    const setupFour = ladderPlan && (ladderPlan.phase === 'setup4' || ladderPlan.phase === 'hold4');
    if (read.stageBlade <= 2 && ec >= 4 && !setupFour) score -= cpuTierHardPlus(tier) ? 0.35 : 0.2;
    if (read.oppLowResources && ec <= 3) score += cpuTierHardPlus(tier) ? 0.6 : 0.35;
    const oppCenter = read.activeStage.find(x => x.slot === 'center');
    if (oppCenter && blade > oppCenter.blade + 1) score += cpuTierHardPlus(tier) ? 1.1 : 0.65;
  }
  if (tier !== 'easy') {
    score += cpuMemberLiveUnlockBonus(c, cpu, tier, hand);
    score += cpuMemberBatonLadderBonus(c, cpu, hand, tier, state, read);
  }
  return score;
}

function cpuWinPressure(cpu) {
  const wins = (cpu?.success_lives || []).length;
  if (wins >= 2) return 1;
  if (wins === 1) return 0.45;
  return 0;
}

function cpuHandHasViableLive(cpu, opts = null) {
  return cpuHandLiveContext(cpu, opts).hasViableLive;
}

function cpuLookupYellCard(card) {
  if (!card) return card;
  if (card.blade_hearts?.length || card.abilities?.length) return card;
  const no = card.card_no;
  const cat = (no && G?.allCards?.[no]) || null;
  if (cat) return { ...cat, ...card };
  return card;
}

function cpuCardBladeHeartColors(card) {
  const src = cpuLookupYellCard(card);
  if (typeof cardYellBladeHeartColors === 'function') {
    return cardYellBladeHeartColors(src).map(normalizeHeartColor);
  }
  const colors = [];
  (src?.blade_hearts || []).forEach((bh) => {
    if (typeof bh === 'string') {
      if (bh === 'draw' || bh === 'score') return;
      colors.push(normalizeHeartColor(bh));
      return;
    }
    const t = bh?.type || bh?.color || '';
    if (t === 'draw' || t === 'score') return;
    colors.push(normalizeHeartColor(bh.color || bh.type || 'any'));
  });
  return colors;
}

function cpuLiveHasYellWildcard(card) {
  return (cpuLookupYellCard(card)?.abilities || []).some((ab) => ab?.type === 'yell_hearts_wildcard');
}

/** Hearts a live-start skill is likely to add before the heart check. */
function cpuLiveStartSkillHeartPool(cpu) {
  const extra = [];
  Object.values(cpu?.stage || {}).forEach((m) => {
    (m?.abilities || []).forEach((ab) => {
      const type = String(ab?.type || '');
      const trigger = String(ab?.trigger || '');
      if (!/heart/.test(type)) return;
      if (trigger !== 'on_live_start' && trigger !== 'live_start' && !/live_start/.test(type)) return;
      const n = Math.min(2, Number(ab.amount || ab.count || 1) || 1);
      const color = normalizeHeartColor(ab.color || ab.heart || 'any');
      for (let i = 0; i < n; i++) extra.push(color);
    });
  });
  return extra;
}

/**
 * Expected blade-hearts from milling `blade` cards of the remaining deck.
 * Uses printed blade hearts on own main_deck, not a flat wild guess.
 */
function cpuExpectedYellHeartPool(cpu, s, pid, tier) {
  if (tier === 'easy') return [];
  const blades = typeof estimateYellBlade === 'function' ? estimateYellBlade(s, pid) : 0;
  if (blades <= 0) return cpuLiveStartSkillHeartPool(cpu);
  const deck = Array.isArray(cpu?.main_deck) ? cpu.main_deck.filter(Boolean) : [];
  const mill = Math.min(blades, deck.length);
  const colors = {};
  deck.forEach((c) => {
    cpuCardBladeHeartColors(c).forEach((col) => {
      colors[col] = (colors[col] || 0) + 1;
    });
  });
  const factor = tier === 'expert' ? 1 : tier === 'hard' ? 0.85 : 0.55;
  const extraAt = tier === 'expert' ? 0.4 : tier === 'hard' ? 0.62 : 0.9;
  const pool = [];
  if (deck.length && mill > 0 && Object.keys(colors).length) {
    Object.entries(colors).forEach(([color, n]) => {
      const expected = mill * (n / deck.length) * factor;
      let take = Math.floor(expected + 1e-9);
      if (expected - take >= extraAt) take += 1;
      take = Math.min(take, n);
      for (let i = 0; i < take; i++) pool.push(color);
    });
  } else if (cpuTierHardPlus(tier)) {
    const fallback = Math.floor(blades * (tier === 'expert' ? 0.35 : 0.25));
    for (let i = 0; i < fallback; i++) pool.push('any');
  }
  return pool.concat(cpuLiveStartSkillHeartPool(cpu));
}

function cpuYellBackedPool(cpu, s, pid, tier, live) {
  const stage = stageHeartPool(cpu);
  const yell = cpuExpectedYellHeartPool(cpu, s, pid, tier);
  const hearts = cpuLiveHasYellWildcard(live) ? yell.map(() => 'any') : yell;
  return stage.concat(hearts);
}

function cpuLiveStageOrYellClear(c, cpu, s, pid, tier) {
  const req = cpuLiveRequiredHearts(c);
  if (cpuCheckHearts(stageHeartPool(cpu), req)) return { stage: true, yell: true };
  const yell = cpuCheckHearts(cpuYellBackedPool(cpu, s, pid, tier, c), req);
  return { stage: false, yell };
}

function cpuExpectedYellAnyHearts(s, pid, tier) {
  if (tier === 'easy') return 0;
  const blades = typeof estimateYellBlade === 'function' ? estimateYellBlade(s, pid) : 0;
  const factor = tier === 'expert' ? 0.72 : tier === 'hard' ? 0.58 : 0.38;
  return Math.max(0, Math.floor(blades * factor));
}

/** Stage hearts + expected Yell hearts from the remaining deck and live-start skills. */
function cpuClearHeartPool(cpu, s, pid, tier) {
  const pool = stageHeartPool(cpu).slice();
  const resolvedPid = pid || (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2');
  const resolvedTier = tier || cpuDiff();
  return pool.concat(cpuExpectedYellHeartPool(cpu, s || G.gameState, resolvedPid, resolvedTier));
}

function cpuHandLiveContext(cpu, opts = null) {
  const hand = cpu?.hand || [];
  const tier = opts?.tier || (typeof cpuDiff === 'function' ? cpuDiff() : 'normal');
  const s = opts?.s || G.gameState;
  const pid = opts?.pid || (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2');
  const stagePool = stageHeartPool(cpu);
  const clearPool = cpuTierHardPlus(tier) || tier === 'normal'
    ? cpuClearHeartPool(cpu, s, pid, tier)
    : stagePool;
  const liveInHand = hand.filter(c => isCpuLiveCard(c));
  const stageViable = liveInHand.filter(c => cpuCheckHearts(stagePool, cpuLiveRequiredHearts(c)));
  const clearViable = liveInHand.filter(c => cpuCheckHearts(clearPool, cpuLiveRequiredHearts(c)));
  // Prefer Lives that clear on stage alone; still treat Yell-expected clears as viable on Hard+.
  const viableLives = (cpuTierHardPlus(tier) ? clearViable : stageViable.length ? stageViable : clearViable);
  const unviableLives = liveInHand.filter(c => !cpuCheckHearts(clearPool, cpuLiveRequiredHearts(c)));
  return {
    pool: clearPool,
    stagePool,
    liveInHand,
    viableLives,
    stageViable,
    unviableLives,
    hasViableLive: viableLives.length > 0,
    needsLives: liveInHand.length === 0,
    needsHeartsForLives: unviableLives.length > 0 && !viableLives.length,
    yellExpected: Math.max(0, clearPool.length - stagePool.length),
  };
}

function cpuSimHeartPoolAfterMember(cpu, member, slot) {
  const stage = {};
  Object.entries(cpu.stage || {}).forEach(([k, v]) => {
    if (v && k !== slot) stage[k] = v;
  });
  if (slot) stage[slot] = member;
  return stageHeartPool({ stage });
}

function cpuLiveUnlockBonusValue(unlockedCount, bestLiveScore, tier) {
  if (!unlockedCount) return 0;
  const mul = cpuTierHardPlus(tier) ? 1 : 0.72;
  let bonus = (cpuTierHardPlus(tier) ? 2.2 : 1.6) + (bestLiveScore || 0) * (cpuTierHardPlus(tier) ? 1.4 : 1.0);
  if (unlockedCount > 1) bonus += (unlockedCount - 1) * (cpuTierHardPlus(tier) ? 1.1 : 0.75);
  return bonus * mul;
}

function cpuMemberLiveUnlockBonus(c, cpu, tier, hand) {
  if (tier === 'easy') return 0;
  const { targets } = cpuUpcomingLiveTargets(cpu, { tier });
  if (!targets.length) return 0;
  const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
  const ec = effectiveCost(c, hand ?? cpu.hand);
  const livePriority = (live) => {
    const printed = live.score || 0;
    const meta = typeof cpuMetaLiveWeight === 'function' ? cpuMetaLiveWeight(live, tier, cpuWinPressure(cpu)) : 0;
    const pol = typeof cpuPolicyCardBonus === 'function' ? cpuPolicyCardBonus(live, 'lives', tier) : 0;
    return printed * 2 + meta + pol;
  };
  const scorePool = (pool) => {
    let unlocked = 0;
    let bestPri = 0;
    targets.forEach(live => {
      if (cpuCheckHearts(pool, cpuLiveRequiredHearts(live))) {
        unlocked++;
        bestPri = Math.max(bestPri, livePriority(live));
      }
    });
    // Map priority back onto the existing unlock bonus curve (approx score units).
    const bestLiveScore = Math.max(printedScoreFromPriority(bestPri), 0);
    return cpuLiveUnlockBonusValue(unlocked, bestLiveScore, tier);
  };
  function printedScoreFromPriority(pri) {
    // Prefer unlocking high-meta Lives even if printed score is modest.
    return Math.max(0, pri / 2.2);
  }
  let best = 0;
  for (const slot of ['center', 'left', 'right']) {
    const existing = cpu.stage?.[slot];
    if (!existing) {
      if (ec > ae) continue;
      best = Math.max(best, scorePool(cpuSimHeartPoolAfterMember(cpu, c, slot)));
      continue;
    }
    if (ec >= 1 && !memberBlocksBaton(existing) && !memberBatonRestricted(existing, c)) {
      const aeBaton = affordableEnergyForBatonPlay(cpu, existing, c);
      if (canAffordBatonWithOptionalDouble(cpu, c, slot, existing, aeBaton, ec)) {
        best = Math.max(best, scorePool(cpuSimHeartPoolAfterMember(cpu, c, slot)));
      }
    }
    if (ae >= ec) {
      best = Math.max(best, scorePool(cpuSimHeartPoolAfterMember(cpu, c, slot)));
    }
  }
  return best;
}

function cpuAnyMemberUnlocksHandLives(cpu, tier) {
  if (tier === 'easy') return false;
  const { targets } = cpuUpcomingLiveTargets(cpu, { tier });
  if (!targets.length) return false;
  const hand = cpu.hand || [];
  return hand.some(c => isCpuMemberCard(c) && cpuMemberLiveUnlockBonus(c, cpu, tier, hand) > 0);
}

function cpuWantsLiveSearch(cpu, tier) {
  if (tier === 'easy') return false;
  const liveCtx = cpuHandLiveContext(cpu);
  if (liveCtx.hasViableLive) return false;
  if (liveCtx.needsHeartsForLives && cpuAnyMemberUnlocksHandLives(cpu, tier)) return false;
  return liveCtx.needsLives || liveCtx.needsHeartsForLives;
}

function cpuAbilityFindsLives(type) {
  if (!type) return false;
  return type.includes('draw') || type.includes('surveil') || type.includes('look_reveal')
    || type.includes('look_deck') || type === 'reveal_top_live_score' || type === 'draw_until_hand'
    || type === 'pay_energy_draw' || type === 'pay_energy_surveil';
}

function cpuScoreLiveForSet(c, tier, winPressure, read = null, cpu = null) {
  const sit = read && cpu ? {
    mustCatchUp: (read.successCount ?? 0) > (cpu.success_lives?.length ?? 0),
    behind: (read.successCount ?? 0) > (cpu.success_lives?.length ?? 0),
  } : null;
  const ctx = cpu ? cpuAbilityCtx(cpu, tier, read, winPressure, null, sit) : { tier, read, winPressure, sit };
  let score = (c.score || 0) + (winPressure * 4);
  score += cpuScoreLiveCardAbilities(c, tier, ctx);
  if (typeof cpuMetaLiveWeight === 'function') {
    score += cpuMetaLiveWeight(c, tier, winPressure);
  }
  if (typeof cpuPolicyCardBonus === 'function') {
    score += cpuPolicyCardBonus(c, 'lives', tier);
  }
  if (tier !== 'easy' && cpu && typeof cpuLiveRaceAdvice === 'function') {
    const stagePool = stageHeartPool(cpu);
    const backed = cpuLiveStageOrYellClear(c, cpu, G.gameState, (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2'), tier);
    const clearable = backed.stage || backed.yell;
    const liveCtx = typeof cpuHandLiveContext === 'function'
      ? cpuHandLiveContext(cpu, { tier, s: G.gameState })
      : null;
    const advice = cpuLiveRaceAdvice({
      tier,
      mySuccess: (cpu.success_lives || []).length,
      oppSuccess: read?.successCount ?? 0,
      canClearStage: backed.stage || (liveCtx?.stageViable || []).some(x => x.instance_id === c.instance_id),
      canClearYell: backed.yell || !!(liveCtx?.hasViableLive),
      oppHearts: (read?.stageHearts || []).length,
      oppActive: read?.activeStage?.length || 0,
      oppVisibleScore: (read?.liveZoneRevealed || []).reduce((n, l) => n + (l.score || 0), 0),
    });
    if (advice.takeClear && backed.stage) score += 4 + (c.score || 0) * (advice.preferHighWhenBoth ? 2.2 : 1.1);
    else if (backed.yell) score += (cpuTierHardPlus(tier) ? 2.4 : 1.1) + (c.score || 0) * 0.8;
    if (advice.takeLowAtTwo && clearable) score += 8;
    if (advice.dropUnclearable && !clearable) score -= 6 + (c.score || 0);
    if (advice.preferHighWhenBoth && clearable) score += (c.score || 0) * 1.4;
    if (typeof cpuActionNetAdjust === 'function') {
      score += cpuActionNetAdjust(tier, cpuActionNetSeat(cpu, read, G.gameState, {
        kind: 'live_set',
        score: c.score || 0,
        canClear: backed.yell || backed.stage,
      }));
    }
  }
  if (cpuTierHardPlus(tier)) score += 0.85;
  else if (tier === 'normal') score += 0.4;
  if (read) {
    if (read.successCount > 0 || winPressure >= 0.45) score += (c.score || 0) * 0.35;
    if (read.totalBlade >= 7) score += (c.score || 0) * 0.25;
    if (read.wrLives.length) {
      const oppBestWr = Math.max(...read.wrLives.map(l => l.score || 0), 0);
      if (oppBestWr >= 2 && (c.score || 0) >= oppBestWr) score += cpuTierHardPlus(tier) ? 0.5 : 0.25;
    }
  }
  if (cpuTierHardPlus(tier) || tier === 'normal') {
    score += cpuLiveHeartEfficiency(c) * (cpuTierHardPlus(tier) ? 0.35 : 0.22);
    if ((c.score || 0) >= 2) score += cpuTierHardPlus(tier) ? 0.5 : 0.3;
    if (sit?.behind) score += (c.score || 0) * 0.4;
  }
  return score;
}

function cpuPickDiscardIds(hand, count, tier, winPressure = 0, read = null) {
  const n = Math.min(count || 0, (hand || []).length);
  if (!n) return [];
  const toIds = (cards) => cards.map(c => c.instance_id).filter(Boolean);
  if (tier === 'easy') return toIds(hand.slice(0, n));
  const protectLives = winPressure >= 0.35 || cpuTierHardPlus(tier) || (read?.successCount ?? 0) >= 1;
  const ranked = [...hand].map(c => {
    let keep = 0;
    if (c.card_type === 'メンバー' || c.card_type_en === 'Member') {
      keep += 3 + (c.blade || 0) + (4 - Math.min(4, c.cost || 0));
      if (typeof cpuMetaMemberWeight === 'function') keep += cpuMetaMemberWeight(c, tier);
    } else if (c.card_type === 'ライブ' || c.card_type_en === 'Live') {
      keep += (c.score || 0) * 1.5 + (protectLives ? 12 + winPressure * 8 : 0);
      if (typeof cpuMetaLiveWeight === 'function') keep += cpuMetaLiveWeight(c, tier, winPressure);
    } else keep += 1;
    return { c, keep };
  }).sort((a, b) => a.keep - b.keep);
  if (protectLives) {
    const nonLives = ranked.filter(x => x.c.card_type !== 'ライブ' && x.c.card_type_en !== 'Live');
    if (nonLives.length >= n) return toIds(nonLives.slice(0, n).map(x => x.c));
  }
  return toIds(ranked.slice(0, n).map(x => x.c));
}

/** First N hand instance_ids — used when heuristic pick fails but hand is large enough. */
function cpuHandDiscardFallback(hand, need) {
  if (!need || (hand || []).length < need) return [];
  return hand.slice(0, need).map(c => c.instance_id).filter(Boolean);
}

function cpuRandomLegalChoice(choices) {
  const legal = (choices || []).filter(c => c != null && c !== '');
  if (!legal.length) return null;
  return legal[Math.floor(Math.random() * legal.length)];
}

/**
 * Ordered resolve payloads for unknown / stuck prompts — cycle on failures
 * before anti_softlock_skip force-dismisses the skill dialogue.
 */
function cpuEnumeratePromptResolvePayloads(pr, cpu, tier, winPressure, read) {
  const payloads = [];
  const seen = new Set();
  const push = (data) => {
    if (!data || typeof data !== 'object') return;
    const key = JSON.stringify(data);
    if (seen.has(key)) return;
    seen.add(key);
    payloads.push(data);
  };
  const hand = cpuLiveHand(cpu);
  const discardNeed = pr.discard_count || pr.count || pr.bottom_count
    || (pr.step === 'discard' || pr.step === 'discard_hand' || pr.step === 'pick_discard'
      ? (pr.ability?.discard || pr.ability?.max_discard || 1) : 0)
    || (pr.ability?.discard || pr.ability?.max_discard || 0);

  if (discardNeed > 0) {
    let pool = hand;
    if (pr.ability?.filter === 'live') pool = hand.filter(c => isCpuLiveCard(c));
    else if (pr.ability?.filter === 'member') pool = hand.filter(c => isCpuMemberCard(c));
    let ids = cpuPickDiscardIds(pool, discardNeed, tier, winPressure, read);
    if (ids.length < discardNeed) ids = cpuHandDiscardFallback(pool, discardNeed);
    if (ids.length >= discardNeed) {
      const slice = ids.slice(0, discardNeed);
      if (pr.pick_mode === 'deck_top') push({ card_ids: slice });
      else {
        push({ discard_ids: slice });
        if (pr.choices?.includes('yes')) push({ choice: 'yes', discard_ids: slice });
      }
    }
  }

  if (pr.type === 'surveil_arrange' || String(pr.type || '').startsWith('surveil_')) {
    try {
      push(typeof cpuSurveilConfirmPayload === 'function'
        ? cpuSurveilConfirmPayload(pr, cpu, tier)
        : null);
    } catch (e) { /* ignore */ }
    const looked = pr.looked_cards || pr.look_cards || [];
    const allIds = looked.map(c => c?.instance_id).filter(Boolean);
    if (allIds.length) push({ choice: 'confirm', top_ids: allIds, wr_ids: [] });
  }

  for (const prefer of ['skip', 'no', 'cancel', 'confirm']) {
    if (pr.choices?.includes(prefer)) push({ choice: prefer });
  }
  for (const choice of (pr.choices || [])) {
    if (choice == null || choice === '') continue;
    push({ choice });
  }

  const slotCands = pr.self_candidates || pr.candidates || pr.stage_members || [];
  for (const c of slotCands) {
    const slot = c?.slot;
    const id = c?.instance_id;
    if (slot) {
      push({ slot });
      push({ target_slot: slot });
      if (pr.choices?.includes('yes')) push({ choice: 'yes', target_slot: slot });
    }
    if (id) {
      const payload = { card_id: id };
      if (slot) payload.slot = slot;
      push(payload);
      push({ member_id: id });
      push({ pick_id: id });
    }
  }
  for (const c of (pr.look_cards || pr.looked_cards || pr.candidates || [])) {
    const id = c?.instance_id;
    if (!id) continue;
    push({ card_id: id });
  }
  const pickNeed = Math.max(0, pr.pick_count || pr.max_pick || 0);
  if (pickNeed > 1) {
    const ids = (pr.candidates || pr.look_cards || [])
      .map(c => c?.instance_id).filter(Boolean).slice(0, pickNeed);
    if (ids.length) push({ card_ids: ids, card_id: ids[0] });
  }
  for (const id of (pr.eligible_ids || [])) {
    if (id) push({ card_id: id });
  }
  for (const slot of (pr.target_slots || pr.slots || [])) {
    if (slot) push({ slot });
  }
  return payloads;
}

function cpuPromptAttemptIndex(key) {
  if (!key) return 0;
  if (!G._cpuPromptAttemptByKey) G._cpuPromptAttemptByKey = Object.create(null);
  return G._cpuPromptAttemptByKey[key] || 0;
}

function cpuBumpPromptAttempt(key) {
  if (!key) return 0;
  if (!G._cpuPromptAttemptByKey) G._cpuPromptAttemptByKey = Object.create(null);
  const next = (G._cpuPromptAttemptByKey[key] || 0) + 1;
  G._cpuPromptAttemptByKey[key] = next;
  return next;
}

function cpuResetPromptAttempts(key) {
  if (!G._cpuPromptAttemptByKey) return;
  if (key) delete G._cpuPromptAttemptByKey[key];
  else G._cpuPromptAttemptByKey = Object.create(null);
}

/**
 * Submit the next unused payload for this prompt key, or anti_softlock_skip.
 * Returns true when an action was submitted.
 */
function cpuTryCyclingPromptResolve(pr, cpu, tier, winPressure, read, s) {
  const cpuId = typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2';
  if (!pr || pr.responder !== cpuId) return false;
  const key = typeof cpuPromptKey === 'function' ? cpuPromptKey(s || G.gameState) : '';
  const payloads = cpuEnumeratePromptResolvePayloads(pr, cpu, tier, winPressure, read);
  const idx = cpuPromptAttemptIndex(key);
  if (idx < payloads.length) {
    const data = payloads[idx];
    cpuBumpPromptAttempt(key);
    TCG_DEBUG.warn('cpu', 'cycle prompt resolve', {
      type: pr.type, step: pr.step, attempt: idx + 1, of: payloads.length, data,
    });
    cpuAct('resolve_prompt', data);
    return true;
  }
  TCG_DEBUG.warn('cpu', 'cycle exhausted → anti_softlock_skip', {
    type: pr.type, step: pr.step, tried: payloads.length,
  });
  if (key) cpuResetPromptAttempts(key);
  cpuAct('anti_softlock_skip', {});
  return true;
}

/**
 * Last-resort CPU prompt resolver: pick any legal option (heuristic first, then cycle).
 * Returns true when an action was submitted.
 */
function cpuGenericPromptFallback(pr, cpu, tier, winPressure, read, s) {
  return cpuTryCyclingPromptResolve(pr, cpu, tier, winPressure, read, s);
}

/**
 * BP07 (Mellow Moment) prompts. The server uses five generic shapes (`bp7_confirm`,
 * `bp7_pick_cards`, `bp7_pick_stage_member`, `bp7_pick_slot`, `bp7_choose_player`),
 * so one resolver covers every new card instead of one branch per ability.
 *
 * Card picks are scored with the shared surveil heuristic. When every candidate is
 * already in the CPU's hand the pick is a cost (discard / reveal-and-bin), so the
 * ranking is inverted and the CPU gives up its least useful cards.
 */
function cpuResolveBp7Prompt(s, cpu, pr, tier, winPressure, read) {
  if (!pr || !String(pr.type || '').startsWith('bp7_')) return false;
  const hand = cpuLiveHand(cpu);

  if (pr.type === 'bp7_pick_stage_member') {
    const cands = (pr.candidates || []).filter((c) => c && (c.slot || c.instance_id));
    if (!cands.length) {
      cpuAct('resolve_prompt', { choice: 'skip' });
      return true;
    }
    // Prefer the highest-Blade Member: BP07 stage picks grant Blade / stack cards.
    const best = [...cands].sort((a, b) => (Number(b.blade || 0) - Number(a.blade || 0))
      || (Number(b.cost || 0) - Number(a.cost || 0)))[0];
    cpuAct('resolve_prompt', { card_id: best.instance_id, slot: best.slot || '' });
    return true;
  }

  if (pr.type === 'bp7_pick_cards') {
    const cands = (pr.candidates || []).filter((c) => c?.instance_id);
    const min = Math.max(0, Number(pr.pick_min ?? 0));
    const max = Math.max(1, Number(pr.pick_max ?? 1));
    if (!cands.length) {
      cpuAct('resolve_prompt', { card_ids: [] });
      return true;
    }
    const handIds = new Set(hand.map((c) => c.instance_id).filter(Boolean));
    const isCost = cands.every((c) => handIds.has(c.instance_id));
    const ranked = [...cands].sort((a, b) => {
      const d = cpuScoreSurveilCandidate(b, cpu, hand, tier, read)
        - cpuScoreSurveilCandidate(a, cpu, hand, tier, read);
      return isCost ? -d : d;
    });
    // Optional picks (min 0): easy CPUs decline, others take the full allowance
    // unless taking cards costs them hand resources.
    let take = max;
    if (min === 0) {
      if (tier === 'easy') take = isCost ? 0 : 1;
      else if (isCost) take = Math.min(max, Math.max(0, hand.length - 2));
    } else {
      take = Math.max(min, isCost ? min : max);
    }
    take = Math.min(take, ranked.length);
    const ids = ranked.slice(0, take).map((c) => c.instance_id);
    const payload = { card_ids: ids };
    if (ids.length === 1) payload.card_id = ids[0];
    if (!ids.length && (pr.choices || []).includes('skip')) payload.choice = 'skip';
    cpuAct('resolve_prompt', payload);
    return true;
  }

  if (pr.type === 'bp7_choose_player') {
    // Every `choose a player` BP07 effect is a tempo hit, so aim it at the human.
    const choices = pr.choices || ['self', 'opponent'];
    const pick = choices.includes('opponent') ? 'opponent' : choices[0];
    cpuAct('resolve_prompt', { choice: pick });
    return true;
  }

  if (pr.type === 'bp7_pick_slot') {
    const choices = (pr.choices || []).filter((c) => c != null && c !== '');
    if (!choices.length) {
      cpuAct('resolve_prompt', { choice: 'skip' });
      return true;
    }
    const ctx = cpuAbilityCtx(cpu, tier, read, winPressure,
      (cpu.energy_zone || []).filter(energyChipActive).length, null);
    if (cpuResolveScoredChoicePrompt(pr, cpu, tier, ctx)) return true;
    // Center first when the prompt offers Stage areas: BP07 center bonuses are the
    // strongest, otherwise fall back to the first legal option.
    const pick = choices.includes('center') ? 'center' : choices[0];
    cpuAct('resolve_prompt', { choice: String(pick), slot: String(pick) });
    return true;
  }

  if (pr.type === 'bp7_confirm') {
    const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
    const ab = pr.ability || {};
    const discardCost = Number(ab.discard ?? ab.optional_discard ?? 0);
    if (discardCost > 0 && hand.length <= discardCost) {
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    if (tier === 'easy' && discardCost > 0) {
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    const score = cpuScoreOptionalAbility(ab, cpu, tier, ae, hand, winPressure, read);
    // Free BP07 optionals (no discard / Energy cost) are upside-only, so take them
    // even when the generic scorer has no opinion about the new ability type.
    const yes = discardCost > 0 ? score >= cpuOptionalYesThreshold(tier) : true;
    cpuAct('resolve_prompt', { choice: yes ? 'yes' : 'no' });
    return true;
  }

  return false;
}

function cpuSurveilConfirmPayload(pr, cpu, tier) {
  const looked = pr.looked_cards || [];
  const hand = cpuLiveHand(cpu);
  const forOpp = (pr.prompt || '').includes('opponent');
  if (!looked.length) {
    return { choice: 'confirm', top_ids: [], wr_ids: [] };
  }
  if (pr.return_all) {
    return {
      choice: 'confirm',
      top_ids: looked.map(c => c.instance_id).filter(Boolean),
      wr_ids: [],
    };
  }
  const { top_ids, wr_ids } = cpuArrangeSurveilCards(looked, cpu, hand, tier, forOpp);
  const allIds = looked.map(c => c.instance_id).filter(Boolean);
  const picked = [...top_ids, ...wr_ids].filter(Boolean);
  const pickedSet = new Set(picked);
  if (allIds.length && (picked.length !== allIds.length || allIds.some(id => !pickedSet.has(id)))) {
    return { choice: 'confirm', top_ids: allIds, wr_ids: [] };
  }
  return { choice: 'confirm', top_ids, wr_ids };
}

function cpuCanPayAbilityDiscard(cpu, ability) {
  const t = ability?.type || '';
  if (t === 'wait_self_draw_discard' || t === 'wait_self_draw_discard_activate') return true;
  const need = Math.max(ability?.discard || 0, ability?.max_discard || 0);
  if (!need) return true;
  return (cpu.hand || []).length >= need;
}

function cpuPickDeckBottomIds(hand, count, tier, winPressure = 0, read = null) {
  return cpuPickDiscardIds(hand, count, tier, winPressure, read);
}

function cpuSchedulePromptRetryIfStuck(s, cpu) {
  const key = cpuPromptKey(s);
  if (!key) return;
  const retries = cpuBumpPromptRetry(s);
  const cpuId = cpuOpponentId();
  // After one failed/empty attempt, start cycling payloads immediately.
  if (retries >= 1) {
    const pr = s?.pending_prompt;
    if (pr?.responder === cpuId) {
      const tier = cpuDiff();
      const read = tier === 'easy' ? null : cpuReadOpponent(s, cpuId);
      if (cpuTryCyclingPromptResolve(pr, cpu, tier, cpuWinPressure(cpu), read, s)) {
        return;
      }
    }
  }
  clearTimeout(G._cpuPromptRetryTimer);
  G._cpuPromptRetryTimer = setTimeout(() => {
    G._cpuPromptRetryTimer = null;
    const live = G.gameState;
    if (!live || !G.isCPU) return;
    const liveKey = cpuPromptKey(live);
    if (!liveKey) {
      cpuSchedule(() => doCPU(live), 300);
      return;
    }
    if (liveKey !== key) return;
    G._cpuPromptScheduled = null;
    G._cpuResolveBusy = null;
    scheduleCpuResolvePrompt(live, live.players?.[cpuOpponentId()] || cpu);
  }, cpuDelay(450));
}

/** Prompt types that need card_ids / discard_ids — never send generic { choice: 'yes' }. */
const CPU_NO_GENERIC_YESNO = new Set([
  'sbp5_draw_deck_bottom', 'sbp6_discard_after_draw', 'mandatory_discard_after_draw',
  'mandatory_discard_look_reveal', 'effect_discard_hand', 'mandatory_discard_group_branch',
  'mandatory_discard_color_threshold_reveal5', 'maki_reveal5_choose_color', 'maki_reveal5_pick_mus',
  'surveil_arrange', 'surveil_pick_one', 'surveil_pick_one_hand_rest_wr',
  'surveil_pick_one_deck_top', 'surveil_pick_one_hand_rest_top',
  'live_success_pick_yell_deck_top',   'pick_named_member_blade', 'pick_named_members_grant_blade',
  'pick_named_members_grant_hearts', 'pick_member_grant_hearts', 'pick_member_cost_bonus',
  'pick_stage_member',
  'sbp6_pick_revealed_member', 'sbp5_pick_revealed_member', 'bp5_pick_kasumi_reveal',
  'sbp6_swap_pick_wr_member', 'sbp6_swap_pick_stage_member', 'sbp6_live_zone_deck_top_hearts',
  'sbp6_leave_play_wr_slot', 'hs_leave_play_wr_slot', 'hs_pick_wr_live_to_zone',
  'optional_named_live_zone_from_hand', 'pick_group_member_blade_faceup',
  'sbp6_pick_members_live_score',
  'sbp5_pick_yell_members', 'sbp5_wr_lives_deck_top',
  'spbp5_wait_discard_surveil', 'bp5_wait_discard_look_reveal', 'bp5_discard_pay_wr_live_score',
  'optional_wait_self_look_reveal',
  'optional_wait_group_member_blade', 'optional_wait_up_to_group_live_score',
  'wait_other_group_draw', 'optional_wait_group_member_draw_discard',
  'activate_members_pick', 'auto_on_ally_wait_activate_blade',
  'spbp5_wait_draw_discard', 'spbp5_wait_or_discard_activate',
  'sbp5_discard_bladeless_wr_live', 'sbp5_live_start_discard_heart',
  'spbp5_subunit_blade_pick', 'spbp5_distinct_groups', 'spbp5_pick_wr_live',
  'ssd1_live_start_draw', 'ssd1_reveal_group_deck', 'ssd1_play_wr_empty', 'both_wr_member_to_empty_stage',
  'optional_swap_area_on_enter',
  'on_enter_blade_self_and_pick_group', 'live_start_arise_choice', 'surveil2_mus_ability_choice',
  'optional_leave_mus_score_add_wr_live',
  'opp_blind_pick_hand_reveal', 'live_success_yell_live_deck_bottom', 'optional_wr_live_deck_bottom',
  'optional_wr_to_deck_top',
  'optional_wr_members_deck_bottom_milestones', 'player_choice_wr_members_deck_bottom',
  'sbp5_aqours_blade_or_position', 'sbp6_live_wr_deck_position', 'sbp6_hand_deck_position',
  'choice_energy_or_wr_lives_deck_top', 'activated_swap_area_pick',
  'optional_success_wr_live_swap',
  'pick_live_match_success_heart',
  'optional_discard_prompt', 'pick_looked_deck_hand',
  'spbp2_stack_wr_member', 'spbp2_wait_self_opp_heart_gap',
  'spbp2_center_move_choose', 'spbp2_center_move_position',
  'activated_discard_trigger_on_enter',
  'stack_energy_zone_pick',
  'both_shuffle_wr_members_deck_bottom_threshold',
  'live_start_unless_discard_return_energy', 'live_success_choose_draw_or_energy_wait',
  'mill_fill_wr_optional_live_deck_top', 'optional_ema_punch_ask',
  'optional_opp_wr_members_to_deck_bottom_then_wait', 'optional_pay_energy_add_from_wr',
  'pr_vol9_wait_opp_max_blade',
]);

/** Prefer a non-empty CPU hand — empty [] must not block fallback to cpu.hand. */
function cpuLiveHand(cpu) {
  const cpuId = typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2';
  const liveHand = G.gameState?.players?.[cpuId]?.hand;
  const cpuHand = cpu?.hand;
  if (Array.isArray(liveHand) && liveHand.length) return liveHand;
  if (Array.isArray(cpuHand) && cpuHand.length) return cpuHand;
  return liveHand || cpuHand || [];
}

/** Hand pick prompts: deck bottom, discard, deck top — must not fall through to generic yes/no. */
function cpuResolveHandPickPrompt(pr, cpu, tier, winPressure, read) {
  const hand = cpuLiveHand(cpu);
  const pick = (need) => cpuPickDeckBottomIds(hand, need, tier, winPressure, read);
  const presentationBusy = !!(G.animating || G._livePollHold || G._perfSpectacleActive
    || G._liveSpectacleGateRunning || G._liveRoundPlaybackActive);

  if (pr.type === 'sbp5_draw_deck_bottom') {
    const need = pr.bottom_count || 1;
    let ids = pick(need).filter(Boolean);
    if (ids.length < need) ids = cpuHandDiscardFallback(hand, need);
    if (ids.length >= need) {
      cpuAct('resolve_prompt', { discard_ids: ids.slice(0, need) });
      return true;
    }
    // Mid-draw animation: hand may briefly be empty after the Member left play.
    if (presentationBusy || !hand.length || hand.length < need) {
      cpuSchedulePromptRetryIfStuck(G.gameState, cpu);
      return true;
    }
    cpuAct('anti_softlock_skip', {});
    return true;
  }
  if (pr.type === 'sbp6_discard_after_draw' || pr.type === 'mandatory_discard_after_draw') {
    const need = pr.discard_count || 1;
    let ids = pick(need).filter(Boolean);
    if (ids.length < need) ids = cpuHandDiscardFallback(hand, need);
    if (ids.length >= need) {
      cpuAct('resolve_prompt', { discard_ids: ids.slice(0, need) });
      return true;
    }
    if (presentationBusy) {
      cpuSchedulePromptRetryIfStuck(G.gameState, cpu);
      return true;
    }
    if (!hand.length || hand.length < need) {
      cpuAct('anti_softlock_skip', {});
      return true;
    }
    cpuSchedulePromptRetryIfStuck(G.gameState, cpu);
    return true;
  }
  if (pr.type === 'effect_discard_hand' || pr.type === 'mandatory_discard_look_reveal') {
    const need = pr.count || pr.discard_count || 1;
    const handNow = cpuLiveHand(cpu);
    let ids = pick(need).filter(Boolean);
    if (ids.length < need) ids = cpuHandDiscardFallback(handNow, need);
    if (ids.length >= need) {
      const payload = (pr.pick_mode === 'deck_top')
        ? { card_ids: ids.slice(0, need) }
        : { discard_ids: ids.slice(0, need) };
      cpuAct('resolve_prompt', payload);
      return true;
    }
    if (presentationBusy) {
      cpuSchedulePromptRetryIfStuck(G.gameState, cpu);
      return true;
    }
    if (!handNow.length || handNow.length < need) {
      cpuAct('anti_softlock_skip', {});
      return true;
    }
    cpuSchedulePromptRetryIfStuck(G.gameState, cpu);
    return true;
  }
  // Kotori μ's branch discard (PL!-bp5-003) — mandatory hand pick, no yes/no choices.
  if (pr.type === 'mandatory_discard_group_branch') {
    const need = pr.discard_count || pr.max_pick || pr.ability?.discard || 1;
    const handNow = cpuLiveHand(cpu);
    let ids = pick(need).filter(Boolean);
    if (ids.length < need) ids = cpuHandDiscardFallback(handNow, need);
    if (ids.length >= need) {
      cpuAct('resolve_prompt', { discard_ids: ids.slice(0, need) });
      return true;
    }
    if (presentationBusy || handNow.length < need) {
      cpuSchedulePromptRetryIfStuck(G.gameState, cpu);
      return true;
    }
    cpuAct('anti_softlock_skip', {});
    return true;
  }
  // Maki Nishikino (PL!-bp6-006) discard step before color + reveal.
  if (pr.type === 'mandatory_discard_color_threshold_reveal5') {
    const need = pr.discard_count || pr.max_pick || pr.ability?.discard || 1;
    const handNow = cpuLiveHand(cpu);
    let ids = pick(need).filter(Boolean);
    if (ids.length < need) ids = cpuHandDiscardFallback(handNow, need);
    if (ids.length >= need) {
      cpuAct('resolve_prompt', { discard_ids: ids.slice(0, need) });
      return true;
    }
    if (presentationBusy || handNow.length < need) {
      cpuSchedulePromptRetryIfStuck(G.gameState, cpu);
      return true;
    }
    cpuAct('anti_softlock_skip', {});
    return true;
  }
  if (pr.type === 'maki_reveal5_choose_color') {
    cpuAct('resolve_prompt', { choice: cpuPickHeartColor(pr.choices, cpu) });
    return true;
  }
  if (pr.type === 'maki_reveal5_pick_mus') {
    const id = pr.candidates?.[0]?.instance_id;
    if (id) {
      cpuAct('resolve_prompt', { card_id: id });
      return true;
    }
    return false;
  }
  return false;
}

function cpuLiveCommitMin(tier, winPressure) {
  if (winPressure >= 1) return 0.25;
  if (winPressure >= 0.45) return 0.4;
  return cpuTierHardPlus(tier) ? 0.45 : tier === 'normal' ? 0.5 : 0.85;
}

function cpuTierLiveCommitMin(tier, posture, winPressure) {
  if (tier === 'easy') return cpuLiveCommitMin(tier, winPressure);
  if (winPressure >= 1) return 0.25;
  if (winPressure >= 0.45) return cpuTierHardPlus(tier) ? 0.28 : 0.32;
  if (posture?.behind || posture?.critical) return cpuTierHardPlus(tier) ? 0.22 : 0.30;
  if (posture?.ahead) return cpuTierHardPlus(tier) ? 0.35 : 0.42;
  return cpuTierHardPlus(tier) ? 0.30 : 0.38;
}

/** Absolute target card count in Live storage (0–3), based on board + hand. */
function cpuDesiredLiveStorageTotal(ctx, hand, viableLives, alreadyStored) {
  const { tier, winPressure, read, sit } = ctx;
  const handLen = hand.length;
  const room = 3 - alreadyStored;
  if (room <= 0) return 3;

  const bestScore = viableLives.length ? Math.max(...viableLives.map(c => c.score || 0)) : 0;
  const strongCount = viableLives.filter(c => (c.score || 0) >= 2).length;
  const viableCount = viableLives.length;
  const stageViableCount = ctx.liveCtx?.stageViable?.length ?? viableCount;
  const posture = cpuPosture(ctx);

  let target = alreadyStored;

  if (winPressure >= 1 || sit?.critical) return 3;

  if (viableCount === 0) {
    if (tier === 'easy') return alreadyStored;
    if (typeof cpuLiveRaceAdvice === 'function') {
      const advice = cpuLiveRaceAdvice({
        tier,
        mySuccess: sit?.cpuWins ?? (ctx?.cpu?.success_lives || []).length,
        oppSuccess: read?.successCount ?? 0,
        canClearStage: false,
        canClearYell: false,
        oppHearts: (read?.stageHearts || []).length,
        oppActive: read?.activeStage?.length || 0,
      });
      if (advice.bluff && handLen >= 4) return Math.min(3, alreadyStored + 1);
    }
    // Member-bluff pressure when behind — still open LIVE when hand allows.
    if (winPressure >= 0.45 && handLen >= 4) target = Math.min(3, alreadyStored + 1);
    else if (cpuTierHardPlus(tier) && (read?.behind || sit?.behind) && handLen >= 5) {
      target = Math.min(3, alreadyStored + 1);
    } else if (tier === 'normal' && sit?.mustCatchUp && handLen >= 6) {
      target = Math.min(3, alreadyStored + 1);
    }
    return target;
  }

  // Pro-style Hard/Expert: set clearable Lives every LIVE phase when viable.
  if (cpuTierHardPlus(tier)) {
    const want = Math.min(
      3,
      Math.max(
        1,
        stageViableCount >= 2 ? Math.min(3, stageViableCount) : 1,
        strongCount >= 2 ? 2 : 1,
        (winPressure >= 0.45 || sit?.behind || sit?.mustCatchUp)
          ? Math.min(3, viableCount)
          : Math.min(2, viableCount),
        handLen >= 5 && viableCount >= 2 ? 2 : 1
      )
    );
    target = Math.min(3, alreadyStored + want);
  } else if (winPressure >= 0.45) {
    const livesWanted = bestScore >= 2 && handLen >= 5 ? 2 : 1;
    target = Math.min(3, alreadyStored + livesWanted);
  } else if (sit?.mustCatchUp || sit?.behind) {
    const push = Math.min(Math.max(strongCount, 1) + (handLen >= 6 ? 1 : 0), 2);
    target = Math.min(3, alreadyStored + push);
  } else if (tier === 'normal') {
    if (bestScore >= 2) target = Math.min(3, alreadyStored + 1);
    else if (handLen >= 5 && viableCount >= 1) target = Math.min(3, alreadyStored + Math.min(2, viableCount));
    else if (viableCount >= 1 && handLen >= 3) target = Math.min(3, alreadyStored + 1);
  } else {
    target = Math.min(3, alreadyStored + (viableCount ? 1 : 0));
  }

  if (tier !== 'easy' && viableCount >= 1 && handLen >= 2) {
    target = Math.max(target, Math.min(3, alreadyStored + 1));
  }
  if (cpuTierHardPlus(tier) && viableCount >= 1) {
    target = Math.max(target, Math.min(3, alreadyStored + Math.min(viableCount, handLen >= 4 ? 2 : 1)));
  }
  if (tier !== 'easy' && (posture.behind || sit?.mustCatchUp) && strongCount >= 1) {
    const behindTarget = cpuTierHardPlus(tier)
      ? Math.min(3, alreadyStored + Math.min(Math.max(strongCount, viableCount), 3))
      : Math.min(3, alreadyStored + Math.min(strongCount, 2));
    target = Math.max(target, behindTarget);
  }
  if (cpuTierHardPlus(tier) && posture.ahead && viableCount >= 1 && handLen >= 3) {
    target = Math.max(target, Math.min(3, alreadyStored + 1));
  }
  // Expert: when multiple Lives clear, push toward full storage more often.
  if (tier === 'expert' && stageViableCount >= 2 && handLen >= 3) {
    target = Math.max(target, Math.min(3, alreadyStored + Math.min(stageViableCount, 2)));
  }

  return Math.min(3, target);
}

function cpuBluffSlotsWanted(ctx, chosenLiveCount, desiredTotal, handLen, bestLiveScore) {
  const gap = Math.max(0, desiredTotal - chosenLiveCount);
  if (gap <= 0) return 0;
  const { tier, winPressure, read, sit } = ctx;
  const posture = cpuPosture(ctx);

  if (tier === 'easy') {
    if (chosenLiveCount === 0) return handLen >= 5 ? Math.min(gap, 1) : 0;
    return 0;
  }

  if (posture.behind || posture.critical) {
    if (chosenLiveCount >= 1) return 0;
    return handLen >= 5 ? Math.min(gap, 1) : 0;
  }
  if (posture.ahead && chosenLiveCount === 0) {
    return handLen >= 5 ? Math.min(gap, 1) : 0;
  }
  if (cpuTierHardPlus(tier) && chosenLiveCount >= 2 && posture.ahead) {
    return Math.min(gap, 1);
  }

  if (chosenLiveCount === 0) {
    if (winPressure >= 0.45 || sit?.mustCatchUp) return Math.min(gap, 1);
    if (cpuTierHardPlus(tier) && handLen >= 5) return Math.min(gap, 1);
    if (tier === 'normal' && handLen >= 5) return Math.min(gap, 1);
    return 0;
  }
  if (winPressure >= 0.45 || sit?.critical) return Math.min(gap, cpuTierHardPlus(tier) ? 2 : 1);
  if (cpuTierHardPlus(tier) && bestLiveScore >= 2 && handLen >= 6
      && (read?.liveZoneCount > 0 || read?.behind || read?.oppRichBoard)) {
    return Math.min(gap, 1);
  }
  if (tier === 'normal' && bestLiveScore >= 2 && handLen >= 7 && read?.liveZoneCount > 0) {
    return Math.min(gap, 1);
  }
  return 0;
}

function cpuScoreThenEffect(then, tier) {
  if (!then?.type) return 0.5;
  const t = then.type;
  const table = {
    look_deck_top_arrange: 4.5, draw: 3.5, draw_cards: 3.5, draw_and_discard: 3,
    look_reveal_filter: 4, look_reveal_group: 3.8, look_reveal_named: 3.8,
    look_reveal_live_score_plus: 4.2, activate_energy: 2.8, blade_bonus: 2.5,
    member_blade_bonus: 2.8, add_from_waiting_room: 3.6, add_from_wr: 3.6,
    live_score_bonus: 3.2, draw_equal_discarded: 2.8, wait_opponent_stage_max_cost: 3.5,
    look_top_optional_wr: 2.5, energy_wait_from_deck: 2.8, pick_yell_member: 2.6,
    choose_heart_modifier: 2.2, hearts_and_blade_bonus: 2.6, mill_then_add_wr_group: 2.4,
    draw_until_hand: 3.6, reveal_top_live_score: 2.8,
  };
  let score = table[t] ?? cpuLookupAbilityBase(t) * 0.85;
  if (cpuTierHardPlus(tier)) score += 0.45;
  else if (tier === 'normal') score += 0.15;
  return score;
}

function cpuDeckCount(cpu) {
  return cpu?.main_deck?.length ?? cpu?.main_deck_count ?? 0;
}

/** Optional discard follow-ups that mill/look the deck are worthless when the deck is empty. */
function cpuThenNeedsDeckCards(then, deckCount) {
  if (!then?.type || deckCount >= 1) return true;
  const deckTypes = new Set([
    'look_reveal_filter', 'look_reveal_group', 'look_reveal_named',
    'look_reveal_live_score_plus', 'look_deck_top_arrange', 'mill_then_add_wr_group',
    'energy_wait_from_deck', 'draw_until_hand',
  ]);
  return !deckTypes.has(then.type);
}

function cpuScoreOptionalAbility(ab, cpu, tier, ae, hand, winPressure = 0, read = null) {
  if (!ab?.type) return -1;
  const type = ab.type;
  const handLen = (hand || []).length;
  const wr = cpu.waiting_room || [];
  const sit = read ? {
    mustCatchUp: (read.successCount ?? 0) > (cpu.success_lives?.length ?? 0),
    behind: (read.successCount ?? 0) > (cpu.success_lives?.length ?? 0),
  } : null;
  const ctx = cpuAbilityCtx(cpu, tier, read, winPressure, ae, sit);
  let score = cpuScoreAbilityType(ab, tier, ctx);

  if (type.includes('surveil') || type === 'optional_discard_surveil' || type === 'deck_surveil') {
    const need = ab.discard || 2;
    if (handLen < need + 2 || cpuDeckCount(cpu) < 1) return -1;
    score += winPressure * 0.55;
  }
  if (type === 'optional_discard_hand' || type === 'optional_discard_prompt' || type.includes('discard_prompt')) {
    const maxD = ab.max_discard || 0;
    const need = maxD > 0 ? Math.min(maxD, handLen) : (ab.discard || 1);
    if (handLen < (maxD > 0 ? 1 : need) + 1) return -1;
    if (ab.filter === 'live') {
      const liveCount = (hand || []).filter(c => isCpuLiveCard(c)).length;
      if (liveCount < (maxD > 0 ? 1 : need)) return -1;
    } else if (ab.filter === 'member') {
      const memberCount = (hand || []).filter(c => isCpuMemberCard(c)).length;
      if (memberCount < (maxD > 0 ? 1 : need)) return -1;
    }
    if (!cpuThenNeedsDeckCards(ab.then, cpuDeckCount(cpu))) return -1;
    if (read && ab.then?.type === 'wait_opponent_stage_max_cost') {
      const maxCost = ab.then.max_cost ?? 4;
      if (!read.activeStage.filter(x => x.cost <= maxCost).length) return -1;
    }
  }
  if (type.includes('pay_energy') || type === 'optional_pay_energy') {
    const cost = ab.cost || 0;
    let reserve = cpuTierHardPlus(tier) ? 0 : tier === 'normal' ? 1 : 2;
    if (sit?.mustCatchUp && tier === 'normal') reserve = 0;
    if (ae < cost + reserve) return -1;
    if (!cpuThenNeedsDeckCards(ab.then, cpuDeckCount(cpu))) return -1;
    score += cpuTierHardPlus(tier) ? 0.5 : 0.25;
  }
  if (type.includes('add_from_wr') || type.includes('wr_live') || type === 'optional_discard_add_from_wr') {
    const need = ab.discard || 0;
    const hasWrLive = wr.some(c => c.card_type === 'ライブ');
    const hasWrMember = wr.some(c => c.card_type === 'メンバー');
    if (type.includes('live') && !hasWrLive) return -1;
    if (type.includes('member') && !hasWrMember) return -1;
    if (need && handLen < need + 1) return -1;
    const pool = stageHeartPool(cpu);
    if (hasWrLive && wr.filter(c => isCpuLiveCard(c)).some(l => cpuCheckHearts(pool, cpuLiveRequiredHearts(l)))) {
      score += cpuTierHardPlus(tier) ? 1.2 : 0.65;
    }
  }
  if (type === 'optional_discard_named') {
    const matches = (hand || []).filter(c => cardMatchesNamedHand(c, ab.names || [], ab.include_self, '')).length;
    if (ab.exact_total) {
      if (matches < ab.exact_total) return -1;
    } else if (matches < 1) {
      return -1;
    }
  } else if (type.includes('discard_named') || type === 'optional_discard_same_group') {
    const need = ab.discard || ab.max_discard || 1;
    if (handLen < need + 1) return -1;
  }
  if (type === 'optional_wait_subunit_opp_pick_active' || type === 'optional_wait_subunit_opp_active') {
    if (read?.strongestActive) score += read.strongestActive.blade * (cpuTierHardPlus(tier) ? 0.3 : 0.18);
    else if (tier !== 'hard') score -= 0.8;
  }
  if (type === 'optional_stage_reposition' || type === 'optional_formation_change_group') {
    if (tier === 'easy') return -1;
    if (read?.oppRichBoard) score += cpuTierHardPlus(tier) ? 0.9 : 0.45;
  }
  if (type === 'optional_return_member_energy') {
    if (tier === 'easy') return -1;
  }
  if (tier !== 'easy' && cpuWantsLiveSearch(cpu, tier) && cpuAbilityFindsLives(type)) {
    score += cpuTierHardPlus(tier) ? 1.2 : 0.75;
  }
  if (tier !== 'easy' && typeof cpuEvalPromptBlend === 'function' && G.gameState) {
    const blendCtx = {
      tier,
      read,
      winPressure,
      sit: sit || null,
      s: G.gameState,
    };
    score = cpuEvalPromptBlend(score, G.gameState, (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2'), 'yes', blendCtx);
  }
  return score;
}

function cpuOptionalYesThreshold(tier) {
  if (cpuTierHardPlus(tier)) return 0.65;
  if (tier === 'normal') return 0.95;
  // Easy: allow occasional strong optionals (was 99 = never).
  return 2.4;
}

function cpuBuildOptionalYesPayload(pr, cpu, tier, winPressure, discardFn) {
  const ab = pr.ability || {};
  const hand = cpu.hand || [];
  const needFixed = ab.exact_total || pr.discard_count || (ab.max_discard ? 0 : (ab.discard || 0));
  const maxDiscard = ab.max_discard || pr.max_discard || 0;
  const data = { choice: 'yes' };
  // Only explicit discard_group — never then.group / ab.group (WR/add targets).
  const discardGroup = ab.discard_group || '';
  let pickPool = hand;
  if (ab.type === 'optional_discard_named') {
    pickPool = hand.filter(c => cardMatchesNamedHand(c, ab.names || [], ab.include_self, pr.source_id));
  } else if (discardGroup) {
    pickPool = hand.filter(c => c.card_type === 'メンバー' && (c.group || '') === discardGroup);
  }
  // Umi (optional_discard_add_from_wr): filter/group describe WR Live to add, not discard cost.
  if (ab.type !== 'optional_discard_add_from_wr') {
    if (ab.filter === 'live') {
      pickPool = pickPool.filter(c => isCpuLiveCard(c));
    } else if (ab.filter === 'member') {
      pickPool = pickPool.filter(c => isCpuMemberCard(c));
    }
  }
  if (maxDiscard > 0) {
    const n = Math.min(maxDiscard, pickPool.length);
    if (n < 1) return { choice: 'yes', discard_ids: [] };
    data.discard_ids = discardFn(n, pickPool);
    if (!data.discard_ids.length) return { choice: 'yes', discard_ids: [] };
  } else if (needFixed > 0) {
    data.discard_ids = discardFn(needFixed, pickPool);
    if (data.discard_ids.length !== needFixed) return null;
  }
  if (pr.needs_pay || ab.type === 'optional_pay_energy') data.pay = true;
  return data;
}

/** Resolve structured effect payload for a prompt choice key (ability.choices branches, yes/no, or key heuristics). */
function cpuInferEffectFromChoiceKey(pr, choiceKey) {
  const ab = pr.ability || {};
  const table = {
    draw_discard: { type: 'draw_and_discard', draw: 1, discard: 1 },
    wait_low: { type: 'wait_opponent_stage_max_cost', max_cost: 2 },
    wait: { type: 'wait_opponent_stage_max_cost', max_cost: ab.max_cost || 2, pick_count: 1 },
    mill: { type: 'mill_deck_to_wr', count: ab.count || 3 },
    self: { type: 'look_top_optional_wr' },
    opp: { type: 'look_top_optional_wr', target: 'opponent' },
    blade: { type: 'member_blade_bonus' },
    wait_opp: { type: 'wait_opponent_stage_max_cost', max_cost: ab.max_cost || 4 },
    energy: { type: 'activate_energy', count: 1 },
    lives: { type: 'add_from_wr' },
    member: { type: 'add_from_wr' },
    live: { type: 'add_from_wr' },
    pay: { type: 'optional_pay_energy', cost: pr.pay_cost || ab.cost || 2 },
    discard: { type: 'draw_and_discard', discard: pr.discard_count || ab.discard || 2 },
    activate_member: { type: 'activate_one_member' },
    activate_energy: { type: 'activate_energy', count: ab.count || 2 },
    both: { type: 'live_score_bonus' },
    top: { type: 'look_deck_top_arrange' },
    bottom: { type: 'mill_deck_to_wr', count: 1 },
    pick: { type: 'add_from_wr' },
    by_name: { type: 'look_reveal_named' },
    by_group: { type: 'look_reveal_group' },
  };
  return table[choiceKey] || null;
}

function cpuEffectPayloadForChoice(pr, choiceKey) {
  if (choiceKey === 'no' || choiceKey === 'skip') return null;
  if (choiceKey === 'yes') return pr.ability || null;
  const branch = pr.ability?.choices?.[choiceKey];
  if (branch?.effect) return branch.effect;
  if (branch?.then) return branch.then;
  if (branch?.type) return branch;
  return cpuInferEffectFromChoiceKey(pr, choiceKey);
}

function cpuChoiceEffectViable(effect, pr, cpu, read) {
  if (!effect?.type) return true;
  const hand = cpu.hand || [];
  const handLen = hand.length;
  const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
  const deckCount = cpuDeckCount(cpu);
  const t = effect.type;
  if (t === 'draw_and_discard' || t === 'draw_discard') {
    const need = effect.discard || 1;
    if (handLen < need) return false;
  }
  if (t.includes('discard') && !t.includes('draw') && (effect.discard || effect.count)) {
    const need = effect.discard || effect.count || 1;
    if (handLen < need) return false;
  }
  if (t === 'mill_deck_to_wr' || t.includes('look_reveal') || t.includes('look_deck') || t === 'reveal_top_live_score') {
    if (deckCount < 1 && !effect.target) return false;
  }
  if (t.includes('pay_energy') || t === 'optional_pay_energy') {
    const cost = effect.cost || pr.pay_cost || pr.ability?.cost || 0;
    if (cost && ae < cost) return false;
  }
  if (t === 'activate_energy') {
    const inactive = (cpu.energy_zone || []).filter(e => !e.active).length;
    if (inactive < (effect.count || 1)) return false;
  }
  if (t === 'activate_one_member') {
    const waited = Object.values(cpu.stage || {}).filter(m => m && memberInWait(m)).length;
    if (!waited) return false;
  }
  if (t.includes('add_from_wr') || t.includes('waiting') || (t.includes('_wr') && t.includes('add'))) {
    const wr = cpu.waiting_room || [];
    if (t.includes('live') && !wr.some(c => c.card_type === 'ライブ')) return false;
    if (t.includes('member') && !wr.some(c => c.card_type === 'メンバー')) return false;
    if (!wr.length && (t.includes('add_from_wr') || t.includes('waiting'))) return false;
  }
  if (t === 'wait_opponent_stage_max_cost' || t.includes('wait_opp')) {
    const maxCost = effect.max_cost ?? pr.ability?.max_cost ?? 4;
    if (read && !read.activeStage.filter(x => x.cost <= maxCost).length) return false;
  }
  if (t === 'look_top_optional_wr' && effect.target === 'opponent') {
    if (read && read.deckCount < 1) return false;
  }
  if (t === 'look_top_optional_wr' && effect.target !== 'opponent' && deckCount < 1) return false;
  if (!cpuThenNeedsDeckCards(effect, deckCount)) return false;
  return true;
}

function cpuScoreChoiceKeyHeuristic(pr, choiceKey, cpu, tier, ctx) {
  const { read, winPressure } = ctx;
  const handLen = (cpu.hand || []).length;
  const deckCount = cpuDeckCount(cpu);
  if (choiceKey === 'self') {
    const dig = winPressure >= 0.45 || deckCount >= 8 || handLen <= 4;
    return dig ? (cpuTierHardPlus(tier) ? 3.2 : 2.4) : 1.2;
  }
  if (choiceKey === 'opp') {
    const mill = read && (read.deckCount <= Math.max(4, read.handCount) || read.deckCount < 7);
    let s = mill ? (cpuTierHardPlus(tier) ? 3.4 : 2.6) : 1.4;
    if (cpuTierHardPlus(tier) && read?.totalBlade >= 5) s += 0.5;
    return s;
  }
  if (choiceKey === 'wait' || choiceKey === 'wait_low' || choiceKey === 'wait_opp') {
    const maxCost = pr.ability?.max_cost || (choiceKey === 'wait_low' ? 2 : 4);
    const hits = read?.activeStage?.filter(x => x.cost <= maxCost).length || 0;
    if (!hits) return -99;
    return hits * (cpuTierHardPlus(tier) ? 1.4 : 0.85) + (read?.strongestActive?.blade || 0) * 0.12;
  }
  if (choiceKey === 'draw_discard') {
    if (handLen < 2) return -99;
    return 2.2 + winPressure * 0.4 + (deckCount >= 6 ? 0.35 : 0);
  }
  if (choiceKey === 'mill') {
    if (deckCount < 1) return -99;
    return cpuTierHardPlus(tier) ? 2.4 : 1.6;
  }
  if (choiceKey === 'energy' || choiceKey === 'activate_energy') {
    const inactive = (cpu.energy_zone || []).filter(e => !e.active).length;
    return inactive > 0 ? 2.4 + inactive * 0.15 : -99;
  }
  if (choiceKey === 'lives' || choiceKey === 'live' || choiceKey === 'member') {
    const wr = cpu.waiting_room || [];
    const has = choiceKey === 'live'
      ? wr.some(c => c.card_type === 'ライブ')
      : wr.some(c => c.card_type === 'メンバー');
    return has ? 2.8 : -99;
  }
  if (choiceKey === 'blade') {
    return tier === 'easy' ? 1.2 : 2.6;
  }
  if (choiceKey === 'pay') {
    const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
    const cost = pr.pay_cost || pr.ability?.cost || 2;
    return ae >= cost ? 2.2 : -99;
  }
  if (choiceKey === 'discard') {
    return handLen >= (pr.discard_count || 2) ? 1.8 : -99;
  }
  if (choiceKey === 'both') {
    return cpuTierHardPlus(tier) ? 3.2 : 2.4;
  }
  if (choiceKey === 'top') return cpuTierHardPlus(tier) ? 2.2 : 1.4;
  if (choiceKey === 'bottom') return tier !== 'easy' ? 2 : 0.8;
  if (choiceKey === 'pick') return 2.4;
  if (choiceKey === 'by_name' || choiceKey === 'by_group') {
    return cpuDeckCount(cpu) >= 1 ? 3 : -99;
  }
  return cpuLookupAbilityBase(pr.type || choiceKey) * cpuTierAbilityMul(tier) * 0.5;
}

function cpuScorePromptChoice(pr, choiceKey, cpu, tier, ctx) {
  const { read, winPressure, ae, sit } = ctx;
  const hand = cpu.hand || [];
  if (choiceKey === 'no' || choiceKey === 'skip') {
    let decline = tier === 'easy' ? 0.55 : 0;
    if (tier !== 'easy' && typeof cpuEvalPromptBlend === 'function' && ctx.s) {
      decline = cpuEvalPromptBlend(decline, ctx.s, ctx.cpuId || (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2'), choiceKey, ctx);
    }
    return decline;
  }
  if (choiceKey === 'yes') {
    const ab = pr.ability || {};
    // cpuScoreOptionalAbility already applies cpuEvalPromptBlend
    return cpuScoreOptionalAbility(ab, cpu, tier, ae, hand, winPressure, read);
  }
  const effect = cpuEffectPayloadForChoice(pr, choiceKey);
  let score;
  if (!effect?.type) {
    score = cpuScoreChoiceKeyHeuristic(pr, choiceKey, cpu, tier, ctx);
  } else {
    if (!cpuChoiceEffectViable(effect, pr, cpu, read)) return -99;
    const ab = { ...effect, type: effect.type };
    score = cpuScoreAbilityType(ab, tier, ctx);
    if (effect.then) score += cpuScoreThenEffect(effect.then, tier) * 0.55;
    if (cpuTierHardPlus(tier) && read) {
      if (effect.target === 'opponent' || tIncludesOpp(effect.type)) {
        score += (read.activeStage?.length || 0) * 0.35 + (read.totalBlade || 0) * 0.06;
      }
      if (effect.type === 'wait_opponent_stage_max_cost') {
        const maxCost = effect.max_cost ?? 4;
        score += (read.activeStage?.filter(x => x.cost <= maxCost).length || 0) * 0.45;
      }
    }
    if (sit?.mustCatchUp && (effect.type?.includes('draw') || effect.type?.includes('surveil'))) {
      score += cpuTierHardPlus(tier) ? 0.65 : 0.35;
    }
  }
  if (tier !== 'easy' && typeof cpuEvalPromptBlend === 'function' && ctx.s && score > -50) {
    score = cpuEvalPromptBlend(score, ctx.s, ctx.cpuId || (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2'), choiceKey, ctx);
  }
  return score;
}

function tIncludesOpp(type) {
  return type && (type.includes('wait_opp') || type.includes('opponent') || type.includes('opp_'));
}

function cpuPickBestPromptChoice(pr, cpu, tier, ctx) {
  const choices = pr.choices || [];
  if (!choices.length) return null;
  let best = null;
  let bestScore = -Infinity;
  for (const key of choices) {
    const score = cpuScorePromptChoice(pr, key, cpu, tier, ctx);
    if (score < -50) continue;
    if (score > bestScore) {
      bestScore = score;
      best = key;
    }
  }
  const decline = choices.find(k => k === 'skip' || k === 'no');
  if (!best) return decline || choices[0];
  const isYesNo = choices.includes('yes') && choices.includes('no');
  const threshold = isYesNo ? cpuOptionalYesThreshold(tier) : (cpuTierHardPlus(tier) ? 0.35 : tier === 'normal' ? 0.55 : 99);
  if (tier === 'easy') {
    if (isYesNo && bestScore < threshold && decline) return decline;
    if (!isYesNo && decline && bestScore < 1.2) return decline;
    return best;
  }
  if (isYesNo && bestScore < threshold && decline) return decline;
  if (!isYesNo && decline && bestScore < threshold * 0.85) return decline;
  return best;
}

function cpuResolveScoredChoicePrompt(pr, cpu, tier, ctx) {
  const choices = pr.choices;
  if (!choices?.length) return false;
  const { winPressure, read, ae } = ctx;
  const hand = cpu.hand || [];
  const discard = (need, pool) => cpuPickDiscardIds(pool || hand, need, tier, winPressure, read);
  const pick = cpuPickBestPromptChoice(pr, cpu, tier, ctx);
  if (!pick) return false;
  if (pick === 'yes' && choices.includes('yes') && choices.includes('no') && pr.ability) {
    const score = cpuScoreOptionalAbility(pr.ability, cpu, tier, ae, hand, winPressure, read);
    if (score >= cpuOptionalYesThreshold(tier)) {
      const data = cpuBuildOptionalYesPayload(pr, cpu, tier, winPressure, discard);
      if (data) { cpuAct('resolve_prompt', data); return true; }
    }
    cpuAct('resolve_prompt', { choice: 'no' });
    return true;
  }
  const payload = { choice: pick };
  const branchFx = pr.ability?.choices?.[pick]?.effect || cpuInferEffectFromChoiceKey(pr, pick);
  if (branchFx?.type === 'draw_and_discard' || pick === 'draw_discard' || pick === 'discard') {
    const need = branchFx?.discard || pr.discard_count || pr.ability?.discard || 1;
    const ids = discard(need);
    if (ids.length >= need) payload.discard_ids = ids.slice(0, need);
    else if (pick === 'discard') return false;
    else if (tier === 'easy') return false;
  }
  if (pick === 'yes') {
    const need = pr.discard_count || pr.ability?.discard || pr.ability?.max_discard || 0;
    if (need > 0) {
      const n = pr.ability?.max_discard ? Math.min(need, hand.length) : need;
      const ids = discard(n);
      if (ids.length >= n) payload.discard_ids = ids.slice(0, n);
      else if (need === n) return false;
    }
    if (pr.needs_pay || pr.ability?.type?.includes('pay_energy')) payload.pay = true;
  }
  cpuAct('resolve_prompt', payload);
  return true;
}

function cpuPickBestLookedCard(looked, cpu, hand, tier, forOpponent = false) {
  if (!looked?.length) return null;
  const { top_ids } = cpuArrangeSurveilCards(looked, cpu, hand, tier, forOpponent);
  return top_ids[0] || looked[0]?.instance_id || null;
}

function cpuPickBestYellDeckTop(candidates, cpu, hand, tier, read) {
  if (!candidates?.length) return null;
  const pool = stageHeartPool(cpu);
  const stageColors = cpuStageColors(cpu);
  const scored = candidates.map(c => ({
    c,
    s: isCpuLiveCard(c)
      ? (c.score || 0) + (cpuCheckHearts(pool, cpuLiveRequiredHearts(c)) ? 2 : 0)
      : cpuScoreMember(c, cpu, hand, stageColors, tier, read),
  })).sort((a, b) => b.s - a.s);
  return scored[0]?.c?.instance_id || candidates[0]?.instance_id || null;
}

/** Hang-risk prompt types: card/slot picks that must not fall through to generic yes/no. */
function cpuResolveHangRiskPrompts(pr, cpu, tier, read, s) {
  const hand = cpu.hand || [];
  const surveilPickOne = new Set([
    'surveil_pick_one', 'surveil_pick_one_hand_rest_wr',
    'surveil_pick_one_deck_top', 'surveil_pick_one_hand_rest_top',
  ]);
  if (surveilPickOne.has(pr.type)) {
    const looked = pr.look_cards || pr.candidates || [];
    const id = cpuPickBestLookedCard(looked, cpu, hand, tier, (pr.prompt || '').includes('opponent'));
    if (id) { cpuAct('resolve_prompt', { card_id: id }); return true; }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return true;
  }
  if (pr.type === 'live_success_pick_yell_deck_top') {
    try {
      if (tier === 'easy' && Math.random() < 0.45) {
        cpuAct('resolve_prompt', { choice: 'skip' });
        return true;
      }
      const cands = pr.candidates || [];
      let id = null;
      try {
        id = cpuPickBestYellDeckTop(cands, cpu, hand, tier, read);
      } catch (err) {
        TCG_DEBUG.warn('cpu', 'yell deck top score failed', err);
      }
      if (!id) id = cands.find(c => c?.instance_id)?.instance_id || null;
      if (id) {
        cpuAct('resolve_prompt', { card_id: id });
        return true;
      }
    } catch (e) {
      TCG_DEBUG.warn('cpu', 'live_success_pick_yell_deck_top failed', e);
    }
    cpuAct('resolve_prompt', { choice: 'skip' });
    return true;
  }
  if (pr.type === 'pick_named_member_blade' || pr.type === 'pick_member_cost_bonus'
      || pr.type === 'pick_stage_member') {
    const best = [...(pr.candidates || [])].sort((a, b) => (b.blade || 0) - (a.blade || 0)
      || (b.cost || 0) - (a.cost || 0))[0];
    if (best?.slot) { cpuAct('resolve_prompt', { slot: best.slot }); return true; }
    if (best?.instance_id) { cpuAct('resolve_prompt', { card_id: best.instance_id }); return true; }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return true;
  }
  const cardPickTypes = new Set([
    'sbp6_pick_revealed_member', 'sbp5_pick_revealed_member', 'bp5_pick_kasumi_reveal',
    'sbp6_swap_pick_wr_member', 'sbp6_swap_pick_stage_member',
  ]);
  if (cardPickTypes.has(pr.type)) {
    const pick = cpuPickBestCandidate(pr.candidates, cpu, hand, tier, read);
    if (pick?.instance_id) { cpuAct('resolve_prompt', { card_id: pick.instance_id }); return true; }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return true;
  }
  if (pr.type === 'sbp6_live_zone_deck_top_hearts') {
    if (tier === 'easy' || !(pr.candidates || []).length) {
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    const lives = (pr.candidates || []).filter(c => c.card_type === 'ライブ');
    const pick = [...lives].sort((a, b) => (a.score || 0) - (b.score || 0))[0]
      || cpuPickBestCandidate(pr.candidates, cpu, hand, tier, read);
    if (pick?.instance_id) { cpuAct('resolve_prompt', { card_id: pick.instance_id }); return true; }
    cpuAct('resolve_prompt', { choice: 'no' });
    return true;
  }
  if (pr.type === 'sbp6_leave_play_wr_slot') {
    if ((pr.step || '') === 'pick') {
      const pick = cpuPickBestCandidate(pr.candidates, cpu, hand, tier, read);
      if (pick?.instance_id) { cpuAct('resolve_prompt', { card_id: pick.instance_id }); return true; }
      cpuSchedulePromptRetryIfStuck(s, cpu);
      return true;
    }
    const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
    const cost = pr.ability?.cost || 2;
    if (tier !== 'easy' && ae >= cost && (pr.candidates || []).length) {
      cpuAct('resolve_prompt', { choice: 'yes' });
      return true;
    }
    cpuAct('resolve_prompt', { choice: 'no' });
    return true;
  }
  if (pr.type === 'hs_leave_play_wr_slot') {
    const pick = cpuPickBestCandidate(pr.candidates, cpu, hand, tier, read);
    if (pick?.instance_id) { cpuAct('resolve_prompt', { card_id: pick.instance_id }); return true; }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return true;
  }
  if (pr.type === 'hs_pick_wr_live_to_zone') {
    const pick = cpuPickBestCandidate(pr.candidates, cpu, hand, tier, read);
    if (pick?.instance_id) { cpuAct('resolve_prompt', { card_id: pick.instance_id }); return true; }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return true;
  }
  if (pr.type === 'optional_named_live_zone_from_hand') {
    if (pr.step === 'pick_hand') {
      const pick = cpuPickBestCandidate(pr.candidates, cpu, hand, tier, read);
      if (pick?.instance_id) { cpuAct('resolve_prompt', { card_id: pick.instance_id }); return true; }
      cpuSchedulePromptRetryIfStuck(s, cpu);
      return true;
    }
    // Prefer yes when Live storage has room — face-up set is usually strong.
    const zoneLen = (cpu.live_zone || []).filter(Boolean).length;
    cpuAct('resolve_prompt', { choice: zoneLen < 3 ? 'yes' : 'no' });
    return true;
  }
  if (pr.type === 'pick_group_member_blade_faceup') {
    const pick = cpuPickBestCandidate(pr.candidates, cpu, hand, tier, read);
    if (pick?.instance_id || pick?.slot) {
      cpuAct('resolve_prompt', {
        card_id: pick.instance_id || '',
        slot: pick.slot || '',
      });
      return true;
    }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return true;
  }
  // Ginko PB1: after both WRs shuffled to deck bottom, pick a Live from WR.
  if (pr.type === 'both_shuffle_wr_members_deck_bottom_threshold') {
    const pick = cpuPickBestCandidate(pr.candidates, cpu, hand, tier, read);
    if (pick?.instance_id) { cpuAct('resolve_prompt', { card_id: pick.instance_id }); return true; }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return true;
  }
  // Wien Margarete Live Start: discard 1 or return Energy (must attach discard_ids).
  if (pr.type === 'live_start_unless_discard_return_energy') {
    const need = pr.discard_count || 1;
    const canReturn = (pr.choices || []).includes('return_energy');
    const canSkip = (pr.choices || []).includes('skip');
    if (canReturn && (tier === 'easy' || hand.length <= need)) {
      cpuAct('resolve_prompt', { choice: 'return_energy' });
      return true;
    }
    const ids = cpuPickDiscardIds(hand, need, tier, cpuWinPressure(cpu), read);
    if ((pr.choices || []).includes('discard') && ids.length >= need) {
      cpuAct('resolve_prompt', { choice: 'discard', discard_ids: ids.slice(0, need) });
      return true;
    }
    if (canReturn) {
      cpuAct('resolve_prompt', { choice: 'return_energy' });
      return true;
    }
    if (canSkip) {
      cpuAct('resolve_prompt', { choice: 'skip' });
      return true;
    }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return true;
  }
  // Wien Margarete Live Success: draw or put Energy into Wait.
  if (pr.type === 'live_success_choose_draw_or_energy_wait') {
    const pick = (tier === 'easy' && (pr.choices || []).includes('energy'))
      ? 'energy'
      : ((pr.choices || []).includes('draw') ? 'draw' : (pr.choices || [])[0]);
    if (pick) { cpuAct('resolve_prompt', { choice: pick }); return true; }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return true;
  }
  if (pr.type === 'sbp6_pick_members_live_score') {
    const max = pr.max_pick || 2;
    const sorted = [...(pr.candidates || [])].sort((a, b) => (b.blade || 0) - (a.blade || 0));
    const ids = sorted.slice(0, max).map(c => c.instance_id).filter(Boolean);
    if (ids.length) { cpuAct('resolve_prompt', { card_ids: ids }); return true; }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return true;
  }
  if (pr.type === 'sbp5_pick_yell_members') {
    const max = pr.max_pick || 2;
    const sorted = [...(pr.candidates || [])].sort(
      (a, b) => cpuScoreMember(b, cpu, hand, cpuStageColors(cpu), tier, read)
        - cpuScoreMember(a, cpu, hand, cpuStageColors(cpu), tier, read)
    );
    const ids = sorted.slice(0, max).map(c => c.instance_id).filter(Boolean);
    if (ids.length) { cpuAct('resolve_prompt', { card_ids: ids }); return true; }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return true;
  }
  if (pr.type === 'sbp5_wr_lives_deck_top') {
    const max = pr.max_pick || 4;
    const picked = pr.picked || [];
    const pickedIds = new Set(picked.map(c => c.instance_id));
    const remaining = (pr.candidates || []).filter(c => !pickedIds.has(c.instance_id));
    const want = cpuTierHardPlus(tier) ? Math.min(max, remaining.length)
      : tier === 'normal' ? Math.min(Math.max(1, Math.floor(max / 2)), remaining.length)
      : Math.min(1, remaining.length);
    if (picked.length < want && remaining.length) {
      const low = [...remaining].sort((a, b) => (a.score || 0) - (b.score || 0))[0];
      if (low?.instance_id) { cpuAct('resolve_prompt', { card_id: low.instance_id }); return true; }
    }
    cpuAct('resolve_prompt', { card_id: '' });
    return true;
  }
  if (pr.type === 'pick_looked_deck_hand') {
    const hand = cpu.hand || [];
    const winPressure = cpuWinPressure(cpu);
    const eligible = pr.eligible_ids || [];
    const byId = new Map((pr.candidates || []).map(c => [c.instance_id, c]));
    const ranked = eligible.map(id => byId.get(id)).filter(Boolean)
      .sort((a, b) => {
        if (a.card_type === 'ライブ' && b.card_type === 'ライブ') {
          return cpuScoreLiveForSet(b, tier, winPressure, read, cpu) - cpuScoreLiveForSet(a, tier, winPressure, read, cpu);
        }
        if (a.card_type === 'メンバー' && b.card_type === 'メンバー') {
          return cpuScoreMember(b, cpu, hand, cpuStageColors(cpu), tier, read)
            - cpuScoreMember(a, cpu, hand, cpuStageColors(cpu), tier, read);
        }
        if (a.card_type === 'メンバー') return -1;
        if (b.card_type === 'メンバー') return 1;
        return (b.score || 0) - (a.score || 0);
      });
    const need = Math.max(1, Number(pr.pick_count || 1));
    const distinct = !!(pr.ability?.distinct_groups);
    if (need > 1 || distinct) {
      const picked = [];
      const usedGroups = new Set();
      for (const c of ranked) {
        if (picked.length >= need) break;
        const g = String(c.group || '');
        if (distinct) {
          if (!g || usedGroups.has(g)) continue;
          usedGroups.add(g);
        }
        if (c.instance_id) picked.push(c.instance_id);
      }
      if (picked.length) {
        cpuAct('resolve_prompt', picked.length === 1 ? { card_id: picked[0] } : { card_ids: picked });
        return true;
      }
      if (pr.optional) { cpuAct('resolve_prompt', { choice: 'skip' }); return true; }
      cpuSchedulePromptRetryIfStuck(s, cpu);
      return true;
    }
    const id = ranked[0]?.instance_id || eligible[0];
    if (id) { cpuAct('resolve_prompt', { card_id: id }); return true; }
    if (pr.optional) { cpuAct('resolve_prompt', { choice: 'skip' }); return true; }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return true;
  }
  if (cpuResolvePickLiveMatchSuccessHeart(pr, cpu, s)) return true;
  return false;
}

function cpuArrangeSurveilCards(looked, cpu, hand, tier, forOpponent = false) {
  const pool = stageHeartPool(cpu);
  const stageColors = cpuStageColors(cpu);
  const scored = looked.map(c => {
    let s = 0;
    if (c.card_type === 'ライブ') {
      s = (c.score || 0) * 2;
      if (!forOpponent && cpuCheckHearts(pool, cpuLiveRequiredHearts(c))) s += 5;
      if (forOpponent) s = 6 - (c.score || 0);
    } else if (c.card_type === 'メンバー') {
      s = forOpponent
        ? 4 - cpuScoreMember(c, cpu, hand, stageColors, tier)
        : cpuScoreMember(c, cpu, hand, stageColors, tier);
    } else {
      s = forOpponent ? 0.2 : 0.5;
    }
    return { c, s };
  }).sort((a, b) => forOpponent ? a.s - b.s : b.s - a.s);
  const keepTop = cpuTierHardPlus(tier)
    ? Math.min(looked.length, Math.max(1, Math.ceil(looked.length / 2)))
    : Math.min(looked.length, 1);
  const topIds = scored.slice(0, keepTop).map(x => x.c.instance_id);
  const wrIds = scored.slice(keepTop).map(x => x.c.instance_id);
  return {
    top_ids: topIds.length ? topIds : looked.map(c => c.instance_id),
    wr_ids: wrIds,
  };
}

function cpuScoreAbility(entry, cpu, tier, read = null, winPressure = 0) {
  const { ability, needEnergy, used } = entry;
  if (used || needEnergy) return -99;
  const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
  const sit = read ? {
    mustCatchUp: (read.successCount ?? 0) > (cpu.success_lives?.length ?? 0),
    behind: (read.successCount ?? 0) > (cpu.success_lives?.length ?? 0),
  } : null;
  const ctx = cpuAbilityCtx(cpu, tier, read, winPressure, ae, sit);
  let score = cpuScoreAbilityType(ability, tier, ctx);
  if (ability?.trigger === 'activated') score += cpuTierHardPlus(tier) ? 0.65 : 0.38;
  const t = ability?.type || '';
  if (t === 'discard_hand_add_live_from_wr' && !(cpu.waiting_room || []).some(c => c.card_type === 'ライブ')) {
    return -99;
  }
  if ((t.includes('wr') || t.includes('waiting') || t.includes('add_from_wr')) && !(cpu.waiting_room || []).length) {
    score -= cpuTierHardPlus(tier) ? 1.2 : 2;
  }
  if (t.includes('opp') && read && !read.activeStage.length) score -= 1.5;
  if (tier !== 'easy' && cpuWantsLiveSearch(cpu, tier)) {
    if (cpuAbilityFindsLives(t)) {
      score += cpuTierHardPlus(tier) ? 1.85 : 1.15;
      if (cpuHandLiveContext(cpu).needsLives) score += cpuTierHardPlus(tier) ? 0.65 : 0.4;
    } else if (t.includes('wr') && (cpu.waiting_room || []).some(c => isCpuLiveCard(c))) {
      score += cpuTierHardPlus(tier) ? 1.2 : 0.75;
    }
  }
  return score;
}

function cpuPickHeartColor(choices, cpu) {
  const list = Array.isArray(choices) && choices.length ? choices : ['yellow', 'pink', 'purple'];
  const pool = stageHeartPool(cpu);
  const counts = {};
  pool.forEach(c => { counts[c] = (counts[c] || 0) + 1; });
  // Prefer colors that unlock the best Live still missing hearts.
  const liveCtx = typeof cpuHandLiveContext === 'function' ? cpuHandLiveContext(cpu) : null;
  const target = [...(liveCtx?.unviableLives || [])].sort((a, b) => {
    const wa = typeof cpuMetaLiveWeight === 'function' ? cpuMetaLiveWeight(a, cpuDiff(), cpuWinPressure(cpu)) : 0;
    const wb = typeof cpuMetaLiveWeight === 'function' ? cpuMetaLiveWeight(b, cpuDiff(), cpuWinPressure(cpu)) : 0;
    return (b.score || 0) + wb - ((a.score || 0) + wa);
  })[0];
  if (target) {
    const needed = {};
    (cpuLiveRequiredHearts(target) || []).forEach(req => {
      const color = normalizeHeartColor(req.color);
      if (color === 'any') return;
      needed[color] = (needed[color] || 0) + (req.count || 1);
    });
    pool.forEach(c => {
      if (needed[c]) needed[c] = Math.max(0, needed[c] - 1);
    });
    const deficit = list.slice().sort((a, b) => (needed[b] || 0) - (needed[a] || 0));
    if ((needed[deficit[0]] || 0) > 0) return deficit[0];
  }
  return list.slice().sort((a, b) => (counts[b] || 0) - (counts[a] || 0))[0] || list[0];
}

function cpuBuildActivatePayload(pick, cpu, tier, winPressure, read) {
  const payload = { card_id: pick.card.instance_id, ability_index: pick.idx };
  const ab = pick.ability;
  const t = ab?.type || '';
  if (t === 'wait_self_choose_heart') {
    payload.heart_choice = cpuPickHeartColor(ab.heart_choices, cpu);
  }
  const needDiscard = ab?.discard || ab?.max_discard || 0;
  const discardTypes = new Set([
    'wait_self_discard_draw',
    'discard_hand_add_live_from_wr', 'activated_pay_discard_add_wr_live',
    'activated_discard_add_wr_scored_live', 'hand_discard_for_stage_blade',
    'wait_self_discard_add_wr_live', 'discard_cost_add_live_subunit', 'wait_self_discard_reveal_until',
    'discard_play_self_from_wr',
  ]);
  const drawFirstDiscard = t === 'wait_self_draw_discard' || t === 'wait_self_draw_discard_activate';
  if (!drawFirstDiscard && (needDiscard || discardTypes.has(t)
      || (t.includes('discard') && !t.includes('add_from_wr') && !t.includes('from_wr')))) {
    const need = Math.max(needDiscard || 1, ab?.discard || 1);
    payload.discard_ids = cpuPickDiscardIds(cpu.hand || [], need, tier, winPressure, read);
  }
  if (t === 'shuffle_named_from_waiting') {
    const max = ab.max_total || 6;
    const picked = (cpu.waiting_room || [])
      .filter(c => c.card_type === 'メンバー' && cardMatchesNamedHand(c, ab.names || [], false, ''))
      .slice(0, max)
      .map(c => c.instance_id)
      .filter(Boolean);
    if (picked.length) payload.wr_ids = picked;
  }
  if (t === 'reveal_live_opp_discard_or_blade') {
    const live = (cpu.hand || []).find(c => c.card_type === 'ライブ' || c.card_type_en === 'Live');
    if (live?.instance_id) payload.reveal_card_id = live.instance_id;
  }
  if (t === 'leave_play_named_from_hand_stack_energy') {
    const maxCost = Number(ab.max_cost ?? 13);
    const pick = (cpu.hand || []).find(c =>
      c && (c.card_type === 'メンバー' || c.card_type_en === 'Member')
      && Number(c.cost ?? 0) <= maxCost
      && typeof cardMatchesNamedHand === 'function'
      && cardMatchesNamedHand(c, ab.names || [], false, ''));
    if (pick?.instance_id) payload.hand_card_id = pick.instance_id;
  }
  return payload;
}

function cpuMainActionMinScore(tier, posture) {
  if (tier === 'easy') return 99;
  if (posture?.behind || posture?.critical) return cpuTierHardPlus(tier) ? 0.25 : 0.4;
  return cpuTierHardPlus(tier) ? 0.4 : 0.55;
}

function cpuActivateMinScore(tier, winPressure, posture) {
  if (tier === 'easy') return winPressure >= 0.45 ? 2.8 : 3.2;
  let min = cpuTierHardPlus(tier)
    ? (winPressure >= 1 ? 2.2 : winPressure >= 0.45 ? 1.35 : 0.45)
    : (winPressure >= 1 ? 2 : winPressure >= 0.45 ? 1.15 : 0.65);
  if (posture?.behind) {
    if (cpuTierHardPlus(tier)) min -= 0.12;
    else if (tier === 'normal') min -= 0.08;
  }
  return min;
}

function cpuListActivateCandidates(s, cpu, ctx) {
  const { tier, winPressure, read } = ctx;
  const posture = cpuPosture(ctx);
  const minScore = cpuActivateMinScore(tier, winPressure, posture);
  const cpuId = ctx.cpuId || (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2');
  cpuClearAbilityBlacklistIfNewTurn(s);
  const hasViableLive = cpuHandHasViableLive(cpu);
  let abilities = collectActivatableAbilities(s, cpuId)
    .map(a => {
      let sc = cpuScoreAbility(a, cpu, tier, read, winPressure);
      if (typeof cpuActionNetAdjust === 'function') {
        sc += cpuActionNetAdjust(tier, cpuActionNetSeat(cpu, read, s, {
          kind: 'activate',
          cost: a.ability?.cost || 0,
          canClear: !!hasViableLive,
        }));
      }
      return { ...a, score: sc };
    })
    .filter(a => {
      if (cpuAbilityBlacklisted(a.card?.instance_id, a.idx)) return false;
      if (cpuAbilityNeedsEmptyStage(a.ability) && !cpuStageHasEmptySlot(cpu)) return false;
      const wrReason = a.wrBlock || abilityWrBlockReason(cpu, a.ability);
      return a.score > minScore && !wrReason && cpuCanPayAbilityDiscard(cpu, a.ability);
    })
    .sort((a, b) => b.score - a.score);
  if (winPressure >= 0.35 && hasViableLive) {
    abilities = abilities.filter(a => {
      const t = a.ability?.type || '';
      if (t === 'wait_self_draw_discard' || t === 'wait_self_draw_discard_activate') return false;
      if (tier !== 'hard' && (a.ability?.discard || a.ability?.max_discard)) return false;
      return true;
    });
  }
  const liveCtx = cpuHandLiveContext(cpu);
  cpuTraceScore('main', 'activate', abilities.slice(0, 5).map(a => ({
    score: a.score,
    label: a.ability?.type || 'ability',
  })), {
    minScore,
    liveInHand: liveCtx.liveInHand.length,
    viableLives: liveCtx.viableLives.length,
    wantsLiveSearch: cpuWantsLiveSearch(cpu, tier),
  });
  return abilities.map(a => ({
    kind: 'activate',
    score: a.score,
    payload: cpuBuildActivatePayload(a, cpu, tier, winPressure, read),
    label: a.ability?.type || 'ability',
  }));
}

function cpuMemberMainAffordable(cpu, hand, c, ae, s) {
  const ec = effectiveCost(c, hand);
  if (ec <= ae) return true;
  const turn = s?.turn;
  for (const slot of ['center', 'left', 'right']) {
    const existing = cpu.stage?.[slot];
    if (!existing || (turn != null && stageMemberEnteredThisTurn(existing, turn))) continue;
    if (memberBlocksBaton(existing) || memberBatonRestricted(existing, c)) continue;
    if (ec < 1) continue;
    const batonCost = Math.max(0, ec - stageMemberEffectiveCost(existing, cpu));
    if (canAffordBatonWithOptionalDouble(cpu, c, slot, existing, affordableEnergyForBatonPlay(cpu, existing, c), ec)) return true;
  }
  return false;
}

function cpuPlanMemberPlay(s, cpu, hand, tier, read = null, opts = {}) {
  const { suboptimalPick = false } = opts;
  const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
  const stageColors = cpuStageColors(cpu);
  const cmp = tier === 'easy'
    ? (a, b) => {
        const na = [cpuMemberNovelty(a, stageColors), effectiveCost(a, hand)];
        const nb = [cpuMemberNovelty(b, stageColors), effectiveCost(b, hand)];
        return nb[0] - na[0] || nb[1] - na[1];
      }
    : (a, b) => cpuScoreMember(b, cpu, hand, stageColors, tier, read, s)
        - cpuScoreMember(a, cpu, hand, stageColors, tier, read, s);
  const aff = hand
    .filter(c => isCpuMemberCard(c) && (tier === 'easy'
      ? effectiveCost(c, hand) <= ae
      : cpuMemberMainAffordable(cpu, hand, c, ae, s)))
    .sort(cmp);
  if (!aff.length) return null;

  const consider = tier === 'easy'
    ? aff.slice(0, Math.min(3, aff.length))
    : aff.slice(0, Math.min(cpuTierHardPlus(tier) ? 8 : 6, aff.length));

  const buildBatonPayload = (c, slot, existing, ec) => {
    const batonCost = Math.max(0, ec - stageMemberEffectiveCost(existing, cpu));
    const aeBaton = affordableEnergyForBatonPlay(cpu, existing, c);
    if (!canAffordBatonWithOptionalDouble(cpu, c, slot, existing, aeBaton, ec)) return null;
    const payload = { card_id: c.instance_id, slot, baton_id: existing.instance_id };
    if (aeBaton < batonCost) {
      const second = bestDoubleBatonSecond(cpu, c, slot, existing, aeBaton, ec);
      if (!second) return null;
      payload.baton_id2 = second.member.instance_id;
    }
    if (cpuMemberPlayBlacklisted(payload.card_id, payload.slot, payload.baton_id)) return null;
    return payload;
  };

  const placements = [];
  for (const c of consider) {
    const ec = effectiveCost(c, hand);
    const base = tier === 'easy'
      ? cpuMemberNovelty(c, stageColors) + (c.blade || 0) * 0.15 + cpuMemberHeartCount(c) * 0.2
      : cpuScoreMember(c, cpu, hand, stageColors, tier, read, s);
    const label = `member c${c.cost || 0}/b${c.blade || 0}`;
    const shuffleAb = (c.abilities || []).find(a => a.type === 'play_cost_reduction_if_shuffle_wr_members');
    const wrMembers = (cpu.waiting_room || []).filter(x => x && (x.card_type === 'メンバー' || x.card_type_en === 'Member')).length;
    const shuffleOpts = (shuffleAb && wrMembers > 0)
      ? { bp7_shuffle_wr_members: true }
      : {};
    const playEc = shuffleOpts.bp7_shuffle_wr_members
      ? Math.max(0, ec - Number(shuffleAb.amount || 2))
      : ec;
    const preferCenter = read && tier !== 'easy' && (c.blade || 0) >= (read.strongestActive?.blade || 0);
    let slotOrder = preferCenter ? ['center', 'left', 'right'] : ['center', 'left', 'right'];
    if (tier !== 'easy') slotOrder = cpuPreferredBatonSlotOrder(cpu, c, tier, hand);

    for (const slot of slotOrder) {
      const existing = cpu.stage?.[slot];
      if (!existing) {
        if (ae < playEc) continue;
        const payload = Object.assign({ card_id: c.instance_id, slot }, shuffleOpts);
        if (cpuMemberPlayBlacklisted(payload.card_id, payload.slot)) continue;
        const unlock = tier === 'easy' ? 0 : cpuLiveUnlockAtSlot(c, cpu, tier, hand, slot);
        const emptyBonus = (preferCenter && slot === 'center' ? 0.35 : 0)
          + (tier === 'easy' ? cpuMemberNovelty(c, stageColors) * 0.2 : 0.9)
          + unlock * 0.25;
        placements.push({
          kind: 'play_member',
          score: base + emptyBonus,
          payload,
          label: `${label}@${slot}`,
        });
        continue;
      }
      if (stageMemberEnteredThisTurn(existing, s.turn)) continue;

      // Baton path — score heart/blade/Live-color delta; skip clear downgrades.
      if (!memberBlocksBaton(existing) && !memberBatonRestricted(existing, c) && ec >= 1) {
        const payload = buildBatonPayload(c, slot, existing, ec);
        if (payload) {
          const boardDelta = cpuScoreBatonBoardDelta(c, existing, slot, cpu, hand, tier);
          const minDelta = tier === 'easy' ? -1.8 : cpuTierHardPlus(tier) ? -0.35 : -0.85;
          if (boardDelta >= minDelta) {
            placements.push({
              kind: 'play_member',
              score: base + boardDelta + (tier === 'easy' ? 0 : 0.15),
              payload,
              label: `${label} baton@${slot}`,
            });
          }
        }
      }

      // Full-cost replace (no baton) only when clearly better.
      if (ae >= playEc) {
        const boardDelta = cpuScoreBatonBoardDelta(c, existing, slot, cpu, hand, tier);
        const isUpgrade = tier === 'easy'
          ? (cpuMemberNovelty(c, stageColors) > cpuMemberNovelty(existing, stageColors)
            || (cpuMemberNovelty(c, stageColors) === cpuMemberNovelty(existing, stageColors)
              && (cpuMemberHeartCount(c) > cpuMemberHeartCount(existing)
                || ((c.blade || 0) > (existing.blade || 0)))))
          : boardDelta >= (cpuTierHardPlus(tier) ? 0.9 : 0.55);
        if (isUpgrade) {
          const payload = Object.assign({ card_id: c.instance_id, slot }, shuffleOpts);
          if (!cpuMemberPlayBlacklisted(payload.card_id, payload.slot)) {
            placements.push({
              kind: 'play_member',
              score: base + boardDelta - 0.4,
              payload,
              label: `${label} replace@${slot}`,
            });
          }
        }
      }
    }
  }

  if (!placements.length) return null;
  placements.sort((a, b) => b.score - a.score);
  let pickIdx = 0;
  if (suboptimalPick && tier === 'normal' && placements.length >= 2 && Math.random() < 0.28) {
    if (placements[0].score - placements[1].score <= 1.25) pickIdx = 1;
  }
  return placements[pickIdx];
}

function cpuListMainActions(s, cpu, ctx) {
  const actions = cpuListActivateCandidates(s, cpu, ctx);
  const memberPlan = cpuPlanMemberPlay(s, cpu, cpu.hand || [], ctx.tier, ctx.read, { suboptimalPick: false });
  if (memberPlan) actions.push(memberPlan);
  return actions;
}

function cpuBestMainAction(actions, ctx, s = null, pid = null) {
  if (!actions.length) return null;
  pid = pid || ctx?.cpuId || (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2');
  const posture = cpuPosture(ctx);
  const min = cpuMainActionMinScore(ctx.tier, posture);
  // Eval-ranked path (Normal/Hard): blend board evaluateState into candidate scores.
  if (ctx.tier !== 'easy' && typeof cpuRankMainActions === 'function' && s) {
    const ranked = cpuRankMainActions(s, pid, actions, ctx);
    if (!ranked) return null;
    // Preserve behind-tiebreak: prefer activate over play when eval scores are neck-and-neck.
    if (actions.length >= 2 && posture.behind) {
      const scored = actions.map(a => ({
        ...a,
        evalScore: typeof cpuScoreAction === 'function'
          ? cpuScoreAction(s, pid, a, ctx, { peers: actions })
          : a.score,
      })).sort((a, b) => b.evalScore - a.evalScore);
      const top = scored[0];
      const second = scored[1];
      if (top && second
        && Math.abs(top.evalScore - second.evalScore) <= 0.2
        && top.kind === 'play_member' && second.kind === 'activate') {
        return second.evalScore >= min ? second : null;
      }
    }
    return ranked.evalScore >= min || ranked.score >= min ? ranked : null;
  }
  const sorted = [...actions].sort((a, b) => b.score - a.score);
  if (sorted.length >= 2 && posture.behind) {
    const top = sorted[0];
    const second = sorted[1];
    if (Math.abs(top.score - second.score) <= 0.15 && top.kind === 'play_member' && second.kind === 'activate') {
      sorted[0] = second;
      sorted[1] = top;
    }
  }
  const best = sorted[0];
  return best.score >= min ? best : null;
}

function cpuTryActivate(s, cpu, tier, winPressure = 0, read = null) {
  if (tier === 'easy') return false;
  const ctx = {
    tier,
    winPressure,
    read,
    sit: read ? {
      behind: (read.successCount ?? 0) > (cpu.success_lives?.length ?? 0),
      critical: (read.successCount ?? 0) >= 2,
    } : null,
  };
  const candidates = cpuListActivateCandidates(s, cpu, ctx);
  if (!candidates.length) return false;
  cpuAct('activate_ability', candidates[0].payload);
  return true;
}

function cpuPlayMemberFromHand(s, cpu, hand, tier, read = null) {
  const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
  const stageColors = cpuStageColors(cpu);
  const memberRows = hand
    .filter(c => isCpuMemberCard(c) && effectiveCost(c, hand) <= ae)
    .map(c => ({
      score: tier === 'easy'
        ? cpuMemberNovelty(c, stageColors)
        : cpuScoreMember(c, cpu, hand, stageColors, tier, read),
      label: `member c${c.cost || 0}/b${c.blade || 0}`,
    }))
    .sort((a, b) => b.score - a.score)
    .slice(0, 3);
  const plan = cpuPlanMemberPlay(s, cpu, hand, tier, read, { suboptimalPick: tier === 'normal' });
  const liveCtx = cpuHandLiveContext(cpu);
  cpuTraceScore('main', 'play_member_pick', memberRows, {
    picked: plan?.label || null,
    slot: plan?.payload?.slot || null,
    liveInHand: liveCtx.liveInHand.length,
    viableLives: liveCtx.viableLives.length,
    unlockBonus: plan?.payload?.card_id
      ? +cpuMemberLiveUnlockBonus(
        hand.find(c => c.instance_id === plan.payload.card_id), cpu, tier, hand
      ).toFixed(2)
      : 0,
  });
  if (!plan) return false;
  cpuAct('play_member', plan.payload);
  return true;
}

function cpuMulligan() {
  const snap = cpuSnapshot();
  if (!snap) return;
  const { cpu, s } = snap;
  const hand = cpu.hand || [];
  const tier = cpuDiff();
  if (tier === 'easy' || !hand.length) {
    cpuAct('mulligan', { card_ids: [] });
    return;
  }
  const protectedLiveIds = new Set(
    hand.filter(c => c.card_type === 'ライブ' && (c.score || 0) >= 2).map(c => c.instance_id)
  );
  const protectedAnchorIds = new Set(
    hand.filter(c => cpuIsMulliganAnchorMember(c, tier)).map(c => c.instance_id)
  );
  const isProtected = (c) => protectedLiveIds.has(c.instance_id) || protectedAnchorIds.has(c.instance_id);
  const members = hand.filter(c => c.card_type === 'メンバー');
  const cheapMembers = members.filter(c => (c.cost || 0) <= 2);
  const ctx = cpuCtx(s, cpu);
  const read = ctx.read;
  const behind = ctx.sit?.behind;
  let toSwap = [];
  if (members.length === 0 && hand.length >= 4) {
    toSwap = hand.filter(c => !isProtected(c)).map(c => c.instance_id);
  } else if (members.length <= 1 && hand.length >= 6) {
    toSwap = hand.filter(c => {
      if (isProtected(c)) return false;
      return c.card_type !== 'メンバー' || (c.cost || 0) >= 4;
    }).map(c => c.instance_id);
  } else if (cpuTierHardPlus(tier) && cheapMembers.length >= 2 && hand.length >= 7) {
    const lowLives = hand.filter(c => c.card_type === 'ライブ' && (c.score || 0) <= 1);
    if (lowLives.length >= 2) toSwap = lowLives.map(c => c.instance_id);
  }
  if (!toSwap.length && behind && members.length) {
    const stageColors = cpuStageColors(cpu);
    const badMembers = members
      .filter(c => (c.cost || 0) >= 4 || cpuScoreMember(c, cpu, hand, stageColors, tier, read) < 1.5)
      .map(c => c.instance_id);
    if (badMembers.length) toSwap = badMembers;
  }
  if (!toSwap.length) {
    const lowLives = hand.filter(c => c.card_type === 'ライブ' && (c.score || 0) <= 1 && !isProtected(c));
    if (lowLives.length) toSwap = lowLives.map(c => c.instance_id);
  }
  if (typeof cpuPolicyMulliganReturn === 'function') {
    const extra = hand.filter(c => !toSwap.includes(c.instance_id) && !isProtected(c) && cpuPolicyMulliganReturn(c, tier));
    if (extra.length) toSwap = toSwap.concat(extra.map(c => c.instance_id));
  }
  cpuAct('mulligan', { card_ids: toSwap.slice(0, cpuTierHardPlus(tier) ? 4 : 3) });
}

function cpuFindDebugTestCard(cpu, s) {
  const cfg = s?.debug_card_test;
  if (!cfg || (cfg.test_pid && cfg.test_pid !== (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2'))) return null;
  const iid = cfg.instance_id;
  const match = (c) => c && (c.debug_test_card || (iid && c.instance_id === iid));
  for (const c of cpu.hand || []) {
    if (match(c)) return { zone: 'hand', card: c };
  }
  for (const slot of ['left', 'center', 'right']) {
    const m = cpu.stage?.[slot];
    if (match(m)) return { zone: 'stage', slot, card: m };
  }
  return null;
}

function cpuTryDebugTestCard(s, cpu, tier, winPressure, read) {
  const found = cpuFindDebugTestCard(cpu, s);
  if (!found) return false;
  if (found.zone === 'hand' && found.card.card_type === 'メンバー') {
    const c = found.card;
    const hand = cpu.hand || [];
    const ec = effectiveCost(c, hand);
    const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
    const slotOrder = ['center', 'left', 'right'];
    const empty = slotOrder.find(sl => !cpu.stage?.[sl]);
    if (empty && ae >= ec) {
      cpuAct('play_member', { card_id: c.instance_id, slot: empty });
      return true;
    }
    for (const slot of slotOrder) {
      const existing = cpu.stage?.[slot];
      if (!existing || stageMemberEnteredThisTurn(existing, s.turn)) continue;
      if (!memberBlocksBaton(existing) && !memberBatonRestricted(existing, c) && ec >= 1) {
        const bc = Math.max(0, ec - stageMemberEffectiveCost(existing, cpu));
        const aeBaton = affordableEnergyForBatonPlay(cpu, existing, c);
        if (canAffordBatonWithOptionalDouble(cpu, c, slot, existing, aeBaton, ec)) {
          const payload = { card_id: c.instance_id, slot, baton_id: existing.instance_id };
          if (aeBaton < bc) {
            const second = bestDoubleBatonSecond(cpu, c, slot, existing, aeBaton, ec);
            if (!second) continue;
            payload.baton_id2 = second.member.instance_id;
          }
          cpuAct('play_member', payload);
          return true;
        }
      }
      if (ae >= ec) {
        cpuAct('play_member', { card_id: c.instance_id, slot });
        return true;
      }
    }
  }
  if (found.zone === 'stage') {
    const abilities = collectActivatableAbilities(s, typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2').filter(a => {
      const card = a.card;
      return card && (card.debug_test_card || card.instance_id === found.card.instance_id);
    });
    if (abilities.length) {
      cpuAct('activate_ability', cpuBuildActivatePayload(abilities[0], cpu, tier, winPressure, read));
      return true;
    }
  }
  return false;
}

function cpuPickCoinFirstPlayer(s) {
  const winner = s?.coin_flip?.winner || 'p2';
  const opp = winner === 'p1' ? 'p2' : 'p1';
  const tier = cpuDiff();
  if (tier === 'easy') return Math.random() < 0.5 ? winner : opp;
  return winner;
}

// --- CPU opponent (Practice vs CPU) --------------------------------------------
// doCPU schedules actions for p2: mulligan, main phase heuristics, live_set placement,
// and cpuResolvePrompt for pending_prompt. Difficulty tiers adjust aggression.

function cpuOpponentId() {
  if (G.cpuPlayerId === 'p1' || G.cpuPlayerId === 'p2') return G.cpuPlayerId;
  return G.playerId === 'p1' ? 'p2' : 'p1';
}

function doCPU(s) {
  if (isReplayViewing()) return;
  if (G.isTutorial && !G.tutorialLive) return;
  if (G.tutorialLive && G.tutorialHoldCpu) return;
  ensurePollHoldReleased(s);
  clearStaleCpuPromptBusyIfResolved(s);
  if (s) syncCpuDifficultyFromState(s);
  const cpuId = cpuOpponentId();
  const cpu = s.players?.[cpuId];
  if (!cpu || !G.cpuToken) return;
  const ph = s.phase;
  if (s.pending_prompt) {
    if (s.pending_prompt.responder === cpuId) {
      TCG_DEBUG.log('cpu', 'prompt pending', { type: s.pending_prompt?.type, seq: s.seq, diff: cpuDiff() });
      scheduleCpuResolvePrompt(s, cpu);
    } else {
      // Opponent-facing prompt (e.g. Wait pick) — do not play/activate until it clears.
      TCG_DEBUG.log('cpu', 'waiting for opponent prompt', {
        type: s.pending_prompt?.type, responder: s.pending_prompt?.responder, seq: s.seq,
      });
    }
    return;
  }
  clearCpuPromptTracking();
  if (ph === 'coin_flip' && s.coin_flip) {
    const flip = s.coin_flip;
    if (!flip.ready?.[cpuId]) {
      if (G.tutorialLive && !tutorialLiveCoinActive()) return;
      TCG_DEBUG.log('cpu', 'ack_coin_flip');
      const ackDelay = G.tutorialLive ? 280 : 1100;
      cpuSchedule(() => cpuAct('ack_coin_flip', {}), ackDelay);
      return;
    }
    if (G.tutorialLive && !tutorialLiveCoinActive()) return;
    if (flip.winner === cpuId) {
      if (!flip.ready?.p1 || !flip.ready?.p2) {
        cpuSchedule(() => doCPU(G.gameState), 480);
        return;
      }
      const chooseKey = `${s.seq}|${flip.winner}`;
      if (G._cpuCoinFlipChooseKey === chooseKey) return;
      G._cpuCoinFlipChooseKey = chooseKey;
      const pick = cpuPickCoinFirstPlayer(s);
      TCG_DEBUG.log('cpu', 'choose_first_player', { pick, diff: cpuDiff() });
      cpuSchedule(() => cpuAct('choose_first_player', { first_player: pick }), 600);
      return;
    }
    return;
  }
  if (ph === 'setup' && !cpu.ready_mulligan) {
    if (G.tutorialLive && tutorialLiveStepId() !== 'mulligan') return;
    TCG_DEBUG.log('cpu', 'mulligan', { diff: cpuDiff() });
    cpuSchedule(() => cpuMulligan(), 700);
    return;
  }
  if ((ph === 'main_first' || ph === 'main_second') && s.active_player === cpuId) {
    if (G._cpuMainBusy || G._cpuActBusy) {
      TCG_DEBUG.log('cpu', 'main skipped — busy', { main: !!G._cpuMainBusy, act: !!G._cpuActBusy, diff: cpuDiff() });
      return;
    }
    TCG_DEBUG.log('cpu', 'main phase', { turn: s.turn, hand: cpu.hand?.length, diff: cpuDiff() });
    cpuSchedule(() => { void cpuMain(); }, 900);
    return;
  }
  if (ph === 'live_set' && s.active_player === cpuId && !s.live_ready?.[cpuId]) {
    TCG_DEBUG.log('cpu', 'live_set', { hand: cpu.hand?.length, liveZone: cpu.live_zone?.length, diff: cpuDiff() });
    if (typeof armCpuLiveSetHangWatch === 'function') armCpuLiveSetHangWatch(s);
    cpuSchedule(() => cpuLiveSet(), 750);
    return;
  }
  if (typeof clearCpuLiveSetHangWatch === 'function') clearCpuLiveSetHangWatch();
  const noActionKey = `${s.turn}|${s.phase}|${s.active_player}|${s.seq}|${s.pending_prompt?.type || ''}|${s.pending_prompt?.responder || ''}`;
  TCG_DEBUG.logOnce('cpu', `no-action:${noActionKey}`, 'no action', TCG_DEBUG.snap(s));
}

// Heart colors currently provided by CPU's stage (mirrors stage_heart_pool in sim_test.py)
function normalizeHeartColor(color) {
  const c = String(color || 'any').toLowerCase().trim();
  if (
    c === 'all' || c === 'gray' || c === 'wild' || c === 'any' || c === ''
    || c === 'all2' || c === 'all_2' || c === 'b_heart07' || c === 'heart07'
  ) {
    return 'any';
  }
  return c;
}

function stageHeartPool(p) {
  const pool = [];
  Object.values(p.stage||{}).forEach(m => {
    if (!m) return;
    memberEffectiveHeartGroups(m).forEach(hg => {
      for (let i = 0; i < (hg.count || 1); i++) pool.push(normalizeHeartColor(hg.color));
    });
  });
  return pool;
}

function sortHeartRequirements(required) {
  const colored = [], wild = [];
  for (const req of required || []) {
    const c = normalizeHeartColor(req.color);
    if (c === 'any') wild.push({ ...req, color: c });
    else colored.push({ ...req, color: c });
  }
  return [...colored, ...wild];
}

/** Mirror server applyLiveHeartReductions for Live card requirement display. */
function applyClientLiveHeartReductions(required, liveCard) {
  let req = (required || []).map(h => ({
    color: h.color || 'any',
    count: Number(h.count || 1),
  }));
  let generic = Number(liveCard?.hearts_reduction || 0);
  while (generic > 0 && req.length) {
    const i = req.length - 1;
    const c = req[i].count;
    if (c <= generic) {
      generic -= c;
      req.pop();
    } else {
      req[i].count = c - generic;
      generic = 0;
    }
  }
  const colorReductions = { ...(liveCard?.hearts_color_reduction || {}) };
  const grayOnly = Number(liveCard?.hearts_reduction_gray || 0);
  if (grayOnly > 0) {
    colorReductions.any = Number(colorReductions.any || 0) + grayOnly;
  }
  for (const [color, nRaw] of Object.entries(colorReductions)) {
    let n = Number(nRaw || 0);
    if (n <= 0) continue;
    const want = normalizeHeartColor(color);
    for (const h of req) {
      if (n <= 0) break;
      if (normalizeHeartColor(h.color) !== want) continue;
      const take = Math.min(h.count, n);
      h.count -= take;
      n -= take;
    }
  }
  const colorIncreases = liveCard?.hearts_color_increase || {};
  for (const [color, nRaw] of Object.entries(colorIncreases)) {
    const n = Number(nRaw || 0);
    if (n <= 0) continue;
    const want = normalizeHeartColor(color);
    let applied = false;
    for (const h of req) {
      if (normalizeHeartColor(h.color) === want) {
        h.count += n;
        applied = true;
        break;
      }
    }
    if (!applied) req.push({ color: want === 'any' ? 'any' : want, count: n });
  }
  // EMOTION / score_per_named_success_live (#97)
  const grayInc = Number(liveCard?.hearts_increase_gray || 0)
    + Number(liveCard?.hearts_increase || 0);
  if (grayInc > 0) {
    let applied = false;
    for (const h of req) {
      if (normalizeHeartColor(h.color) === 'any') {
        h.count += grayInc;
        applied = true;
        break;
      }
    }
    if (!applied) req.push({ color: 'any', count: grayInc });
  }
  return req.filter(h => h.count > 0);
}

/** Effective Live requirements for UI (card reductions + μ's Dreamin-style Success aura). */
function liveCardPrintedScoreClient(liveCard) {
  if (liveCard?._printed_score != null) return Number(liveCard._printed_score);
  const no = liveCard?.card_no;
  if (no && G.allCards?.[no] && G.allCards[no].score != null) {
    return Number(G.allCards[no].score);
  }
  return Number(liveCard?.score || 0);
}

/** True when Live Start / similar effects changed this Live's printed heart requirements. */
function liveCardRequirementsModified(liveCard) {
  if (!liveCard) return false;
  if (Number(liveCard.hearts_reduction || 0) > 0) return true;
  if (Number(liveCard.hearts_reduction_gray || 0) > 0) return true;
  const colorRed = liveCard.hearts_color_reduction || {};
  for (const n of Object.values(colorRed)) {
    if (Number(n || 0) > 0) return true;
  }
  const colorInc = liveCard.hearts_color_increase || {};
  for (const n of Object.values(colorInc)) {
    if (Number(n || 0) > 0) return true;
  }
  if (Number(liveCard.hearts_increase_gray || 0) > 0) return true;
  if (Number(liveCard.hearts_increase || 0) > 0) return true;
  return false;
}

/** Colors whose required count differs from printed (for yellow count styling). */
function liveReqModifiedColors(printed, effective) {
  const pMap = new Map();
  const eMap = new Map();
  (typeof groupHeartsByColor === 'function'
    ? groupHeartsByColor(printed || [])
    : (printed || [])).forEach((h) => {
    const c = normalizeHeartColor(h.color || 'any');
    pMap.set(c, (pMap.get(c) || 0) + Number(h.count || 1));
  });
  (typeof groupHeartsByColor === 'function'
    ? groupHeartsByColor(effective || [])
    : (effective || [])).forEach((h) => {
    const c = normalizeHeartColor(h.color || 'any');
    eMap.set(c, (eMap.get(c) || 0) + Number(h.count || 1));
  });
  const mod = new Set();
  for (const [color, pCount] of pMap) {
    if ((eMap.get(color) || 0) !== pCount) mod.add(color);
  }
  for (const [color, eCount] of eMap) {
    if ((pMap.get(color) || 0) !== eCount) mod.add(color);
  }
  return mod;
}

/**
 * stageMemberLiveCostInfo — defined in board-render.js (loaded before this file).
 * Do not redeclare here or board badges lose the shared helper.
 */

function effectiveLiveRequiredHearts(liveCard, state, pid) {
  let req = (liveCard?.required_hearts || liveCard?.hearts || []).map(h => ({
    color: h.color || 'any',
    count: Number(h.count || 1),
  }));
  const p = state?.players?.[pid];
  const printedScore = liveCardPrintedScoreClient(liveCard);
  if (p && (liveCard?.card_type === 'ライブ' || liveCard?.card_type_en === 'Live')
      && (liveCard?.group || '') === "μ's"
      && printedScore >= 5) {
    let reduceAmt = 0;
    for (const sl of p.success_lives || []) {
      for (const ab of sl.abilities || []) {
        if ((ab.trigger || '') !== 'continuous') continue;
        if ((ab.type || '') !== 'reduce_hearts_mus_live_min_score_success') continue;
        if (printedScore < Number(ab.min_score ?? 5)) continue;
        reduceAmt = Math.max(reduceAmt, Number(ab.reduce ?? 2));
      }
    }
    if (reduceAmt > 0) {
      req = applyClientLiveHeartReductions(req, {
        hearts_color_reduction: { any: reduceAmt },
      });
    }
  }
  return applyClientLiveHeartReductions(req, liveCard || {});
}

// Reuses the same checkHearts algorithm the server uses, so the CPU's "is this
// plausible" check matches what the engine will actually decide.
function cpuCheckHearts(available, required) {
  const isWild = c => c === 'any';
  const norm = (available || []).map(normalizeHeartColor);
  let pool = norm.filter(h => !isWild(h));
  let wildCount = norm.filter(h => isWild(h)).length;
  for (const req of sortHeartRequirements(required)) {
    const color = req.color, need0 = req.count||1;
    let need = need0;
    if(isWild(color)) {
      const take = Math.min(need, pool.length);
      pool = pool.slice(take);
      need -= take;
      if(need>0) { if(wildCount>=need){wildCount-=need;need=0;} else return false; }
    } else {
      const idxs = []; pool.forEach((h,i)=>{ if(h===color) idxs.push(i); });
      if(idxs.length >= need) {
        const remove = new Set(idxs.slice(0,need));
        pool = pool.filter((_,i)=>!remove.has(i));
      } else {
        const needWild = need - idxs.length;
        if(wildCount >= needWild) {
          const remove = new Set(idxs);
          pool = pool.filter((_,i)=>!remove.has(i));
          wildCount -= needWild;
        } else return false;
      }
    }
  }
  return true;
}

function cpuApplyMainAction(best, stateEval) {
  if (best && best.kind !== 'end_main') {
    if (best.kind === 'activate') cpuAct('activate_ability', best.payload);
    else cpuAct('play_member', best.payload);
    return;
  }
  cpuTraceScore('main', 'end_main', [], {
    reason: best?.kind === 'end_main' ? 'eval prefers end_main' : 'no candidate above threshold',
    evaluateState: stateEval != null ? +stateEval.toFixed(1) : undefined,
  });
  cpuAct('end_main', {});
}

async function cpuMain() {
  if (G._cpuMainBusy) return;
  const snap = cpuSnapshot();
  if (!snap) return;
  const { s, cpu, cpuId } = snap;
  const ph = s.phase;
  if (s.active_player !== cpuId || (ph !== 'main_first' && ph !== 'main_second')) return;
  G._cpuMainBusy = true;
  try {
  const ctx = cpuCtx(s, cpu, cpuId);
  const hand = cpu.hand || [];
  if (s.pending_prompt?.responder === cpuId) {
    if (typeof cpuExpertClearQueue === 'function') cpuExpertClearQueue();
    return;
  }
  if (!s.pending_prompt) clearDeferredPromptState();
  if (s.debug_card_test && cpuTryDebugTestCard(s, cpu, ctx.tier, ctx.winPressure, ctx.read)) return;
  if (ctx.tier === 'easy') {
    // Occasional strong activates so Easy still exercises skills without playing optimally.
    if (Math.random() < 0.28) {
      const acts = cpuListActivateCandidates(s, cpu, ctx);
      if (acts[0] && (acts[0].score || 0) >= 3.0) {
        cpuApplyMainAction(acts[0]);
        return;
      }
    }
    if (cpuPlayMemberFromHand(s, cpu, hand, ctx.tier, ctx.read)) return;
    cpuAct('end_main', {});
    return;
  }

  // Expert: dry-run sequence search (falls back to Hard ranking on failure).
  if (ctx.tier === 'expert' && typeof cpuExpertChooseMainAction === 'function') {
    let expertBest = null;
    try {
      expertBest = await cpuExpertChooseMainAction(s, cpu, ctx, cpuId);
    } catch (e) {
      TCG_DEBUG.warn('cpu', 'expert choose failed', e?.message || e);
      if (typeof cpuExpertClearQueue === 'function') cpuExpertClearQueue();
      if (!G._cpuExpertFallbackToast) {
        G._cpuExpertFallbackToast = true;
        toast('Expert search failed — using Hard heuristics', 3200);
      }
    }
    // Re-check after await — polls/watchdog must not apply a stale Expert plan.
    const live = G.gameState || s;
    if (live.active_player !== cpuId
        || (live.phase !== 'main_first' && live.phase !== 'main_second')
        || live.pending_prompt?.responder === cpuId) {
      return;
    }
    if (expertBest) {
      const stateEval = typeof cpuEvaluateState === 'function'
        ? cpuEvaluateState(live, cpuId, { tier: ctx.tier, read: ctx.read })
        : null;
      cpuTraceScore('main', 'decision', [{ kind: expertBest.kind, score: expertBest.evalScore || expertBest.score, label: expertBest.label }], {
        picked: expertBest.kind,
        ending: expertBest.kind === 'end_main',
        expert: true,
        evaluateState: stateEval != null ? +stateEval.toFixed(1) : undefined,
      });
      cpuApplyMainAction(expertBest, stateEval);
      return;
    }
    if (!G._cpuExpertFallbackToast) {
      G._cpuExpertFallbackToast = true;
      TCG_DEBUG.warn('cpu', 'expert search returned null — Hard heuristics fallback');
    }
  }

  const actions = cpuListMainActions(G.gameState || s, cpu, ctx);
  // Synthetic end_main peer so Hard opportunity-cost can prefer ending vs a weak play.
  if (ctx.tier !== 'easy') {
    actions.push({ kind: 'end_main', score: 0, label: 'end_main', payload: {} });
  }
  const board = G.gameState || s;
  const best = cpuBestMainAction(actions, ctx, board, cpuId);
  const posture = cpuPosture(ctx);
  const liveCtx = cpuHandLiveContext(cpu);
  const ladderPlan = cpuBatonLadderPlan(board.turn || 1, cpuMaxStageCost(cpu), cpu);
  const stateEval = typeof cpuEvaluateState === 'function'
    ? cpuEvaluateState(board, cpuId, { tier: ctx.tier, read: ctx.read })
    : null;
  cpuTraceScore('main', 'decision', actions.slice(0, 5).map(a => ({
    kind: a.kind,
    score: a.score,
    label: a.label,
  })), {
    picked: best ? best.kind : null,
    minScore: cpuMainActionMinScore(ctx.tier, posture),
    ending: !best || best.kind === 'end_main',
    liveInHand: liveCtx.liveInHand.length,
    viableLives: liveCtx.viableLives.length,
    wantsLiveSearch: cpuWantsLiveSearch(cpu, ctx.tier),
    ladderPhase: ladderPlan.phase,
    maxStageCost: cpuMaxStageCost(cpu),
    evaluateState: stateEval != null ? +stateEval.toFixed(1) : undefined,
    tier: ctx.tier,
  });
  cpuApplyMainAction(best, stateEval);
  } finally {
    G._cpuMainBusy = false;
  }
}

function cpuSelectIndependentLives(pool, liveInHand, targetLiveCount, tier, winPressure = 0, read = null, handLen = 0, cpu = null, ctx = null) {
  if (targetLiveCount <= 0) return [];
  const scoreLive = (c) => {
    let sc = cpuScoreLiveForSet(c, tier, winPressure, read, cpu) + cpuLiveJitter(tier);
    if (tier !== 'easy' && typeof cpuEvalLiveBonus === 'function' && ctx?.s) {
      sc += cpuEvalLiveBonus(ctx.s, ctx.cpuId || (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2'), c, ctx);
    }
    // Prefer stage-clearable Lives over Yell-dependent ones when scores are close.
    const stagePool = ctx?.liveCtx?.stagePool || stageHeartPool(cpu || {});
    if (cpuCheckHearts(stagePool, cpuLiveRequiredHearts(c))) sc += cpuTierHardPlus(tier) ? 1.1 : 0.55;
    return sc;
  };
  const viable = liveInHand
    .filter(c => cpuCheckHearts(pool, cpuLiveRequiredHearts(c)))
    .sort((a, b) => scoreLive(b) - scoreLive(a));
  if (!viable.length) return [];

  const posture = ctx ? cpuPosture(ctx) : null;
  const minVal = tier === 'easy'
    ? cpuLiveCommitMin(tier, winPressure)
    : cpuTierLiveCommitMin(tier, posture, winPressure);
  const handRetainPenalty = (n) => {
    let p = n * (cpuTierHardPlus(tier) ? 0.08 : tier === 'normal' ? 0.18 : 0.4);
    if (handLen > 0 && handLen - n < 2) p += cpuTierHardPlus(tier) ? 0.15 : 0.35;
    return p;
  };
  /** Jointly allocate a shared heart pool (incl. Yell wilds) across a combo. */
  const comboFeasible = (cards) => {
    let working = pool.slice();
    for (const c of cards) {
      if (!cpuCheckHearts(working, cpuLiveRequiredHearts(c))) return false;
      // Approximate consumption: remove exact requirement slots from a working pool.
      const req = cpuLiveRequiredHearts(c) || [];
      for (const r of sortHeartRequirements(req)) {
        let need = r.count || 1;
        const color = normalizeHeartColor(r.color);
        if (color === 'any') {
          while (need > 0 && working.length) {
            working.pop();
            need--;
          }
        } else {
          for (let i = working.length - 1; i >= 0 && need > 0; i--) {
            if (working[i] === color) {
              working.splice(i, 1);
              need--;
            }
          }
          while (need > 0) {
            const wi = working.lastIndexOf('any');
            if (wi < 0) return false;
            working.splice(wi, 1);
            need--;
          }
        }
      }
    }
    return true;
  };
  const comboValue = (cards) =>
    cards.reduce((s, c) => s + scoreLive(c), 0) - handRetainPenalty(cards.length);

  let bestCombo = [];
  let bestComboVal = -99;
  if ((cpuTierHardPlus(tier) || tier === 'normal') && viable.length > 1) {
    const limit = Math.min(targetLiveCount, viable.length, 3);
    for (let mask = 1; mask < (1 << Math.min(viable.length, 6)); mask++) {
      const pick = [];
      for (let i = 0; i < viable.length && pick.length < 6; i++) {
        if (mask & (1 << i)) pick.push(viable[i]);
      }
      if (pick.length > limit || pick.length === 0) continue;
      if (comboFeasible(pick)) {
        const val = comboValue(pick);
        if (val > bestComboVal) { bestComboVal = val; bestCombo = pick; }
      }
    }
    if (bestCombo.length && (bestComboVal >= minVal || cpuTierHardPlus(tier))) return bestCombo;
  }

  // Greedy joint pick
  const greedy = [];
  let working = pool.slice();
  for (const c of viable) {
    if (greedy.length >= targetLiveCount) break;
    if (!cpuCheckHearts(working, cpuLiveRequiredHearts(c))) continue;
    greedy.push(c);
    // consume approximately
    const req = cpuLiveRequiredHearts(c) || [];
    for (const r of sortHeartRequirements(req)) {
      let need = r.count || 1;
      const color = normalizeHeartColor(r.color);
      if (color === 'any') {
        while (need > 0 && working.length) { working.pop(); need--; }
      } else {
        for (let i = working.length - 1; i >= 0 && need > 0; i--) {
          if (working[i] === color) { working.splice(i, 1); need--; }
        }
        while (need > 0) {
          const wi = working.lastIndexOf('any');
          if (wi < 0) break;
          working.splice(wi, 1);
          need--;
        }
      }
    }
  }
  if (greedy.length && (comboValue(greedy) >= minVal || cpuTierHardPlus(tier))) return greedy;

  const top = viable[0];
  if (cpuTierHardPlus(tier) && top) return greedy.length ? greedy : [top];
  if (tier === 'normal' && top && (top.score || 0) >= 1) return greedy.length ? greedy : [top];
  if (scoreLive(top) >= minVal) return greedy.length ? greedy : [top];
  if (tier === 'easy' && viable.length) return viable.slice(0, 1);
  return greedy;
}

function cpuLiveBluffMembers(members, tier, maxBluffs, handLen, winPressure = 0, read = null) {
  if (maxBluffs <= 0 || !members.length) return [];
  const cheapMembers = [...members].sort((a, b) => (a.cost || 0) - (b.cost || 0));
  const bluffs = [];
  const maxBluffCost = winPressure >= 1 ? 3 : winPressure >= 0.45 ? 2 : 2;
  let minHand = winPressure >= 1 ? 3 : winPressure >= 0.45 ? 4 : (cpuTierHardPlus(tier) ? 6 : 7);
  if (read?.behind) minHand = Math.max(4, minHand - 1);
  if (handLen < minHand && winPressure < 0.45) return [];

  const bluff = cheapMembers.find(m => (m.cost || 0) <= maxBluffCost) || cheapMembers[0];
  if (bluff) bluffs.push(bluff);
  if (maxBluffs >= 2 && winPressure >= 0.45 && cheapMembers.length > 1) {
    const second = cheapMembers.find(m => m.instance_id !== bluff?.instance_id && (m.cost || 0) <= 1);
    if (second) bluffs.push(second);
  }
  return bluffs.slice(0, maxBluffs);
}

// LIVE Phase: each Live checked independently against the full stage heart pool.
function cpuLiveSet() {
  const snap = cpuSnapshot();
  if (!snap) return;
  const { s, cpu, cpuId } = snap;
  if (s.phase !== 'live_set' || s.active_player !== cpuId || s.live_ready?.[cpuId]) return;
  const storage = cpu.live_zone || [];
  const alreadyStored = storage.length;
  const canAdd = 3 - alreadyStored;
  const hand = cpu.hand || [];
  const ctx = cpuCtx(s, cpu, cpuId);
  const { tier, winPressure, read, sit } = ctx;

  if (s.debug_card_test?.test_pid === cpuId && alreadyStored > 0) {
    cpuAct('end_live_set', {});
    return;
  }

  if (canAdd <= 0 || !hand.length) {
    cpuAct('end_live_set', {});
    return;
  }

  const pool = ctx.liveCtx?.pool || cpuClearHeartPool(cpu, s, cpuId, tier);
  const liveInHand = hand.filter(c => isCpuLiveCard(c));
  const members = hand.filter(c => isCpuMemberCard(c));
  const liveCtx = ctx.liveCtx || cpuHandLiveContext(cpu, { s, pid: cpuId, tier });
  const viableLives = liveCtx.viableLives || liveInHand.filter(c => cpuCheckHearts(pool, cpuLiveRequiredHearts(c)));
  ctx.liveCtx = liveCtx;

  let desiredTotal = cpuDesiredLiveStorageTotal(ctx, hand, viableLives, alreadyStored);
  if (tier !== 'easy' && viableLives.length && desiredTotal <= alreadyStored) {
    desiredTotal = Math.min(3, alreadyStored + Math.min(viableLives.length, cpuTierHardPlus(tier) ? 2 : 1));
  }
  if (alreadyStored >= desiredTotal) {
    cpuAct('end_live_set', {});
    return;
  }

  const needToAdd = desiredTotal - alreadyStored;
  const targetLives = Math.min(needToAdd, viableLives.length);
  const posture = cpuPosture(ctx);
  const commitMin = tier === 'easy'
    ? cpuLiveCommitMin(tier, winPressure)
    : cpuTierLiveCommitMin(tier, posture, winPressure);
  let chosenLives = cpuSelectIndependentLives(
    pool, liveInHand, targetLives, tier, winPressure, read, hand.length, cpu, ctx
  );
  // Expert/Hard: after Main is locked, rank Live-set candidates locally (hearts + race).
  if ((tier === 'expert' || tier === 'hard') && typeof cpuLiveRaceAdvice === 'function') {
    const stagePool = liveCtx.stagePool || stageHeartPool(cpu);
    const advice = cpuLiveRaceAdvice({
      tier,
      mySuccess: (cpu.success_lives || []).length,
      oppSuccess: read?.successCount ?? 0,
      canClearStage: viableLives.some(c => cpuCheckHearts(stagePool, cpuLiveRequiredHearts(c))),
      canClearYell: viableLives.length > 0,
      oppHearts: (read?.stageHearts || []).length,
      oppActive: read?.activeStage?.length || 0,
      oppVisibleScore: (read?.liveZoneRevealed || []).reduce((n, l) => n + (l.score || 0), 0),
    });
    if (advice.dropUnclearable && chosenLives.length) {
      const backed = chosenLives.filter(c => cpuLiveStageOrYellClear(c, cpu, s, cpuId, tier).yell);
      if (backed.length) chosenLives = backed;
    }
    if (advice.takeLowAtTwo && chosenLives.length > 1) {
      chosenLives = chosenLives
        .slice()
        .sort((a, b) => (cpuCheckHearts(stagePool, cpuLiveRequiredHearts(b)) ? 1 : 0)
          - (cpuCheckHearts(stagePool, cpuLiveRequiredHearts(a)) ? 1 : 0))
        .slice(0, 1);
    }
  }
  if (!chosenLives.length && viableLives.length && needToAdd > 0 && tier !== 'easy') {
    chosenLives = [...viableLives]
      .sort((a, b) => cpuScoreLiveForSet(b, tier, winPressure, read, cpu)
        - cpuScoreLiveForSet(a, tier, winPressure, read, cpu))
      .slice(0, Math.min(needToAdd, viableLives.length));
  }
  const toAdd = chosenLives.map(c => c.instance_id);
  const bestLiveScore = chosenLives.reduce((m, c) => Math.max(m, c.score || 0), 0);
  const comboVal = chosenLives.reduce((s, c) => s + cpuScoreLiveForSet(c, tier, winPressure, read, cpu), 0);

  const bluffWanted = cpuBluffSlotsWanted(ctx, chosenLives.length, desiredTotal, hand.length, bestLiveScore);
  if (bluffWanted > 0 && members.length) {
    cpuLiveBluffMembers(members, tier, Math.min(bluffWanted, canAdd - toAdd.length), hand.length, winPressure, read)
      .forEach(m => toAdd.push(m.instance_id));
  }

  if (!toAdd.length && desiredTotal > alreadyStored && members.length && bluffWanted > 0) {
    const bluff = cpuLiveBluffMembers(members, tier, 1, hand.length, winPressure, read)[0];
    if (bluff) toAdd.push(bluff.instance_id);
  }

  cpuTraceScore('live_set', '', viableLives.map(c => ({
    score: cpuScoreLiveForSet(c, tier, winPressure, read, cpu),
    cardScore: c.score || 0,
    id: c.instance_id,
  })), {
    viableCount: viableLives.length,
    comboValue: comboVal,
    commitMin,
    desiredTotal,
    chosenIds: toAdd,
    bluffCount: Math.max(0, toAdd.length - chosenLives.length),
  });

  if (toAdd.length) {
    cpuAct('set_live_cards', { card_ids: toAdd });
    return;
  }
  cpuAct('end_live_set', {});
}

function cpuScoreSurveilCandidate(c, cpu, hand, tier, read) {
  if ((c.card_type || c.card_type_en) === 'ライブ' || c.card_type_en === 'Live') {
    return cpuScoreLiveForSet(c, tier, cpuWinPressure(cpu), read, cpu);
  }
  if ((c.card_type || c.card_type_en) === 'メンバー' || c.card_type_en === 'Member') {
    return cpuScoreMember(c, cpu, hand, cpuStageColors(cpu), tier, read);
  }
  return (c.cost || 0) + (c.blade || 0);
}

function cpuHandDiscardFodderScore(hand, need, tier) {
  const ranked = [...(hand || [])].map(c => {
    if (c.card_type === 'ライブ') return Math.max(0, 5 - (c.score || 0) * 1.3);
    if (c.card_type === 'メンバー') {
      return Math.max(0, 6 - (c.blade || 0) * 0.8 - Math.min(c.cost || 0, 4) * 0.25);
    }
    return 3;
  }).sort((a, b) => b - a);
  return ranked.slice(0, need).reduce((s, v) => s + v, 0);
}

function cpuStageMemberById(cpu, instanceId) {
  if (!instanceId) return null;
  for (const [slot, m] of Object.entries(cpu.stage || {})) {
    if (m?.instance_id === instanceId) return { slot, member: m };
  }
  return null;
}

/** Penalty for putting the skill's source member into Wait before surveil/draw effects. */
function cpuWaitSelfCost(cpu, sourceId, read, winPressure) {
  const staged = cpuStageMemberById(cpu, sourceId);
  if (!staged || memberInWait(staged.member)) return 0;
  const blade = (staged.member.blade || 0) + (staged.member.live_blade_bonus || 0);
  let cost = blade * (winPressure >= 0.45 ? 0.35 : 0.55);
  if (read?.oppThreatBlade >= 6 && blade >= 3) cost += 1.1;
  if (winPressure >= 1) cost *= 0.5;
  return cost;
}

function cpuSurveilConfirmScore(pr, cpu, tier, winPressure, read) {
  const ab = pr.ability || {};
  const hand = cpu.hand || [];
  const discardNeed = ab.discard || pr.discard_count || 1;
  const look = ab.look || 5;
  const deckCount = cpuDeckCount(cpu);
  if (deckCount < 1 || hand.length < discardNeed + 1) return -1;

  const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
  let score = cpuScoreOptionalAbility(
    { ...ab, type: ab.type || 'optional_wait_self_discard_look_reveal_group' },
    cpu, tier, ae, hand, winPressure, read
  );
  score += Math.min(look, deckCount) * (cpuTierHardPlus(tier) ? 0.38 : 0.24);
  score += cpuHandDiscardFodderScore(hand, discardNeed, tier) * 0.45;
  score -= cpuWaitSelfCost(cpu, pr.source_id, read, winPressure);

  const minCost = ab.min_cost || 9;
  const handMaxMemberCost = Math.max(
    0,
    ...(hand.filter(c => c.card_type === 'メンバー').map(c => c.cost || 0))
  );
  if (handMaxMemberCost < minCost) score += cpuTierHardPlus(tier) ? 1.5 : 0.85;
  if (read?.behind || winPressure >= 0.45) score += cpuTierHardPlus(tier) ? 1 : 0.55;
  if (deckCount <= 4) score -= 1.5;
  return score;
}

function cpuSurveilPickMinScore(tier, winPressure) {
  if (winPressure >= 1) return cpuTierHardPlus(tier) ? 2.8 : 3.8;
  if (winPressure >= 0.45) return cpuTierHardPlus(tier) ? 3.8 : 4.8;
  return cpuTierHardPlus(tier) ? 5.2 : 6.5;
}

function cpuShouldTakeSurveilPick(c, cpu, hand, tier, read, ab, winPressure) {
  const minCost = ab.min_cost || 9;
  if (c.cost != null && (c.cost || 0) < minCost) return false;
  const group = ab.group || '';
  if (group && c.group && c.group !== group && group !== 'mixed') return false;
  const memberScore = cpuScoreMember(c, cpu, hand, cpuStageColors(cpu), tier, read);
  if (memberScore < cpuSurveilPickMinScore(tier, winPressure)) return false;
  const handMembers = hand.filter(h => h.card_type === 'メンバー');
  const bestInHand = handMembers.length
    ? Math.max(...handMembers.map(h => cpuScoreMember(h, cpu, hand, cpuStageColors(cpu), tier, read)))
    : 0;
  const margin = cpuTierHardPlus(tier) ? 0.6 : 1.3;
  return memberScore >= bestInHand + margin;
}

function cpuPickBestCandidate(candidates, cpu, hand, tier, read) {
  const sorted = [...(candidates || [])].sort(
    (a, b) => cpuScoreSurveilCandidate(b, cpu, hand, tier, read) - cpuScoreSurveilCandidate(a, cpu, hand, tier, read)
  );
  return sorted[0] || null;
}

function cpuResolveWaitDiscardLookReveal(pr, cpu, tier, winPressure, read) {
  const hand = cpu.hand || [];
  const discard = (need) => cpuPickDiscardIds(hand, need, tier, winPressure, read);
  const step = pr.step || 'confirm';
  const ab = pr.ability || {};
  const discardNeed = pr.discard_count || ab.discard || 1;

  if (step === 'confirm') {
    if (tier === 'easy') {
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    const score = cpuSurveilConfirmScore(pr, cpu, tier, winPressure, read);
    cpuAct('resolve_prompt', {
      choice: score >= cpuOptionalYesThreshold(tier) ? 'yes' : 'no',
    });
    return true;
  }
  if (step === 'discard') {
    const ids = discard(discardNeed);
    if (ids.length >= discardNeed) {
      cpuAct('resolve_prompt', { discard_ids: ids });
      return true;
    }
    if (hand.length >= discardNeed) {
      const fallback = hand.slice(0, discardNeed).map(c => c.instance_id).filter(Boolean);
      if (fallback.length >= discardNeed) {
        cpuAct('resolve_prompt', { discard_ids: fallback.slice(0, discardNeed) });
        return true;
      }
    }
    cpuAct('resolve_prompt', { discard_ids: [] });
    return true;
  }
  if (step === 'pick') {
    const cands = pr.candidates || [];
    if (!cands.length) {
      cpuAct('resolve_prompt', { choice: 'skip' });
      return true;
    }
    const eligible = cands.filter(c =>
      cpuShouldTakeSurveilPick(c, cpu, hand, tier, read, ab, winPressure)
    );
    const pick = cpuPickBestCandidate(
      eligible.length ? eligible : cands,
      cpu, hand, tier, read
    );
    if (pick?.instance_id) {
      cpuAct('resolve_prompt', { card_id: pick.instance_id });
    } else {
      cpuAct('resolve_prompt', { choice: 'skip' });
    }
    return true;
  }
  return false;
}

function cpuResolveOptionalSwapAreaOnEnter(pr, cpu, tier, read) {
  const allowed = new Set(pr.choices || ['skip', 'left', 'center', 'right']);
  if (tier === 'easy' || !allowed.size) {
    cpuAct('resolve_prompt', { choice: 'skip' });
    return true;
  }
  const slot = cpuPickPositionSlot(pr, cpu, read, tier);
  cpuAct('resolve_prompt', { choice: slot && allowed.has(slot) ? slot : 'skip' });
  return true;
}

function cpuPickPositionSlot(pr, cpu, read, tier) {
  const choices = (pr.choices || ['left', 'center', 'right']).filter(s => s !== 'skip');
  const from = pr.source_slot || 'center';
  const stage = cpu.stage || {};
  const empty = choices.filter(s => s !== from && !stage[s]);
  if (empty.length) return empty.includes('center') ? 'center' : empty[0];
  const swapTargets = choices
    .filter(s => s !== from && stage[s])
    .map(s => ({
      slot: s,
      blade: (stage[s].blade || 0) + (stage[s].live_blade_bonus || 0),
      cost: stage[s].cost || 0,
    }))
    .sort((a, b) => a.blade - b.blade || a.cost - b.cost);
  return swapTargets[0]?.slot || choices.find(s => s !== from) || choices[0];
}

function cpuWrMemberCombos(candidates, maxPick) {
  const pool = (candidates || []).filter(c =>
    c.card_type === 'メンバー' || c.card_type_en === 'Member' || !c.card_type
  );
  const max = Math.min(maxPick, pool.length);
  const combos = [];
  const walk = (start, picked) => {
    if (picked.length) combos.push([...picked]);
    if (picked.length >= max) return;
    for (let i = start; i < pool.length; i++) {
      walk(i + 1, [...picked, pool[i]]);
    }
  };
  walk(0, []);
  return combos;
}

function cpuScoreWrDeckBottomCombo(combo, milestones, tier) {
  const cost = combo.reduce((s, c) => s + (c.cost || 0), 0);
  const key = String(cost);
  if (milestones?.[key]) {
    const t = milestones[key].type || '';
    if (t === 'live_score_bonus') return 120;
    if (t === 'blade_bonus') return 55;
    if (t === 'draw_cards') return 28;
  }
  if (tier === 'easy') return -2;
  return cost * 0.15;
}

function cpuPickWrMembersDeckBottom(candidates, maxPick, tier, milestones) {
  const combos = cpuWrMemberCombos(candidates, maxPick);
  let best = { ids: [], score: -999 };
  for (const combo of combos) {
    const score = cpuScoreWrDeckBottomCombo(combo, milestones, tier);
    if (score > best.score) {
      best = { ids: combo.map(c => c.instance_id).filter(Boolean), score };
    }
  }
  return best.score > 0 ? best.ids : [];
}

function cpuPickLowWrLive(candidates, tier, read) {
  const lives = (candidates || []).filter(c =>
    c.card_type === 'ライブ' || c.card_type_en === 'Live'
  );
  if (!lives.length) return null;
  const deckFat = (read?.deckCount ?? 15) > 10;
  const sorted = [...lives].sort((a, b) => (a.score || 0) - (b.score || 0));
  const low = sorted[0];
  if (tier === 'easy') return null;
  if ((low.score || 0) <= 2 || deckFat) return low;
  if (cpuTierHardPlus(tier) && (low.score || 0) <= 3) return low;
  return null;
}

function cpuCountWaitMembers(stage) {
  return Object.values(stage || {}).filter(m => m && memberInWait(m)).length;
}

function cpuResolveBranchPickPrompts(pr, cpu, tier, winPressure, read, s) {
  const hand = cpu.hand || [];

  if (pr.type === 'on_enter_blade_self_and_pick_group') {
    const cands = pr.candidates || [];
    const best = [...cands].sort((a, b) =>
      (b.blade || 0) + (b.cost || 0) * 0.05 - ((a.blade || 0) + (a.cost || 0) * 0.05)
    )[0];
    if (best?.slot) {
      cpuAct('resolve_prompt', { slot: best.slot });
    } else if (best?.instance_id) {
      cpuAct('resolve_prompt', { card_id: best.instance_id });
    } else {
      cpuAct('resolve_prompt', { slot: '' });
    }
    return true;
  }

  if (pr.type === 'wait_opponent_stage_pick' && pr.step === 'pick_opp_wait') {
    const slot = cpuPickOppStageTarget(pr.candidates, read, tier) || pr.candidates?.[0]?.slot;
    if (slot) {
      cpuAct('resolve_prompt', { slot });
      return true;
    }
    return false;
  }

  // Hime pb1-014: opponent chooses own Stage Member to move into the facing area.
  // Prefer lowest cost / least valuable so Hime's front isn't a strong piece.
  if (pr.type === 'pos_change_opp_front_pick') {
    const cands = [...(pr.candidates || [])].sort((a, b) =>
      (a.cost || 0) - (b.cost || 0) || (a.blade || 0) - (b.blade || 0) || (a.hearts || 0) - (b.hearts || 0)
    );
    const slot = cands[0]?.slot;
    if (slot) {
      cpuAct('resolve_prompt', { slot });
      return true;
    }
    return false;
  }

  if (pr.type === 'live_start_arise_choice') {
    if (pr.step === 'pick_wait_member') {
      const wait = [...(pr.candidates || [])].sort((a, b) =>
        (b.blade || 0) - (a.blade || 0) || (b.cost || 0) - (a.cost || 0)
      );
      const slot = wait[0]?.slot;
      if (slot) cpuAct('resolve_prompt', { slot });
      return true;
    }
    const waitCount = cpuCountWaitMembers(cpu.stage);
    const oppLowHeart = (read?.weakestActive?.hearts ?? 99) <= 3
      || (read?.stageMembers || []).some(m => m && (m.hearts || 0) <= 3 && m.active !== false);
    const selfBehind = winPressure >= 0.45 || read?.behind;
    let choice = 'activate';
    if (waitCount === 0) {
      choice = 'wait';
    } else if (cpuTierHardPlus(tier) && oppLowHeart && !selfBehind) {
      choice = 'wait';
    } else if (tier === 'normal' && oppLowHeart && waitCount <= 1 && !selfBehind) {
      choice = 'wait';
    } else if (tier === 'easy') {
      choice = waitCount > 0 ? 'activate' : 'wait';
    }
    cpuAct('resolve_prompt', { choice });
    return true;
  }

  if (pr.type === 'surveil2_mus_ability_choice') {
    const pick = cpuPickBestCandidate(pr.candidates, cpu, hand, tier, read);
    if (pick?.instance_id) {
      cpuAct('resolve_prompt', { card_id: pick.instance_id });
    } else {
      cpuAct('resolve_prompt', { choice: 'skip' });
    }
    return true;
  }

  if (pr.type === 'optional_leave_mus_score_add_wr_live') {
    if (pr.step === 'pick_member') {
      const pick = [...(pr.candidates || [])].sort((a, b) => (a.cost || 0) - (b.cost || 0))[0];
      if (pick?.instance_id) cpuAct('resolve_prompt', { card_id: pick.instance_id });
      else cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    const wrLives = (cpu.waiting_room || []).some(c => c.card_type === 'ライブ' && (c.group || '') === (pr.ability?.group || "μ's"));
    cpuAct('resolve_prompt', { choice: wrLives ? 'yes' : 'no' });
    return true;
  }

  if (pr.type === 'opp_blind_pick_hand_reveal') {
    const need = pr.pick_count || 3;
    const pool = [...hand];
    if (pool.length < need) {
      cpuAct('resolve_prompt', { card_ids: pool.map(c => c.instance_id).filter(Boolean) });
      return true;
    }
    const lives = pool.filter(c => c.card_type === 'ライブ');
    const nonLives = pool.filter(c => c.card_type !== 'ライブ');
    const ownerIsCpu = pr.owner === (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2');
    let ids;
    if (ownerIsCpu) {
      const ranked = [...nonLives].sort((a, b) =>
        cpuHandDiscardFodderScore([a], tier) - cpuHandDiscardFodderScore([b], tier)
      );
      ids = ranked.slice(0, need).map(c => c.instance_id);
      if (ids.length < need) {
        ids = ids.concat(
          lives.slice(0, need - ids.length).map(c => c.instance_id)
        );
      }
    } else {
      const withLive = lives.length
        ? [lives.sort((a, b) => (a.score || 0) - (b.score || 0))[0]]
        : [];
      const rest = [...nonLives].sort((a, b) => (b.cost || 0) - (a.cost || 0));
      ids = withLive.concat(rest).slice(0, need).map(c => c.instance_id);
    }
    if (ids.length >= need) {
      cpuAct('resolve_prompt', { card_ids: ids.slice(0, need) });
    } else {
      cpuSchedulePromptRetryIfStuck(s, cpu);
    }
    return true;
  }

  if (pr.type === 'live_success_yell_live_deck_bottom' || pr.type === 'optional_wr_live_deck_bottom') {
    const pick = cpuPickLowWrLive(pr.candidates, tier, read);
    if (pick?.instance_id) {
      cpuAct('resolve_prompt', { choice: 'pick', card_id: pick.instance_id });
    } else {
      cpuAct('resolve_prompt', { choice: 'skip' });
    }
    return true;
  }

  if (pr.type === 'optional_wr_to_deck_top') {
    const cands = (pr.candidates || []).filter(c => c?.instance_id);
    if (pr.step === 'pick') {
      if (cands[0]?.instance_id) {
        cpuAct('resolve_prompt', { choice: 'yes', card_id: cands[0].instance_id });
      } else {
        cpuAct('resolve_prompt', { choice: 'no' });
      }
      return true;
    }
    if (tier === 'easy' || !cands.length) {
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    cpuAct('resolve_prompt', { choice: 'yes', card_id: cands[0].instance_id });
    return true;
  }

  if (pr.type === 'optional_wr_members_deck_bottom_milestones') {
    const milestones = pr.milestones || pr.ability?.milestones || {};
    const maxPick = pr.max_pick || pr.ability?.max_pick || 2;
    if (pr.step === 'pick_members') {
      let ids = cpuPickWrMembersDeckBottom(pr.candidates, maxPick, tier, milestones);
      if (!ids.length && (pr.candidates || []).length) {
        const fallback = [...pr.candidates].sort((a, b) => (a.cost || 0) - (b.cost || 0))[0];
        if (fallback?.instance_id) ids = [fallback.instance_id];
      }
      if (ids.length) {
        cpuAct('resolve_prompt', { card_ids: ids });
      } else {
        cpuAct('resolve_prompt', { choice: 'no' });
      }
      return true;
    }
    const ids = cpuPickWrMembersDeckBottom(pr.candidates, maxPick, tier, milestones);
    if (ids.length) {
      cpuAct('resolve_prompt', { choice: 'yes' });
    } else {
      cpuAct('resolve_prompt', { choice: 'no' });
    }
    return true;
  }

  if (pr.type === 'player_choice_wr_members_deck_bottom') {
    if (pr.step === 'pick_members') {
      const target = s?.players?.[pr.target] || cpu;
      const wr = (target.waiting_room || []).filter(c =>
        c.card_type === 'メンバー' || c.card_type_en === 'Member'
      );
      const maxPick = pr.max_pick || 2;
      const millOpp = pr.target && pr.target !== (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2');
      const sorted = [...wr].sort((a, b) => {
        const sa = millOpp ? (b.cost || 0) : -(b.cost || 0);
        const sb = millOpp ? (a.cost || 0) : -(a.cost || 0);
        return sa - sb || (b.cost || 0) - (a.cost || 0);
      });
      const n = cpuTierHardPlus(tier) ? Math.min(maxPick, sorted.length)
        : tier === 'normal' ? Math.min(Math.max(1, Math.floor(maxPick / 2)), sorted.length)
        : Math.min(1, sorted.length);
      const pickIds = sorted.slice(0, n).map(c => c.instance_id).filter(Boolean);
      if (pickIds.length) {
        cpuAct('resolve_prompt', { card_ids: pickIds });
      } else {
        cpuSchedulePromptRetryIfStuck(s, cpu);
      }
      return true;
    }
    if (pr.choices?.includes('self') && pr.choices?.includes('opp')) {
      const digSelf = winPressure >= 0.45 || (read?.deckCount ?? 0) >= 8 || hand.length <= 4;
      const millOpp = read && (read.deckCount ?? 20) <= 10;
      const selfWr = (cpu.waiting_room || []).filter(c => c.card_type === 'メンバー').length;
      const oppWr = (s?.players?.p1?.waiting_room || []).filter(c => c.card_type === 'メンバー').length;
      let pick = 'self';
      if (cpuTierHardPlus(tier)) {
        pick = millOpp && oppWr && !digSelf ? 'opp' : (digSelf && selfWr ? 'self' : (oppWr && !selfWr ? 'opp' : 'self'));
      } else if (tier === 'normal') {
        pick = digSelf && selfWr ? 'self' : (millOpp && oppWr ? 'opp' : (selfWr ? 'self' : 'opp'));
      } else {
        pick = selfWr ? 'self' : 'opp';
      }
      cpuAct('resolve_prompt', { choice: pick });
      return true;
    }
    return false;
  }

  if (pr.type === 'choice_energy_or_wr_lives_deck_top') {
    const inactive = (cpu.energy_zone || []).filter(e => !e.active).length;
    const wrLives = (cpu.waiting_room || []).filter(c =>
      (c.card_type === 'ライブ' || c.card_type_en === 'Live')
      && (c.group || '') === (pr.ability?.group || 'Nijigasaki')
    );
    const wantEnergy = inactive > 0 && (cpuTierHardPlus(tier) || winPressure >= 0.35);
    const wantLives = wrLives.length > 0 && (read?.deckCount ?? 15) <= 12;
    const pick = wantEnergy && !wantLives ? 'energy' : wantLives ? 'lives' : 'energy';
    cpuAct('resolve_prompt', { choice: pick });
    return true;
  }

  if (pr.type === 'sbp5_aqours_blade_or_position') {
    const hasSaintSnow = Object.values(cpu.stage || {}).some(m =>
      m && /saint snow/i.test(m.subunit || m.name_en || '')
    );
    const aqoursOthers = (pr.candidates || []).length
      || Object.values(cpu.stage || {}).filter(m => m && (m.group || '') === 'Sunshine').length > 1;
    const pick = hasSaintSnow && tier !== 'easy' && !aqoursOthers ? 'position' : 'blade';
    cpuAct('resolve_prompt', { choice: pick });
    return true;
  }

  if (pr.type === 'sbp6_live_wr_deck_position') {
    if (pr.choices?.includes('skip')) {
      const liveId = pr.live_id;
      const byId = new Map((pr.candidates || []).map(c => [c.instance_id, c]));
      const live = byId.get(liveId) || pr.candidates?.[0];
      const score = live?.score || 0;
      if (score >= 3 && cpuTierHardPlus(tier)) {
        cpuAct('resolve_prompt', { choice: 'top' });
      } else if (score <= 2 && tier !== 'easy') {
        cpuAct('resolve_prompt', { choice: 'bottom' });
      } else {
        cpuAct('resolve_prompt', { choice: 'skip' });
      }
      return true;
    }
    cpuAct('resolve_prompt', { choice: pr.choices?.includes('bottom') ? 'bottom' : 'skip' });
    return true;
  }

  if (pr.type === 'sbp6_hand_deck_position') {
    const pos = cpuTierHardPlus(tier) ? 'bottom' : (tier === 'normal' ? 'top' : 'skip');
    if (pos === 'skip' || !hand.length) {
      cpuAct('resolve_prompt', { choice: 'skip' });
      return true;
    }
    const fodder = cpuPickDeckBottomIds(hand, 1, tier, winPressure, read);
    const id = fodder[0] || hand.sort((a, b) => (a.cost || 0) - (b.cost || 0))[0]?.instance_id;
    if (id) {
      cpuAct('resolve_prompt', { choice: pos, discard_ids: [id], position: pos });
    } else {
      cpuAct('resolve_prompt', { choice: 'skip' });
    }
    return true;
  }

  if (pr.type === 'activated_swap_area_pick') {
    const slot = cpuPickPositionSlot(pr, cpu, read, tier);
    const allowed = new Set((pr.choices || []).filter(c => c !== 'skip'));
    cpuAct('resolve_prompt', { choice: slot && allowed.has(slot) ? slot : (pr.choices?.[0] || 'skip') });
    return true;
  }

  return false;
}

function cpuResolveSpbp5WaitOrDiscardActivate(pr, cpu, tier, winPressure, read) {
  const hand = cpu.hand || [];
  const step = pr.step || 'choose';
  const ab = pr.ability || {};
  if (step === 'choose') {
    const canWait = pr.choices?.includes('wait');
    const canDiscard = pr.choices?.includes('discard');
    const discardNeed = ab.discard || 1;
    const hasDiscard = hand.length >= discardNeed;
    const fodder = cpuHandDiscardFodderScore(hand, discardNeed, tier);
    const waitCost = cpuWaitSelfCost(cpu, pr.source_id, read, winPressure);
    const discardPath = canDiscard && hasDiscard && fodder >= 2.5;
    const waitPath = canWait && (waitCost <= 1.8 || winPressure >= 0.45 || !discardPath);
    if (waitPath && (cpuTierHardPlus(tier) || waitCost <= 2.5)) {
      cpuAct('resolve_prompt', { choice: 'wait' });
    } else if (discardPath) {
      cpuAct('resolve_prompt', { choice: 'discard' });
    } else if (canWait) {
      cpuAct('resolve_prompt', { choice: 'wait' });
    } else {
      cpuAct('resolve_prompt', { choice: pr.choices?.[0] || 'no' });
    }
    return true;
  }
  if (step === 'discard') {
    const need = ab.discard || pr.discard_count || 1;
    const ids = cpuPickDiscardIds(hand, need, tier, winPressure, read);
    if (ids.length >= need) {
      cpuAct('resolve_prompt', { discard_ids: ids });
    } else {
      cpuAct('resolve_prompt', { choice: 'no' });
    }
    return true;
  }
  return false;
}

function cpuResolveSpbp5SurveilPick(pr, cpu, tier, winPressure, read) {
  const hand = cpu.hand || [];
  if (pr.type === 'spbp5_subunit_blade_pick') {
    const cands = pr.candidates || [];
    const pick = tier !== 'easy' ? cpuPickBestCandidate(cands, cpu, hand, tier, read) : null;
    if (pick?.instance_id) {
      cpuAct('resolve_prompt', { card_id: pick.instance_id });
    } else {
      cpuAct('resolve_prompt', { choice: 'skip' });
    }
    return true;
  }
  if (pr.type === 'spbp5_distinct_groups') {
    const cands = pr.candidates || [];
    const pick = tier !== 'easy' ? cpuPickBestCandidate(cands, cpu, hand, tier, read) : null;
    if (pick?.instance_id) {
      cpuAct('resolve_prompt', { card_id: pick.instance_id });
    } else {
      cpuAct('resolve_prompt', { choice: 'skip' });
    }
    return true;
  }
  if (pr.type === 'spbp5_pick_wr_live') {
    const cands = pr.candidates || [];
    const lives = cands.filter(c => (c.card_type || '') === 'ライブ' || c.card_type_en === 'Live');
    const pick = cpuPickBestCandidate(lives.length ? lives : cands, cpu, hand, tier, read);
    if (pick?.instance_id) {
      cpuAct('resolve_prompt', { card_id: pick.instance_id });
    } else {
      cpuAct('resolve_prompt', { choice: 'no' });
    }
    return true;
  }
  return false;
}

function cpuOptionalSuccessWrLiveSwapCfg(pr) {
  return { group: pr.group || 'Nijigasaki', filter: pr.filter || 'live' };
}

/**
 * Shioriko PL!N-bp4-010 Live Start — pick a Live in Live storage.
 * Prefer a name that matches Success Live (heart grant); never silent-return.
 */
function cpuResolvePickLiveMatchSuccessHeart(pr, cpu, s) {
  if (pr?.type !== 'pick_live_match_success_heart') return false;
  const cands = pr.candidates || [];
  let id = cands.map((c) => c?.instance_id).find(Boolean) || null;
  if (!id) {
    const lives = (cpu.live_zone || []).filter((c) => c && isCpuLiveCard(c) && c.instance_id);
    const successNames = new Set(
      (cpu.success_lives || []).map((c) => String(c?.name_en || c?.name || ''))
    );
    const matched = lives.find((c) => successNames.has(String(c.name_en || c.name || '')));
    id = (matched || lives[0])?.instance_id || null;
  } else {
    // Among prompt candidates, prefer a Success Live name match when Expert/Hard.
    const successNames = new Set(
      (cpu.success_lives || []).map((c) => String(c?.name_en || c?.name || ''))
    );
    if (successNames.size && cpuTierHardPlus(cpuDiff())) {
      const matched = cands.find((c) => c?.instance_id
        && successNames.has(String(c.name_en || c.name || '')));
      if (matched?.instance_id) id = matched.instance_id;
    }
  }
  if (id) {
    cpuAct('resolve_prompt', { card_id: id });
    return true;
  }
  cpuSchedulePromptRetryIfStuck(s, cpu);
  return true;
}

/** Shioriko PL!N-bp4-010-SEC — Success Live ↔ WR Live swap (confirm + two card picks). */
function cpuResolveOptionalSuccessWrLiveSwap(pr, cpu, tier) {
  const cfg = cpuOptionalSuccessWrLiveSwapCfg(pr);
  const step = pr.step || 'confirm';

  if (step === 'confirm') {
    const inSucc = (cpu.success_lives || []).some(c => isCpuLiveCard(c) && cardMatchesWrPickClient(c, cfg));
    const inWr = (cpu.waiting_room || []).some(c => isCpuLiveCard(c) && cardMatchesWrPickClient(c, cfg));
    cpuAct('resolve_prompt', { choice: (inSucc && inWr) ? 'yes' : 'no' });
    return true;
  }
  if (step === 'pick_success_live') {
    const sorted = [...(pr.candidates || [])].sort((a, b) => (a.score || 0) - (b.score || 0));
    const pick = sorted[0];
    if (pick?.instance_id) cpuAct('resolve_prompt', { card_id: pick.instance_id });
    else cpuAct('anti_softlock_skip', {});
    return true;
  }
  if (step === 'pick_wr_live') {
    const sorted = [...(pr.candidates || [])].sort((a, b) => (b.score || 0) - (a.score || 0));
    const pick = sorted[0];
    if (pick?.instance_id) cpuAct('resolve_prompt', { card_id: pick.instance_id });
    else cpuAct('anti_softlock_skip', {});
    return true;
  }
  cpuAct('anti_softlock_skip', {});
  return true;
}

/** Rurino PL!HS-bp5-003 leave → optional Position Change (yes/no → member → dest). */
function cpuResolveOptionalStageReposition(pr, cpu, tier, winPressure, read, ae) {
  if (pr?.type !== 'optional_stage_reposition') return false;
  const activeEnergy = ae ?? (cpu.energy_zone || []).filter(energyChipActive).length;
  const hand = cpu.hand || [];
  const step = pr.step || '';
  const cands = (pr.candidates || []).filter(c => c && c.slot);

  if (step === 'pick_member') {
    const sorted = [...cands].sort((a, b) =>
      (a.cost || 0) - (b.cost || 0) || (a.blade || 0) - (b.blade || 0));
    const pick = sorted[0];
    if (!pick) {
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    cpuAct('resolve_prompt', { card_id: pick.instance_id, slot: pick.slot });
    return true;
  }

  if (step === 'pick_dest') {
    const from = pr.from_slot || 'center';
    const dest = cpuPickPositionSlot(
      { ...pr, source_slot: from, choices: pr.target_slots || ['left', 'center', 'right'] },
      cpu, read, tier
    ) || (pr.target_slots || [])[0];
    if (!dest) {
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    cpuAct('resolve_prompt', { slot: dest });
    return true;
  }

  if (!cands.length) {
    cpuAct('resolve_prompt', { choice: 'no' });
    return true;
  }
  const ab = pr.ability || { type: 'optional_stage_reposition' };
  const score = cpuScoreOptionalAbility(ab, cpu, tier, activeEnergy, hand, winPressure, read);
  if (score < cpuOptionalYesThreshold(tier)) {
    cpuAct('resolve_prompt', { choice: 'no' });
    return true;
  }
  if (cands.length === 1) {
    const only = cands[0];
    cpuAct('resolve_prompt', {
      choice: 'yes',
      card_id: only.instance_id,
      slot: only.slot,
    });
    return true;
  }
  cpuAct('resolve_prompt', { choice: 'yes' });
  return true;
}

/** Step-aware resolver for multi-part skill prompts (surveil chains, discard steps, etc.). */
function cpuResolveStepPrompt(pr, cpu, tier, winPressure, read) {
  if (pr.type === 'optional_success_wr_live_swap') {
    return cpuResolveOptionalSuccessWrLiveSwap(pr, cpu, tier);
  }
  if (cpuResolveOptionalStageReposition(pr, cpu, tier, winPressure, read)) return true;
  if (cpuResolveHandPickPrompt(pr, cpu, tier, winPressure, read)) return true;
  if (pr.type === 'ssd1_live_start_draw') {
    if ((pr.step || '') === 'confirm' || !pr.step) {
      cpuAct('resolve_prompt', { choice: tier === 'easy' ? 'no' : 'yes' });
      return true;
    }
  }
  if (pr.type === 'ssd1_reveal_group_deck') {
    if (pr.step === 'confirm') {
      cpuAct('resolve_prompt', { choice: tier === 'easy' ? 'no' : 'yes' });
      return true;
    }
    if (pr.step === 'pick_hand') {
      const id = pr.candidates?.[0]?.instance_id || cpu.hand?.[0]?.instance_id;
      if (id) { cpuAct('resolve_prompt', { card_id: id }); return true; }
    }
    if (pr.step === 'deck_pos') {
      cpuAct('resolve_prompt', { choice: 'bottom' });
      return true;
    }
  }
  if (pr.type === 'spbp5_wait_discard_surveil' || pr.type === 'bp5_wait_discard_look_reveal'
      || pr.type === 'bp5_discard_pay_wr_live_score') {
    if (pr.type === 'bp5_discard_pay_wr_live_score') {
      if (pr.step === 'discard') {
        const need = pr.discard_count || 1;
        const ids = (cpu.hand || []).slice(0, need).map(c => c.instance_id).filter(Boolean);
        if (ids.length >= need) {
          cpuAct('resolve_prompt', { discard_ids: ids.slice(0, need) });
          return true;
        }
        cpuAct('resolve_prompt', { choice: 'skip' });
        return true;
      }
      if (pr.step === 'pick_live') {
        const id = pr.candidates?.[0]?.instance_id;
        if (id && tier !== 'easy') {
          cpuAct('resolve_prompt', { card_id: id });
          return true;
        }
        cpuAct('resolve_prompt', { choice: 'skip' });
        return true;
      }
    }
    return cpuResolveWaitDiscardLookReveal(pr, cpu, tier, winPressure, read);
  }
  if (pr.type === 'wait_other_group_draw') {
    const id = pr.stage_members?.[0]?.instance_id;
    if (id) {
      cpuAct('resolve_prompt', { member_id: id });
      return true;
    }
    cpuAct('resolve_prompt', { choice: 'skip' });
    return true;
  }
  if (pr.type === 'optional_wait_group_member_draw_discard') {
    if (pr.step === 'pick_member') {
      const id = pr.stage_members?.[0]?.instance_id;
      if (id) {
        cpuAct('resolve_prompt', { member_id: id });
        return true;
      }
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    if (tier === 'easy') {
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    const group = pr.group || pr.ability?.group || 'Nijigasaki';
    const hasGroup = Object.values(cpu.stage || {}).some(m => m && (m.group || '') === group);
    cpuAct('resolve_prompt', { choice: hasGroup ? 'yes' : 'no' });
    return true;
  }
  if (pr.type === 'optional_wait_group_member_blade') {
    if (pr.step === 'pick_member') {
      const id = pr.stage_members?.[0]?.instance_id;
      if (id) {
        cpuAct('resolve_prompt', { member_id: id });
        return true;
      }
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    if (tier === 'easy') {
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    const group = pr.group || pr.ability?.group || 'Nijigasaki';
    const hasGroup = Object.values(cpu.stage || {}).some(
      m => m && (m.group || '') === group && !m.in_wait
    );
    cpuAct('resolve_prompt', { choice: hasGroup ? 'yes' : 'no' });
    return true;
  }
  if (pr.type === 'optional_wait_up_to_group_live_score') {
    if (pr.step === 'pick_members') {
      const max = pr.max_wait || pr.ability?.max_wait || 3;
      const ids = (pr.stage_members || []).slice(0, max).map(m => m.instance_id).filter(Boolean);
      cpuAct('resolve_prompt', { member_ids: ids });
      return true;
    }
    if (tier === 'easy') {
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    const group = pr.group || pr.ability?.group || 'Nijigasaki';
    const hasGroup = Object.values(cpu.stage || {}).some(
      m => m && (m.group || '') === group && !m.in_wait
    );
    cpuAct('resolve_prompt', { choice: hasGroup ? 'yes' : 'no' });
    return true;
  }
  if (pr.type === 'activate_members_pick') {
    const cands = pr.candidates || [];
    const best = [...cands].sort((a, b) => (b.blade || 0) - (a.blade || 0))[0] || cands[0];
    const id = best?.instance_id;
    if (id) {
      cpuAct('resolve_prompt', { member_id: id, slot: best?.slot });
      return true;
    }
    cpuAct('resolve_prompt', { choice: 'skip' });
    return true;
  }
  if (pr.type === 'auto_on_ally_wait_activate_blade') {
    if (pr.step === 'discard') {
      const need = pr.discard_count || 1;
      const ids = cpuPickDiscardIds(cpu.hand || [], need, tier, winPressure, read);
      if (ids.length >= need) {
        cpuAct('resolve_prompt', { discard_ids: ids.slice(0, need) });
        return true;
      }
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    if (tier === 'easy' || (cpu.hand || []).length < (pr.discard_count || 1)) {
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    cpuAct('resolve_prompt', { choice: 'yes' });
    return true;
  }
  if (pr.type === 'optional_wait_self_look_reveal') {
    if ((pr.step || '') === 'discard') {
      const need = pr.discard_count || pr.ability?.discard || 1;
      const ids = cpuPickDiscardIds(cpu.hand || [], need, tier, winPressure, read);
      if (ids.length >= need) {
        cpuAct('resolve_prompt', { discard_ids: ids.slice(0, need) });
        return true;
      }
      const hand = cpu.hand || [];
      if (hand.length >= need) {
        const fallback = hand.slice(0, need).map(c => c.instance_id).filter(Boolean);
        if (fallback.length >= need) {
          cpuAct('resolve_prompt', { discard_ids: fallback.slice(0, need) });
          return true;
        }
      }
      cpuAct('resolve_prompt', { discard_ids: [] });
      return true;
    }
    if ((pr.step || 'confirm') === 'confirm') {
      if (tier === 'easy') {
        cpuAct('resolve_prompt', { choice: 'no' });
        return true;
      }
      const score = cpuSurveilConfirmScore(pr, cpu, tier, winPressure, read);
      if (score >= cpuOptionalYesThreshold(tier)) {
        const data = cpuBuildOptionalYesPayload(pr, cpu, tier, winPressure, (n, pool) =>
          cpuPickDiscardIds(pool || cpu.hand || [], n, tier, winPressure, read));
        if (data) {
          cpuAct('resolve_prompt', data);
          return true;
        }
      }
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
  }
  if (pr.type === 'spbp5_wait_or_discard_activate') {
    return cpuResolveSpbp5WaitOrDiscardActivate(pr, cpu, tier, winPressure, read);
  }
  if (pr.type === 'spbp5_wait_draw_discard' || pr.type === 'sbp5_discard_bladeless_wr_live'
      || pr.type === 'sbp5_live_start_discard_heart') {
    if ((pr.step || '') === 'discard') {
      const need = pr.discard_count || pr.ability?.discard || 1;
      const ids = cpuPickDiscardIds(cpu.hand || [], need, tier, winPressure, read);
      if (ids.length >= need) {
        cpuAct('resolve_prompt', { discard_ids: ids });
        return true;
      }
    }
  }
  if (pr.type === 'spbp5_subunit_blade_pick' || pr.type === 'spbp5_distinct_groups' || pr.type === 'spbp5_pick_wr_live') {
    return cpuResolveSpbp5SurveilPick(pr, cpu, tier, winPressure, read);
  }
  if (pr.type === 'spbp5_mill_swap_pick') {
    cpuAct('resolve_prompt', { choice: cpuPickPositionSlot(pr, cpu, read, tier) });
    return true;
  }
  const step = pr.step || '';
  if (step === 'discard' || step === 'discard_hand' || step === 'pick_discard') {
    const need = pr.discard_count || pr.ability?.discard || pr.ability?.max_discard || 1;
    let ids = cpuPickDiscardIds(cpu.hand || [], need, tier, winPressure, read).filter(Boolean);
    if (ids.length < need) ids = cpuHandDiscardFallback(cpu.hand || [], need);
    if (ids.length >= need) {
      cpuAct('resolve_prompt', { discard_ids: ids.slice(0, need) });
      return true;
    }
  }
  if (step === 'pick' && (pr.candidates?.length)) {
    const ab = pr.ability || {};
    const hand = cpu.hand || [];
    const eligible = ab.min_cost
      ? pr.candidates.filter(c => cpuShouldTakeSurveilPick(c, cpu, hand, tier, read, ab, winPressure))
      : pr.candidates;
    const pick = cpuPickBestCandidate(
      eligible.length ? eligible : (ab.min_cost ? [] : pr.candidates),
      cpu, hand, tier, read
    );
    if (pick?.instance_id) {
      cpuAct('resolve_prompt', { card_id: pick.instance_id });
      return true;
    }
    if (pr.choices?.includes('skip')) {
      cpuAct('resolve_prompt', { choice: 'skip' });
      return true;
    }
  }
  if (step === 'confirm' && pr.choices?.includes('yes') && pr.choices?.includes('no')
      && pr.type !== 'optional_success_wr_live_swap') {
    if (pr.type?.includes('wait') && pr.type?.includes('discard') && pr.type?.includes('look')) {
      const score = cpuSurveilConfirmScore(pr, cpu, tier, winPressure, read);
      cpuAct('resolve_prompt', { choice: score >= cpuOptionalYesThreshold(tier) ? 'yes' : 'no' });
      return true;
    }
    const ab = pr.ability || {};
    const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
    const score = ab.type
      ? cpuScoreOptionalAbility(ab, cpu, tier, ae, cpu.hand || [], winPressure, read)
      : (cpuTierHardPlus(tier) ? 2 : tier === 'normal' ? 1.2 : 0);
    cpuAct('resolve_prompt', { choice: score >= cpuOptionalYesThreshold(tier) ? 'yes' : 'no' });
    return true;
  }
  if (step === 'choose' && pr.choices?.length) {
    if (pr.choices.includes('wait') && pr.choices.includes('discard')) {
      return cpuResolveSpbp5WaitOrDiscardActivate(
        { ...pr, step: 'choose' }, cpu, tier, winPressure, read
      );
    }
    const ctx = cpuAbilityCtx(cpu, tier, read, winPressure,
      (cpu.energy_zone || []).filter(energyChipActive).length,
      read ? { mustCatchUp: winPressure >= 0.45, behind: read.behind } : null);
    if (cpuResolveScoredChoicePrompt(pr, cpu, tier, ctx)) return true;
  }
  return false;
}

function cpuResolvePromptBody(s, cpu, pr) {
  const tier = cpuDiff();
  const hand = cpu.hand || [];
  const winPressure = cpuWinPressure(cpu);
  const cpuId = typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2';
  const read = tier === 'easy' ? null : cpuReadOpponent(s, cpuId);
  if (cpuResolveHandPickPrompt(pr, cpu, tier, winPressure, read)) return;
  if (cpuResolveBp7Prompt(s, cpu, pr, tier, winPressure, read)) return;
  if (pr.type === 'optional_discard_prompt') {
    const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
    const ab = pr.ability || {};
    const score = cpuScoreOptionalAbility(ab, cpu, tier, ae, hand, winPressure, read);
    if (score >= cpuOptionalYesThreshold(tier)) {
      const data = cpuBuildOptionalYesPayload(pr, cpu, tier, winPressure, (need, pool) =>
        cpuPickDiscardIds(pool || hand, need, tier, winPressure, read));
      if (data) { cpuAct('resolve_prompt', data); return; }
    }
    cpuAct('resolve_prompt', { choice: 'no' });
    return;
  }
  if (pr.type === 'optional_stage_reposition') {
    const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
    if (cpuResolveOptionalStageReposition(pr, cpu, tier, winPressure, read, ae)) return;
  }
  if(pr.type==='spbp5_repeat_mill_blade'||pr.type==='spbp5_energy_wait_opp_draw'||pr.type==='spbp5_wr_pay_add_hand'){
    const ctx = cpuAbilityCtx(cpu, tier, read, winPressure, (cpu.energy_zone || []).filter(energyChipActive).length,
      read ? { mustCatchUp: winPressure >= 0.45, behind: read.behind } : null);
    if (cpuResolveScoredChoicePrompt(pr, cpu, tier, ctx)) return;
    const yes=pr.choices?.includes('yes')?'yes':pr.choices?.[0];
    cpuAct('resolve_prompt',{choice:yes==='yes'?'yes':'no'});
    return;
  }
  if(pr.type==='spbp5_heart_choice_moved'){
    cpuAct('resolve_prompt',{choice:(pr.choices||['pink'])[0]});
    return;
  }
  if(pr.type==='spbp5_mill_swap_pick'){
    cpuAct('resolve_prompt',{choice:cpuPickPositionSlot(pr, cpu, cpuReadOpponent(G.gameState, typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2'), cpuDiff())});
    return;
  }
  if(pr.type==='spbp5_pay_energy_score'){
    cpuAct('resolve_prompt',{choice:'skip'});
    return;
  }
  if(pr.type==='player_choice'){
    const ctx = cpuAbilityCtx(cpu, tier, read, winPressure, (cpu.energy_zone || []).filter(energyChipActive).length,
      read ? { mustCatchUp: winPressure >= 0.45, behind: (read.successCount ?? 0) > (cpu.success_lives?.length ?? 0) } : null);
    if (cpuResolveScoredChoicePrompt(pr, cpu, tier, ctx)) return;
  }
  if(pr.type==='surveil_arrange'){
    const tier = cpuDiff();
    cpuAct('resolve_prompt', cpuSurveilConfirmPayload(pr, cpu, tier));
    return;
  }
  if (pr.type === 'live_success_order_sources' || pr.type === 'live_start_order_sources') {
    const ids = (pr.candidates || []).map((c) => c.instance_id).filter(Boolean);
    if (ids.length) cpuAct('resolve_prompt', { card_ids: ids });
    return;
  }
  if (cpuResolveHangRiskPrompts(pr, cpu, tier, read, s)) return;
  if (cpuResolveBranchPickPrompts(pr, cpu, tier, winPressure, read, s)) return;
  if(pr.type==='opponent_text_answer'){
    // Emma Punch: CPU should answer in-character sometimes ("please" / お願いします).
    if (/Emma Punch/i.test(pr.prompt || '')) {
      const pick = Math.random() < 0.55 ? 'please' : 'no thank you';
      cpuAct('resolve_prompt', { answer_text: pick });
      return;
    }
    const answers=['chocominto','strawberry','you','pizza'];
    const pick=answers[Math.floor(Math.random()*answers.length)];
    cpuAct('resolve_prompt',{answer_text:pick});
    return;
  }
  if(pr.type==='opponent_choice'){
    const ctx = cpuAbilityCtx(cpu, tier, read, winPressure, (cpu.energy_zone || []).filter(energyChipActive).length,
      read ? { mustCatchUp: winPressure >= 0.45, behind: read.behind } : null);
    if (cpuResolveScoredChoicePrompt(pr, cpu, tier, ctx)) return;
    const pick=pr.choices?.[0]||'you';
    cpuAct('resolve_prompt',{choice:pick});
    return;
  }
  if(pr.type==='pick_wr_live_deck_top'){
    const id=pr.candidates?.[0]?.instance_id;
    if(id) cpuAct('resolve_prompt',{card_id:id});
    return;
  }
  if(pr.type==='pick_judge_success_live'){
    const cands=pr.candidates||[];
    const sorted=[...cands].sort((a,b)=>(b.score||0)-(a.score||0));
    const id=sorted[0]?.instance_id;
    if(id) cpuAct('resolve_prompt',{card_id:id});
    return;
  }
  if(pr.type==='replace_success_with_wr_live'){
    const step=pr.step||'confirm';
    if(step==='pick_wr'){
      const cands=pr.candidates||[];
      const sorted=[...cands].sort((a,b)=>(b.score||0)-(a.score||0));
      const id=sorted[0]?.instance_id;
      if(id) cpuAct('resolve_prompt',{card_id:id});
      else cpuAct('resolve_prompt',{choice:'no'});
      return;
    }
    // Prefer replace when a WR Live exists (candidates present on confirm).
    if((pr.candidates||[]).length) cpuAct('resolve_prompt',{choice:'yes'});
    else cpuAct('resolve_prompt',{choice:'no'});
    return;
  }
  if(pr.type==='pick_wr_to_hand'||pr.type==='pick_wr_leave_stage_add'){
    const cfg = wrPickCfgFromPrompt(pr);
    const raw = pr.candidates || [];
    let cands = raw.filter(c => cardMatchesWrPickClient(c, cfg));
    // Summaries used to omit subunit — never softlock if re-filter empties a non-empty list.
    if (!cands.length && raw.length) cands = raw;
    const need = Math.max(1, Number(pr.pick_count) || 1);
    if (need > 1) {
      const ids = cands.slice(0, need).map(c => c.instance_id).filter(Boolean);
      if (ids.length) {
        cpuAct('resolve_prompt', { card_ids: ids });
      } else {
        cpuSchedulePromptRetryIfStuck(s, cpu);
      }
      return;
    }
    const pick = cpuPickBestCandidate(cands, cpu, hand, tier, read);
    if(pick?.instance_id) {
      cpuAct('resolve_prompt',{card_id:pick.instance_id});
    } else {
      cpuSchedulePromptRetryIfStuck(s, cpu);
    }
    return;
  }
  if(pr.type==='pick_wr_members_deck_top'){
    // Always card_ids[] — server rejects lone card_id when pick_count is 1 (Karin LS).
    const need = pr.pick_count || 2;
    const ids = (pr.candidates || []).slice(0, need).map(c => c.instance_id).filter(Boolean);
    cpuAct('resolve_prompt', { card_ids: ids });
    return;
  }
  if(pr.type==='shuffle_named_from_waiting_pick'){
    const max=pr.max_pick||pr.ability?.max_total||6;
    const ids=(pr.candidates||[]).slice(0,max).map(c=>c.instance_id).filter(Boolean);
    if(ids.length) cpuAct('resolve_prompt',{card_ids:ids});
    return;
  }
  if(cpuResolvePickLiveMatchSuccessHeart(pr, cpu, s)) return;
  if(pr.type==='live_success_pick_energy_or_member'){
    cpuAct('resolve_prompt',{choice:pr.can_both?'both':'energy'});
    return;
  }
  if(pr.type==='look_top_optional_wr'){
    cpuAct('resolve_prompt',{choice: cpuDiff()==='easy'?'no':'yes'});
    return;
  }
  if(pr.type==='opp_may_discard_or_modifier'){
    const live=(cpu.hand||[]).find(c=>c.card_type==='ライブ');
    cpuAct('resolve_prompt',{choice:live?'yes':'no',...(live?{discard_ids:[live.instance_id]}:{})});
    return;
  }
  if(pr.type==='reveal_live_opp_discard_or_blade'){
    cpuAct('resolve_prompt',{choice:(cpu.hand||[]).length?'yes':'no',
      ...(cpu.hand?.[0]?{discard_ids:[cpu.hand[0].instance_id]}:{})});
    return;
  }
  if(pr.type==='live_success_pick_yell_live'){
    const id=pr.candidates?.[0]?.instance_id;
    if(id) cpuAct('resolve_prompt',{card_id:id});
    return;
  }
  if(pr.type==='pick_surveil_heart_threshold'){
    cpuAct('resolve_prompt',{choice:'skip'});
    return;
  }
  if(pr.type==='pick_looked_deck_hand'){
    if (cpuResolveHangRiskPrompts(pr, cpu, tier, read, s)) return;
    const eligible=pr.eligible_ids||[];
    const id=eligible[0];
    if(id) { cpuAct('resolve_prompt',{card_id:id}); return; }
    if(pr.optional) { cpuAct('resolve_prompt',{choice:'skip'}); return; }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return;
  }
  if(pr.type==='live_success_pay_choice_wr_add'){
    if(pr.step==='confirm'){
      cpuAct('resolve_prompt',{choice:'no'});
      return;
    }
    const ctx = cpuAbilityCtx(cpu, tier, read, winPressure, (cpu.energy_zone || []).filter(energyChipActive).length,
      read ? { mustCatchUp: winPressure >= 0.45, behind: (read.successCount ?? 0) > (cpu.success_lives?.length ?? 0) } : null);
    if (cpuResolveScoredChoicePrompt(pr, cpu, tier, ctx)) return;
  }
  if(pr.type==='pick_yell_member'){
    const id=pr.candidates?.[0]?.instance_id;
    if(id) cpuAct('resolve_prompt',{card_id:id});
    return;
  }
  if(pr.type==='optional_success_wr_live_swap'){
    cpuResolveOptionalSuccessWrLiveSwap(pr, cpu, tier);
    return;
  }
  if(pr.type==='optional_success_live_swap'){
    if(pr.step==='confirm'){
      const hasLive=(cpu.hand||[]).some(c=>c.card_type==='ライブ');
      const hasSucc=(cpu.success_lives||[]).length>0;
      cpuAct('resolve_prompt',{choice:(hasLive&&hasSucc)?'yes':'no'});
      return;
    }
    const id=pr.candidates?.[0]?.instance_id;
    if(id) cpuAct('resolve_prompt',{card_id:id});
    return;
  }
  if(pr.type==='pick_same_name_member'){
    const id=pr.stage_members?.[0]?.instance_id;
    if(id) cpuAct('resolve_prompt',{member_id:id});
    return;
  }
  if(pr.type==='optional_pay_energy_if_baton'){
    const cost=pr.ability?.cost||1;
    const ae=(cpu.energy_zone||[]).filter(energyChipActive).length;
    cpuAct('resolve_prompt',{choice:ae>=cost?'yes':'no'});
    return;
  }
  if(pr.type==='optional_pay_energy_on_enter'){
    const cost=pr.pay_cost||pr.ability?.cost||1;
    const ae=(cpu.energy_zone||[]).filter(energyChipActive).length;
    cpuAct('resolve_prompt',{choice:ae>=cost?'yes':'no'});
    return;
  }
  if (pr.type === 'optional_stack_energy' || pr.type === 'optional_stack_energy_draw'
      || pr.type === 'optional_stack_energy_add_wr_live' || pr.type === 'optional_stack_energy_draw_blade_all') {
    const need = pr.ability?.energy || 1;
    const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
    if (ae < need) {
      cpuAct('resolve_prompt', { choice: 'no' });
      return;
    }
    const ctx = cpuAbilityCtx(cpu, tier, read, winPressure, ae,
      read ? { mustCatchUp: winPressure >= 0.45, behind: read.behind } : null);
    const score = cpuScoreOptionalAbility(pr.ability || {}, cpu, tier, ae, cpu.hand || [], winPressure, read);
    cpuAct('resolve_prompt', { choice: score >= cpuOptionalYesThreshold(tier) ? 'yes' : 'no' });
    return;
  }
  if(pr.type==='mandatory_discard_look_reveal'){
    const tier = cpuDiff();
    const winPressure = cpuWinPressure(cpu);
    const read = tier === 'easy' ? null : cpuReadOpponent(s, typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2');
    if (cpuResolveHandPickPrompt(pr, cpu, tier, winPressure, read)) return;
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return;
  }
  if(pr.type==='optional_pay_play_hand_member'){
    const matchFn = typeof handMembersMatchingPlayAbilityClient === 'function'
      ? handMembersMatchingPlayAbilityClient
      : null;
    const members = matchFn
      ? matchFn(cpu.hand || [], pr.ability || {}, pr.candidates || [])
      : (cpu.hand || []).filter(c => {
          if (c.card_type !== 'メンバー' || (c.cost || 0) > (pr.ability?.max_cost ?? 4)) return false;
          if (pr.ability?.any_group) return true;
          const names = pr.ability?.names || [];
          if (names.length) {
            const label = c.name_en || c.name || '';
            return names.some(n => label === n || label.includes(n));
          }
          return (c.group || '') === (pr.ability?.group || 'Nijigasaki');
        });
    const m = members[0];
    if(pr.step==='pick_slot'){
      const slot=(pr.slots||[])[0]||'center';
      cpuAct('resolve_prompt',{choice:slot});
      return;
    }
    if(m) cpuAct('resolve_prompt',{choice:'yes',card_id:m.instance_id});
    else cpuAct('resolve_prompt',{choice:'no'});
    return;
  }
  if(pr.type==='reveal_hand_member_cost_live_score'){
    const ids=(cpu.hand||[]).filter(c=>c.card_type==='メンバー').slice(0,2).map(c=>c.instance_id);
    cpuAct('resolve_prompt',{card_ids:ids});
    return;
  }
  if(pr.type==='optional_discard_blade_draw_if_live'){
    const c=cpu.hand?.[0];
    cpuAct('resolve_prompt',{choice:c?'yes':'no',...(c?{discard_ids:[c.instance_id]}:{})});
    return;
  }
  if(pr.type==='live_start_pay_or_discard'){
    const ae=(cpu.energy_zone||[]).filter(energyChipActive).length;
    const cost=pr.pay_cost||2;
    if(ae>=cost) cpuAct('resolve_prompt',{choice:'pay'});
    else {
      const ids=(cpu.hand||[]).slice(0,pr.discard_count||2).map(c=>c.instance_id);
      cpuAct('resolve_prompt',{choice:'discard',discard_ids:ids});
    }
    return;
  }
  if(pr.type==='optional_pay_energy_live_success'){
    const cost=pr.pay_cost||6;
    const ae=(cpu.energy_zone||[]).filter(energyChipActive).length;
    cpuAct('resolve_prompt',{choice:ae>=cost?'yes':'no'});
    return;
  }
  if(pr.type==='on_enter_draw_swap_area'){
    const slot=pr.slots?.[0]||'center';
    cpuAct('resolve_prompt',{choice:slot});
    return;
  }
  if(pr.type==='optional_swap_area_on_enter'){
    cpuResolveOptionalSwapAreaOnEnter(pr, cpu, tier, read);
    return;
  }
  if(pr.type==='optional_wr_member_reenter'){
    const slot=pr.candidates?.[0]?.slot;
    if(slot) cpuAct('resolve_prompt',{choice:'yes',slot});
    else cpuAct('resolve_prompt',{choice:'no'});
    return;
  }
  if(pr.type==='activate_energy_up_to'){
    const max=pr.max||6;
    const inactive=(cpu.energy_zone||[]).filter(e=>!e.active).length;
    cpuAct('resolve_prompt',{choice:String(Math.min(max,inactive))});
    return;
  }
  if(pr.type==='pick_member_return_energy'){
    const m=pr.members?.[0];
    if(m) cpuAct('resolve_prompt',{member_id:m.instance_id,count:m.stacked_count||1});
    return;
  }
  if(pr.type==='wait_members_pick'){
    const ids=(pr.stage_members||[]).slice(0,pr.max_members||1).map(c=>c.instance_id);
    cpuAct('resolve_prompt',{member_ids:ids});
    return;
  }
  if(pr.type==='wait_subunit_member_pick'){
    const id=pr.stage_members?.[0]?.instance_id;
    if(id) cpuAct('resolve_prompt',{member_ids:[id]});
    return;
  }
  if(pr.type==='opp_pick_hidden_hand'){
    const slots=pr.hand_slots||[];
    const pick=slots[Math.floor(Math.random()*slots.length)];
    if(pick?.instance_id) cpuAct('resolve_prompt',{card_id:pick.instance_id});
    return;
  }
  if(pr.type==='opp_pick_stage_active'){
    const id=pr.stage_members?.[0]?.instance_id;
    if(id) cpuAct('resolve_prompt',{member_id:id});
    return;
  }
  if(pr.type==='optional_negate_member_live_start_add_wr'){
    const id=pr.choices?.find(c=>c!=='skip');
    cpuAct('resolve_prompt',{choice:id||'skip'});
    return;
  }
  if(pr.type==='pick_wr_distinct_lives_opp_choice'){
    const ids=(pr.candidates||[]).slice(0,pr.pick_count||2).map(c=>c.instance_id);
    if(ids.length) cpuAct('resolve_prompt',{card_ids:ids});
    return;
  }
  if(pr.type==='optional_reveal_live_deck_bottom_surveil'){
    if(pr.step==='pick_hand_live'){
      const live=(pr.candidates||[]).find(c=>c.card_type==='ライブ') || (cpu.hand||[]).find(c=>c.card_type==='ライブ');
      if(live) cpuAct('resolve_prompt',{card_id:live.instance_id});
      return;
    }
    cpuAct('resolve_prompt',{choice:pr.choices?.includes('yes')?'yes':'no'});
    return;
  }
  if(pr.type==='optional_wr_member_deck_top_blade'){
    if(pr.step==='pick_wr_member'){
      const id=pr.candidates?.[0]?.instance_id;
      if(id) cpuAct('resolve_prompt',{card_id:id});
      return;
    }
    if(pr.step==='pick_stage_blade'){
      const slot=pr.candidates?.[0]?.slot;
      if(slot) cpuAct('resolve_prompt',{slot});
      return;
    }
    cpuAct('resolve_prompt',{choice:'no'});
    return;
  }
  if(pr.type==='player_choice_wr_live_deck_bottom_draw'){
    if(pr.step==='pick_wr_live'){
      const id=pr.candidates?.[0]?.instance_id;
      if(id) cpuAct('resolve_prompt',{card_id:id});
      return;
    }
    cpuAct('resolve_prompt',{choice:pr.choices?.includes('self')?'self':'opponent'});
    return;
  }
  if(pr.type==='live_start_center_cost_choice'){
    if(pr.step==='pick_stage_blade'||pr.step==='pick_opp_wait'){
      const slot=pr.candidates?.[0]?.slot;
      if(slot) cpuAct('resolve_prompt',{slot});
      return;
    }
    cpuAct('resolve_prompt',{choice:pr.choices?.includes('blade')?'blade':'wait_opp'});
    return;
  }
  if(pr.type==='wait_swap_wr_member_center'){
    if(pr.step==='discard_hand'){
      const id=cpu.hand?.[0]?.instance_id;
      if(id) cpuAct('resolve_prompt',{discard_ids:[id]});
      return;
    }
    if(pr.step==='pick_stage_member'||pr.step==='pick_opp_wait'){
      const slot=pr.candidates?.[0]?.slot;
      if(slot) cpuAct('resolve_prompt',{slot});
      return;
    }
    if(pr.step==='pick_wr_member'){
      const id=pr.candidates?.[0]?.instance_id;
      if(id) cpuAct('resolve_prompt',{card_id:id});
      return;
    }
    return;
  }
  if(pr.type==='opp_pick_wr_live_offer'){
    const id=pr.choices?.[0];
    if(id) cpuAct('resolve_prompt',{choice:id});
    return;
  }
  if(pr.type==='pick_named_members_grant_hearts'||pr.type==='pick_named_members_grant_blade'){
    const step=pr.step||'pick_named';
    const first=pr.first_slot||'';
    const cands=(pr.candidates||[]).filter(c=>{
      if(step==='pick_named') return !!c.named;
      return !!(c && c.slot && c.slot !== first);
    });
    const slot=cands[0]?.slot;
    if(slot) cpuAct('resolve_prompt',{slot});
    return;
  }
  if(pr.type==='pick_baton_entered_member_heart'){
    const cands=pr.candidates||[];
    const best=[...cands].sort((a,b)=>(b.blade||0)-(a.blade||0)||(b.cost||0)-(a.cost||0))[0];
    const slot=best?.slot||cands[0]?.slot;
    if(slot) cpuAct('resolve_prompt',{slot});
    else cpuAct('anti_softlock_skip',{});
    return;
  }
  if(pr.type==='hsbp6_pick_wr_live_and_member'||pr.type==='pl_muse_wr_pick_sequence'){
    const filter=pr.wr_pick_cfg?.filter||(pr.step==='pick_live'?'live':'member');
    const cands=(pr.candidates||[]).filter(c=>{
      if(filter==='live') return c.card_type==='ライブ'||c.card_type_en==='Live';
      if(filter==='member') return c.card_type==='メンバー'||c.card_type_en==='Member';
      return true;
    });
    const pool=cands.length?cands:(pr.candidates||[]);
    const pick=cpuPickBestCandidate(pool, cpu, hand, tier, read)||pool[0];
    if(pick?.instance_id) cpuAct('resolve_prompt',{card_id:pick.instance_id});
    else cpuAct('resolve_prompt',{choice:'skip'});
    return;
  }
  if(pr.type==='optional_discard_activate_wait_hearts'||pr.type==='optional_discard_activate_wait_blade'){
    const step=pr.step||'confirm';
    if(step==='pick_wait'){
      const slots=pr.wait_slots||[];
      const slot=slots[0];
      if(slot) cpuAct('resolve_prompt',{choice:slot,slot});
      else cpuAct('resolve_prompt',{choice:'no'});
      return;
    }
    if(tier==='easy'){ cpuAct('resolve_prompt',{choice:'no'}); return; }
    const ids=cpuPickDiscardIds(hand, 2, tier, winPressure, read);
    if(ids.length>=2) cpuAct('resolve_prompt',{choice:'yes',discard_ids:ids.slice(0,2)});
    else cpuAct('resolve_prompt',{choice:'no'});
    return;
  }
  if(pr.type==='pl_muse_stack_heart_choice'){
    const color=cpuPickHeartColor(pr.heart_choices||pr.choices, cpu);
    cpuAct('resolve_prompt',{choice:color,heart_choice:color});
    return;
  }
  if(pr.type==='play_stacked_member_from_under'){
    if(tier==='easy'){ cpuAct('resolve_prompt',{choice:'no'}); return; }
    const card=pr.stack_cards?.[0]||pr.candidates?.[0];
    const slot=pr.empty_slots?.[0];
    if(card?.instance_id&&slot){
      cpuAct('resolve_prompt',{choice:'yes',card_id:card.instance_id,slot});
    } else {
      cpuAct('resolve_prompt',{choice:'no'});
    }
    return;
  }
  if(pr.type==='stack_energy_zone_pick'){
    const need=Math.max(1, Number(pr.energy_count||pr.max_pick||1));
    const pool=(pr.candidates&&pr.candidates.length)?pr.candidates:(cpu.energy_zone||[]);
    // Prefer unused (active) chips for CPU.
    const ordered=[...pool].sort((a,b)=>Number(!!(b.active))-Number(!!(a.active)));
    const ids=ordered.slice(0,need).map(c=>c.instance_id).filter(Boolean);
    if(ids.length>=need) cpuAct('resolve_prompt',{energy_ids:ids, card_ids:ids});
    else cpuAct('anti_softlock_skip',{});
    return;
  }
  if(pr.type==='activated_discard_trigger_on_enter'){
    const pool=(pr.candidates&&pr.candidates.length)?pr.candidates:hand;
    const pick=cpuPickBestCandidate(pool, cpu, hand, tier, read)||pool[0];
    if(pick?.instance_id) cpuAct('resolve_prompt',{card_id:pick.instance_id});
    else cpuAct('anti_softlock_skip',{});
    return;
  }
  if(pr.type==='spbp2_discard_liella_choice'){
    const step=pr.step||'pick_hand';
    const grp=pr.group||'Superstar';
    if(step==='pick_hand'){
      const pool=hand.filter(c=>(c.group||'')===grp);
      const pick=cpuPickBestCandidate(pool, cpu, hand, tier, read)||pool[0]||hand[0];
      if(pick?.instance_id) cpuAct('resolve_prompt',{card_id:pick.instance_id});
      else cpuAct('anti_softlock_skip',{});
      return;
    }
    if(step==='choose'){
      const choices=pr.choices||['energy','hearts'];
      let pick='energy';
      if(choices.includes('both')&&tier==='hard') pick='both';
      else if(choices.includes('hearts')&&tier!=='easy') pick='hearts';
      else if(choices.includes(pick)) {/* keep */}
      else pick=choices[0];
      cpuAct('resolve_prompt',{choice:pick});
      return;
    }
    if(step==='pick_member'){
      const cands=pr.candidates||[];
      const best=[...cands].sort((a,b)=>(b.blade||0)-(a.blade||0))[0];
      const slot=best?.slot||cands[0]?.slot;
      if(slot) cpuAct('resolve_prompt',{slot});
      else cpuAct('anti_softlock_skip',{});
      return;
    }
    cpuAct('anti_softlock_skip',{});
    return;
  }
  if(pr.type==='optional_pay_energy_up_to'){
    const ae=(cpu.energy_zone||[]).filter(energyChipActive).length;
    const max=Math.min(pr.ability?.max_cost||2, ae);
    cpuAct('resolve_prompt',{choice:String(max)});
    return;
  }
  if(pr.type==='activate_wr_member_pick'){
    if(pr.step==='pick_member'){
      const id=pr.candidates?.[0]?.instance_id;
      if(id) cpuAct('resolve_prompt',{card_id:id});
      return;
    }
    if(pr.step==='pick_ability'){
      const choice=pr.choices?.[0] ?? '0';
      cpuAct('resolve_prompt',{choice:String(choice)});
      return;
    }
    if(pr.step==='pick_discard'){
      const need=pr.discard_count||1;
      const ids=(cpu.hand||[]).slice(0,need).map(c=>c.instance_id).filter(Boolean);
      if(ids.length>=need) cpuAct('resolve_prompt',{discard_ids:ids});
      return;
    }
    cpuAct('resolve_prompt',{choice:String(pr.choices?.[0] ?? '0')});
    return;
  }
  if(pr.type==='activated_pick_on_enter_ability'){
    cpuAct('resolve_prompt',{choice:'0'});
    return;
  }
  if(pr.type==='auto_yell_no_blade_heart'){
    return;
  }
  if(pr.type==='auto_yell_no_live_retry'){
    const owner = pr.owner || pr.responder || (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2');
    const yellCards = s.yell_reveal?.[owner] || [];
    const hasLive = yellCards.some(c => isLiveTypeCard(c));
    cpuAct('resolve_prompt', { choice: hasLive ? 'no' : 'yes' });
    return;
  }
  if(pr.type==='auto_yell_mill_extra_yell'){
    const owner = pr.owner || pr.responder || (typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2');
    const ids = (pr.candidates || []).slice(0, 2).map(c => c.instance_id).filter(Boolean);
    cpuAct('resolve_prompt', { choice: ids.length ? 'yes' : 'no', card_ids: ids });
    return;
  }
  if(pr.type==='ssd1_play_wr_empty' || pr.type==='both_wr_member_to_empty_stage'){
    if(pr.step==='pick_wr'){
      const id=pr.candidates?.[0]?.instance_id;
      if(id) cpuAct('resolve_prompt',{card_id:id});
      else cpuSchedulePromptRetryIfStuck(s, cpu);
      return;
    }
    if(pr.step==='pick_slot'){
      const slot=pr.slots?.[0];
      if(slot) cpuAct('resolve_prompt',{slot});
      else cpuSchedulePromptRetryIfStuck(s, cpu);
      return;
    }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return;
  }
  const pb1SlotPick=[
    'pick_other_blade_member_bonus','pick_other_heart_member_bonus','live_start_wr_group_member_count_pick_heart',
    'live_start_activate_stage_live_start_ability','live_start_edel_note_dual_pick_buff',
    'treat_pick_group_member_hearts_as',
    'cl1_pick_stage_member_blade','bp5_wr_live_deck_position',
    'pick_named_member_blade','pick_member_cost_bonus',
    'sbp5_pick_stage_member_blade','sbp5_pick_saint_snow_position','sbp5_position_change_slot',
    'sbp6_wait_opp_side_member','opp_member_match_heart_blade',
  ];
  if(pb1SlotPick.includes(pr.type)){
    const slot=pr.candidates?.[0]?.slot;
    if(slot) cpuAct('resolve_prompt',{slot});
    return;
  }
  if(pr.type==='reveal_hand_named_stack_under'){
    if(tier==='easy'){ cpuAct('resolve_prompt',{choice:'no'}); return; }
    const id=pr.candidates?.[0]?.instance_id||cpu.hand?.[0]?.instance_id;
    if(id) cpuAct('resolve_prompt',{choice:'yes',card_id:id});
    else cpuAct('resolve_prompt',{choice:'no'});
    return;
  }
  if(pr.type==='batch99_stack_wr_member'){
    const id=pr.candidates?.[0]?.instance_id;
    if(id) cpuAct('resolve_prompt',{pick_id:id});
    else cpuAct('resolve_prompt',{choice:'skip'});
    return;
  }
  if(pr.type==='spbp2_stack_wr_member'){
    const id=pr.candidates?.[0]?.instance_id;
    if(id) cpuAct('resolve_prompt',{pick_id:id});
    else cpuAct('resolve_prompt',{choice:'skip'});
    return;
  }
  if(pr.type==='spbp2_wait_self_opp_heart_gap'){
    if(pr.step==='confirm'){
      cpuAct('resolve_prompt',{choice: pr.self_candidates?.length ? 'yes' : 'no'});
      return;
    }
    if(pr.step==='pick_self'){
      const slot=pr.self_candidates?.[0]?.slot||pr.candidates?.[0]?.slot;
      if(slot) { cpuAct('resolve_prompt',{slot}); return; }
      cpuAct('resolve_prompt',{choice: pr.choices?.includes('no') ? 'no' : 'skip'});
      return;
    }
    if(pr.step==='pick_opp'){
      const slot=pr.candidates?.[0]?.slot;
      if(slot) { cpuAct('resolve_prompt',{slot}); return; }
      cpuAct('resolve_prompt',{choice: pr.choices?.includes('skip') ? 'skip' : 'no'});
      return;
    }
    cpuGenericPromptFallback(pr, cpu, tier, winPressure, read, s);
    return;
  }
  if(pr.type==='spbp2_center_move_choose'){
    cpuAct('resolve_prompt',{choice:'heart'});
    return;
  }
  if(pr.type==='spbp2_center_move_position'){
    if(pr.choices?.includes('no')||!pr.target_slots?.length){
      cpuAct('resolve_prompt',{choice:'no'});
    } else {
      cpuAct('resolve_prompt',{choice:'yes', target_slot:pr.target_slots[0]});
    }
    return;
  }
  if(pr.type==='discard_subunit_hand_draw'){
    const ids=(pr.candidates||[]).slice(0,1).map(c=>c.instance_id).filter(Boolean);
    cpuAct('resolve_prompt',{discard_ids:ids});
    return;
  }
  if(pr.type==='pick_number_reveal_deck_top'){
    cpuAct('resolve_prompt',{choice:'5'});
    return;
  }
  if(pr.type==='position_change_pick'){
    cpuAct('resolve_prompt',{choice:'yes'});
    return;
  }
  if(pr.type==='optional_pos_change_subunit_blade'){
    if(pr.choices?.includes('yes')&&pr.target_slots?.length){
      cpuAct('resolve_prompt',{choice:'yes', target_slot:pr.target_slots[0]});
    } else {
      cpuAct('resolve_prompt',{choice:'no'});
    }
    return;
  }
  if(pr.type==='mandatory_discard_after_draw'){
    const tier = cpuDiff();
    const winPressure = cpuWinPressure(cpu);
    const read = tier === 'easy' ? null : cpuReadOpponent(s, typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2');
    if (cpuResolveHandPickPrompt(pr, cpu, tier, winPressure, read)) return;
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return;
  }
  if(pr.type==='sbp5_draw_deck_bottom'||pr.type==='sbp6_discard_after_draw'){
    const tier = cpuDiff();
    const winPressure = cpuWinPressure(cpu);
    const read = tier === 'easy' ? null : cpuReadOpponent(s, typeof cpuOpponentId === 'function' ? cpuOpponentId() : 'p2');
    if (cpuResolveHandPickPrompt(pr, cpu, tier, winPressure, read)) return;
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return;
  }
  if (pr.type === 'optional_pay_energy_add_from_wr'
      || pr.type === 'optional_ema_punch_ask'
      || pr.type === 'optional_opp_wr_members_to_deck_bottom_then_wait'
      || pr.type === 'mill_fill_wr_optional_live_deck_top'
      || pr.type === 'pr_vol9_wait_opp_max_blade') {
    const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
    const score = cpuScoreOptionalAbility(pr.ability || { type: pr.type }, cpu, tier, ae, hand, winPressure, read);
    const yes = score >= cpuOptionalYesThreshold(tier);
    if (yes && (pr.candidates || []).length && (pr.pick_count || pr.max_pick || 0) > 0) {
      const need = pr.pick_count || pr.max_pick || 1;
      const pick = cpuPickBestCandidate(pr.candidates, cpu, hand, tier, read);
      const ids = [];
      if (pick?.instance_id) ids.push(pick.instance_id);
      for (const c of (pr.candidates || [])) {
        if (ids.length >= need) break;
        if (c?.instance_id && !ids.includes(c.instance_id)) ids.push(c.instance_id);
      }
      cpuAct('resolve_prompt', { choice: 'yes', card_ids: ids.slice(0, need), card_id: ids[0] });
      return;
    }
    cpuAct('resolve_prompt', { choice: yes ? 'yes' : 'no' });
    return;
  }
  const heartTypes=['choose_heart_per_success','choose_heart_mus_member','choose_heart_modifier','waive_required_heart_color','choose_required_heart_pair_gray','choose_replace_member_hearts','maki_reveal5_choose_color'];
  if(heartTypes.includes(pr.type)){
    cpuAct('resolve_prompt',{choice:cpuPickHeartColor(pr.choices, cpu)});
    return;
  }
  if (pr.choices?.includes('yes') && pr.choices?.includes('no') && pr.ability) {
    const ctx = cpuAbilityCtx(cpu, tier, read, winPressure, (cpu.energy_zone || []).filter(energyChipActive).length,
      read ? { mustCatchUp: cpuWinPressure(cpu) >= 0.45, behind: read?.behind } : null);
    if (cpuResolveScoredChoicePrompt(pr, cpu, tier, ctx)) return;
  }
  if (CPU_NO_GENERIC_YESNO.has(pr.type)) {
    if (cpuGenericPromptFallback(pr, cpu, tier, winPressure, read, s)) return;
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return;
  }
  if (pr.choices?.length && !pr.choices.includes('yes') && !pr.choices.includes('no')) {
    if (pr.type === 'optional_swap_area_on_enter') {
      cpuResolveOptionalSwapAreaOnEnter(pr, cpu, tier, read);
      return;
    }
    const ctx = cpuAbilityCtx(cpu, tier, read, winPressure, (cpu.energy_zone || []).filter(energyChipActive).length,
      read ? { mustCatchUp: winPressure >= 0.45, behind: read.behind } : null);
    if (cpuResolveScoredChoicePrompt(pr, cpu, tier, ctx)) return;
  }
  {
    const ctx = cpuAbilityCtx(cpu, tier, read, winPressure, (cpu.energy_zone || []).filter(energyChipActive).length,
      read ? { mustCatchUp: winPressure >= 0.45, behind: read.behind } : null);
    if (pr.choices?.length && cpuResolveScoredChoicePrompt(pr, cpu, tier, ctx)) return;
  }
  // Unknown / unhandled shape — cycle legal payloads instead of bare yes/no softlock.
  if (cpuTryCyclingPromptResolve(pr, cpu, tier, winPressure, read, s)) return;
  cpuSchedulePromptRetryIfStuck(s, cpu);
}

function cpuResolvePromptSmart(s, cpu, pr, tier) {
  const hand = cpu.hand || [];
  const ae = (cpu.energy_zone || []).filter(energyChipActive).length;
  const ctx = cpuCtx(s, cpu, typeof cpuOpponentId === 'function' ? cpuOpponentId() : null);
  const winPressure = ctx.winPressure;
  const read = ctx.read;
  const discard = (need, pool) => cpuPickDiscardIds(pool || hand, need, tier, winPressure, read);

  if (cpuResolveStepPrompt(pr, cpu, tier, winPressure, read)) return true;

  if (pr.type === 'effect_discard_hand' || pr.type === 'mandatory_discard_look_reveal' || pr.type === 'mandatory_discard_after_draw') {
    if (cpuResolveHandPickPrompt(pr, cpu, tier, winPressure, read)) return true;
  }
  if (pr.type === 'sbp5_draw_deck_bottom' || pr.type === 'sbp6_discard_after_draw') {
    if (cpuResolveHandPickPrompt(pr, cpu, tier, winPressure, read)) return true;
  }
  if (pr.type === 'ssd1_live_start_draw' || pr.type === 'ssd1_reveal_group_deck' || pr.type === 'ssd1_play_wr_empty' || pr.type === 'both_wr_member_to_empty_stage') {
    if (cpuResolveStepPrompt(pr, cpu, tier, winPressure, read)) return true;
  }
  if (pr.type === 'live_start_pay_or_discard') {
    const cost = pr.pay_cost || 2;
    if (ae >= cost && cpuTierHardPlus(tier)) { cpuAct('resolve_prompt', { choice: 'pay' }); return true; }
    if (ae >= cost && tier === 'normal' && ae >= cost + 1) { cpuAct('resolve_prompt', { choice: 'pay' }); return true; }
    const ids = discard(pr.discard_count || 2);
    cpuAct('resolve_prompt', { choice: 'discard', discard_ids: ids });
    return true;
  }
  if (pr.type === 'optional_live_start' || pr.type === 'optional_discard_prompt') {
    const ab = pr.ability || {};
    const score = cpuScoreOptionalAbility(ab, cpu, tier, ae, hand, winPressure, read);
    if (score >= cpuOptionalYesThreshold(tier)) {
      const data = cpuBuildOptionalYesPayload(pr, cpu, tier, winPressure, discard);
      if (data) { cpuAct('resolve_prompt', data); return true; }
    }
    cpuAct('resolve_prompt', { choice: 'no' });
    return true;
  }
  if (pr.type === 'optional_pay_energy_on_enter' || pr.type === 'optional_pay_energy_if_baton') {
    const ab = pr.ability || {};
    const cost = (pr.pay_cost ?? ab.cost ?? 1);
    const score = cpuScoreOptionalAbility(
      { ...ab, type: ab.type || pr.type, cost },
      cpu, tier, ae, hand, winPressure, read
    );
    const yes = score >= cpuOptionalYesThreshold(tier) && ae >= cost;
    cpuAct('resolve_prompt', { choice: yes ? 'yes' : 'no' });
    return true;
  }
  if (pr.type === 'optional_pay_energy_live_success') {
    const cost = pr.pay_cost || 6;
    cpuAct('resolve_prompt', { choice: (cpuTierHardPlus(tier) && ae >= cost) || (tier === 'normal' && ae >= cost + 2) ? 'yes' : 'no' });
    return true;
  }
  if (pr.type === 'look_top_optional_wr') {
    const millOppDeck = read && pr.prompt?.includes('opponent');
    const yes = cpuTierHardPlus(tier) || (tier === 'normal' && (hand.length >= 4 || millOppDeck));
    cpuAct('resolve_prompt', { choice: yes ? 'yes' : 'no' });
    return true;
  }
  if (pr.type === 'player_choice') {
    const ctx = cpuAbilityCtx(cpu, tier, read, winPressure, ae,
      read ? { mustCatchUp: ctx.sit?.mustCatchUp, behind: ctx.sit?.behind } : null);
    if (cpuResolveScoredChoicePrompt(pr, cpu, tier, ctx)) return true;
  }
  if (pr.type === 'opponent_choice') {
    const ctx = cpuAbilityCtx(cpu, tier, read, winPressure, ae,
      read ? { mustCatchUp: ctx.sit?.mustCatchUp, behind: ctx.sit?.behind } : null);
    if (cpuResolveScoredChoicePrompt(pr, cpu, tier, ctx)) return true;
  }
  if (pr.type === 'surveil_arrange') {
    cpuAct('resolve_prompt', cpuSurveilConfirmPayload(pr, cpu, tier));
    return true;
  }
  if (pr.type === 'live_success_order_sources' || pr.type === 'live_start_order_sources') {
    const ids = (pr.candidates || []).map((c) => c.instance_id).filter(Boolean);
    if (ids.length) {
      cpuAct('resolve_prompt', { card_ids: ids });
      return true;
    }
  }
  if (pr.type === 'optional_swap_area_on_enter') {
    return cpuResolveOptionalSwapAreaOnEnter(pr, cpu, tier, read);
  }
  if (cpuResolveBranchPickPrompts(pr, cpu, tier, winPressure, read, s)) return true;
  if (cpuResolveHangRiskPrompts(pr, cpu, tier, read, s)) return true;
  if (pr.type === 'pick_wr_live_deck_top' || pr.type === 'pick_wr_members_deck_top'
    || pr.type === 'shuffle_named_from_waiting_pick'
    || pr.type === 'pick_wr_to_hand' || pr.type === 'pick_wr_leave_stage_add'
    || pr.type === 'pick_judge_success_live') {
    const cands = pr.candidates || [];
    const pickN = pr.type === 'shuffle_named_from_waiting_pick'
      ? (pr.max_pick || pr.ability?.max_total || 6)
      : (pr.pick_count || 1);
    const scoreCard = c => isCpuLiveCard(c)
      ? (c.score || 0) + (cpuCheckHearts(stageHeartPool(cpu), cpuLiveRequiredHearts(c)) ? 2 : 0)
      : cpuScoreMember(c, cpu, hand, cpuStageColors(cpu), tier, read);
    const sorted = [...cands].sort((a, b) => scoreCard(b) - scoreCard(a));
    const ids = sorted.slice(0, pickN).map(c => c.instance_id).filter(Boolean);
    if (ids.length) {
      // pick_wr_members_deck_top always expects card_ids[] (even when pick_count=1).
      // Sending { card_id } softlocks Karin Live Start on Hard/Expert (#Karin WR top).
      if (pr.type === 'pick_wr_members_deck_top') {
        cpuAct('resolve_prompt', { card_ids: ids });
        return true;
      }
      cpuAct('resolve_prompt', pickN > 1 ? { card_ids: ids } : { card_id: ids[0] });
      return true;
    }
    if (pr.type === 'pick_wr_members_deck_top') {
      // "Up to N" — empty selection is legal when no usable candidates.
      cpuAct('resolve_prompt', { card_ids: [] });
      return true;
    }
  }
  if (pr.type === 'wait_opponent_stage_pick' && pr.step === 'pick_opp_wait') {
    const slot = cpuPickOppStageTarget(pr.candidates, read, tier) || pr.candidates?.[0]?.slot;
    if (slot) { cpuAct('resolve_prompt', { slot }); return true; }
  }
  if (pr.type === 'pos_change_opp_front_pick') {
    const cands = [...(pr.candidates || [])].sort((a, b) =>
      (a.cost || 0) - (b.cost || 0) || (a.blade || 0) - (b.blade || 0) || (a.hearts || 0) - (b.hearts || 0)
    );
    const slot = cands[0]?.slot;
    if (slot) { cpuAct('resolve_prompt', { slot }); return true; }
  }
  if (pr.type === 'live_start_center_cost_choice') {
    if (pr.step === 'pick_stage_blade') {
      const slot = cpuPickOppStageTarget(pr.candidates, read, tier) || pr.candidates?.[0]?.slot;
      if (slot) { cpuAct('resolve_prompt', { slot }); return true; }
    }
    if (pr.step === 'pick_opp_wait') {
      const slot = cpuPickOppStageTarget(pr.candidates, read, tier) || pr.candidates?.[0]?.slot;
      if (slot) { cpuAct('resolve_prompt', { slot }); return true; }
    }
    const oppStrong = (read?.strongestActive?.blade || 0) >= 2 || (read?.totalBlade || 0) >= 5;
    const pick = pr.choices?.includes('wait_opp') && oppStrong ? 'wait_opp'
      : pr.choices?.includes('blade') ? 'blade' : pr.choices?.[0];
    if (pick) { cpuAct('resolve_prompt', { choice: pick }); return true; }
  }
  if (pr.type === 'wait_swap_wr_member_center' && (pr.step === 'pick_stage_member' || pr.step === 'pick_opp_wait')) {
    const slot = cpuPickOppStageTarget(pr.candidates, read, tier) || pr.candidates?.[0]?.slot;
    if (slot) { cpuAct('resolve_prompt', { slot }); return true; }
  }
  if (pr.type === 'opp_pick_wr_live_offer') {
    const choices = pr.choices || [];
    const byId = new Map((pr.candidates || []).map(c => [c.instance_id, c]));
    const ranked = choices.slice().sort((a, b) => {
      const ca = byId.get(a), cb = byId.get(b);
      return ((ca?.score || 0) - (cb?.score || 0));
    });
    const pick = ranked[0] || choices[0];
    if (pick) { cpuAct('resolve_prompt', { choice: pick }); return true; }
  }
  if (pr.type === 'pick_yell_member' || pr.type === 'pick_same_name_member' || pr.type === 'blade_per_discarded_pick_member' || pr.type === 'pick_member_grant_hearts') {
    const cands = pr.candidates || pr.stage_members || [];
    const best = [...cands].sort((a, b) => (b.blade || 0) - (a.blade || 0))[0];
    const id = best?.instance_id || best?.member_id;
    if (id) {
      if (pr.type === 'pick_same_name_member' || pr.type === 'pick_member_grant_hearts') {
        cpuAct('resolve_prompt', { member_id: id, card_id: id });
      } else {
        cpuAct('resolve_prompt', { card_id: id });
      }
      return true;
    }
    cpuAct('resolve_prompt', { choice: 'skip' });
    return true;
  }
  if (pr.type === 'wait_pick_member_grant_live_score') {
    // Chika: Wait a Stage Member (prefer highest blade; nested {slot,summary} ok).
    const cands = (pr.candidates || []).map((c) => {
      if (c && c.summary && typeof c.summary === 'object') {
        return { ...c.summary, slot: c.slot, instance_id: c.instance_id || c.summary.instance_id };
      }
      return c;
    }).filter((c) => c && c.slot);
    const best = [...cands].sort((a, b) => (b.blade || 0) - (a.blade || 0))[0] || cands[0];
    if (best?.slot) {
      cpuAct('resolve_prompt', { slot: best.slot });
      return true;
    }
    cpuAct('resolve_prompt', { choice: 'cancel' });
    return true;
  }
  if (pr.type === 'live_success_pick_energy_or_member') {
    cpuAct('resolve_prompt', { choice: pr.can_both && cpuTierHardPlus(tier) ? 'both' : (tier === 'easy' ? 'energy' : 'member') });
    return true;
  }
  if (pr.type === 'optional_success_live_swap') {
    if (pr.step === 'confirm') {
      const lives = hand.filter(c => c.card_type === 'ライブ');
      const succ = cpu.success_lives || [];
      const bestHand = lives.sort((a, b) => (b.score || 0) - (a.score || 0))[0];
      const worstSucc = [...succ].sort((a, b) => (a.score || 0) - (b.score || 0))[0];
      const swap = tier !== 'easy' && bestHand && worstSucc && (bestHand.score || 0) > (worstSucc.score || 0) + (cpuTierHardPlus(tier) ? 0 : 1);
      cpuAct('resolve_prompt', { choice: swap ? 'yes' : 'no' });
      return true;
    }
    if (pr.step === 'pick_hand_live') {
    const lives = hand.filter(c => c.card_type === 'ライブ').sort((a, b) => (b.score || 0) - (a.score || 0));
    if (lives[0]) { cpuAct('resolve_prompt', { card_id: lives[0].instance_id }); return true; }
      return true;
    }
    if (pr.step === 'pick_success_live') {
      const succ = cpu.success_lives || [];
      const pick = [...succ].sort((a, b) => (a.score || 0) - (b.score || 0))[0];
      if (pick) { cpuAct('resolve_prompt', { card_id: pick.instance_id }); return true; }
      return true;
    }
  }
  if (pr.type === 'optional_success_wr_live_swap') {
    return cpuResolveOptionalSuccessWrLiveSwap(pr, cpu, tier);
  }
  if (pr.type === 'optional_pay_play_hand_member') {
    const matchFn = typeof handMembersMatchingPlayAbilityClient === 'function'
      ? handMembersMatchingPlayAbilityClient
      : null;
    const members = matchFn
      ? matchFn(hand, pr.ability || {}, pr.candidates || [])
      : hand.filter(c => {
          if (c.card_type !== 'メンバー' || (c.cost || 0) > (pr.ability?.max_cost ?? 4)) return false;
          if (pr.ability?.any_group) return true;
          const names = pr.ability?.names || [];
          if (names.length) {
            const label = c.name_en || c.name || '';
            return names.some(n => label === n || label.includes(n));
          }
          return (c.group || '') === (pr.ability?.group || 'Nijigasaki');
        });
    const m = members.sort((a, b) =>
      cpuScoreMember(b, cpu, hand, cpuStageColors(cpu), tier, read) - cpuScoreMember(a, cpu, hand, cpuStageColors(cpu), tier, read))[0];
    if (pr.step === 'pick_slot') {
      const slot = ['center', 'left', 'right'].find(sl => !cpu.stage?.[sl]) || (pr.slots || [])[0] || 'center';
      cpuAct('resolve_prompt', { choice: slot });
      return true;
    }
    if (m && tier !== 'easy') cpuAct('resolve_prompt', { choice: 'yes', card_id: m.instance_id });
    else cpuAct('resolve_prompt', { choice: m ? 'yes' : 'no', ...(m ? { card_id: m.instance_id } : {}) });
    return true;
  }
  if (pr.type === 'optional_discard_blade_per_card') {
    const max = pr.max_discard || pr.ability?.max_discard || 2;
    if (tier === 'easy' || hand.length < 1) {
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    const n = Math.min(max, hand.length, cpuTierHardPlus(tier) ? 2 : 1);
    const ids = cpuPickDiscardIds(hand, n, tier, winPressure, read);
    if (ids.length >= 1) {
      cpuAct('resolve_prompt', { choice: 'yes', discard_ids: ids.slice(0, max) });
    } else {
      cpuAct('resolve_prompt', { choice: 'no' });
    }
    return true;
  }
  if (pr.type === 'optional_discard_blade_draw_if_live') {
    const low = discard(1)[0];
    const liveInHand = hand.some(c => c.card_type === 'ライブ');
    cpuAct('resolve_prompt', { choice: (tier !== 'easy' && liveInHand && low) ? 'yes' : (low ? 'yes' : 'no'), ...(low ? { discard_ids: [low] } : {}) });
    return true;
  }
  if (pr.type === 'opp_may_discard_or_modifier') {
    const lowLive = hand.filter(c => c.card_type === 'ライブ').sort((a, b) => (a.score || 0) - (b.score || 0))[0];
    const oppClose = (read?.successCount ?? 0) >= 2;
    const use = cpuTierHardPlus(tier)
      ? lowLive && ((lowLive.score || 0) <= 2 || !oppClose)
      : tier === 'normal' ? lowLive && (lowLive.score || 0) <= 1 : lowLive;
    cpuAct('resolve_prompt', { choice: use ? 'yes' : 'no', ...(use ? { discard_ids: [lowLive.instance_id] } : {}) });
    return true;
  }
  if (pr.type === 'reveal_live_opp_discard_or_blade') {
    const ids = discard(1);
    cpuAct('resolve_prompt', { choice: ids.length && tier !== 'easy' ? 'yes' : (hand.length ? 'yes' : 'no'), ...(ids.length ? { discard_ids: ids } : {}) });
    return true;
  }
  if (pr.type === 'activate_energy_up_to') {
    const max = pr.max || 6;
    const inactive = (cpu.energy_zone || []).filter(e => !e.active).length;
    const take = cpuTierHardPlus(tier) ? Math.min(max, inactive) : tier === 'normal' ? Math.min(max, inactive, Math.max(1, Math.floor(inactive * 0.6))) : Math.min(max, inactive, 2);
    cpuAct('resolve_prompt', { choice: String(take) });
    return true;
  }
  if (pr.type === 'optional_pay_energy_up_to') {
    const max = Math.min(pr.ability?.max_cost || 2, ae);
    const pay = cpuTierHardPlus(tier) ? max : tier === 'normal' ? Math.min(max, Math.max(1, max - 1)) : Math.min(1, max);
    cpuAct('resolve_prompt', { choice: String(pay) });
    return true;
  }
  if (pr.type === 'pick_member_return_energy') {
    const m = [...(pr.members || [])].sort((a, b) => (b.stacked_count || 1) - (a.stacked_count || 1))[0];
    if (m) { cpuAct('resolve_prompt', { member_id: m.instance_id, count: m.stacked_count || 1 }); return true; }
  }
  if (pr.type === 'live_success_pay_choice_wr_add') {
    if (pr.step === 'confirm') {
      cpuAct('resolve_prompt', { choice: cpuTierHardPlus(tier) && ae >= (pr.pay_cost || 2) ? 'yes' : 'no' });
      return true;
    }
    const abCtx = cpuAbilityCtx(cpu, tier, read, winPressure, ae,
      read ? { mustCatchUp: ctx.sit?.mustCatchUp, behind: ctx.sit?.behind } : null);
    if (cpuResolveScoredChoicePrompt(pr, cpu, tier, abCtx)) return true;
  }
  if (pr.type === 'pick_looked_deck_hand') {
    const eligible = pr.eligible_ids || [];
    const byId = new Map((pr.candidates || []).map(c => [c.instance_id, c]));
    const ranked = eligible.map(id => byId.get(id)).filter(Boolean)
      .sort((a, b) => {
        if (a.card_type === 'ライブ' && b.card_type === 'ライブ') {
          return cpuScoreLiveForSet(b, tier, winPressure, read, cpu) - cpuScoreLiveForSet(a, tier, winPressure, read, cpu);
        }
        if (a.card_type === 'メンバー' && b.card_type === 'メンバー') {
          return cpuScoreMember(b, cpu, hand, cpuStageColors(cpu), tier, read)
            - cpuScoreMember(a, cpu, hand, cpuStageColors(cpu), tier, read);
        }
        if (a.card_type === 'メンバー') return -1;
        if (b.card_type === 'メンバー') return 1;
        return (b.score || 0) - (a.score || 0);
      });
    const need = Math.max(1, Number(pr.pick_count || 1));
    const distinct = !!(pr.ability?.distinct_groups);
    if (need > 1 || distinct) {
      const picked = [];
      const usedGroups = new Set();
      for (const c of ranked) {
        if (picked.length >= need) break;
        const g = String(c.group || '');
        if (distinct) {
          if (!g || usedGroups.has(g)) continue;
          usedGroups.add(g);
        }
        if (c.instance_id) picked.push(c.instance_id);
      }
      if (picked.length) {
        cpuAct('resolve_prompt', picked.length === 1 ? { card_id: picked[0] } : { card_ids: picked });
        return true;
      }
      if (pr.optional) { cpuAct('resolve_prompt', { choice: 'skip' }); return true; }
      cpuSchedulePromptRetryIfStuck(s, cpu);
      return true;
    }
    const id = ranked[0]?.instance_id || eligible[0];
    if (id) { cpuAct('resolve_prompt', { card_id: id }); return true; }
    if (pr.optional) { cpuAct('resolve_prompt', { choice: 'skip' }); return true; }
    cpuSchedulePromptRetryIfStuck(s, cpu);
    return true;
  }
  if (pr.type === 'pay_energy_reveal_live_wr_superset') {
    if (pr.step === 'pick_wr_live') {
      const id = pr.candidates?.[0]?.instance_id;
      if (id) { cpuAct('resolve_prompt', { card_id: id }); return true; }
      return true;
    }
    const live = (pr.candidates || []).find(c => c.card_type === 'ライブ')
      || (cpu.hand || []).find(c => c.card_type === 'ライブ');
    if (live && (cpu.energy_zone || []).filter(energyChipActive).length >= (pr.pay_cost || 2)) {
      cpuAct('resolve_prompt', { card_id: live.instance_id });
    }
    return true;
  }
  if (pr.type === 'wait_swap_wr_member_center' && pr.step === 'discard_hand') {
    const ids = discard(1);
    if (ids.length) { cpuAct('resolve_prompt', { discard_ids: ids }); return true; }
  }
  if (pr.type === 'discard_subunit_hand_draw') {
    const ids = discard(1);
    if (ids.length) { cpuAct('resolve_prompt', { discard_ids: ids }); return true; }
  }
  if (pr.type === 'optional_reveal_live_deck_bottom_surveil') {
    if (pr.step === 'pick_hand_live') {
      const live = (pr.candidates || [])
        .filter(c => c.card_type === 'ライブ')
        .sort((a, b) => (a.score || 0) - (b.score || 0))[0]
        || hand.filter(c => c.card_type === 'ライブ').sort((a, b) => (a.score || 0) - (b.score || 0))[0];
      if (live) { cpuAct('resolve_prompt', { card_id: live.instance_id }); return true; }
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    const yes = pr.choices?.includes('yes') && tier !== 'easy';
    cpuAct('resolve_prompt', { choice: yes ? 'yes' : 'no' });
    return true;
  }
  if (pr.type === 'optional_wr_member_deck_top_blade') {
    if (pr.step === 'pick_wr_member' || pr.step === 'pick_stage_blade') {
      return false;
    }
    const yes = cpuTierHardPlus(tier) || (tier === 'normal' && (pr.candidates?.length || pr.stage_members?.length));
    cpuAct('resolve_prompt', { choice: yes ? 'yes' : 'no' });
    return true;
  }
  if (pr.type === 'optional_pos_change_subunit_blade') {
    const yes = tier !== 'easy' && pr.choices?.includes('yes') && pr.target_slots?.length;
    if (yes) {
      cpuAct('resolve_prompt', { choice: 'yes', target_slot: pr.target_slots[0] });
    } else {
      cpuAct('resolve_prompt', { choice: 'no' });
    }
    return true;
  }
  if (pr.type === 'optional_activate_wait_subunit_add_live_wr' && pr.step === 'pick_wait_member') {
    const c = (pr.candidates || []).find((x) => x && (x.slot || x.instance_id));
    if (!c) {
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    cpuAct('resolve_prompt', { card_id: c.instance_id, slot: c.slot || '' });
    return true;
  }
  if (cpuResolveOptionalStageReposition(pr, cpu, tier, winPressure, read, ae)) return true;
  if (pr.type === 'optional_formation_change_group' && pr.step === 'assign') {
    const slots = pr.target_slots || [];
    const cur = (pr.assign_queue || [])[pr.assign_index || 0] || {};
    const from = cur.from_slot || cur.slot || '';
    const dest = slots.find((s) => s && s !== from) || slots[0];
    if (!dest) {
      cpuAct('resolve_prompt', { choice: 'no' });
      return true;
    }
    cpuAct('resolve_prompt', { slot: dest });
    return true;
  }
  if (pr.type === 'pick_surveil_heart_threshold') {
    const pool = stageHeartPool(cpu);
    const counts = {};
    pool.forEach(c => { counts[c] = (counts[c] || 0) + 1; });
    const choices = pr.choices || [];
    const ranked = choices.filter(c => c !== 'skip').sort((a, b) => (counts[b] || 0) - (counts[a] || 0));
    const pick = tier !== 'easy' && ranked.length ? ranked[0] : 'skip';
    cpuAct('resolve_prompt', { choice: pick });
    return true;
  }
  if (pr.type === 'spbp5_pay_energy_score') {
    const cost = pr.pay_cost || pr.ability?.cost || 2;
    cpuAct('resolve_prompt', { choice: cpuTierHardPlus(tier) && ae >= cost ? 'pay' : 'skip' });
    return true;
  }
  const optionalHandled = new Set([
    'optional_live_start', 'optional_discard_prompt', 'optional_pay_energy_on_enter',
    'optional_pay_energy_if_baton', 'optional_pay_energy_live_success', 'optional_success_live_swap',
    'optional_pay_play_hand_member', 'optional_discard_blade_draw_if_live',
    'optional_reveal_live_deck_bottom_surveil', 'optional_wr_member_deck_top_blade',
    'optional_pos_change_subunit_blade', 'optional_pay_energy_up_to',
  ]);
  if (pr.type.startsWith('optional_') && !optionalHandled.has(pr.type) && !pr.step) {
    const abCtx = cpuAbilityCtx(cpu, tier, read, winPressure, ae,
      read ? { mustCatchUp: ctx.sit?.mustCatchUp, behind: ctx.sit?.behind } : null);
    if (pr.choices?.length && cpuResolveScoredChoicePrompt(pr, cpu, tier, abCtx)) return true;
    const ab = pr.ability || {};
    const score = cpuScoreOptionalAbility(
      { ...ab, type: ab.type || pr.type },
      cpu, tier, ae, hand, winPressure, read
    );
    if (score >= cpuOptionalYesThreshold(tier)) {
      const data = cpuBuildOptionalYesPayload(pr, cpu, tier, winPressure, discard);
      if (data) { cpuAct('resolve_prompt', data); return true; }
    }
    cpuAct('resolve_prompt', { choice: 'no' });
    return true;
  }
  if (pr.choices?.length && !pr.step && !CPU_NO_GENERIC_YESNO.has(pr.type)) {
    const abCtx = cpuAbilityCtx(cpu, tier, read, winPressure, ae,
      read ? { mustCatchUp: ctx.sit?.mustCatchUp, behind: ctx.sit?.behind } : null);
    if (cpuResolveScoredChoicePrompt(pr, cpu, tier, abCtx)) return true;
  }
  const heartTypes = ['choose_heart_per_success', 'choose_heart_mus_member', 'choose_heart_modifier', 'waive_required_heart_color', 'choose_required_heart_pair_gray', 'choose_replace_member_hearts', 'maki_reveal5_choose_color'];
  if (heartTypes.includes(pr.type) && tier !== 'easy') {
    const pool = stageHeartPool(cpu);
    const counts = {};
    pool.forEach(c => { counts[c] = (counts[c] || 0) + 1; });
    const choices = pr.choices || ['yellow', 'pink', 'blue', 'green', 'purple'];
    const pick = choices.slice().sort((a, b) => (counts[b] || 0) - (counts[a] || 0))[0] || choices[0];
    cpuAct('resolve_prompt', { choice: pick });
    return true;
  }
  return false;
}

// CPU prompt entry: client/js/cpu-ai.js (loaded after handler helpers below)

function cpuClearLocalCpuPromptIfResolved(nextState) {
  const s = nextState || G.gameState;
  const cpuId = cpuOpponentId();
  if (!s || s.pending_prompt?.responder === cpuId) return;
  if (G.gameState?.pending_prompt?.responder === cpuId) {
    G.gameState = { ...G.gameState, pending_prompt: null };
    if (G.gameState.surveil_stash) delete G.gameState.surveil_stash;
  }
  if (G._deferredPromptState?.pending_prompt?.responder === cpuId) {
    clearDeferredPromptState();
    closeM('overlay-surveil');
  }
}

function scheduleCpuContinueAfterAct(type, tries = 0) {
  if (!G.isCPU) return;
  const gameplay = [
    'play_member', 'activate_ability', 'end_main', 'end_live_set', 'set_live_cards',
    'resolve_prompt', 'anti_softlock_skip', 'mulligan', 'ack_coin_flip', 'choose_first_player',
  ];
  if (!gameplay.includes(type)) return;
  if (tries > 48) {
    TCG_DEBUG.warn('cpu', 'continue-after-act gave up', type);
    ensurePollHoldReleased(G.gameState);
    cpuClearLocalCpuPromptIfResolved(G.gameState);
    doCPU(G.gameState);
    armWatchdog(G.gameState);
    return;
  }
  if (type === 'resolve_prompt' || type === 'anti_softlock_skip') {
    cpuClearLocalCpuPromptIfResolved(G.gameState);
    const s = G.gameState;
    const cpuId = cpuOpponentId();
    // Chained prompts (e.g. Kinako optional_pay → pick_looked_deck_hand): keep
    // resolving. Do not wipe a live server-backed CPU prompt as "stale".
    if (type === 'resolve_prompt' && s?.pending_prompt?.responder === cpuId) {
      ensurePollHoldReleased(s);
      doCPU(s);
      armWatchdog(s);
      return;
    }
    if (s && s.pending_prompt?.responder !== cpuId) {
      ensurePollHoldReleased(s);
      doCPU(s);
      armWatchdog(s);
      return;
    }
  }
  const setupAct = CPU_SETUP_ACTION_TYPES.has(type);
  const cpuOwnsPrompt = !!(G.gameState?.pending_prompt
    && G.gameState.pending_prompt.responder === cpuOpponentId());
  // Chained skill steps must not wait on a stuck Baton/WR animation — the Wait
  // overlay is already up and the server prompt will otherwise sit forever.
  if (!setupAct && !cpuOwnsPrompt && (G._liveSpectacleGateRunning || G._liveRoundPlaybackActive
      || (G.animating && tries < 12))) {
    cpuSchedule(() => scheduleCpuContinueAfterAct(type, tries + 1), 280);
    return;
  }
  if (G._perfSpectacleActive && tries < 20) {
    cpuSchedule(() => scheduleCpuContinueAfterAct(type, tries + 1), 320);
    return;
  }
  const s = G.gameState;
  if (!s) return;
  ensurePollHoldReleased(s);
  doCPU(s);
  armWatchdog(s);
}

function cpuActInflightKey(type, data = {}) {
  const s = G.gameState;
  if (!s || !G.isCPU) return null;
  const seq = s.seq ?? 0;
  if (type === 'play_member') {
    return `${seq}|${type}|${data.card_id || ''}|${data.slot || ''}`;
  }
  if (type === 'activate_ability') {
    return `${seq}|${type}|${data.card_id || ''}|${data.ability_index ?? ''}`;
  }
  if (type === 'end_live_set' || type === 'end_main' || type === 'set_live_cards') {
    return `${seq}|${type}`;
  }
  return null;
}

const CPU_SETUP_ACTION_TYPES = new Set(['ack_coin_flip', 'choose_first_player', 'mulligan']);

function isCpuPrepPhase(s) {
  const ph = s?.phase;
  return ph === 'coin_flip' || ph === 'setup';
}

/** Serialize concurrent CPU acts during main/live only — not coin flip / mulligan setup. */
function cpuActUsesBusyGuard(s, type) {
  if (CPU_SETUP_ACTION_TYPES.has(type) || isCpuPrepPhase(s)) return false;
  return true;
}

async function cpuAct(type,data) {
  if (isReplayViewing()) return;
  if (G._cpuActBusy && cpuActUsesBusyGuard(G.gameState, type)) {
    cpuSchedule(() => cpuAct(type, data), 150);
    return;
  }
  const inflightKey = cpuActInflightKey(type, data);
  if (inflightKey && G._cpuActInflight === inflightKey) return;
  if (type === 'end_live_set') {
    const cpuId = cpuOpponentId();
    const live = G.gameState;
    if (live?.phase === 'live_set' && live?.live_ready?.[cpuId]) return;
  }
  if (type === 'resolve_prompt' || type === 'anti_softlock_skip') {
    const key = cpuPromptKey(G.gameState);
    // Softlock escape must always be allowed even if a prior resolve_prompt is busy.
    if (type === 'anti_softlock_skip') {
      G._cpuResolveBusy = null;
    } else if (key && G._cpuResolveBusy === key) {
      cpuSchedule(() => cpuAct(type, data), 200);
      return;
    }
    if (key) G._cpuResolveBusy = key;
  }
  if (inflightKey) G._cpuActInflight = inflightKey;
  G._cpuActBusy = true;
  G._cpuActBusySince = Date.now();
  G._cpuActBusyType = type;
  let actOk = false;
  tcgDebugRecordAction(type, data, 'cpu');
  TCG_DEBUG.log('action', 'cpuAct →', type, data);
  try {
    // Must use apiPost (not frozen lexical API) so match-primary routes to VPS Redis.
    let d;
    try {
      d = await apiPost('action', {
        room_id: G.roomId,
        token: G.cpuToken,
        type,
        data,
      }, { silent: true });
    } catch (postErr) {
      d = { error: postErr && postErr.message ? postErr.message : String(postErr || 'action failed') };
    }
    if(d.error){
      TCG_DEBUG.warn('action', 'cpuAct failed', type, d.error);
      if (type === 'resolve_prompt' || type === 'anti_softlock_skip') {
        if (type === 'resolve_prompt' && /no pending prompt/i.test(d.error || '')) {
        G._cpuResolveBusy = null;
        G._cpuPromptScheduled = null;
          cpuResetPromptRetry();
          clearStaleCpuPromptFromClient({ force: true });
          if (G.polling) {
            ensurePollHoldReleased(G.gameState);
            await pullLatestState();
          }
          cpuSchedule(() => doCPU(G.gameState), 300);
          return;
        }
        if (type === 'resolve_prompt') {
          await cpuHandleResolvePromptError();
        } else {
          G._cpuResolveBusy = null;
          G._cpuPromptScheduled = null;
          const live = G.gameState;
          if (live?.pending_prompt?.responder === cpuOpponentId()) armCpuPromptHangWatch(live);
        }
      } else if (type === 'ack_coin_flip' || type === 'choose_first_player') {
        G._cpuCoinFlipChooseKey = null;
        cpuSchedule(() => doCPU(G.gameState), 400);
      } else if (type === 'activate_ability') {
        if (data?.card_id != null && data?.ability_index != null) {
          cpuBlacklistAbility(data.card_id, data.ability_index);
        }
        if (/heart color/i.test(d.error || '')) {
        G._cpuResolveBusy = null;
        const live = G.gameState;
        const cpuId = cpuOpponentId();
        const cpu = live?.players?.[cpuId];
        const pr = live?.pending_prompt;
        if (!pr && cpu && data?.card_id != null && data?.ability_index != null) {
          const card = Object.values(cpu.stage || {}).find(m => m?.instance_id === data.card_id);
          const ab = card?.abilities?.[data.ability_index];
          if (ab?.type === 'wait_self_choose_heart') {
              G._cpuActBusy = false;
              if (inflightKey && G._cpuActInflight === inflightKey) G._cpuActInflight = null;
            cpuAct('activate_ability', {
              ...data,
              heart_choice: cpuPickHeartColor(ab.heart_choices, cpu),
            });
            return;
            }
          }
        }
        cpuSchedule(() => doCPU(G.gameState), 500);
      } else if (type === 'play_member') {
        if (/not enough active energy/i.test(d.error || '')) {
          cpuBlacklistMemberPlay(data?.card_id, data?.slot, data?.baton_id);
          if (G.polling) {
            ensurePollHoldReleased(G.gameState);
            await pullLatestState();
          }
        }
        cpuSchedule(() => doCPU(G.gameState), 500);
      } else if (G.isCPU && ['activate_ability', 'end_main', 'end_live_set'].includes(type)) {
        if (type === 'end_live_set' && /already locked/i.test(d.error || '')) {
          if (G.polling) {
            ensurePollHoldReleased(G.gameState);
            await pullLatestState();
          }
          return;
        }
        cpuSchedule(() => doCPU(G.gameState), 500);
      }
      if (/no matching/i.test(d.error) && /waiting room/i.test(d.error)) {
        toast(d.error, 3200);
      }
      if (type === 'set_live_cards') {
        const cpuId = cpuOpponentId();
        const live = G.gameState;
        if (live?.phase === 'live_set' && !live?.live_ready?.[cpuId]) {
          G._cpuActBusy = false;
          if (inflightKey && G._cpuActInflight === inflightKey) G._cpuActInflight = null;
          cpuAct('end_live_set', {});
        }
      }
      return;
    }
    actOk = true;
    TCG_DEBUG.log('action', 'cpuAct ok', type, { seq: d.seq ?? G.lastSeq });
    if (type === 'resolve_prompt' || type === 'anti_softlock_skip') {
      // Free the busy latch so the next chained step can fire, but do NOT bump
      // seq / null pending_prompt here. That made pullLatestState treat the new
      // multi-step prompt as "already applied" (Shioriko Success/WR swap hung
      // after {choice:'yes'} with pick_success_live still on the server).
      G._cpuResolveBusy = null;
      G._cpuPromptScheduled = null;
      cpuResetPromptRetry();
      if (typeof cpuResetPromptAttempts === 'function') cpuResetPromptAttempts();
      cpuClearLocalCpuPromptIfResolved(G.gameState);
    }
    try {
      if (G.polling) {
        ensurePollHoldReleased(G.gameState);
        // During Live Start / spectacle, poll hold blocks pullLatestState — use the
        // skill-resolution pull so chained prompts (Mia optional_live_start → heart)
        // are visible to the next doCPU tick.
        const presentationHeld = !!(G._livePollHold || G.animating || G._liveSpectacleGateRunning
          || G._perfSpectacleActive || G._liveRoundPlaybackActive);
        if ((type === 'resolve_prompt' || type === 'anti_softlock_skip')
            && presentationHeld
            && typeof pullSkillResolutionState === 'function') {
          await pullSkillResolutionState();
        } else {
          await pullLatestState(true);
        }
      }
    } catch (pullErr) {
      TCG_DEBUG.warn('cpu', 'pullLatestState after cpuAct failed', type, pullErr);
    }
  } catch(e){
    TCG_DEBUG.warn('action', 'cpuAct error', type, e);
    if (type === 'resolve_prompt') {
      await cpuHandleResolvePromptError();
    } else if (type === 'anti_softlock_skip') {
      G._cpuResolveBusy = null;
      G._cpuPromptScheduled = null;
      const live = G.gameState;
      if (live?.pending_prompt?.responder === cpuOpponentId()) armCpuPromptHangWatch(live);
    } else if (['play_member', 'activate_ability', 'end_main', 'end_live_set', 'set_live_cards'].includes(type)) {
      actOk = true;
    }
  } finally {
    G._cpuActBusy = false;
    G._cpuActBusySince = 0;
    G._cpuActBusyType = null;
    if (inflightKey && G._cpuActInflight === inflightKey) G._cpuActInflight = null;
    if (actOk) scheduleCpuContinueAfterAct(type);
    if (type === 'end_live_set' || type === 'set_live_cards') {
      if (typeof clearCpuLiveSetHangWatch === 'function') clearCpuLiveSetHangWatch();
    }
    if (typeof armCpuPromptHangWatch === 'function'
        && G.gameState?.pending_prompt?.responder === cpuOpponentId()) {
      armCpuPromptHangWatch(G.gameState);
    }
  }
}
