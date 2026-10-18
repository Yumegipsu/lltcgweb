/**
 * Ranked PR pack reward popup — animated multi-card reveal after returning to hub.
 */
(function (global) {
  'use strict';

  const PENDING_KEY = 'tcg_pending_ranked_pr_reward';
  let revealInProgress = false;
  let scheduleTimer = null;

  function el(id) {
    if (typeof global.el === 'function') return global.el(id);
    return document.getElementById(id);
  }

  function t(key, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.t;
    return typeof fn === 'function' ? fn(key, vars || {}) : key;
  }

  function tt(key, fallback, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.tt;
    if (typeof fn === 'function') return fn(key, fallback, vars || {});
    const v = t(key, vars);
    return (v && v !== key) ? v : fallback;
  }

  function sleep(ms) {
    if (typeof global.sleep === 'function') return global.sleep(ms);
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  function rankedPrRewardCards(reward) {
    if (!reward || typeof reward !== 'object' || reward.skipped) return [];
    if (Array.isArray(reward.cards) && reward.cards.length) {
      return reward.cards.map((c) => Object.assign({}, c || {}, {
        card_no: c.card_no || reward.card_no,
        // reward.converted is only the first card. Copying it onto every pull
        // painted Star Gems on cards that were kept (#290 follow-up).
        converted: !!c.converted,
        star_gems: Number(c.star_gems) || 0,
        seal_tier: c.seal_tier || '',
        seals: Number(c.seals) || 0,
      }));
    }
    if (reward.card_no || reward.card?.card_no) {
      return [Object.assign({}, reward.card || {}, {
        card_no: reward.card_no || reward.card?.card_no,
        converted: !!(reward.converted || reward.card?.converted),
        star_gems: reward.card?.star_gems || reward.star_gems_earned || 0,
      })];
    }
    return [];
  }

  function rankedPrRewardHasCard(reward) {
    return rankedPrRewardCards(reward).length > 0;
  }

  function persistPending(reward) {
    try {
      sessionStorage.setItem(PENDING_KEY, JSON.stringify(reward));
    } catch (e) { /* ignore */ }
  }

  function clearPersistedPending() {
    try { sessionStorage.removeItem(PENDING_KEY); } catch (e) { /* ignore */ }
  }

  function restorePendingFromStorage() {
    global.A = global.A || {};
    if (rankedPrRewardHasCard(global.A.pendingRankedPrReward)) return;
    try {
      const raw = sessionStorage.getItem(PENDING_KEY);
      if (!raw) return;
      const reward = JSON.parse(raw);
      if (rankedPrRewardHasCard(reward)) {
        global.A.pendingRankedPrReward = reward;
      } else {
        clearPersistedPending();
      }
    } catch (e) {
      clearPersistedPending();
    }
  }

  function queueRankedPrReward(reward) {
    if (global.G && global.G.isSpectator) return;
    if (!rankedPrRewardHasCard(reward)) return;
    global.A = global.A || {};
    global.A.pendingRankedPrReward = reward;
    persistPending(reward);
    schedulePendingRankedPrReward();
  }

  function isHubActive() {
    return !!el('screen-hub')?.classList.contains('active');
  }

  function isBlockingModalOpen() {
    const migration = el('modal-star-gem-migration');
    if (migration && migration.classList.contains('open')) return true;
    const login = el('overlay-login-bonus');
    if (login && login.classList.contains('open')) return true;
    return false;
  }

  function clearScheduleTimer() {
    if (scheduleTimer) {
      clearTimeout(scheduleTimer);
      scheduleTimer = null;
    }
  }

  function schedulePendingRankedPrReward() {
    restorePendingFromStorage();
    if (!global.A?.pendingRankedPrReward) return;
    clearScheduleTimer();
    const attempt = () => {
      scheduleTimer = null;
      restorePendingFromStorage();
      if (!global.A?.pendingRankedPrReward) return;
      if (!isHubActive() || isBlockingModalOpen()) {
        scheduleTimer = setTimeout(attempt, 200);
        return;
      }
      void maybeShowPendingRankedPrReward();
    };
    scheduleTimer = setTimeout(attempt, 120);
  }

  async function maybeShowPendingRankedPrReward() {
    if (revealInProgress) return;
    restorePendingFromStorage();
    const reward = global.A?.pendingRankedPrReward;
    if (!rankedPrRewardHasCard(reward)) {
      if (global.A) global.A.pendingRankedPrReward = null;
      clearPersistedPending();
      return;
    }
    if (!isHubActive() || isBlockingModalOpen()) {
      schedulePendingRankedPrReward();
      return;
    }
    if (typeof global.buildPackOpenCardEl !== 'function') {
      // Pack UI not ready yet — keep pending and retry.
      schedulePendingRankedPrReward();
      return;
    }
    revealInProgress = true;
    try {
      // Clear only after we know the reveal can paint.
      global.A.pendingRankedPrReward = null;
      clearPersistedPending();
      await playRankedPrReveal(reward);
    } catch (e) {
      // Re-queue so a hard failure does not permanently swallow the pack UI.
      global.A.pendingRankedPrReward = reward;
      persistPending(reward);
    } finally {
      revealInProgress = false;
      if (typeof global.TCGLoginBonus?.scheduleHubLoginBonus === 'function') {
        global.TCGLoginBonus.scheduleHubLoginBonus();
      }
    }
  }

  function rankedPrCardName(card) {
    if (typeof global.cardLocaleName === 'function') {
      return global.cardLocaleName(card) || card.card_name_en || card.card_name || card.name_en || card.name || card.card_no || '?';
    }
    return card.card_name_en || card.card_name || card.name_en || card.name || card.card_no || '?';
  }

  function rankedPrRarityLabel(card) {
    if (card.converted) return 'Converted';
    return String(card.rarity || '').trim();
  }

  function rankedPrRarityClass(card) {
    if (typeof global.packResultRarityClass === 'function') {
      return global.packResultRarityClass(card.rarity) || '';
    }
    return '';
  }

  function rankedPrSubText(reward, cards) {
    const paid = cards.filter((c) => c.converted && (
      (c.seal_tier && Number(c.seals) > 0) || Number(c.star_gems) > 0
    ));
    const gems = Number(reward.star_gems_earned || 0)
      || paid.reduce((sum, c) => sum + (Number(c.star_gems) || 0), 0);
    const converted = paid.length;
    // Seals preference: PR duplicates paid PR seals instead of Star Gems (#277).
    const prSeals = cards.reduce((sum, c) => sum + (c.seal_tier === 'PR' ? (Number(c.seals) || 0) : 0), 0);
    const nSeals = cards.reduce((sum, c) => sum + (c.seal_tier === 'N' ? (Number(c.seals) || 0) : 0), 0);
    if (prSeals > 0 || nSeals > 0) {
      const parts = [];
      if (prSeals > 0) parts.push(`${prSeals} PR seals`);
      if (nSeals > 0) parts.push(`${nSeals} N seals`);
      const text = parts.join(' + ');
      return tt('win.rankedPrPackDupeSeals', `${converted} duplicate(s) → ${text}`, {
        count: converted || cards.length,
        seals: text,
      });
    }
    if (!converted && !gems) return '';
    if (gems > 0) {
      return tt('win.rankedPrPackDupes', `${converted} duplicate(s) → ${gems} Star Gems`, {
        count: converted || cards.length,
        gems,
      });
    }
    return '';
  }

  function flashRarePull(tier) {
    const flash = el('ranked-pr-reward-flash');
    if (!flash || typeof global.packMotionOk !== 'function' || !global.packMotionOk()) return;
    flash.classList.remove('active', 'flash-premium');
    if (tier >= 2) flash.classList.add('flash-premium');
    void flash.offsetWidth;
    flash.classList.add('active');
    setTimeout(() => flash.classList.remove('active', 'flash-premium'), tier >= 3 ? 580 : 520);
  }

  async function playRankedPrReveal(reward) {
    const overlay = el('overlay-ranked-pr-reward');
    const wrap = el('ranked-pr-reward-card-wrap');
    const titleEl = el('ranked-pr-reward-title');
    const detailsEl = el('ranked-pr-reward-details');
    const nameEl = el('ranked-pr-reward-card-name');
    const rarityEl = el('ranked-pr-reward-rarity');
    const subEl = el('ranked-pr-reward-sub');
    const okBtn = el('btn-ranked-pr-reward-ok');
    if (!overlay || !wrap) return;

    const cards = rankedPrRewardCards(reward);
    if (!cards.length) return;

    if (titleEl) {
      titleEl.textContent = tt('win.rankedPrPackPopupTitle', 'Ranked PR pack!', { count: cards.length });
    }
    if (detailsEl) detailsEl.hidden = true;
    if (nameEl) nameEl.textContent = '';
    if (rarityEl) {
      rarityEl.textContent = '';
      rarityEl.className = 'ranked-pr-reward-rarity';
    }
    if (subEl) {
      subEl.textContent = '';
      subEl.hidden = true;
    }
    if (okBtn) okBtn.hidden = true;
    wrap.replaceChildren();
    wrap.classList.remove('is-pack-row');

    let finished = false;
    const finish = () => {
      if (finished) return;
      finished = true;
      overlay.classList.remove('active');
      overlay.setAttribute('aria-hidden', 'true');
      wrap.replaceChildren();
      wrap.classList.remove('is-pack-row');
      el('ranked-pr-reward-flash')?.classList.remove('active', 'flash-premium');
    };

    try {
      if (typeof global.preloadPackPullFaces === 'function') {
        await global.preloadPackPullFaces(cards);
      }
    } catch (_) { /* continue */ }

    if (typeof global.buildPackOpenCardEl !== 'function') {
      throw new Error('pack UI unavailable');
    }

    overlay.classList.add('active');
    overlay.setAttribute('aria-hidden', 'false');

    const motion = typeof global.packMotionOk === 'function' ? global.packMotionOk() : true;
    const revealed = [];

    for (let i = 0; i < cards.length; i++) {
      const cardData = cards[i];
      wrap.replaceChildren();
      wrap.classList.remove('is-pack-row');
      const cardEl = global.buildPackOpenCardEl(cardData, 0, 1);
      cardEl.classList.add('pack-top', 'pack-faces-ready');
      wrap.appendChild(cardEl);

      const tier = parseInt(cardEl.dataset.revealTier || '0', 10);
      const revealMs = motion && global.PACK_REVEAL_MS
        ? (global.PACK_REVEAL_MS[tier] || 0)
        : 0;

      await sleep(motion ? (i === 0 ? 280 : 180) : 0);

      if (motion) {
        cardEl.classList.add('revealing');
        if (tier >= 1) flashRarePull(tier);
        if (typeof global.sfxPlay === 'function') global.sfxPlay('pack_reveal');
        await sleep(revealMs || 1);
        cardEl.classList.remove('revealing');
      }

      if (detailsEl && nameEl && rarityEl) {
        nameEl.textContent = rankedPrCardName(cardData);
        rarityEl.textContent = rankedPrRarityLabel(cardData);
        rarityEl.className = 'ranked-pr-reward-rarity pack-results-rarity ' + rankedPrRarityClass(cardData);
        detailsEl.hidden = false;
      }
      revealed.push(cardData);
      await sleep(motion ? 420 : 80);
    }

    // Final pack layout: show all pulls together.
    wrap.replaceChildren();
    wrap.classList.add('is-pack-row');
    revealed.forEach((cardData, idx) => {
      const cardEl = global.buildPackOpenCardEl(cardData, idx, revealed.length);
      cardEl.classList.add('pack-top', 'pack-faces-ready', 'ranked-pr-pack-mini');
      wrap.appendChild(cardEl);
    });

    if (detailsEl && nameEl && rarityEl) {
      nameEl.textContent = revealed.map(rankedPrCardName).join(' · ');
      rarityEl.textContent = tt('win.rankedPrPackSummary', `${revealed.length} cards`, { count: revealed.length });
      rarityEl.className = 'ranked-pr-reward-rarity';
      detailsEl.hidden = false;
    }
    const sub = rankedPrSubText(reward, revealed);
    if (subEl && sub) {
      subEl.textContent = sub;
      subEl.hidden = false;
    }
    if (okBtn) {
      okBtn.textContent = t('common.ok');
      okBtn.hidden = false;
    }

    await new Promise(resolve => {
      if (!okBtn) {
        finish();
        resolve();
        return;
      }
      const onOkOnce = (e) => {
        e?.preventDefault?.();
        okBtn.removeEventListener('click', onOkOnce);
        finish();
        resolve();
      };
      okBtn.addEventListener('click', onOkOnce);
    });
  }

  global.queueRankedPrReward = queueRankedPrReward;
  global.schedulePendingRankedPrReward = schedulePendingRankedPrReward;
  global.maybeShowPendingRankedPrReward = maybeShowPendingRankedPrReward;
  global.playRankedPrReveal = playRankedPrReveal;
  global.rankedPrRewardHasCard = rankedPrRewardHasCard;
  global.rankedPrRewardCards = rankedPrRewardCards;
  global.hasPendingRankedPrReward = function hasPendingRankedPrReward() {
    restorePendingFromStorage();
    return rankedPrRewardHasCard(global.A?.pendingRankedPrReward)
      || !!el('overlay-ranked-pr-reward')?.classList.contains('active');
  };
})(typeof window !== 'undefined' ? window : globalThis);
