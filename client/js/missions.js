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
      row.className = 'missions-row';
      if (m.status === 'claimed' || m.status === 'locked') row.classList.add('missions-row--dim');

      const body = document.createElement('div');
      body.className = 'missions-row-body';
      const title = document.createElement('div');
      title.className = 'missions-row-title';
      title.textContent = t(m.i18n_key || m.id);
      const reward = document.createElement('div');
      reward.className = 'missions-row-reward';
      reward.innerHTML = '+' + Number(m.reward || 0).toLocaleString() + ' <span class="star-gem-inline">' + starGemIconHtml(16) + '</span>';
      body.appendChild(title);
      body.appendChild(reward);

      const actions = document.createElement('div');
      actions.className = 'missions-row-actions';
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
      } else if (m.status === 'locked') {
        const chip = document.createElement('span');
        chip.className = 'missions-status-chip';
        chip.textContent = t('missions.statusLocked');
        actions.appendChild(chip);
      } else {
        const chip = document.createElement('span');
        chip.className = 'missions-status-chip missions-status-chip--active';
        chip.textContent = t('missions.statusActive');
        actions.appendChild(chip);
      }

      row.appendChild(body);
      row.appendChild(actions);
      list.appendChild(row);
    });
  }

  function openMissionsModal() {
    if (typeof global.openM === 'function') global.openM('modal-missions');
    else el('modal-missions')?.classList.add('open');
    setTab('daily');
    void loadMissions();
  }

  function closeMissionsModal() {
    if (typeof global.closeM === 'function') global.closeM('modal-missions');
    else el('modal-missions')?.classList.remove('open');
  }

  function bindUi() {
    if (document.body.dataset.missionsBound) return;
    document.body.dataset.missionsBound = '1';
    el('hub-missions')?.addEventListener('click', () => openMissionsModal());
    el('btn-missions-close')?.addEventListener('click', () => closeMissionsModal());
    el('btn-missions-tab-daily')?.addEventListener('click', () => setTab('daily'));
    el('btn-missions-tab-milestone')?.addEventListener('click', () => setTab('milestone'));
  }

  global.TCGMissions = {
    syncHubBadge,
    applyProfileMissions,
    onMissionCompletions,
    loadMissions,
    openMissionsModal,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindUi);
  } else {
    bindUi();
  }
})(window);
