/**
 * CPU meta weights — seed strength from printed stats + Loveca Point list.
 * Difficulty scales how much the AI trusts meta vs casual heuristics.
 * Prefer catalog loveca_point; boost table covers high-value staples.
 */
(function (global) {
  'use strict';

  /** Exact card_no boosts (rarity variants share base via strip). Values ~0–4. */
  const MEMBER_META_BOOST = {
    'PL!N-bp1-003': 3.6, // Shizuku
    'PL!N-bp1-012': 3.0, // Lanzhu
    'LL-bp2-001': 2.8,
    'PL!N-bp1-002': 2.2, // Kasumi
    'PL!N-sd1-008': 2.0, // Emma
    'PL!HS-bp2-014': 1.9, // Rurino
    'PL!SP-bp1-005': 1.4, // Ren
    'PL!SP-sd1-019': 1.3, // Shiki
    'PL!SP-sd1-020': 1.3, // Natsumi
    'PL!SP-pb1-014': 1.2, // Chisato
    'PL!N-bp4-010': 2.4, // Shioriko
    'PL!N-bp4-011': 2.2, // Mia
    'PL!AQ-bp3-001': 2.0,
    'PL!HS-bp6-001': 2.1, // Kaho
  };

  const LIVE_META_BOOST = {
    'PL!N-bp1-029': 2.4, // Eutopia
    'PL!SP-bp2-024': 2.6, // Vitamin SUMMER!
    'PL!N-bp2-029': 2.2,
    'PL!AQ-bp1-029': 2.0,
    'PL!HS-bp6-029': 2.1,
  };

  function stripCardNo(cardNo) {
    return String(cardNo || '')
      .replace(/[＋+].*$/, '')
      .replace(/-(SEC|SECL|SRL|RM|SD2|SD|PR|P|R|N|L|C)\d*$/i, '');
  }

  function lookupBoost(map, cardNo, kind) {
    if (typeof global.cpuPolicyCardRate === 'function') {
      const rate = global.cpuPolicyCardRate(kind, cardNo);
      if (rate > 0) return rate * 4;
    }
    const raw = String(cardNo || '');
    if (map[raw] != null) return map[raw];
    const base = stripCardNo(raw);
    if (map[base] != null) return map[base];
    return 0;
  }

  function tierMul(tier) {
    if (tier === 'expert') return 1.55;
    if (tier === 'hard') return 1.35;
    if (tier === 'normal') return 0.95;
    return 0.28;
  }

  function lovecaPts(card) {
    const no = card?.card_no;
    const fromCard = Number(card?.loveca_point || 0);
    if (fromCard > 0) return fromCard;
    const catalog = global.G?.allCards?.[no];
    if (catalog) return Number(catalog.loveca_point || 0) || 0;
    // Strip rarity and retry catalog
    const base = stripCardNo(no);
    const cat2 = global.G?.allCards?.[base];
    return Number(cat2?.loveca_point || 0) || 0;
  }

  global.cpuMetaMemberWeight = function cpuMetaMemberWeight(card, tier) {
    if (!card) return 0;
    const blade = Number(card.blade || 0);
    const cost = Number(card.cost || 0);
    const loveca = lovecaPts(card);
    const boost = lookupBoost(MEMBER_META_BOOST, card.card_no, 'members');
    const abilityN = Array.isArray(card.abilities) ? Math.min(4, card.abilities.length) : 0;
    let w = blade * 0.55 + loveca * 1.85 + boost + abilityN * 0.28 - Math.max(0, cost - 3) * 0.12;
    if (loveca >= 2) w += loveca * 0.55 * tierMul(tier);
    if (loveca >= 3) w += 1.2 * tierMul(tier);
    // Activated skills on stage Members are high priority for Hard/Expert.
    if ((tier === 'hard' || tier === 'expert') && (card.abilities || []).some((a) => a?.trigger === 'activated')) {
      w += 0.85 * tierMul(tier);
    }
    return w * tierMul(tier);
  };

  global.cpuMetaLiveWeight = function cpuMetaLiveWeight(card, tier, winPressure) {
    if (!card) return 0;
    const score = Number(card.score || 0);
    const loveca = lovecaPts(card);
    const boost = lookupBoost(LIVE_META_BOOST, card.card_no, 'lives');
    const req = card.required_hearts || card.hearts || [];
    const heartTax = req.reduce((n, h) => n + (h.count || 1), 0);
    const abilityN = Array.isArray(card.abilities) ? Math.min(4, card.abilities.length) : 0;
    let w = score * 1.35 + loveca * 1.45 + boost + abilityN * 0.35 - heartTax * 0.05;
    if ((winPressure || 0) >= 0.45) w += score * 0.45 + boost * 0.25;
    if (score >= 2) w += 0.85 * tierMul(tier);
    if ((tier === 'hard' || tier === 'expert') && score >= 1 && abilityN > 0) {
      w += 0.55 * tierMul(tier);
    }
    return w * tierMul(tier);
  };
})(window);
