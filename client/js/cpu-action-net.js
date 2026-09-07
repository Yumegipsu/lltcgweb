/**
 * Action critic trained on ranked replay decisions.
 * Scores a candidate the way those players acted, plus whether that line
 * improved the success race. Easy ignores it.
 */
(function (global) {
  'use strict';

  const FEATURES = 20;
  const HIDDEN = 8;

  function net() {
    const loaded = global.LLTCG_CPU_ACTION_NET;
    return loaded && typeof loaded === 'object' ? loaded : null;
  }

  function blend(tier) {
    if (tier === 'expert') return 0.85;
    if (tier === 'hard') return 0.7;
    if (tier === 'normal') return 0.4;
    return 0;
  }

  function tanh(z) {
    const x = Math.max(-20, Math.min(20, z));
    const e = Math.exp(2 * x);
    return (e - 1) / (e + 1);
  }

  function sigmoid(z) {
    const x = Math.max(-20, Math.min(20, z));
    return 1 / (1 + Math.exp(-x));
  }

  function features(sit) {
    const kind = sit?.kind || '';
    const my = sit?.mySuccess || 0;
    const opp = sit?.oppSuccess || 0;
    const turn = sit?.turn || 1;
    return [
      1,
      Math.min(1, Math.max(0, turn / 12)),
      Math.min(1, my / 3),
      Math.min(1, opp / 3),
      Math.max(-1, Math.min(1, (my - opp) / 3)),
      Math.min(1, (sit?.emptySlots || 0) / 3),
      Math.min(1, (sit?.hearts || 0) / 6),
      Math.min(1, (sit?.oppHearts || 0) / 6),
      Math.min(1, (sit?.cost || 0) / 15),
      Math.min(1, (sit?.score || 0) / 4),
      Math.min(1, (sit?.blade || 0) / 4),
      kind === 'play_member' ? 1 : 0,
      kind === 'activate' ? 1 : 0,
      kind === 'end_main' ? 1 : 0,
      kind === 'live_set' ? 1 : 0,
      kind === 'prompt' ? 1 : 0,
      sit?.canClear ? 1 : 0,
      opp > my ? 1 : 0,
      opp >= 2 ? 1 : 0,
      Math.min(1, (sit?.hand || 0) / 8),
    ];
  }

  function judge(sit) {
    const w = net();
    if (!w || !w.w1) return { take: 0.5, value: 0, score: 0 };
    const x = features(sit);
    const h = [];
    for (let j = 0; j < HIDDEN; j++) {
      const row = w.w1[j] || [];
      let s = Number(w.b1?.[j] || 0);
      for (let i = 0; i < FEATURES; i++) s += Number(row[i] || 0) * x[i];
      h.push(tanh(s));
    }
    let takeLogit = Number(w.b_take || 0);
    let valueLogit = Number(w.b_value || 0);
    for (let j = 0; j < HIDDEN; j++) {
      takeLogit += Number(w.w_take?.[j] || 0) * h[j];
      valueLogit += Number(w.w_value?.[j] || 0) * h[j];
    }
    const take = sigmoid(takeLogit);
    const value = tanh(valueLogit);
    return { take, value, score: (take - 0.5) * 2 + value * 0.6 };
  }

  function seatSit(cpu, read, s, extra) {
    const stage = cpu?.stage || {};
    const empty = ['left', 'center', 'right'].filter((sl) => !stage[sl]).length;
    const hearts = typeof global.stageHeartPool === 'function' ? global.stageHeartPool(cpu).length : 0;
    return {
      turn: s?.turn || 1,
      mySuccess: (cpu?.success_lives || []).length,
      oppSuccess: read?.successCount || 0,
      emptySlots: empty,
      hearts,
      oppHearts: (read?.stageHearts || []).length,
      hand: (cpu?.hand || []).length,
      ...extra,
    };
  }

  /** Points added to a candidate the CPU is about to take. Easy returns 0. */
  global.cpuActionNetAdjust = function cpuActionNetAdjust(tier, sit) {
    const trust = blend(tier);
    if (trust <= 0 || !sit) return 0;
    const judged = judge(sit);
    return judged.score * 2.4 * trust;
  };

  global.cpuActionNetJudge = judge;
  global.cpuActionNetSeat = seatSit;
  global.cpuActionNetFeatures = features;
})(window);
