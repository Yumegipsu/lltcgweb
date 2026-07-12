/**
 * CPU meta weights — seed strength from printed stats + April 2026 Loveca Point list
 * (restricted / high-point cards ≈ stronger in competitive play).
 * Difficulty scales how much the AI trusts meta vs casual heuristics.
 */
(function (global) {
  'use strict';

  /** Exact card_no boosts (and rarity variants share base via strip). Values ~0–4. */
  const MEMBER_META_BOOST = {
    'PL!N-bp1-003': 3.6, // Shizuku 4pt
    'PL!N-bp1-012': 3.0, // Lanzhu 3pt
    'LL-bp2-001': 2.8,   // You/Natsumi/Rurino 3pt
    'PL!N-bp1-002': 2.2, // Kasumi 2pt
    'PL!N-sd1-008': 2.0, // Emma 2pt
    'PL!HS-bp2-014': 1.9, // Rurino 2pt
    'PL!SP-bp1-005': 1.4, // Ren 1pt
    'PL!SP-sd1-019': 1.3, // Shiki
    'PL!SP-sd1-020': 1.3, // Natsumi
    'PL!SP-pb1-014': 1.2, // Chisato
  };

  const LIVE_META_BOOST = {
    'PL!N-bp1-029': 2.4, // Eutopia 1pt Live
    'PL!SP-bp2-024': 2.6, // Vitamin SUMMER!
  };

  function stripCardNo(cardNo) {
    return String(cardNo || '')
      .replace(/[＋+].*$/, '')
      .replace(/-(SEC|SECL|SRL|RM|SD2|SD|PR|P|R|N|L|C)\d*$/i, '');
  }

  function lookupBoost(map, cardNo) {
    const raw = String(cardNo || '');
    if (map[raw] != null) return map[raw];
    const base = stripCardNo(raw);
    if (map[base] != null) return map[base];
    return 0;
  }

  function tierMul(tier) {
    if (tier === 'expert') return 1.5;
    if (tier === 'hard') return 1.35;
    if (tier === 'normal') return 0.95;
    return 0.28; // easy barely uses meta
  }

  function lovecaPts(card) {
    const no = card?.card_no;
    const fromCard = Number(card?.loveca_point || 0);
    if (fromCard > 0) return fromCard;
    const catalog = global.G?.allCards?.[no];
    return Number(catalog?.loveca_point || 0) || 0;
  }

  /**
   * Additive score for member play / keep decisions.
   */
  global.cpuMetaMemberWeight = function cpuMetaMemberWeight(card, tier) {
    if (!card) return 0;
    const blade = Number(card.blade || 0);
    const cost = Number(card.cost || 0);
    const loveca = lovecaPts(card);
    const boost = lookupBoost(MEMBER_META_BOOST, card.card_no);
    const abilityN = Array.isArray(card.abilities) ? Math.min(4, card.abilities.length) : 0;
    let w = blade * 0.55 + loveca * 1.15 + boost + abilityN * 0.28 - Math.max(0, cost - 3) * 0.12;
    return w * tierMul(tier);
  };

  /**
   * Additive score for Live set / clear priority.
   * High printed score + meta boost preferred; hard chases them harder when pressured.
   */
  global.cpuMetaLiveWeight = function cpuMetaLiveWeight(card, tier, winPressure) {
    if (!card) return 0;
    const score = Number(card.score || 0);
    const loveca = lovecaPts(card);
    const boost = lookupBoost(LIVE_META_BOOST, card.card_no);
    const req = card.required_hearts || card.hearts || [];
    const heartTax = req.reduce((n, h) => n + (h.count || 1), 0);
    const abilityN = Array.isArray(card.abilities) ? Math.min(4, card.abilities.length) : 0;
    let w = score * 1.15 + loveca * 1.05 + boost + abilityN * 0.35 - heartTax * 0.08;
    if ((winPressure || 0) >= 0.45) w += score * 0.35 + boost * 0.25;
    return w * tierMul(tier);
  };
})(window);
