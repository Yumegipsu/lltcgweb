/**
 * Replay-tuned CPU policy. Loads window.LLTCG_CPU_POLICY (mined rates).
 * Empty buckets fall back to the heuristic prior baked into the artifact.
 * Difficulty is trust + noise on the same tables — not four unrelated AIs.
 */
(function (global) {
  'use strict';

  const FALLBACK = {
    version: 1,
    source: 'prior',
    mulligan: {
      return_live: 0.22,
      return_low_cost_member: 0.18,
      return_high_cost_member: 0.62,
      keep_score2_live: 0.84,
    },
    main: {
      play_vs_hold: { early_low: 0.72, early_high: 0.28, behind_any: 0.66, ahead_hold: 0.41 },
      activate: {
        draw: { yes: 0.78, need_live: 0.88, behind: 0.84, opp2: 0.7 },
        surveil: { yes: 0.7, need_live: 0.86, behind: 0.8, opp2: 0.62 },
        blade: { yes: 0.74, need_live: 0.55, behind: 0.6, opp2: 0.5 },
        heart: { yes: 0.8, need_live: 0.9, behind: 0.86, opp2: 0.72 },
        wait: { yes: 0.46, need_live: 0.3, behind: 0.58, opp2: 0.82 },
        live_score: { yes: 0.68, need_live: 0.4, behind: 0.5, opp2: 0.55 },
        energy: { yes: 0.6, need_live: 0.5, behind: 0.64, opp2: 0.48 },
      },
    },
    live: {
      clearable_commit: 0.9,
      yell_only_commit: 0.32,
      bluff_when_no_clear: 0.14,
      bluff_when_opp2: 0.22,
      prefer_high_score_when_both_clear: 0.92,
      take_low_score_at_two_successes: 0.96,
    },
    prompts: {
      yes: {
        optional_live_start: 0.62,
        optional_discard_prompt: 0.48,
        optional_pay_energy_on_enter: 0.55,
        default: 0.5,
      },
      keep_live_on_discard: 0.84,
      discard_member_first: 0.78,
    },
    cards: { members: {}, lives: {} },
  };

  function stripCardNo(cardNo) {
    return String(cardNo || '')
      .replace(/[＋+].*$/, '')
      .replace(/-(SEC|SECL|SRL|RM|SD2|SD|PR|P|R|N|L|C)\d*$/i, '');
  }

  function policy() {
    const loaded = global.LLTCG_CPU_POLICY;
    return loaded && typeof loaded === 'object' ? loaded : FALLBACK;
  }

  function blend(tier) {
    if (tier === 'expert') return 0.92;
    if (tier === 'hard') return 0.82;
    if (tier === 'normal') return 0.5;
    return 0.08;
  }

  function cardRate(kind, cardNo) {
    const pol = policy();
    const bucket = pol.cards?.[kind] || {};
    const raw = String(cardNo || '');
    const base = stripCardNo(raw);
    const row = bucket[base] || bucket[raw];
    if (!row || typeof row !== 'object') return 0;
    return Math.max(0, Math.min(1, Number(row.rate) || 0));
  }

  function activateFamily(type) {
    const t = String(type || '').toLowerCase();
    if (/wait/.test(t)) return 'wait';
    if (/heart/.test(t)) return 'heart';
    if (/blade/.test(t)) return 'blade';
    if (/surveil|look_/.test(t)) return 'surveil';
    if (/draw/.test(t)) return 'draw';
    if (/energy/.test(t)) return 'energy';
    if (/score|live/.test(t)) return 'live_score';
    return 'draw';
  }

  function activateRate(type, ctx) {
    const fam = activateFamily(type);
    const row = policy().main?.activate?.[fam] || policy().main?.activate?.draw || { yes: 0.5 };
    if (ctx?.opp2 && row.opp2 != null) return Number(row.opp2);
    if (ctx?.needLive && row.need_live != null) return Number(row.need_live);
    if (ctx?.behind && row.behind != null) return Number(row.behind);
    return Number(row.yes ?? 0.5);
  }

  function promptYes(type) {
    const yes = policy().prompts?.yes || {};
    if (yes[type] != null) return Number(yes[type]);
    return Number(yes.default ?? 0.5);
  }

  function playHoldRate(ctx) {
    const row = policy().main?.play_vs_hold || FALLBACK.main.play_vs_hold;
    if (ctx?.behind) return Number(row.behind_any ?? 0.66);
    if (ctx?.ahead) return 1 - Number(row.ahead_hold ?? 0.41);
    if ((ctx?.cost || 0) <= 4) return Number(row.early_low ?? 0.72);
    return Number(row.early_high ?? 0.28);
  }

  global.cpuPolicyGet = policy;
  global.cpuPolicyBlend = blend;
  global.cpuPolicyStripCardNo = stripCardNo;
  global.cpuPolicyCardRate = cardRate;
  global.cpuPolicyActivateRate = activateRate;
  global.cpuPolicyPromptYes = promptYes;

  /** Inclusion-rate bonus (points). Zero when the card never appeared. */
  global.cpuPolicyCardBonus = function cpuPolicyCardBonus(card, kind, tier) {
    if (!card) return 0;
    const rate = cardRate(kind, card.card_no);
    if (rate <= 0) return 0;
    const scale = tier === 'expert' ? 4.2 : tier === 'hard' ? 3.6 : tier === 'normal' ? 2.2 : 0.4;
    return rate * scale * blend(tier) * 4;
  };

  global.cpuPolicyPlayBias = function cpuPolicyPlayBias(card, tier, ctx) {
    if (!card || tier === 'easy') return 0;
    const rate = playHoldRate({
      behind: !!ctx?.behind,
      ahead: !!ctx?.ahead,
      cost: Number(card.cost || 0),
    });
    // Center 0.5 = no nudge. High play rate pushes the member up.
    return (rate - 0.5) * 2.4 * blend(tier);
  };

  global.cpuPolicyMulliganReturn = function cpuPolicyMulliganReturn(card, tier) {
    if (!card || tier === 'easy') return false;
    const m = policy().mulligan || FALLBACK.mulligan;
    const type = card.card_type || card.card_type_en;
    const isLive = type === 'ライブ' || type === 'Live';
    if (isLive) {
      if ((card.score || 0) >= 2 && Math.random() < Number(m.keep_score2_live ?? 0.84)) return false;
      return Math.random() < Number(m.return_live ?? 0.22) * blend(tier);
    }
    if ((card.cost || 0) >= 9) return Math.random() < Number(m.return_high_cost_member ?? 0.62) * blend(tier);
    if ((card.cost || 0) <= 2) return Math.random() < Number(m.return_low_cost_member ?? 0.18) * blend(tier);
    return false;
  };

  /**
   * Yes/no for optional prompts before dedicated branches.
   * Returns 'yes' | 'no' | null (null = let the type switch decide).
   */
  global.cpuPolicyPromptChoice = function cpuPolicyPromptChoice(type, tier, ctx) {
    if (!type || tier === 'easy') return null;
    const yesRate = promptYes(type);
    const trust = blend(tier);
    if (yesRate >= 0.72 && trust >= 0.5) return 'yes';
    if (yesRate <= 0.32 && trust >= 0.5) return 'no';
    if (ctx?.opp2 && /wait|discard|disrupt/.test(type) && yesRate >= 0.55) return 'yes';
    if (ctx?.behind && /live_start|pay_energy/.test(type) && yesRate >= 0.5) return 'yes';
    return null;
  };

  global.cpuPolicyKeepLiveOnDiscard = function cpuPolicyKeepLiveOnDiscard(tier) {
    const rate = Number(policy().prompts?.keep_live_on_discard ?? 0.84);
    return tier !== 'easy' && rate >= 0.6;
  };
})(window);
