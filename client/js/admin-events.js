/**
 * Owner Admin panel (Events CRUD) + Events hub tab / player scaffold.
 * Admin gate: A.user.is_social_mod (same as social mod inbox).
 */
(function (global) {
  'use strict';

  /** Flip with PHP TCG_EVENTS_HUB_ENABLED when player Events UI should open. */
  const TCG_EVENTS_HUB_ENABLED = false;

  const REWARD_TYPES = ['coins', 'star_gems', 'scouting_ticket', 'scouting_ticket_sr', 'scouting_ticket_ur', 'card', 'sleeve', 'playmat'];
  const KEY_REWARD_TYPES = ['event_tokens', 'event_keys', 'pr_pack'];
  const AMOUNT_TYPES = ['coins', 'star_gems', 'scouting_ticket', 'scouting_ticket_sr', 'scouting_ticket_ur', 'event_tokens', 'event_keys', 'pr_pack'];

  function editorType() {
    const sel = el('admin-event-type');
    return sel ? sel.value : 'classic';
  }

  const state = {
    events: [],
    editingId: null,
    // Owner-only: the server turns the Events tab on while a test event is running.
    hubOverride: false,
    testLabId: null,
    testLabTimer: null,
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
    return String(s == null ? '' : s)
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
    if (TCG_EVENTS_HUB_ENABLED || state.hubOverride) {
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

  async function refreshHubOverride() {
    if (!isAdmin()) return;
    try {
      const res = await accountPost('events_active_summary', {});
      const next = !!(res && res.hub_enabled) && !TCG_EVENTS_HUB_ENABLED;
      if (next !== state.hubOverride) {
        state.hubOverride = next;
        syncHubButtons();
      }
    } catch (e) { /* hub stays as it was */ }
  }

  function openEvents() {
    if (!TCG_EVENTS_HUB_ENABLED && !state.hubOverride) return;
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
          <h3>${esc(ev.name)}${ev.test_mode ? '<span class="admin-test-badge">TEST</span>' : ''}</h3>
          <p class="admin-events-meta">${esc(ev.status)} · ${esc(ev.starts_at_jst)} → ${esc(ev.ends_at_jst)} JST${ev.test_mode && ev.clock ? ' · test clock ' + esc(ev.clock.virtual_now_jst) + ' JST' + (ev.clock.speed > 1 ? ' (×' + esc(ev.clock.speed) + ')' : '') : ''}</p>
          <div class="admin-events-card-actions">
            ${ev.test_mode ? `<button type="button" class="btn-grad" data-lab="${esc(ev.id)}">Test lab</button>` : ''}
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
    list.querySelectorAll('[data-lab]').forEach((btn) => {
      btn.addEventListener('click', () => void openTestLab(btn.getAttribute('data-lab')));
    });
  }

  // ------------------------------------------------------------------ Test lab (owner sandbox)

  function fmtReward(type, p) {
    p = p || {};
    if (type === 'card') return `card ${p.card_no || '?'} ×${p.qty || 1}`;
    if (type === 'sleeve') return `sleeve ${p.sleeve_id || '?'}`;
    if (type === 'playmat') return `playmat ${p.playmat_id || '?'}`;
    return `${type} ×${p.amount || 0}`;
  }

  function keyLabSection(res, people) {
    if (!res.event || res.event.event_type !== 'key_event') return '';
    const wallets = res.wallets || {};
    const rows = people.map((p) => {
      const w = wallets[p.discord_id] || { keys: 0, tokens: 0, wheel2_left: 0, spins: 0 };
      return `<div class="admin-test-row" data-who="${esc(p.discord_id)}" data-keyrow>
        <span class="admin-test-name">${esc(p.username)}</span>
        <span>Keys ${esc(w.keys)} · Tokens ${esc(w.tokens)} · bonus spins ${esc(w.wheel2_left)} · spun ${esc(w.spins)}</span>
        <button type="button" class="btn-out" data-knorm="ranked:win">Ranked win</button>
        <button type="button" class="btn-out" data-knorm="ranked:loss">Ranked loss</button>
        <button type="button" class="btn-out" data-knorm="cpu:win">CPU win</button>
        <button type="button" class="btn-out" data-knorm="cpu:loss">CPU loss</button>
        <button type="button" class="btn-out" data-kev="win:pvp">Event win</button>
        <button type="button" class="btn-out" data-kev="loss:pvp">Event loss</button>
        <button type="button" class="btn-out" data-kev="win:cpu">Event CPU win</button>
        <input type="number" data-kgrant-keys value="10" aria-label="keys">
        <button type="button" class="btn-out" data-kgrant="keys">+Keys</button>
        <input type="number" data-kgrant-tokens value="100" aria-label="tokens">
        <button type="button" class="btn-out" data-kgrant="tokens">+Tokens</button>
        <button type="button" class="btn-out" data-kspin>Spin</button>
        <button type="button" class="btn-out" data-kspins>Spin x200 tally</button>
      </div>`;
    }).join('');
    const tally = res.spin_tally
      ? `<pre class="admin-test-log">${esc(res.spin_tally.spins)} spins, ${esc(res.spin_tally.tokens_paid)} tokens paid\n${esc(Object.entries(res.spin_tally.results).map(([k, v]) => `${v}  ${k}`).join('\n'))}</pre>` : '';
    const last = [res.simulated_keys != null ? `normal match gave ${res.simulated_keys} keys` : '',
      res.event_match ? `event match: ${JSON.stringify(res.event_match)}` : '',
      res.spin ? `spin: wheel ${res.spin.wheel} -> ${JSON.stringify(res.spin.reward)}` : ''].filter(Boolean).join(' · ');
    return `<section><h4>Key Event wallets</h4>${rows}${last ? `<p class="admin-events-meta">${esc(last)}</p>` : ''}${tally}</section>`;
  }

  async function labAct(action, extra) {
    const id = state.testLabId;
    if (!id) return null;
    setErr('');
    try {
      const res = await accountPost('events_test_lab', Object.assign({ event_id: id, action }, extra || {}));
      renderTestLab(res);
      void refreshHubOverride(); // the Events tab appears for you while a test event is active
      return res;
    } catch (e) {
      setErr(e && e.message ? e.message : 'Test lab action failed');
      return null;
    }
  }

  async function openTestLab(id) {
    state.testLabId = id;
    const box = el('admin-test-lab');
    if (box) {
      box.hidden = false;
      box.innerHTML = '<p class="admin-events-muted">Loading…</p>';
      box.scrollIntoView({ block: 'nearest' });
    }
    await labAct('state');
    clearInterval(state.testLabTimer);
    // A sped-up clock moves on its own: keep the panel in step with it.
    state.testLabTimer = setInterval(() => {
      const scr = el('screen-admin');
      if (!state.testLabId || !scr || scr.hidden) return;
      if (state.labSpeed > 1 || state.labLive) void labAct('state');
    }, 5000);
  }

  function closeTestLab() {
    state.testLabId = null;
    clearInterval(state.testLabTimer);
    const box = el('admin-test-lab');
    if (box) { box.hidden = true; box.innerHTML = ''; }
    void loadAdminList();
  }

  function renderTestLab(res) {
    const box = el('admin-test-lab');
    if (!box || !res || !res.event) return;
    const ev = res.event;
    const clock = ev.clock || { speed: 1, virtual_now_jst: '' };
    state.labSpeed = clock.speed;
    state.labLive = ev.status === 'active';
    const speeds = [1, 60, 600, 3600].map((n) =>
      `<button type="button" class="btn-out${clock.speed === n ? ' is-on' : ''}" data-clock-speed="${n}">×${n}</button>`
    ).join('');
    const jumps = [['+1h', 3600], ['+6h', 21600], ['+1d', 86400], ['+3d', 259200], ['−1h', -3600]].map(([label, sec]) =>
      `<button type="button" class="btn-out" data-clock-jump="${sec}">${label}</button>`
    ).join('');
    const targets = [['before_start', '5 min before start'], ['start', 'Start'], ['before_end', '5 min before end'], ['end', 'End (settle ranking)']]
      .map(([t, label]) => `<button type="button" class="btn-out" data-clock-to="${t}">${label}</button>`).join('');

    const byId = new Map((res.leaderboard || []).map((r) => [r.discord_id, r]));
    const people = [];
    people.push({ discord_id: res.you, username: 'You', points: (byId.get(res.you) || {}).points || 0, you: true });
    (res.puppets || []).forEach((p) => people.push({
      discord_id: p.discord_id, username: p.username, points: (byId.get(p.discord_id) || {}).points || 0,
    }));
    const rows = people.map((p) => {
      const rank = (byId.get(p.discord_id) || {}).rank;
      return `<div class="admin-test-row" data-who="${esc(p.discord_id)}">
        <span class="admin-test-name">${rank ? '#' + esc(rank) + ' ' : ''}${esc(p.username)}</span>
        <strong>${esc(p.points)} EP</strong>
        <button type="button" class="btn-out" data-sim="ranked:win">Ranked win</button>
        <button type="button" class="btn-out" data-sim="ranked:loss">Ranked loss</button>
        <button type="button" class="btn-out" data-sim="cpu:win">CPU win</button>
        <button type="button" class="btn-out" data-sim="cpu:loss">CPU loss</button>
        <input type="number" data-award-amount value="100" aria-label="EP">
        <button type="button" class="btn-out" data-award>Add EP</button>
      </div>`;
    }).join('');

    const log = (res.log || []).map((l) =>
      `<tr><td>${esc(l.virtual_at_jst)}</td><td>${esc(l.username || l.discord_id)}</td><td>${esc(l.source)} ${esc(l.source_ref)}</td><td>${esc(fmtReward(l.reward_type, l.reward_payload))}</td></tr>`
    ).join('');

    box.innerHTML = `
      <div class="admin-test-row">
        <h3>Test lab: ${esc(ev.name)}<span class="admin-test-badge">TEST</span></h3>
        <button type="button" class="btn-ghost" data-lab-close>Close</button>
      </div>
      <p class="admin-events-meta">Only you can see this event. Real matches never count. Rewards are only listed below, never granted.</p>
      <section>
        <h4>Clock</h4>
        <p class="admin-events-meta">Status <strong>${esc(ev.status)}</strong> · event time <strong>${esc(clock.virtual_now_jst)}</strong> JST
          · window ${esc(ev.starts_at_jst)} → ${esc(ev.ends_at_jst)} JST${clock.speed > 1 ? ` · running ×${esc(clock.speed)}` : ''}</p>
        <div class="admin-test-clock">${jumps}</div>
        <div class="admin-test-clock">${targets}</div>
        <div class="admin-test-clock">Speed: ${speeds}
          <button type="button" class="btn-ghost" data-clock-reset>Reset clock</button></div>
      </section>
      <section>
        <h4>Players</h4>
        <div class="admin-test-row">
          <input type="number" id="admin-puppet-count" min="1" max="50" value="5" aria-label="Puppets">
          <button type="button" class="btn-out" data-puppets>Create puppets</button>
          <span class="admin-events-muted">Simulated matches use the real EP amounts for ranked and CPU games.</span>
        </div>
        ${rows}
      </section>
      ${keyLabSection(res, people)}
      <section>
        <h4>Rewards that would have been granted (${(res.log || []).length})</h4>
        ${log ? `<table class="admin-test-log"><thead><tr><th>Event time (JST)</th><th>Player</th><th>From</th><th>Reward</th></tr></thead><tbody>${log}</tbody></table>`
          : '<p class="admin-events-muted">Nothing yet. Reach a milestone or let the event end.</p>'}
      </section>
      <section>
        <h4>Reset</h4>
        <div class="admin-test-row">
          <label><input type="checkbox" id="admin-reset-clock" checked> also reset the clock</label>
          <button type="button" class="btn-ghost" data-reset>Wipe points, claims and reward log</button>
        </div>
      </section>`;

    box.querySelector('[data-lab-close]')?.addEventListener('click', closeTestLab);
    box.querySelectorAll('[data-clock-jump]').forEach((b) => b.addEventListener('click',
      () => void labAct('clock', { mode: 'jump', seconds: Number(b.getAttribute('data-clock-jump')) })));
    box.querySelectorAll('[data-clock-to]').forEach((b) => b.addEventListener('click',
      () => void labAct('clock', { mode: 'jump_to', target: b.getAttribute('data-clock-to') })));
    box.querySelectorAll('[data-clock-speed]').forEach((b) => b.addEventListener('click',
      () => void labAct('clock', { mode: 'speed', speed: Number(b.getAttribute('data-clock-speed')) })));
    box.querySelector('[data-clock-reset]')?.addEventListener('click', () => void labAct('clock', { mode: 'reset' }));
    box.querySelector('[data-puppets]')?.addEventListener('click',
      () => void labAct('puppets_create', { count: Number((el('admin-puppet-count') || {}).value || 5) }));
    box.querySelectorAll('.admin-test-row[data-who]').forEach((row) => {
      const who = row.getAttribute('data-who');
      row.querySelectorAll('[data-sim]').forEach((b) => b.addEventListener('click', () => {
        const [kind, result] = b.getAttribute('data-sim').split(':');
        void labAct('simulate_match', { discord_id: who, kind, result });
      }));
      row.querySelector('[data-award]')?.addEventListener('click', () => {
        const amount = Number((row.querySelector('[data-award-amount]') || {}).value || 0);
        void labAct('award', { discord_id: who, points: amount });
      });
    });
    box.querySelectorAll('[data-keyrow]').forEach((row) => {
      const who = row.getAttribute('data-who');
      const num = (sel) => Number((row.querySelector(sel) || {}).value || 0);
      row.querySelectorAll('[data-knorm]').forEach((b) => b.addEventListener('click', () => {
        const [kind, result] = b.getAttribute('data-knorm').split(':');
        void labAct('simulate_normal_match', { discord_id: who, kind, result });
      }));
      row.querySelectorAll('[data-kev]').forEach((b) => b.addEventListener('click', () => {
        const [result, mode] = b.getAttribute('data-kev').split(':');
        void labAct('simulate_event_match', { discord_id: who, result, vs_cpu: mode === 'cpu' });
      }));
      row.querySelectorAll('[data-kgrant]').forEach((b) => b.addEventListener('click', () => {
        const field = b.getAttribute('data-kgrant');
        void labAct('grant_wallet', { discord_id: who, [field]: num(field === 'keys' ? '[data-kgrant-keys]' : '[data-kgrant-tokens]') });
      }));
      row.querySelector('[data-kspin]')?.addEventListener('click', () => void labAct('spin', { discord_id: who }));
      row.querySelector('[data-kspins]')?.addEventListener('click', () => void labAct('simulate_spins', { discord_id: who, count: 200 }));
    });
    box.querySelector('[data-reset]')?.addEventListener('click', () => {
      if (!window.confirm('Wipe all points, claims and the reward log for this test event?')) return;
      void labAct('reset', { reset_clock: !!(el('admin-reset-clock') || {}).checked });
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
    const typeSel = el('admin-event-type');
    if (typeSel) {
      typeSel.value = ev && ev.event_type === 'key_event' ? 'key_event' : 'classic';
      typeSel.disabled = !!ev; // the type is fixed when the event is created
    }
    const cfgBox = el('admin-event-config');
    if (cfgBox) {
      const ov = ev && ev.config_overrides && Object.keys(ev.config_overrides).length ? ev.config_overrides : null;
      cfgBox.value = ov ? JSON.stringify(ov, null, 2) : '';
    }
    syncTypeUi();
    const testBox = el('admin-event-test');
    if (testBox) {
      testBox.checked = !!(ev && ev.test_mode);
      testBox.disabled = !!ev; // the flag is fixed when the event is created
    }
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

  function syncTypeUi() {
    const key = editorType() === 'key_event';
    document.querySelectorAll('[data-key-only]').forEach((n) => { n.hidden = !key; });
  }

  async function fillDefaultTemplate() {
    setErr('');
    try {
      const res = await accountPost('events_default_template', {});
      const ms = (res.milestones || []).map((m) => rewardFromServer(m, 'milestone'));
      const rk = (res.rank_rewards || []).map((r) => rewardFromServer(r, 'rank'));
      renderRewardEditor('admin-milestones', ms.length ? ms : [emptyRewardRow('milestone')], 'milestone');
      renderRewardEditor('admin-ranks', rk.length ? rk : [emptyRewardRow('rank')], 'rank');
    } catch (e) {
      setErr(e && e.message ? e.message : 'Could not load the default template');
    }
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
    const typeOpts = (editorType() === 'key_event' ? KEY_REWARD_TYPES.concat(REWARD_TYPES) : REWARD_TYPES).map((t) =>
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
    if (AMOUNT_TYPES.includes(rt)) {
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
      if (AMOUNT_TYPES.includes(row.reward_type)) {
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
      test_mode: !!(el('admin-event-test') && el('admin-event-test').checked),
      event_type: editorType(),
      milestones: serializeRewards(collectRewardRows('admin-milestones', 'milestone')),
      rank_rewards: serializeRewards(collectRewardRows('admin-ranks', 'rank')),
    };
    const cfgText = ((el('admin-event-config') || {}).value || '').trim();
    if (body.event_type === 'key_event' && cfgText) {
      try {
        body.config = JSON.parse(cfgText);
      } catch (e2) {
        setErr('Config overrides are not valid JSON');
        return;
      }
    } else if (body.event_type === 'key_event') {
      body.config = {};
    }
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
      const keyEvents = [];
      for (const ev of events) {
        let lbRows = [];
        try {
          const lb = await accountPost('events_leaderboard', { event_id: ev.id, limit: 20 });
          lbRows = Array.isArray(lb.rows) ? lb.rows : [];
        } catch (e) { /* ignore */ }
        if (ev.event_type === 'key_event' && global.TCGEventKeys) {
          keyEvents.push(ev);
          parts.push(global.TCGEventKeys.cardHtml(ev, lbRows));
          continue;
        }
        const banner = ev.banner_url
          ? `<img class="events-banner" src="${esc(ev.banner_url)}" alt="">`
          : '';
        const ms =(ev.milestones || []).map((m) => {
          const hit = Number(ev.my_points || 0) >= Number(m.threshold_points || 0);
          return `<li class="${hit ? 'is-hit' : ''}">${esc(m.threshold_points)} EP → ${esc(m.reward_type)}</li>`;
        }).join('');
        const lbHtml = lbRows.map((r) =>
          `<li>#${esc(r.rank)} ${esc(r.username)} — ${esc(r.points)} EP</li>`
        ).join('') || '<li>—</li>';
        parts.push(`<section class="events-player-card">
          ${banner}
          <h3>${esc(ev.name)}${ev.test_mode ? '<span class="admin-test-badge">TEST · only you</span>' : ''}</h3>
          <p class="admin-events-meta">${esc(ev.starts_at_jst)} → ${esc(ev.ends_at_jst)} JST${ev.test_mode && ev.clock ? ' · test clock ' + esc(ev.clock.virtual_now_jst) + ' JST' : ''}</p>
          <p class="events-my-points"><strong>${esc(ev.my_points || 0)}</strong> EP</p>
          <h4>${esc(tt('events.milestones', 'Milestones'))}</h4>
          <ul class="events-ms-list">${ms || '<li>—</li>'}</ul>
          <h4>${esc(tt('events.leaderboard', 'Leaderboard'))}</h4>
          <ol class="events-lb-list">${lbHtml}</ol>
        </section>`);
      }
      root.innerHTML = parts.join('');
      for (const ev of keyEvents) {
        global.TCGEventKeys.bind(root, ev, { post: accountPost, reload: loadPlayerEvents });
      }
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
    el('admin-event-type')?.addEventListener('change', () => {
      syncTypeUi();
      renderRewardEditor('admin-milestones', collectRewardRows('admin-milestones', 'milestone'), 'milestone');
      renderRewardEditor('admin-ranks', collectRewardRows('admin-ranks', 'rank'), 'rank');
    });
    el('btn-admin-default-template')?.addEventListener('click', () => void fillDefaultTemplate());

    el('btn-hub-events')?.addEventListener('click', () => openEvents());
    el('btn-events-back')?.addEventListener('click', () => closeEventsToHub());
    el('btn-events-refresh')?.addEventListener('click', () => void loadPlayerEvents());

    syncHubButtons();
    // The account may land after this script runs: wait for it, then ask the server about test events.
    let adminTries = 0;
    const adminWait = setInterval(() => {
      if (isAdmin()) {
        clearInterval(adminWait);
        void refreshHubOverride();
        syncHubButtons();
      } else if (++adminTries > 30) {
        clearInterval(adminWait);
      }
    }, 1000);
    // Re-sync when account me lands
    const prev = global.onTcgAccountReady;
    global.onTcgAccountReady = function () {
      try { if (typeof prev === 'function') prev.apply(this, arguments); } catch (e) { /* ignore */ }
      syncHubButtons();
      void refreshHubOverride();
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
