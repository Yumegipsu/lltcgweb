/**
 * LL-TCG CPU board evaluation + action scoring (DeepSeek-adapted).
 * Wins via Live Success — not ATK/life/stock. No full simulateAction;
 * scores use public+own features and heuristic deltas on existing candidates.
 */
(function (global) {
  'use strict';

  const WEIGHTS = {
    easy: {
      win: 0, liveThreat: 0, board: 0, resources: 0, tempo: 0, oppThreat: 0,
      blend: 0, jitter: 0.35, endMainOppCost: 0,
    },
    normal: {
      win: 1.0, liveThreat: 0.85, board: 0.55, resources: 0.45, tempo: 0.35, oppThreat: 0.4,
      blend: 0.42, jitter: 0.08, endMainOppCost: 0.55,
    },
    hard: {
      win: 1.35, liveThreat: 1.15, board: 0.7, resources: 0.55, tempo: 0.55, oppThreat: 0.95,
      blend: 0.62, jitter: 0.015, endMainOppCost: 1.15,
    },
  };

  function tierKey(tier) {
    if (tier === 'hard' || tier === 'normal') return tier;
    return 'easy';
  }

  function wFor(tier) {
    return WEIGHTS[tierKey(tier)] || WEIGHTS.easy;
  }

  function countSuccess(p) {
    return (p?.success_lives || []).length;
  }

  function stageMembers(p) {
    return Object.values(p?.stage || {}).filter(Boolean);
  }

  function activeEnergyCount(p) {
    const chips = p?.energy_zone || [];
    if (typeof global.energyChipActive === 'function') {
      return chips.filter(global.energyChipActive).length;
    }
    return chips.filter((c) => c && c.active !== false).length;
  }

  function stageBladeSum(p) {
    return stageMembers(p).reduce((n, m) => {
      if (typeof global.memberContributesBladeToYell === 'function'
        && !global.memberContributesBladeToYell(m)) return n;
      return n + (m.blade || 0) + (m.live_blade_bonus || 0);
    }, 0);
  }

  function emptySlots(p) {
    return ['left', 'center', 'right'].filter((sl) => !p?.stage?.[sl]).length;
  }

  function wrUtility(p) {
    const wr = p?.waiting_room || [];
    let lives = 0;
    let members = 0;
    for (const c of wr) {
      if (typeof global.isCpuLiveCard === 'function' ? global.isCpuLiveCard(c) : c.card_type === 'ライブ') lives++;
      else if (typeof global.isCpuMemberCard === 'function' ? global.isCpuMemberCard(c) : c.card_type === 'メンバー') members++;
    }
    return lives * 1.4 + members * 0.35 + Math.min(wr.length, 12) * 0.05;
  }

  function deckThinPenalty(p, pid) {
    let n = p?.main_deck?.length;
    if (n == null && typeof global.cpuOppDeckCount === 'function') {
      n = global.cpuOppDeckCount(p, pid);
    }
    n = n ?? p?.main_deck_count ?? 20;
    if (n <= 3) return 28;
    if (n <= 6) return 14;
    if (n <= 10) return 5;
    return 0;
  }

  function viableLiveSignal(cpu) {
    if (typeof global.cpuHandLiveContext === 'function') {
      const ctx = global.cpuHandLiveContext(cpu);
      const best = (ctx.viableLives || []).reduce((m, c) => Math.max(m, c.score || 0), 0);
      return {
        viable: ctx.viableLives?.length || 0,
        liveInHand: ctx.liveInHand?.length || 0,
        needsHearts: !!ctx.needsHeartsForLives,
        bestScore: best,
      };
    }
    return { viable: 0, liveInHand: 0, needsHearts: false, bestScore: 0 };
  }

  /**
   * Numeric score from pid's perspective. Positive = good for pid.
   */
  global.cpuEvaluateState = function cpuEvaluateState(s, pid, opts) {
    const tier = opts?.tier || (typeof global.cpuDiff === 'function' ? global.cpuDiff() : 'normal');
    const W = wFor(tier);
    if (W.blend <= 0 && tier === 'easy') return 0;

    const me = s?.players?.[pid];
    const oppId = pid === 'p1' ? 'p2' : 'p1';
    const opp = s?.players?.[oppId];
    if (!me) return 0;

    const read = opts?.read != null
      ? opts.read
      : (typeof global.cpuReadOpponent === 'function' ? global.cpuReadOpponent(s, pid) : null);

    const myWins = countSuccess(me);
    const oppWins = read?.successCount ?? countSuccess(opp);
    const winDiff = myWins - oppWins;
    // Distance to 3 successes — huge urgency near lethal
    const myToWin = Math.max(0, 3 - myWins);
    const oppToWin = Math.max(0, 3 - oppWins);
    let winScore = winDiff * 120;
    if (myToWin === 1) winScore += 90;
    if (myToWin === 0) winScore += 400;
    if (oppToWin === 1) winScore -= 110;
    if (oppToWin === 0) winScore -= 500;

    const live = viableLiveSignal(me);
    let liveThreat = live.viable * 28 + live.bestScore * 18;
    if (live.needsHearts) liveThreat -= 12;
    if (live.liveInHand === 0) liveThreat -= 8;
    // Own stage blade helps Live performance
    liveThreat += stageBladeSum(me) * 3.2;
    const yell = (typeof global.estimateYellBlade === 'function')
      ? global.estimateYellBlade(s, pid)
      : 0;
    liveThreat += yell * 2.4;

    const board = stageMembers(me).reduce((n, m) => n + (m.cost || 0) * 0.55 + (m.blade || 0) * 1.1, 0)
      + emptySlots(me) * -4
      + (typeof global.cpuMaxStageCost === 'function' ? global.cpuMaxStageCost(me) * 1.8 : 0);

    const handLen = me.hand?.length ?? me.hand_count ?? 0;
    const resources = activeEnergyCount(me) * 6.5
      + handLen * 4.2
      + wrUtility(me)
      - deckThinPenalty(me, pid);

    let tempo = 0;
    if (oppWins > myWins) tempo += 18; // behind → value greed/disruption elsewhere
    if (myWins > oppWins) tempo += 8;
    if (live.viable > 0 && (s.phase || '').startsWith('main')) tempo += 14;

    let oppThreat = 0;
    if (read) {
      oppThreat -= (read.totalBlade || 0) * 2.8;
      oppThreat -= (read.successCount || 0) * 35;
      if ((read.successCount || 0) >= 2) oppThreat -= 40;
      oppThreat -= Math.min(3, read.activeStage?.length || 0) * 5;
      if (read.hasEmptySlot) oppThreat += 3;
    }

    const score = winScore * W.win
      + liveThreat * W.liveThreat
      + board * W.board
      + resources * W.resources
      + tempo * W.tempo
      + oppThreat * W.oppThreat;

    if (opts?.trace && typeof global.cpuTraceScore === 'function') {
      global.cpuTraceScore('eval', 'state', [], {
        pid,
        tier,
        score: +score.toFixed(2),
        winScore,
        liveThreat: +liveThreat.toFixed(1),
        board: +board.toFixed(1),
        resources: +resources.toFixed(1),
        tempo,
        oppThreat: +oppThreat.toFixed(1),
        myWins,
        oppWins,
      });
    }
    return score;
  };

  /**
   * Hard-only: penalty/bonus for how an action interacts with opp near-win / Live timing.
   */
  global.cpuHardThreatAdjust = function cpuHardThreatAdjust(s, pid, action, ctx) {
    const tier = ctx?.tier || 'hard';
    if (tier !== 'hard') return 0;
    const read = ctx?.read;
    const sit = ctx?.sit;
    const me = s?.players?.[pid];
    if (!me || !action) return 0;
    let adj = 0;
    const oppNearWin = (read?.successCount ?? 0) >= 2;
    const behind = !!sit?.behind || (read?.successCount ?? 0) > countSuccess(me);
    const kind = action.kind || action.type;
    const label = String(action.label || action.payload?.ability_type || '');

    if (oppNearWin || behind) {
      if (kind === 'activate') {
        if (/wait_opp|opponent|disrupt|mill/i.test(label) || /wait_opp|opponent/.test(label)) {
          adj += 1.4;
        }
        if (/blade|heart|draw|surveil|wr|live/i.test(label)) adj += 0.55;
      }
      if (kind === 'play_member') adj += 0.35; // board before Live set
    }
    // Prefer setup before live_set when we have viable Lives
    const live = viableLiveSignal(me);
    if (live.viable > 0 && kind === 'activate' && /blade|heart|color/i.test(label)) {
      adj += 0.85;
    }
    if (kind === 'end_main' && live.viable === 0 && behind) adj -= 0.6;
    return adj;
  };

  /**
   * Score a main/Live/prompt action. Uses existing baseScore when present + eval blend.
   * @param {object} action { kind, score?, label?, payload? }
   * @param {object} ctx cpuCtx-like
   * @param {object} [opts] { peers: other actions for end_main opportunity cost }
   */
  global.cpuScoreAction = function cpuScoreAction(s, pid, action, ctx, opts) {
    const tier = ctx?.tier || (typeof global.cpuDiff === 'function' ? global.cpuDiff() : 'normal');
    const W = wFor(tier);
    const base = typeof action.score === 'number' ? action.score : 0;
    if (tier === 'easy' || W.blend <= 0) {
      return base + (Math.random() - 0.5) * W.jitter;
    }

    const stateScore = global.cpuEvaluateState(s, pid, {
      tier,
      read: ctx?.read,
      trace: false,
    });
    // Normalize state score into action-scale (~ tens → few points)
    const stateTerm = stateScore / 85;

    let delta = 0;
    const kind = action.kind || action.type;
    if (kind === 'play_member') {
      delta += Math.max(0, base) * 0.25 + 0.8;
    } else if (kind === 'activate') {
      delta += Math.max(0, base) * 0.35 + 0.4;
      // Disrupt when behind
      if (ctx?.sit?.behind && /opp|wait|opponent/i.test(String(action.label || ''))) {
        delta += tier === 'hard' ? 0.9 : 0.35;
      }
    } else if (kind === 'end_main') {
      const peers = (opts?.peers || []).filter((a) => a && a !== action && a.kind !== 'end_main');
      const bestAlt = peers.reduce((m, a) => Math.max(m, a.score || 0), -99);
      if (bestAlt > 0) {
        delta -= bestAlt * W.endMainOppCost;
      }
      delta -= 0.15; // slight preference to act when tied
    } else if (kind === 'live_set' || kind === 'set_live') {
      delta += Math.max(0, base) * 0.4 + (action.comboValue || 0) * 0.15;
      const myWins = countSuccess(s?.players?.[pid]);
      if (myWins >= 2) delta += 1.2;
    } else if (kind === 'prompt_choice') {
      delta += Math.max(0, base) * 0.2;
    }

    delta += global.cpuHardThreatAdjust(s, pid, action, ctx);

    let score = base * (1 - W.blend * 0.35) + (stateTerm + delta) * W.blend;
    if (W.jitter > 0) score += (Math.random() - 0.5) * W.jitter * 2;
    return score;
  };

  /** Re-rank main-phase candidates; returns best or null below min. */
  global.cpuRankMainActions = function cpuRankMainActions(s, pid, actions, ctx) {
    if (!actions?.length) return null;
    const tier = ctx?.tier || 'normal';
    if (tier === 'easy') {
      const sorted = [...actions].sort((a, b) => (b.score || 0) - (a.score || 0));
      return sorted[0] || null;
    }

    const scored = actions.map((a) => {
      const baseScore = typeof a.score === 'number' ? a.score : 0;
      const evalScore = global.cpuScoreAction(s, pid, a, ctx, { peers: actions });
      return { ...a, baseScore, evalScore, score: evalScore };
    });
    scored.sort((a, b) => b.evalScore - a.evalScore);

    // Hard: consider top-N and re-apply threat adjust (already in score)
    if (tier === 'hard' && scored.length >= 2) {
      const top = scored.slice(0, Math.min(5, scored.length));
      top.sort((a, b) => {
        const ta = global.cpuHardThreatAdjust(s, pid, a, ctx);
        const tb = global.cpuHardThreatAdjust(s, pid, b, ctx);
        return (b.evalScore + tb * 0.5) - (a.evalScore + ta * 0.5);
      });
      scored[0] = top[0];
    }

    if (typeof global.cpuTraceScore === 'function') {
      global.cpuTraceScore('main', 'eval_rank', scored.slice(0, 5).map((a) => ({
        kind: a.kind,
        score: a.evalScore,
        label: a.label,
        base: a.baseScore,
      })), {
        state: +global.cpuEvaluateState(s, pid, { tier, read: ctx?.read }).toFixed(1),
      });
    }

    const posture = typeof global.cpuPosture === 'function' ? global.cpuPosture(ctx) : {};
    const min = typeof global.cpuMainActionMinScore === 'function'
      ? global.cpuMainActionMinScore(tier, posture)
      : (tier === 'hard' ? 0.4 : 0.7);
    const best = scored[0];
    return best && best.evalScore >= min ? best : null;
  };

  /** Blend eval into Live card score for set phase. */
  global.cpuEvalLiveBonus = function cpuEvalLiveBonus(s, pid, liveCard, ctx) {
    const tier = ctx?.tier || 'normal';
    if (tier === 'easy') return 0;
    const W = wFor(tier);
    const myWins = countSuccess(s?.players?.[pid]);
    let b = (liveCard?.score || 0) * 0.08 * W.liveThreat;
    if (myWins >= 2) b += 0.55 * W.win;
    if (ctx?.sit?.behind) b += 0.35 * W.tempo;
    if ((ctx?.read?.successCount || 0) >= 2) b += 0.45;
    return b;
  };

  /** Blend eval into optional/prompt choice scores. */
  global.cpuEvalPromptBlend = function cpuEvalPromptBlend(baseScore, s, pid, choiceKey, ctx) {
    const tier = ctx?.tier || 'normal';
    if (tier === 'easy') return baseScore;
    const W = wFor(tier);
    const stateTerm = global.cpuEvaluateState(s, pid, { tier, read: ctx?.read }) / 100;
    let adj = stateTerm * W.blend * 0.35;
    if (choiceKey === 'yes') {
      if (ctx?.sit?.behind) adj += 0.15 * W.tempo;
      if ((ctx?.winPressure || 0) >= 0.45) adj += 0.12;
    }
    if (choiceKey === 'no' || choiceKey === 'skip') {
      adj -= 0.05 * W.blend;
    }
    return baseScore + adj;
  };
})(window);
