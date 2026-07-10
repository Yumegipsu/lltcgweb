/**
 * Ranked PR card reward popup — animated reveal after returning to hub.
 */
(function (global) {
  'use strict';

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

  function sleep(ms) {
    if (typeof global.sleep === 'function') return global.sleep(ms);
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  function rankedPrRewardHasCard(reward) {
    if (!reward || typeof reward !== 'object') return false;
    if (reward.skipped) return false;
    return !!(reward.card_no || reward.card?.card_no);
  }

  function queueRankedPrReward(reward) {
    if (!rankedPrRewardHasCard(reward)) return;
    global.A = global.A || {};
    global.A.pendingRankedPrReward = reward;
  }

  function isHubActive() {
    return !!el('screen-hub')?.classList.contains('active');
  }

  function isBlockingModalOpen() {
    const migration = el('modal-star-gem-migration');
    return !!(migration && migration.classList.contains('open'));
  }

  function clearScheduleTimer() {
    if (scheduleTimer) {
      clearTimeout(scheduleTimer);
      scheduleTimer = null;
    }
  }

  function schedulePendingRankedPrReward() {
    if (!global.A?.pendingRankedPrReward) return;
    clearScheduleTimer();
    const attempt = () => {
      scheduleTimer = null;
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
    const reward = global.A?.pendingRankedPrReward;
    if (!rankedPrRewardHasCard(reward)) {
      if (global.A) global.A.pendingRankedPrReward = null;
      return;
    }
    if (!isHubActive() || isBlockingModalOpen()) {
      schedulePendingRankedPrReward();
      return;
    }
    revealInProgress = true;
    global.A.pendingRankedPrReward = null;
    try {
      await playRankedPrReveal(reward);
    } finally {
      revealInProgress = false;
    }
  }

  function rankedPrCardName(reward) {
    const card = reward.card || {};
    if (typeof global.cardLocaleName === 'function') {
      return global.cardLocaleName(card) || card.card_name_en || card.card_name || card.card_no || '?';
    }
    return card.card_name_en || card.card_name || card.card_no || '?';
  }

  function rankedPrRarityLabel(reward) {
    const card = reward.card || {};
    const converted = !!(card.converted || reward.converted);
    if (converted) return 'Converted';
    return String(card.rarity || '').trim();
  }

  function rankedPrRarityClass(reward) {
    if (typeof global.packResultRarityClass === 'function') {
      const card = reward.card || {};
      return global.packResultRarityClass(card.rarity) || '';
    }
    return '';
  }

  function rankedPrSubText(reward) {
    const card = reward.card || {};
    if (!(card.converted || reward.converted)) return '';
    const name = rankedPrCardName(reward);
    const gems = card.star_gems || reward.star_gems || reward.star_gems_earned || 0;
    return t('win.rankedPrDupe', { name, gems });
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

    const cardData = Object.assign({}, reward.card || {}, {
      card_no: reward.card_no || reward.card?.card_no,
      converted: !!(reward.converted || reward.card?.converted),
      star_gems: reward.card?.star_gems || reward.star_gems_earned || 0,
    });

    if (titleEl) titleEl.textContent = t('win.rankedPrPopupTitle');
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

    let finished = false;
    const finish = () => {
      if (finished) return;
      finished = true;
      overlay.classList.remove('active');
      overlay.setAttribute('aria-hidden', 'true');
      wrap.replaceChildren();
      el('ranked-pr-reward-flash')?.classList.remove('active', 'flash-premium');
    };

    try {
      if (typeof global.preloadPackPullFaces === 'function') {
        await global.preloadPackPullFaces([cardData]);
      }
    } catch (_) { /* continue */ }

    if (typeof global.buildPackOpenCardEl !== 'function') return;

    const cardEl = global.buildPackOpenCardEl(cardData, 0, 1);
    cardEl.classList.add('pack-top', 'pack-faces-ready');
    wrap.appendChild(cardEl);

    overlay.classList.add('active');
    overlay.setAttribute('aria-hidden', 'false');

    const tier = parseInt(cardEl.dataset.revealTier || '0', 10);
    const motion = typeof global.packMotionOk === 'function' ? global.packMotionOk() : true;
    const revealMs = motion && global.PACK_REVEAL_MS
      ? (global.PACK_REVEAL_MS[tier] || 0)
      : 0;

    await sleep(motion ? 280 : 0);

    if (motion) {
      cardEl.classList.add('revealing');
      if (tier >= 1) flashRarePull(tier);
      if (typeof global.sfxPlay === 'function') global.sfxPlay('pack_reveal');
      await sleep(revealMs || 1);
      cardEl.classList.remove('revealing');
    }

    if (detailsEl && nameEl && rarityEl) {
      nameEl.textContent = rankedPrCardName(reward);
      rarityEl.textContent = rankedPrRarityLabel(reward);
      rarityEl.className = 'ranked-pr-reward-rarity pack-results-rarity ' + rankedPrRarityClass(reward);
      detailsEl.hidden = false;
    }
    const sub = rankedPrSubText(reward);
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
})(typeof window !== 'undefined' ? window : globalThis);
