/**
 * Owner Admin panel (Events CRUD) + Events hub tab / player scaffold.
 * Admin gate: A.user.is_social_mod (same as social mod inbox).
 */
(function (global) {
  'use strict';

  /** Flip with PHP TCG_EVENTS_HUB_ENABLED when player Events UI should open. */
  const TCG_EVENTS_HUB_ENABLED = false;

  const REWARD_TYPES = ['coins', 'star_gems', 'card', 'sleeve', 'playmat'];

  const state = {
    events: [],
    editingId: null,
  };

  function el(id) {
    return document.getElementById(id);
  }

  function tt(key, fallback, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.tt;
    return typeof fn === 'function' ? fn(key, fallback, vars || {}) : (fallback || key);
  }

  function accountPost(action, body) {
    return global.accountPost(action, body || {});
  }

  function isAdmin() {
    return !!(global.A && global.A.user && global.A.user.is_social_mod);
  }

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function setErr(msg) {
    const box = el('admin-events-err');
    if (box) box.textContent = msg || '';
  }

  function syncHubButtons() {
    const adminBtn = el('btn-hub-admin');
    if (adminBtn) {
      adminBtn.hidden = !isAdmin();
    }
    const eventsBtn = el('btn-hub-events');
    if (!eventsBtn) return;
    eventsBtn.hidden = false;
    if (TCG_EVENTS_HUB_ENABLED) {
      eventsBtn.disabled = false;
      eventsBtn.classList.remove('llc-menu-item--disabled');
      eventsBtn.removeAttribute('aria-disabled');
      const sub = eventsBtn.querySelector('.llc-menu-item-sub');
      if (sub) {
        sub.setAttribute('data-i18n', 'hub.events.sub');
        sub.textContent = tt('hub.events.sub', 'Limited-time Event Points & rewards');
      }
    } else {
      eventsBtn.disabled = true;
      eventsBtn.classList.add('llc-menu-item--disabled');
      eventsBtn.setAttribute('aria-disabled', 'true');
      const sub = eventsBtn.querySelector('.llc-menu-item-sub');
      if (sub) {
        sub.setAttribute('data-i18n', 'hub.events.comingSoon');
        sub.textContent = tt('hub.events.comingSoon', 'Coming soon');
      }
    }
  }

  function openAdmin() {
    if (!isAdmin()) return;
    setErr('');
    if (typeof global.showScr === 'function') global.showScr('admin');
    void loadAdminList();
  }

  function closeAdminToHub() {
    if (typeof global.showScr === 'function') global.showScr('hub');
  }

  function openEvents() {
    if (!TCG_EVENTS_HUB_ENABLED) return;
    if (typeof global.showScr === 'function') global.showScr('events');
    void loadPlayerEvents();
  }

  function closeEventsToHub() {
    if (typeof global.showScr === 'function') global.showScr('hub');
  }

  async function loadAdminList() {
    const list = el('admin-events-list');
    if (!list) return;
    list.innerHTML = '<p class="admin-events-muted">Loading…</p>';
    try {
      const res = await accountPost('events_admin_list', {});
      state.events = Array.isArray(res.events) ? res.events : [];
      renderAdminList();
    } catch (e) {
      list.innerHTML = '';
      setErr(e && e.message ? e.message : 'Failed to load events');
    }
  }

  function renderAdminList() {
    const list = el('admin-events-list');
    if (!list) return;
    if (!state.events.length) {
      list.innerHTML = '<p class="admin-events-muted">No events yet.</p>';
      return;
    }
    list.innerHTML = state.events.map((ev) => {
      const banner = ev.banner_url
        ? `<img class="admin-events-thumb" src="${esc(ev.banner_url)}" alt="" loading="lazy">`
        : '';
      return `<article class="admin-events-card" data-id="${esc(ev.id)}">
        ${banner}
        <div class="admin-events-card-body">
          <h3>${esc(ev.name)}</h3>
          <p class="admin-events-meta">${esc(ev.status)} · ${esc(ev.starts_at_jst)} → ${esc(ev.ends_at_jst)} JST</p>
          <div class="admin-events-card-actions">
            <button type="button" class="btn-out" data-edit="${esc(ev.id)}">Edit</button>
            <button type="button" class="btn-ghost" data-del="${esc(ev.id)}">Delete</button>
          </div>
        </div>
      </article>`;
    }).join('');
    list.querySelectorAll('[data-edit]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-edit');
        const ev = state.events.find((x) => x.id === id);
        if (ev) fillEditor(ev);
      });
    });
    list.querySelectorAll('[data-del]').forEach((btn) => {
      btn.addEventListener('click', () => void deleteEvent(btn.getAttribute('data-del')));
    });
  }

  function emptyRewardRow(kind) {
    return {
      kind,
      threshold_points: 100,
      rank_from: 1,
      rank_to: 1,
      reward_type: 'coins',
      amount: 100,
      card_no: '',
      qty: 1,
      sleeve_id: '',
      playmat_id: '',
    };
  }

  function rewardFromServer(row, kind) {
    const p = row.reward_payload || {};
    return {
      kind,
      id: row.id,
      threshold_points: row.threshold_points || 100,
      rank_from: row.rank_from || 1,
      rank_to: row.rank_to || 1,
      reward_type: row.reward_type || 'coins',
      amount: p.amount || 100,
      card_no: p.card_no || '',
      qty: p.qty || 1,
      sleeve_id: p.sleeve_id || '',
      playmat_id: p.playmat_id || '',
    };
  }

  function fillEditor(ev) {
    state.editingId = ev ? ev.id : null;
    el('admin-event-id').value = ev ? ev.id : '';
    el('admin-event-name').value = ev ? ev.name : '';
    el('admin-event-banner').value = ev ? (ev.banner_url || '') : '';
    el('admin-event-starts').value = ev ? (ev.starts_at_jst || '') : '';
    el('admin-event-ends').value = ev ? (ev.ends_at_jst || '') : '';
    const milestones = (ev && Array.isArray(ev.milestones) ? ev.milestones : [])
      .map((m) => rewardFromServer(m, 'milestone'));
    const ranks = (ev && Array.isArray(ev.rank_rewards) ? ev.rank_rewards : [])
      .map((r) => rewardFromServer(r, 'rank'));
    renderRewardEditor('admin-milestones', milestones.length ? milestones : [emptyRewardRow('milestone')], 'milestone');
    renderRewardEditor('admin-ranks', ranks.length ? ranks : [emptyRewardRow('rank')], 'rank');
    const form = el('admin-event-form-wrap');
    if (form) form.hidden = false;
    el('admin-event-form-title').textContent = ev ? `Edit ${ev.name}` : 'New event';
  }

  function renderRewardEditor(containerId, rows, kind) {
    const box = el(containerId);
    if (!box) return;
    box.innerHTML = rows.map((row, i) => rewardRowHtml(row, i, kind)).join('');
    box.querySelectorAll('[data-rm-reward]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const idx = Number(btn.getAttribute('data-rm-reward'));
        const next = collectRewardRows(containerId, kind).filter((_, j) => j !== idx);
        renderRewardEditor(containerId, next.length ? next : [emptyRewardRow(kind)], kind);
      });
    });
    box.querySelectorAll('select[data-reward-type]').forEach((sel) => {
      sel.addEventListener('change', () => {
        const next = collectRewardRows(containerId, kind);
        renderRewardEditor(containerId, next, kind);
      });
    });
  }

  function rewardRowHtml(row, i, kind) {
    const typeOpts = REWARD_TYPES.map((t) =>
      `<option value="${t}"${row.reward_type === t ? ' selected' : ''}>${t}</option>`
    ).join('');
    let fields = '';
    if (kind === 'milestone') {
      fields += `<label>Threshold EP <input type="number" min="1" data-f="threshold_points" value="${esc(row.threshold_points)}"></label>`;
    } else {
      fields += `<label>Rank from <input type="number" min="1" data-f="rank_from" value="${esc(row.rank_from)}"></label>`;
      fields += `<label>Rank to <input type="number" min="1" data-f="rank_to" value="${esc(row.rank_to)}"></label>`;
    }
    fields += `<label>Type <select data-reward-type data-f="reward_type">${typeOpts}</select></label>`;
    const rt = row.reward_type;
    if (rt === 'coins' || rt === 'star_gems') {
      fields += `<label>Amount <input type="number" min="1" data-f="amount" value="${esc(row.amount)}"></label>`;
    } else if (rt === 'card') {
      fields += `<label>Card no <input data-f="card_no" value="${esc(row.card_no)}" placeholder="LL01-001"></label>`;
      fields += `<label>Qty <input type="number" min="1" data-f="qty" value="${esc(row.qty)}"></label>`;
    } else if (rt === 'sleeve') {
      fields += `<label>Sleeve id <input data-f="sleeve_id" value="${esc(row.sleeve_id)}"></label>`;
    } else if (rt === 'playmat') {
      fields += `<label>Playmat id <input data-f="playmat_id" value="${esc(row.playmat_id)}"></label>`;
    }
    return `<div class="admin-reward-row" data-idx="${i}">
      ${fields}
      <button type="button" class="btn-ghost" data-rm-reward="${i}">Remove</button>
    </div>`;
  }

  function collectRewardRows(containerId, kind) {
    const box = el(containerId);
    if (!box) return [];
    return Array.from(box.querySelectorAll('.admin-reward-row')).map((row) => {
      const get = (f) => {
        const inp = row.querySelector(`[data-f="${f}"]`);
        return inp ? inp.value : '';
      };
      return {
        kind,
        threshold_points: Number(get('threshold_points') || 100),
        rank_from: Number(get('rank_from') || 1),
        rank_to: Number(get('rank_to') || 1),
        reward_type: get('reward_type') || 'coins',
        amount: Number(get('amount') || 100),
        card_no: get('card_no'),
        qty: Number(get('qty') || 1),
        sleeve_id: get('sleeve_id'),
        playmat_id: get('playmat_id'),
      };
    });
  }

  function serializeRewards(rows) {
    return rows.map((row) => {
      const base = { reward_type: row.reward_type };
      if (row.kind === 'milestone') {
        base.threshold_points = Math.max(1, Number(row.threshold_points) || 1);
      } else {
        base.rank_from = Math.max(1, Number(row.rank_from) || 1);
        base.rank_to = Math.max(base.rank_from, Number(row.rank_to) || base.rank_from);
      }
      if (row.reward_type === 'coins' || row.reward_type === 'star_gems') {
        base.reward_payload = { amount: Math.max(1, Number(row.amount) || 1) };
      } else if (row.reward_type === 'card') {
        base.reward_payload = { card_no: String(row.card_no || '').trim(), qty: Math.max(1, Number(row.qty) || 1) };
      } else if (row.reward_type === 'sleeve') {
        base.reward_payload = { sleeve_id: String(row.sleeve_id || '').trim() };
      } else {
        base.reward_payload = { playmat_id: String(row.playmat_id || '').trim() };
      }
      return base;
    });
  }

  async function saveEvent(ev) {
    ev.preventDefault();
    setErr('');
    const id = (el('admin-event-id').value || '').trim();
    const body = {
      id: id || undefined,
      name: (el('admin-event-name').value || '').trim(),
      banner_url: (el('admin-event-banner').value || '').trim(),
      starts_at_jst: (el('admin-event-starts').value || '').trim(),
      ends_at_jst: (el('admin-event-ends').value || '').trim(),
      milestones: serializeRewards(collectRewardRows('admin-milestones', 'milestone')),
      rank_rewards: serializeRewards(collectRewardRows('admin-ranks', 'rank')),
    };
    try {
      await accountPost('events_admin_upsert', body);
      el('admin-event-form-wrap').hidden = true;
      await loadAdminList();
    } catch (e) {
      setErr(e && e.message ? e.message : 'Save failed');
    }
  }

  async function deleteEvent(id) {
    if (!id || !window.confirm('Delete this event?')) return;
    setErr('');
    try {
      await accountPost('events_admin_delete', { id });
      await loadAdminList();
    } catch (e) {
      setErr(e && e.message ? e.message : 'Delete failed');
    }
  }

  async function forceTick() {
    setErr('');
    try {
      await accountPost('events_admin_force_tick', {});
      await loadAdminList();
    } catch (e) {
      setErr(e && e.message ? e.message : 'Tick failed');
    }
  }

  async function loadPlayerEvents() {
    const root = el('events-player-root');
    if (!root) return;
    root.innerHTML = '<p class="admin-events-muted">Loading…</p>';
    try {
      const summary = await accountPost('events_active_summary', {});
      const events = Array.isArray(summary.events) ? summary.events : [];
      if (!events.length) {
        root.innerHTML = `<p class="admin-events-muted">${esc(tt('events.none', 'No active events right now.'))}</p>`;
        return;
      }
      const parts = [];
      for (const ev of events) {
        let lbRows = [];
        try {
          const lb = await accountPost('events_leaderboard', { event_id: ev.id, limit: 20 });
          lbRows = Array.isArray(lb.rows) ? lb.rows : [];
        } catch (e) { /* ignore */ }
        const banner = ev.banner_url
          ? `<img class="events-banner" src="${esc(ev.banner_url)}" alt="">`
          : '';
        const ms = (ev.milestones || []).map((m) => {
          const hit = Number(ev.my_points || 0) >= Number(m.threshold_points || 0);
          return `<li class="${hit ? 'is-hit' : ''}">${esc(m.threshold_points)} EP → ${esc(m.reward_type)}</li>`;
        }).join('');
        const lbHtml = lbRows.map((r) =>
          `<li>#${esc(r.rank)} ${esc(r.username)} — ${esc(r.points)} EP</li>`
        ).join('') || '<li>—</li>';
        parts.push(`<section class="events-player-card">
          ${banner}
          <h3>${esc(ev.name)}</h3>
          <p class="admin-events-meta">${esc(ev.starts_at_jst)} → ${esc(ev.ends_at_jst)} JST</p>
          <p class="events-my-points"><strong>${esc(ev.my_points || 0)}</strong> EP</p>
          <h4>${esc(tt('events.milestones', 'Milestones'))}</h4>
          <ul class="events-ms-list">${ms || '<li>—</li>'}</ul>
          <h4>${esc(tt('events.leaderboard', 'Leaderboard'))}</h4>
          <ol class="events-lb-list">${lbHtml}</ol>
        </section>`);
      }
      root.innerHTML = parts.join('');
    } catch (e) {
      root.innerHTML = `<p class="lerr">${esc(e && e.message ? e.message : 'Failed to load')}</p>`;
    }
  }

  function bind() {
    el('btn-hub-admin')?.addEventListener('click', () => openAdmin());
    el('btn-admin-back')?.addEventListener('click', () => closeAdminToHub());
    el('btn-admin-new-event')?.addEventListener('click', () => fillEditor(null));
    el('btn-admin-refresh')?.addEventListener('click', () => void loadAdminList());
    el('btn-admin-force-tick')?.addEventListener('click', () => void forceTick());
    el('btn-admin-cancel-edit')?.addEventListener('click', () => {
      const wrap = el('admin-event-form-wrap');
      if (wrap) wrap.hidden = true;
    });
    el('btn-admin-add-milestone')?.addEventListener('click', () => {
      const rows = collectRewardRows('admin-milestones', 'milestone');
      rows.push(emptyRewardRow('milestone'));
      renderRewardEditor('admin-milestones', rows, 'milestone');
    });
    el('btn-admin-add-rank')?.addEventListener('click', () => {
      const rows = collectRewardRows('admin-ranks', 'rank');
      rows.push(emptyRewardRow('rank'));
      renderRewardEditor('admin-ranks', rows, 'rank');
    });
    el('admin-event-form')?.addEventListener('submit', (e) => void saveEvent(e));

    el('btn-hub-events')?.addEventListener('click', () => openEvents());
    el('btn-events-back')?.addEventListener('click', () => closeEventsToHub());
    el('btn-events-refresh')?.addEventListener('click', () => void loadPlayerEvents());

    syncHubButtons();
    // Re-sync when account me lands
    const prev = global.onTcgAccountReady;
    global.onTcgAccountReady = function () {
      try { if (typeof prev === 'function') prev.apply(this, arguments); } catch (e) { /* ignore */ }
      syncHubButtons();
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }

  global.TCGAdminEvents = {
    syncHubButtons,
    openAdmin,
    hubEnabled: () => TCG_EVENTS_HUB_ENABLED,
  };
})(typeof window !== 'undefined' ? window : globalThis);
