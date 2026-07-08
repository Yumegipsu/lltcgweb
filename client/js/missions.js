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

  async function claimMission(missionId) {
    if (!missionId || typeof global.accountPost !== 'function') return;
    const errEl = el('missions-err');
    if (errEl) errEl.textContent = '';
    try {
      const res = await global.accountPost('missions_claim', { mission_id: missionId });
      if (res.star_gems != null && typeof global.syncStarGemsFromProfile === 'function') {
        if (global.A && global.A.profile) global.A.profile.star_gems = res.star_gems;
        global.syncStarGemsFromProfile(global.A.profile);
        if (typeof global.updateStarGemsUI === 'function') global.updateStarGemsUI();
      }
      syncHubBadge(res.claimable_count ?? 0);
      await loadMissions();
      if (typeof global.toastSuccess === 'function') {
        const title = t(res.mission?.i18n_key || 'missions.claimed');
        global.toastSuccess(t('missions.claimedToast', {
          title,
          reward: res.star_gems_gained || res.mission?.reward || 0,
        }), 2800);
      }
    } catch (e) {
      if (errEl) errEl.textContent = e.message || 'Claim failed';
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
      sub.innerHTML = '+' + Number(m.reward || 0).toLocaleString()
        + ' <span class="star-gem-inline">' + starGemIconHtml(16) + '</span>'
        + ' · ' + missionStatusLabel(m.status);
      body.append(title, sub);

      const actions = document.createElement('span');
      actions.className = 'missions-item-action';
      if (m.status === 'completed') {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-grad missions-claim-btn';
        btn.textContent = t('missions.claim');
        btn.addEventListener('click', () => claimMission(m.id));
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
