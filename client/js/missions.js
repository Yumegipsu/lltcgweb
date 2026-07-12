/**
 * Star Gem missions — daily tasks and milestones (signed-in accounts).
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

  function starGemIconHtml(size) {
    if (typeof global.starGemIconHtml === 'function') {
      return global.starGemIconHtml(size || 18);
    }
    return '';
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
    if (badge) {
      badge.hidden = state.claimableCount <= 0;
      badge.textContent = state.claimableCount > 9 ? '9+' : String(state.claimableCount);
    }
  }

  function onMissionCompletions(completions) {
    if (!Array.isArray(completions) || !completions.length) return;
    completions.forEach((item) => {
      const title = t(item.i18n_key || item.id || '');
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
    return '+' + Number(m.reward || 0).toLocaleString()
      + ' <span class="star-gem-inline">' + starGemIconHtml(16) + '</span>'
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
    syncHubBadge(res.claimable_count ?? 0);
    await loadMissions();
    if (typeof global.toastSuccess === 'function') {
      const title = t(res.mission?.i18n_key || 'missions.claimed');
      if (res.starter_granted && res.starter_granted.label) {
        global.toastSuccess(t('missions.claimedStarterToast', {
          title,
          deck: res.starter_granted.label,
        }), 3200);
      } else {
        global.toastSuccess(t('missions.claimedToast', {
          title,
          reward: res.star_gems_gained || res.mission?.reward || 0,
        }), 2800);
      }
    }
  }

  function setTab(tab) {
    state.tab = tab === 'milestone' ? 'milestone' : 'daily';
    el('btn-missions-tab-daily')?.classList.toggle('active', state.tab === 'daily');
    el('btn-missions-tab-milestone')?.classList.toggle('active', state.tab === 'milestone');
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
      title.textContent = t(m.i18n_key || m.id);
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
    el('btn-missions-tab-daily')?.addEventListener('click', () => setTab('daily'));
    el('btn-missions-tab-milestone')?.addEventListener('click', () => setTab('milestone'));
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
