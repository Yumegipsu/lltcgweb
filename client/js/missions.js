/**
 * Missions — daily / weekly / monthly tasks and milestones (signed-in accounts).
 */
(function (global) {
  'use strict';

  const state = {
    missions: [],
    claimableCount: 0,
    tab: 'daily',
    loading: false,
    starterPickMissionId: null,
    starterPickId: null,
  };

  function el(id) {
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

  /** Localize dynamic play-milestone name slots for the active UI locale. */
  function localizeMissionVars(vars) {
    const src = vars && typeof vars === 'object' ? vars : {};
    const out = Object.assign({}, src);
    const I18N = global.LLTCG_I18N;
    if (!I18N) return out;
    if (out.idol != null && typeof I18N.idolLocaleName === 'function') {
      out.idol = I18N.idolLocaleName(String(out.idol), { form: 'full' }) || out.idol;
    }
    if (out.group != null && typeof I18N.unitLocaleName === 'function') {
      out.group = I18N.unitLocaleName(String(out.group)) || out.group;
    }
    if (out.subunit != null && typeof I18N.subunitLocaleName === 'function') {
      out.subunit = I18N.subunitLocaleName(String(out.subunit)) || out.subunit;
    }
    return out;
  }

  function missionTitle(i18nKey, i18nVars, fallbackId) {
    const key = i18nKey || fallbackId || '';
    const vars = localizeMissionVars(i18nVars || {});
    const title = t(key, vars);
    if (title && title !== key) return title;
    // Fallback if a template key is missing: still show the localized name.
    if (vars.idol) return String(vars.idol) + (vars.n != null ? (' ×' + vars.n) : '');
    if (vars.group) return String(vars.group) + (vars.n != null ? (' ×' + vars.n) : '');
    if (vars.subunit) return String(vars.subunit) + (vars.n != null ? (' ×' + vars.n) : '');
    return key || fallbackId || '';
  }

  function starGemIconHtml(size) {
    if (typeof global.starGemIconHtml === 'function') {
      return global.starGemIconHtml(size || 18);
    }
    return '';
  }

  function coinIconHtml(size) {
    const px = Number(size) || 16;
    return '<img src="assets/ui/llsifas-gold-coin.webp" alt="" aria-hidden="true" width="'
      + px + '" height="' + px + '" style="width:' + px + 'px;height:' + px
      + 'px;object-fit:contain;display:block">';
  }

  const MISSION_TABS = ['daily', 'weekly', 'monthly', 'milestone'];
  const TICKET_ICONS = {
    tickets: 'assets/Scout_Ticket.webp',
    ticket_sr: 'assets/Scout_Ticket_SR.webp',
    ticket_ur: 'assets/Scout_Ticket_UR.webp',
  };

  function fmtN(n) {
    return Number(n || 0).toLocaleString();
  }

  /** Plain-text name of one reward item (tooltips, toasts) in the active locale. */
  function rewardItemLabel(item) {
    const n = fmtN(item.amount);
    switch (item.type) {
      case 'gems': return t('missions.rewardGems', { n });
      case 'coins': return t('missions.rewardCoins', { n });
      case 'tickets': return t('missions.rewardTickets', { n });
      case 'ticket_sr': return t('missions.rewardTicketSr', { n });
      case 'ticket_ur': return t('missions.rewardTicketUr', { n });
      case 'seal': return t('missions.rewardSeal', { tier: String(item.tier || 'N').toUpperCase(), n });
      default: return '';
    }
  }

  function rewardItemHtml(item) {
    const label = rewardItemLabel(item).replace(/"/g, '&quot;');
    const n = fmtN(item.amount);
    let inner;
    if (item.type === 'gems') {
      inner = '+' + n + ' <span class="star-gem-inline">' + starGemIconHtml(16) + '</span>';
    } else if (item.type === 'coins') {
      inner = '+' + n + ' <span class="star-gem-inline">' + coinIconHtml(16) + '</span>';
    } else if (item.type === 'seal' && typeof global.sealIconHtml === 'function') {
      inner = '×' + n + ' ' + global.sealIconHtml(item.tier, 16);
    } else if (TICKET_ICONS[item.type]) {
      inner = '×' + n + ' <img src="' + TICKET_ICONS[item.type]
        + '" alt="" aria-hidden="true" width="16" height="16" style="width:16px;height:16px;object-fit:contain">';
    } else {
      inner = label;
    }
    return '<span class="missions-reward-item" title="' + label + '" aria-label="' + label + '">' + inner + '</span>';
  }

  function rewardsHtml(rewards) {
    return rewards.map(rewardItemHtml).join('<span class="missions-reward-sep">·</span>');
  }

  function overlayEl() {
    return el('overlay-missions');
  }

  function starterOverlayEl() {
    return el('overlay-mission-starter');
  }

  function syncHubBadge(count) {
    state.claimableCount = Number(count) || 0;
    const pill = el('hub-missions');
    const badge = el('hub-missions-badge');
    if (!pill) return;
    pill.hidden = !(typeof global.isSignedInAccount === 'function' && global.isSignedInAccount());
    const hasClaim = state.claimableCount > 0;
    pill.classList.toggle('has-claim', hasClaim);
    pill.setAttribute('aria-label', hasClaim
      ? t('hub.missionsClaimable', { n: state.claimableCount })
      : t('hub.missions'));
    if (badge) {
      badge.hidden = !hasClaim;
      badge.textContent = state.claimableCount > 9 ? '9+' : String(state.claimableCount);
    }
  }

  function onMissionCompletions(completions) {
    if (!Array.isArray(completions) || !completions.length) return;
    completions.forEach((item) => {
      const title = missionTitle(item.i18n_key, item.i18n_vars, item.id);
      if (typeof global.toastSuccess === 'function') {
        global.toastSuccess(t('missions.completeToast', { title }), 3200);
      } else if (typeof global.toast === 'function') {
        global.toast(t('missions.completeToast', { title }), 3200);
      }
    });
    if (state.claimableCount >= 0) {
      state.claimableCount += completions.length;
      syncHubBadge(state.claimableCount);
    }
  }

  function applyProfileMissions(profile) {
    const m = profile && profile.missions;
    if (m && typeof m.claimable_count === 'number') {
      syncHubBadge(m.claimable_count);
    }
  }

  function missionStatusLabel(status) {
    if (status === 'completed') return t('missions.statusReady');
    if (status === 'claimed') return t('missions.statusClaimed');
    return t('missions.statusActive');
  }

  function rewardSubHtml(m) {
    if (Array.isArray(m.rewards) && m.rewards.length) {
      let progressBit = '';
      if (m.threshold != null && m.status !== 'claimed') {
        const cur = Number(m.progress != null ? m.progress : 0);
        progressBit = ' · ' + cur.toLocaleString() + ' / ' + Number(m.threshold).toLocaleString();
      }
      return rewardsHtml(m.rewards) + progressBit + ' · ' + missionStatusLabel(m.status);
    }
    if (m.reward_type === 'starter_choice') {
      const available = Number(m.available_starter_count || 0);
      let rewardBit;
      if (available > 0) {
        rewardBit = t('missions.rewardStarter');
      } else {
        const fallback = Number(m.reward_fallback || m.reward || 0);
        rewardBit = '+' + fallback.toLocaleString()
          + ' <span class="star-gem-inline">' + starGemIconHtml(16) + '</span>';
      }
      let progressBit = '';
      if (m.threshold != null && m.status !== 'claimed') {
        const cur = Number(m.progress != null ? m.progress : m.total_cards || 0);
        progressBit = ' · ' + cur.toLocaleString() + ' / ' + Number(m.threshold).toLocaleString();
      }
      return rewardBit + progressBit + ' · ' + missionStatusLabel(m.status);
    }
    if (m.reward_type === 'free_sleeve') {
      let progressBit = '';
      if (m.threshold != null && m.status !== 'claimed') {
        const cur = Number(m.progress != null ? m.progress : 0);
        progressBit = ' · ' + cur.toLocaleString() + ' / ' + Number(m.threshold).toLocaleString();
      }
      return (t('missions.rewardFreeSleeve') || 'Free sleeve claim')
        + progressBit + ' · ' + missionStatusLabel(m.status);
    }
    if (m.reward_type === 'coins_and_pr_pack') {
      const prLabel = tt('loginBonus.reward.prPack', 'PR Pack');
      return '+' + Number(m.reward || 0).toLocaleString()
        + ' <span class="star-gem-inline">' + coinIconHtml(16) + '</span>'
        + ' · ' + prLabel
        + ' · ' + missionStatusLabel(m.status);
    }
    if (m.reward_type === 'coins') {
      let progressBit = '';
      if (m.threshold != null && m.status !== 'claimed') {
        const cur = Number(m.progress != null ? m.progress : 0);
        progressBit = ' · ' + cur.toLocaleString() + ' / ' + Number(m.threshold).toLocaleString();
      }
      return '+' + Number(m.reward || 0).toLocaleString()
        + ' <span class="star-gem-inline">' + coinIconHtml(16) + '</span>'
        + progressBit
        + ' · ' + missionStatusLabel(m.status);
    }
    if (m.reward_type === 'star_gems_and_coins') {
      let progressBit = '';
      if (m.threshold != null && m.status !== 'claimed') {
        const cur = Number(m.progress != null ? m.progress : 0);
        progressBit = ' · ' + cur.toLocaleString() + ' / ' + Number(m.threshold).toLocaleString();
      }
      const coins = Number(m.coins_reward != null ? m.coins_reward : m.reward || 0);
      return '+' + Number(m.reward || 0).toLocaleString()
        + ' <span class="star-gem-inline">' + starGemIconHtml(16) + '</span>'
        + ' · +' + coins.toLocaleString()
        + ' <span class="star-gem-inline">' + coinIconHtml(16) + '</span>'
        + progressBit
        + ' · ' + missionStatusLabel(m.status);
    }
    let progressBit = '';
    if (m.threshold != null && m.status !== 'claimed') {
      const cur = Number(m.progress != null ? m.progress : 0);
      progressBit = ' · ' + cur.toLocaleString() + ' / ' + Number(m.threshold).toLocaleString();
    }
    return '+' + Number(m.reward || 0).toLocaleString()
      + ' <span class="star-gem-inline">' + starGemIconHtml(16) + '</span>'
      + progressBit
      + ' · ' + missionStatusLabel(m.status);
  }

  async function loadMissions() {
    if (typeof global.accountGet !== 'function') return;
    state.loading = true;
    renderList();
    try {
      const res = await global.accountGet('missions_list');
      if (res.success) {
        state.missions = res.missions || [];
        syncHubBadge(res.claimable_count ?? 0);
      }
    } catch (e) {
      const err = el('missions-err');
      if (err) err.textContent = e.message || 'Failed to load missions';
    } finally {
      state.loading = false;
      renderList();
    }
  }

  function closeStarterPicker() {
    const ov = starterOverlayEl();
    if (!ov) return;
    ov.classList.remove('open');
    ov.setAttribute('aria-hidden', 'true');
    state.starterPickMissionId = null;
    state.starterPickId = null;
    const confirmBtn = el('btn-mission-starter-confirm');
    if (confirmBtn) confirmBtn.disabled = true;
  }

  function openStarterPicker(mission) {
    const ov = starterOverlayEl();
    const grid = el('mission-starter-grid');
    if (!ov || !grid) {
      const errEl = el('missions-err');
      if (errEl) errEl.textContent = t('missions.starterPickTitle') + ' unavailable';
      return;
    }
    state.starterPickMissionId = mission.id;
    state.starterPickId = null;
    grid.replaceChildren();
    let opts = Array.isArray(mission.starter_options) ? mission.starter_options : [];
    if (!opts.length && Array.isArray(global.A?.profile?.starter_options)) {
      const owned = new Set(
        (Array.isArray(mission.owned_starters) ? mission.owned_starters : [])
          .map((k) => String(k))
      );
      opts = global.A.profile.starter_options.map((o) => ({
        id: o.id,
        label: o.label || o.id,
        image: o.image || '',
        owned: owned.has(String(o.id)),
      }));
    }
    if (!opts.length) {
      const errEl = el('missions-err');
      if (errEl) errEl.textContent = 'No starter decks available to choose';
      return;
    }
    opts.forEach((o) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'starter-opt' + (o.owned ? ' starter-opt--owned' : '');
      b.dataset.id = o.id;
      b.disabled = !!o.owned;
      b.setAttribute('aria-disabled', o.owned ? 'true' : 'false');
      const ownedTag = o.owned
        ? '<div class="starter-owned-tag">' + t('missions.rewardStarterOwned') + '</div>'
        : '';
      b.innerHTML = '<img src="' + (o.image || '') + '" alt="" onerror="this.style.display=\'none\'">'
        + '<div class="starter-name">' + (o.label || o.id) + '</div>'
        + ownedTag;
      if (!o.owned) {
        b.onclick = () => {
          grid.querySelectorAll('.starter-opt').forEach((x) => x.classList.remove('sel'));
          b.classList.add('sel');
          state.starterPickId = o.id;
          const confirmBtn = el('btn-mission-starter-confirm');
          if (confirmBtn) confirmBtn.disabled = false;
        };
      }
      grid.appendChild(b);
    });
    const confirmBtn = el('btn-mission-starter-confirm');
    if (confirmBtn) confirmBtn.disabled = true;
    const err = el('mission-starter-err');
    if (err) err.textContent = '';
    ov.classList.add('open');
    ov.setAttribute('aria-hidden', 'false');
    if (global.LLTCG_I18N && typeof global.LLTCG_I18N.applyI18n === 'function') {
      global.LLTCG_I18N.applyI18n(ov);
    }
  }

  async function confirmStarterPick() {
    if (!state.starterPickMissionId || !state.starterPickId) return;
    const err = el('mission-starter-err');
    if (err) err.textContent = '';
    const confirmBtn = el('btn-mission-starter-confirm');
    if (confirmBtn) confirmBtn.disabled = true;
    try {
      await claimMission(state.starterPickMissionId, state.starterPickId);
      closeStarterPicker();
    } catch (e) {
      if (err) err.textContent = e.message || 'Claim failed';
      if (confirmBtn) confirmBtn.disabled = false;
    }
  }

  async function claimMission(missionId, starterId) {
    if (!missionId || typeof global.accountPost !== 'function') return;
    const mission = (state.missions || []).find((m) => m.id === missionId);
    // Treat missing second arg as "open picker" for starter-choice rewards.
    const needsPicker = arguments.length < 2
      && mission
      && mission.reward_type === 'starter_choice'
      && Number(mission.available_starter_count || 0) > 0;
    if (needsPicker) {
      openStarterPicker(mission);
      return;
    }

    const errEl = el('missions-err');
    if (errEl) errEl.textContent = '';
    const body = { mission_id: missionId };
    if (starterId) body.starter = starterId;
    const res = await global.accountPost('missions_claim', body);
    if (res.star_gems != null && typeof global.syncStarGemsFromProfile === 'function') {
      if (global.A && global.A.profile) global.A.profile.star_gems = res.star_gems;
      global.syncStarGemsFromProfile(global.A.profile);
      if (typeof global.updateStarGemsUI === 'function') global.updateStarGemsUI();
    }
    if ((res.coins != null || res.free_sleeve_claims != null)
      && typeof global.syncCoinsFromProfile === 'function') {
      global.syncCoinsFromProfile({
        ...(global.A && global.A.profile ? global.A.profile : {}),
        free_sleeve_claims: res.free_sleeve_claims != null
          ? res.free_sleeve_claims
          : (global.A && global.A.profile && global.A.profile.free_sleeve_claims),
        coins: res.coins != null ? res.coins : (global.A && global.A.coins),
      });
    }
    if (res.pr_pack && typeof global.queueRankedPrReward === 'function') {
      global.queueRankedPrReward(res.pr_pack);
    }
    if (res.seals && typeof global.syncSealsFromPayload === 'function') {
      global.syncSealsFromPayload({ seals: res.seals });
    }
    syncHubBadge(res.claimable_count ?? 0);
    await loadMissions();
    if (typeof global.toastSuccess === 'function') {
      const title = missionTitle(res.mission?.i18n_key, res.mission?.i18n_vars, res.mission?.id);
      if (res.starter_granted && res.starter_granted.label) {
        global.toastSuccess(t('missions.claimedStarterToast', {
          title,
          deck: res.starter_granted.label,
        }), 3200);
      } else if (Array.isArray(res.rewards) && res.rewards.length) {
        global.toastSuccess(t('missions.claimedRewardsToast', {
          title,
          rewards: res.rewards.map(rewardItemLabel).filter(Boolean).join(' · '),
        }), 3600);
      } else if ((res.mission?.reward_type || res.reward_type) === 'free_sleeve') {
        global.toastSuccess(t('missions.claimedFreeSleeveToast', { title })
          || ('Claimed ' + title + ' — free sleeve unlocked'), 3200);
      } else if ((res.mission?.reward_type || res.reward_type) === 'coins_and_pr_pack') {
        global.toastSuccess(t('missions.claimedCoinsAndPrToast', {
          title,
          coins: res.coins_gained || res.mission?.reward || 0,
        }) || ('Claimed ' + title + ' (+' + (res.coins_gained || 0) + ' Coins · PR Pack)'), 3600);
      } else if ((res.mission?.reward_type || res.reward_type) === 'coins') {
        global.toastSuccess(t('missions.claimedCoinsToast', {
          title,
          coins: res.coins_gained || res.mission?.reward || 0,
          n: res.coins_gained || res.mission?.reward || 0,
        }) || ('Claimed ' + title + ' (+' + (res.coins_gained || 0) + ' Coins)'), 3200);
      } else if ((res.mission?.reward_type || res.reward_type) === 'star_gems_and_coins') {
        global.toastSuccess(t('missions.claimedGemsAndCoinsToast', {
          title,
          gems: res.star_gems_gained || res.mission?.reward || 0,
          coins: res.coins_gained || res.mission?.coins_reward || 0,
          reward: res.star_gems_gained || res.mission?.reward || 0,
        }) || ('Claimed ' + title + ' (+' + (res.star_gems_gained || 0) + ' · +' + (res.coins_gained || 0) + ' Coins)'), 3200);
      } else {
        global.toastSuccess(t('missions.claimedToast', {
          title,
          reward: res.star_gems_gained || res.mission?.reward || 0,
        }), 2800);
      }
    }
  }

  function syncResetNote() {
    const note = el('missions-reset-note');
    if (!note) return;
    const key = state.tab === 'weekly' ? 'missions.resetWeekly'
      : state.tab === 'monthly' ? 'missions.resetMonthly' : '';
    note.hidden = !key;
    note.textContent = key ? t(key) : '';
  }

  function setTab(tab) {
    state.tab = MISSION_TABS.includes(tab) ? tab : 'daily';
    MISSION_TABS.forEach((name) => {
      el('btn-missions-tab-' + name)?.classList.toggle('active', state.tab === name);
    });
    syncResetNote();
    el('missions-scroll')?.scrollTo(0, 0);
    renderList();
  }

  function renderList() {
    const list = el('missions-list');
    if (!list) return;
    list.replaceChildren();
    if (state.loading) {
      const p = document.createElement('p');
      p.className = 'missions-loading';
      p.textContent = t('missions.loading');
      list.appendChild(p);
      return;
    }
    const items = (state.missions || []).filter((m) => m.type === state.tab);
    if (!items.length) {
      const p = document.createElement('p');
      p.className = 'missions-empty';
      p.textContent = t('missions.empty');
      list.appendChild(p);
      return;
    }
    items.forEach((m) => {
      const row = document.createElement('div');
      row.className = 'llc-menu-item missions-item';
      if (m.status === 'claimed') row.classList.add('missions-item--claimed');

      const body = document.createElement('span');
      body.className = 'llc-menu-item-body';
      const title = document.createElement('span');
      title.className = 'llc-menu-item-title';
      title.textContent = missionTitle(m.i18n_key, m.i18n_vars, m.id);
      const sub = document.createElement('span');
      sub.className = 'llc-menu-item-sub';
      sub.innerHTML = rewardSubHtml(m);
      body.append(title, sub);

      const actions = document.createElement('span');
      actions.className = 'missions-item-action';
      if (m.status === 'completed') {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-grad missions-claim-btn';
        btn.textContent = t('missions.claim');
        btn.addEventListener('click', () => {
          void claimMission(m.id).catch((e) => {
            const errEl = el('missions-err');
            if (errEl) errEl.textContent = e.message || 'Claim failed';
          });
        });
        actions.appendChild(btn);
      } else if (m.status === 'claimed') {
        const chip = document.createElement('span');
        chip.className = 'missions-status-chip missions-status-chip--claimed';
        chip.textContent = t('missions.statusClaimed');
        actions.appendChild(chip);
      } else {
        const chip = document.createElement('span');
        chip.className = 'missions-status-chip missions-status-chip--active';
        chip.textContent = t('missions.statusActive');
        actions.appendChild(chip);
      }

      row.append(body, actions);
      list.appendChild(row);
    });
  }

  function openMissionsModal() {
    const ov = overlayEl();
    if (!ov) return;
    ov.classList.add('open');
    ov.setAttribute('aria-hidden', 'false');
    document.body.classList.add('missions-overlay-open');
    setTab('daily');
    void loadMissions();
    if (global.LLTCG_I18N && typeof global.LLTCG_I18N.applyI18n === 'function') {
      global.LLTCG_I18N.applyI18n(ov);
    }
  }

  function closeMissionsModal() {
    closeStarterPicker();
    const ov = overlayEl();
    if (!ov) return;
    ov.classList.remove('open');
    ov.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('missions-overlay-open');
  }

  function bindUi() {
    if (document.body.dataset.missionsBound) return;
    document.body.dataset.missionsBound = '1';
    el('hub-missions')?.addEventListener('click', () => openMissionsModal());
    el('btn-missions-close')?.addEventListener('click', () => closeMissionsModal());
    MISSION_TABS.forEach((name) => {
      el('btn-missions-tab-' + name)?.addEventListener('click', () => setTab(name));
    });
    overlayEl()?.addEventListener('click', (ev) => {
      if (ev.target === overlayEl()) closeMissionsModal();
    });
    el('btn-mission-starter-confirm')?.addEventListener('click', () => {
      void confirmStarterPick();
    });
    el('btn-mission-starter-cancel')?.addEventListener('click', () => closeStarterPicker());
    starterOverlayEl()?.addEventListener('click', (ev) => {
      if (ev.target === starterOverlayEl()) closeStarterPicker();
    });
    if (global.LLTCG_I18N && typeof global.LLTCG_I18N.onLocaleChange === 'function') {
      global.LLTCG_I18N.onLocaleChange(() => {
        if (overlayEl()?.classList.contains('open')) {
          if (global.LLTCG_I18N && typeof global.LLTCG_I18N.applyI18n === 'function') {
            global.LLTCG_I18N.applyI18n(overlayEl());
          }
          syncResetNote();
          renderList();
        }
      });
    }
  }

  global.TCGMissions = {
    syncHubBadge,
    applyProfileMissions,
    onMissionCompletions,
    loadMissions,
    openMissionsModal,
    closeMissionsModal,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindUi);
  } else {
    bindUi();
  }
})(window);
