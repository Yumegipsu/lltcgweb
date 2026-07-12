/**
 * Expert CPU — short Main-phase sequence search via server dry_run_actions.
 * Built on cpuEvaluateState; does not reimplement Loveca rules on the client.
 */
(function (global) {
  'use strict';

  const CONFIG = {
    MAX_MAIN_ACTIONS: 2,
    TOP_N_ACTIONS: 5,
    MAX_SEQUENCES: 16,
  };

  /** Remaining planned Main actions for this phase (excluding end_main). */
  let pendingQueue = [];
  let pendingKey = '';

  function queueKey(s, cpuId) {
    return `${s?.room_id || ''}|${s?.turn}|${s?.phase}|${s?.seq}|${cpuId}`;
  }

  function clearExpertQueue() {
    pendingQueue = [];
    pendingKey = '';
  }

  function toServerStep(action) {
    if (!action) return null;
    const kind = action.kind || action.type;
    if (kind === 'play_member') {
      return { type: 'play_member', data: action.payload || {} };
    }
    if (kind === 'activate' || kind === 'activate_ability') {
      return { type: 'activate_ability', data: action.payload || {} };
    }
    if (kind === 'end_main') {
      return { type: 'end_main', data: {} };
    }
    return null;
  }

  function activeEnergy(p) {
    const chips = p?.energy_zone || [];
    if (typeof global.energyChipActive === 'function') {
      return chips.filter(global.energyChipActive).length;
    }
    return chips.filter((c) => c && c.active !== false).length;
  }

  function emptyStageSlots(p) {
    return ['left', 'center', 'right'].filter((sl) => !p?.stage?.[sl]).length;
  }

  /**
   * Loveca opponent-threat term (not Weiss Power/stock). Higher = worse for us.
   */
  function opponentTurnPenalty(state, pid) {
    const oppId = pid === 'p1' ? 'p2' : 'p1';
    const opp = state?.players?.[oppId];
    const me = state?.players?.[pid];
    if (!opp) return 0;

    const read = typeof global.cpuReadOpponent === 'function'
      ? global.cpuReadOpponent(state, pid)
      : null;

    const oppHand = read
      ? (read.handCount ?? 0)
      : (opp.hand_count ?? opp.hand?.length ?? 0);
    const oppEnergy = activeEnergy(opp);
    const oppEmpty = emptyStageSlots(opp);
    const oppSuccess = read?.successCount ?? (opp.success_lives || []).length;
    const mySuccess = (me?.success_lives || []).length;
    const oppBlade = read?.totalBlade ?? 0;

    let pen = oppHand * 9
      + Math.min(3, oppEmpty) * oppHand * 2.5
      + Math.min(oppEnergy, 6) * 4
      + oppBlade * 2.2
      + oppSuccess * 38;

    if (oppSuccess >= 2) pen += 55;
    if (oppSuccess > mySuccess) pen += 22;
    // Holding many cards while we leave empty stage → comeback risk
    if (oppHand >= 5 && emptyStageSlots(me) > 0) pen += 18;

    return pen;
  }

  function scoreLeafState(state, pid, tier) {
    const base = typeof global.cpuEvaluateState === 'function'
      ? global.cpuEvaluateState(state, pid, { tier, read: typeof global.cpuReadOpponent === 'function' ? global.cpuReadOpponent(state, pid) : null })
      : 0;
    return base - opponentTurnPenalty(state, pid);
  }

  function prefilterActions(s, pid, actions, ctx) {
    if (!actions?.length) return [];
    const scored = actions.map((a) => {
      const evalScore = typeof global.cpuScoreAction === 'function'
        ? global.cpuScoreAction(s, pid, a, ctx, { peers: actions })
        : (a.score || 0);
      return { ...a, evalScore };
    });
    scored.sort((a, b) => b.evalScore - a.evalScore);
    return scored.slice(0, CONFIG.TOP_N_ACTIONS);
  }

  /**
   * Build sequences of length 1..MAX_MAIN_ACTIONS, each ending with end_main for leaf eval.
   * Also includes the pure end_main sequence.
   */
  function buildSequences(actions) {
    const end = { kind: 'end_main', score: 0, label: 'end_main', payload: {} };
    const sequences = [[end]];
    const depth = Math.min(CONFIG.MAX_MAIN_ACTIONS, actions.length || 0);

    // Length-1: each action then end_main
    for (const a of actions) {
      sequences.push([a, end]);
    }

    // Length-2: ordered pairs (skip identical play of same card_id when obvious)
    if (depth >= 2) {
      for (let i = 0; i < actions.length; i++) {
        for (let j = 0; j < actions.length; j++) {
          if (i === j) continue;
          const a = actions[i];
          const b = actions[j];
          // Same activate instance twice is rarely useful
          if (a.kind === 'activate' && b.kind === 'activate'
            && a.payload?.card_id === b.payload?.card_id
            && a.payload?.ability_index === b.payload?.ability_index) {
            continue;
          }
          if (a.kind === 'play_member' && b.kind === 'play_member'
            && a.payload?.card_id === b.payload?.card_id) {
            continue;
          }
          sequences.push([a, b, end]);
        }
      }
    }

    // Cap total sequences (prefer shorter + higher prefilter rank)
    if (sequences.length > CONFIG.MAX_SEQUENCES) {
      return sequences.slice(0, CONFIG.MAX_SEQUENCES);
    }
    return sequences;
  }

  async function dryRunSequences(sequences) {
    const roomId = global.G?.roomId;
    const token = global.G?.cpuToken;
    const API = global.API || './api.php';
    if (!roomId || !token) {
      throw new Error('Missing room or CPU token for dry-run');
    }

    const payloadSeqs = sequences.map((seq) => {
      const steps = [];
      for (const a of seq) {
        const step = toServerStep(a);
        if (step) steps.push(step);
      }
      return steps;
    }).filter((steps) => steps.length > 0);

    const r = await fetch(`${API}?action=dry_run_actions`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ room_id: roomId, token, sequences: payloadSeqs }),
    });
    const d = await r.json();
    if (d.error) throw new Error(d.error);
    return { results: d.results || [], payloadSeqs };
  }

  /**
   * Choose next Expert Main action. Uses queue across ticks; re-searches when empty.
   * @returns {Promise<object|null>} action { kind, payload, ... } or null to fall back
   */
  global.cpuExpertChooseMainAction = async function cpuExpertChooseMainAction(s, cpu, ctx, cpuId) {
    const tier = ctx?.tier || 'expert';
    const key = queueKey(s, cpuId);

    if (pendingKey && pendingKey !== key) {
      clearExpertQueue();
    }

    if (pendingQueue.length) {
      const next = pendingQueue.shift();
      if (typeof global.TCG_DEBUG?.log === 'function') {
        global.TCG_DEBUG.log('cpu', 'expert queue pop', { kind: next?.kind, left: pendingQueue.length });
      }
      return next;
    }

    const listFn = typeof global.cpuListMainActions === 'function'
      ? global.cpuListMainActions
      : null;
    if (!listFn) return null;

    const raw = listFn(s, cpu, ctx) || [];
    const top = prefilterActions(s, cpuId, raw, ctx);
    const sequences = buildSequences(top);

    let results;
    try {
      const dry = await dryRunSequences(sequences);
      results = dry.results;
    } catch (e) {
      if (typeof global.TCG_DEBUG?.warn === 'function') {
        global.TCG_DEBUG.warn('cpu', 'expert dry-run failed', e.message || e);
      }
      clearExpertQueue();
      return null;
    }

    let bestIdx = -1;
    let bestScore = -Infinity;
    const scored = [];

    for (let i = 0; i < results.length; i++) {
      const res = results[i];
      const seqIdx = typeof res?.index === 'number' ? res.index : i;
      const seq = sequences[seqIdx];
      if (!res || !res.ok || !res.state) {
        scored.push({ i: seqIdx, score: -Infinity, error: res?.error || 'no state' });
        continue;
      }
      let score = scoreLeafState(res.state, cpuId, tier);
      // Prefer sequences that open a useful prompt over hard errors; slight noise for variety
      if (res.stopped === 'pending_prompt') score += 8;
      if (res.stopped === 'error') score = -Infinity;
      score += Math.random() * 0.05;
      scored.push({ i: seqIdx, score, stopped: res.stopped, seqLen: seq?.length });
      if (score > bestScore) {
        bestScore = score;
        bestIdx = seqIdx;
      }
    }

    if (typeof global.cpuTraceScore === 'function') {
      global.cpuTraceScore('main', 'expert_search', scored.slice(0, 8).map((row) => ({
        score: +row.score.toFixed?.(2) || row.score,
        stopped: row.stopped,
        seqLen: row.seqLen,
        error: row.error,
      })), { bestIdx, bestScore: +bestScore.toFixed(2), candidates: top.length });
    }

    if (bestIdx < 0 || !sequences[bestIdx]) {
      clearExpertQueue();
      return { kind: 'end_main', score: 0, label: 'end_main', payload: {} };
    }

    const bestSeq = sequences[bestIdx];
    const plan = bestSeq.filter((a) => a && a.kind !== 'end_main');
    if (!plan.length) {
      clearExpertQueue();
      return { kind: 'end_main', score: 0, label: 'end_main', payload: {} };
    }

    pendingKey = key;
    pendingQueue = plan.slice(1);
    const first = plan[0];
    if (typeof global.TCG_DEBUG?.log === 'function') {
      global.TCG_DEBUG.log('cpu', 'expert plan', {
        first: first.kind,
        label: first.label,
        queued: pendingQueue.length,
        score: +bestScore.toFixed(2),
      });
    }
    return first;
  };

  global.cpuExpertClearQueue = clearExpertQueue;
})(window);
