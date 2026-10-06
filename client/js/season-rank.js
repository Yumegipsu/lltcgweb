/**
 * Seasonal rank badge (icon + radial progress) for the hub, ranked menu,
 * leaderboards, and profile. Clicking the icon opens the full ladder.
 */
(function (global) {
  'use strict';

  const FALLBACK_STEPS = [
    { step: 0, key: 'c-green', letter: 'C', tone: 'green', icon: 'client/img/ranks/c-green.png' },
    { step: 1, key: 'c-pink', letter: 'C', tone: 'pink', icon: 'client/img/ranks/c-pink.png' },
    { step: 2, key: 'b-green', letter: 'B', tone: 'green', icon: 'client/img/ranks/b-green.png' },
    { step: 3, key: 'b-pink', letter: 'B', tone: 'pink', icon: 'client/img/ranks/b-pink.png' },
    { step: 4, key: 'a-green', letter: 'A', tone: 'green', icon: 'client/img/ranks/a-green.png' },
    { step: 5, key: 'a-pink', letter: 'A', tone: 'pink', icon: 'client/img/ranks/a-pink.png' },
    { step: 6, key: 's-green', letter: 'S', tone: 'green', icon: 'client/img/ranks/s-green.png' },
    { step: 7, key: 's-pink', letter: 'S', tone: 'pink', icon: 'client/img/ranks/s-pink.png' },
  ];

  const RANK_FALLBACK = {
    'c-green': 'Green C',
    'c-pink': 'Pink C',
    'b-green': 'Green B',
    'b-pink': 'Pink B',
    'a-green': 'Green A',
    'a-pink': 'Pink A',
    's-green': 'Green S',
    's-pink': 'Pink S',
  };

  let lastFocus = null;

  function tt(key, fallback, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.tt;
    if (typeof fn === 'function') return fn(key, fallback, vars);
    return fallback != null ? String(fallback) : key;
  }

  function seasonActive(season) {
    return !!(season && season.active && season.icon);
  }

  function seasonVisible(season) {
    return !!(season && season.icon);
  }

  function seasonLabel(season) {
    // Always localize — server `label` is English-only (e.g. "2026 Season 1").
    const id = season && season.season_id ? String(season.season_id) : '';
    const year = /^\d{4}/.test(id) ? id.slice(0, 4) : '2026';
    const n = season && season.season_number ? season.season_number : 1;
    return tt('season.label', '{year} Season {n}', { year: year, n: n });
  }

  function seasonNowSec(season) {
    const n = Number(season && season.now);
    if (Number.isFinite(n) && n > 0) return n;
    return Math.floor(Date.now() / 1000);
  }

  /** Whole days remaining until ends_at; null if unknown. */
  function daysRemaining(season) {
    const ends = Number(season && season.ends_at);
    if (!Number.isFinite(ends) || ends <= 0) return null;
    const left = ends - seasonNowSec(season);
    if (left <= 0) return 0;
    if (left < 86400) return 0;
    return Math.ceil(left / 86400);
  }

  function remainText(season) {
    const days = daysRemaining(season);
    if (days == null) return '';
    if (days <= 0) return tt('season.endsToday', 'Ends today');
    if (days === 1) return tt('season.dayLeft', '1 day left');
    return tt('season.daysLeft', '{n} days left', { n: days });
  }

  function countdownLine(season) {
    if (!season) return '';
    const label = seasonLabel(season);
    const remain = remainText(season);
    if (!label) return remain;
    if (!remain) return label;
    return tt('season.metaLine', '{label} · {remain}', { label: label, remain: remain });
  }

  function paintCountdown(host, season, opts) {
    if (!host) return;
    const requireActive = !opts || opts.requireActive !== false;
    const active = season && (season.active || season.started);
    if (!season || (requireActive && !active && !season.ends_at)) {
      host.textContent = '';
      host.hidden = true;
      return;
    }
    if (requireActive && !active) {
      host.textContent = '';
      host.hidden = true;
      return;
    }
    const line = countdownLine(season);
    if (!line) {
      host.textContent = '';
      host.hidden = true;
      return;
    }
    host.hidden = false;
    host.textContent = line;
  }

  function updateHubRankedSub(host, season) {
    if (!host) return;
    const fallback = tt('hub.ranked.sub', 'Climb ELO in matchmade games');
    if (season && (season.active || season.started) && season.ends_at) {
      const line = countdownLine(season);
      host.textContent = line || fallback;
      host.removeAttribute('data-i18n');
      return;
    }
    host.setAttribute('data-i18n', 'hub.ranked.sub');
    host.textContent = fallback;
  }

  function rankName(step) {
    const key = step && step.key ? step.key : '';
    return tt('season.rank.' + key, RANK_FALLBACK[key] || step.letter || '');
  }

  function ladderSteps(season) {
    const list = season && Array.isArray(season.steps) && season.steps.length ? season.steps : FALLBACK_STEPS;
    return list.slice().sort((a, b) => Number(b.step) - Number(a.step));
  }

  function ensureOverlay() {
    let root = document.getElementById('overlay-season-ladder');
    if (root) return root;
    root = document.createElement('div');
    root.id = 'overlay-season-ladder';
    root.className = 'season-ladder-overlay';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-hidden', 'true');
    root.innerHTML = ''
      + '<div class="season-ladder-shell">'
      + '  <div class="season-ladder-panel">'
      + '    <div class="account-screen-head">'
      + '      <button type="button" class="btn-ghost season-ladder-close"></button>'
      + '      <h2 class="season-ladder-title"></h2>'
      + '    </div>'
      + '    <p class="account-lead season-ladder-lead"></p>'
      + '    <div class="season-ladder-scroll"><ol class="season-ladder-list"></ol></div>'
      + '    <button type="button" class="btn-grad season-ladder-stats"></button>'
      + '  </div>'
      + '</div>';
    document.body.appendChild(root);
    root.querySelector('.season-ladder-close').addEventListener('click', closeLadder);
    root.querySelector('.season-ladder-stats').addEventListener('click', () => openStats());
    root.addEventListener('click', (event) => {
      if (event.target === root) closeLadder();
    });
    return root;
  }

  // Card usage sheet + end-of-season feedback (issue #225).
  function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function statsOverlay() {
    let root = document.getElementById('overlay-season-stats');
    if (root) return root;
    root = el('div', 'season-ladder-overlay season-stats-overlay');
    root.id = 'overlay-season-stats';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-hidden', 'true');
    root.innerHTML = ''
      + '<div class="season-ladder-shell season-stats-shell">'
      + '  <div class="season-ladder-panel">'
      + '    <div class="account-screen-head">'
      + '      <button type="button" class="btn-ghost season-stats-close"></button>'
      + '      <h2 class="season-ladder-title"></h2>'
      + '    </div>'
      + '    <div class="season-stats-bar"><select class="season-stats-select"></select></div>'
      + '    <p class="account-lead season-stats-lead"></p>'
      + '    <div class="season-ladder-scroll season-stats-scroll"></div>'
      + '  </div>'
      + '</div>';
    document.body.appendChild(root);
    const close = () => {
      root.classList.remove('open');
      root.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('season-stats-open');
    };
    root.querySelector('.season-stats-close').addEventListener('click', close);
    root.addEventListener('click', (e) => { if (e.target === root) close(); });
    return root;
  }

  function fmtDate(sec) {
    try { return new Date(sec * 1000).toLocaleString(); } catch (e) { return String(sec); }
  }

  function renderFeedbackForm(host, data) {
    const fb = data.feedback || {};
    if (!fb.open) return;
    const box = el('div', 'season-stats-feedback');
    box.appendChild(el('h3', '', tt('season.feedbackTitle', 'Season feedback')));
    box.appendChild(el('p', 'account-lead', tt('season.feedbackLead',
      'What should we improve for the next season? Open until the season ends.')));
    const ta = el('textarea', 'season-stats-textarea');
    ta.maxLength = 2000;
    ta.rows = 5;
    ta.placeholder = tt('season.feedbackPlaceholder', 'Your suggestions…');
    const msg = el('p', 'season-stats-msg');
    const btn = el('button', 'btn-primary', fb.submitted
      ? tt('season.feedbackUpdate', 'Update feedback')
      : tt('season.feedbackSend', 'Send feedback'));
    btn.type = 'button';
    btn.addEventListener('click', async () => {
      const text = ta.value.trim();
      if (!text) return;
      btn.disabled = true;
      try {
        await global.accountPost('season_feedback_submit', { body: text });
        msg.textContent = tt('season.feedbackThanks', 'Thanks! Your feedback was sent.');
        ta.value = '';
        btn.textContent = tt('season.feedbackUpdate', 'Update feedback');
      } catch (e) {
        msg.textContent = (e && e.message) || tt('season.feedbackError', 'Could not send feedback');
      }
      btn.disabled = false;
    });
    if (fb.submitted) {
      msg.textContent = tt('season.feedbackSent', 'You already sent feedback this season. Sending again replaces it.');
    }
    box.appendChild(ta);
    box.appendChild(btn);
    box.appendChild(msg);
    host.appendChild(box);
  }

  // Owner-only inbox: its own overlay, opened from the Admin screen.
  function inboxOverlay() {
    let root = document.getElementById('overlay-season-feedback-inbox');
    if (root) return root;
    root = el('div', 'season-ladder-overlay season-stats-overlay');
    root.id = 'overlay-season-feedback-inbox';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-hidden', 'true');
    root.innerHTML = ''
      + '<div class="season-ladder-shell season-stats-shell">'
      + '  <div class="season-ladder-panel">'
      + '    <div class="account-screen-head">'
      + '      <button type="button" class="btn-ghost season-inbox-close"></button>'
      + '      <h2 class="season-ladder-title"></h2>'
      + '    </div>'
      + '    <div class="season-ladder-scroll season-inbox-body"></div>'
      + '  </div>'
      + '</div>';
    document.body.appendChild(root);
    const close = () => {
      root.classList.remove('open');
      root.setAttribute('aria-hidden', 'true');
    };
    root.querySelector('.season-inbox-close').addEventListener('click', close);
    root.addEventListener('click', (e) => { if (e.target === root) close(); });
    return root;
  }

  document.addEventListener('click', (event) => {
    if (event.target.closest && event.target.closest('#btn-admin-feedback')) openFeedbackInbox();
  });

  function inspectStatCard(no) {
    if (typeof global.showCard !== 'function') return;
    const all = (global.G && global.G.allCards) || {};
    const card = all[no] || all[String(no).replace(/＋/g, '+')] || all[String(no).replace(/\+/g, '＋')]
      || { card_no: no, name: no };
    global.showCard(card, null, global.G && global.G.gameState, global.G && global.G.playerId);
  }

  async function openFeedbackInbox() {
    const root = inboxOverlay();
    root.querySelector('.season-ladder-title').textContent = tt('season.feedbackInbox', 'Season feedback inbox');
    root.querySelector('.season-inbox-close').textContent = tt('news.close', 'Close');
    const box = root.querySelector('.season-inbox-body');
    root.classList.add('open');
    root.setAttribute('aria-hidden', 'false');
    box.replaceChildren(el('p', 'account-lead', '…'));
    try {
      const res = await global.accountPost('season_feedback_list', {});
      const rows = res.feedback || [];
      box.replaceChildren();
      if (!rows.length) {
        box.appendChild(el('p', 'account-lead', tt('season.feedbackNone', 'No feedback yet.')));
        return;
      }
      rows.forEach((r) => {
        const item = el('div', 'season-stats-inbox-item' + (r.read_at ? '' : ' is-new'));
        item.appendChild(el('div', 'season-stats-inbox-meta',
          r.label + ' · ' + (r.username || r.discord_id) + ' · ' + fmtDate(r.updated_at)));
        item.appendChild(el('div', 'season-stats-inbox-body', r.body));
        box.appendChild(item);
      });
      if (res.unread) global.accountPost('season_feedback_list', { mark_read: true }).catch(() => {});
    } catch (e) {
      box.replaceChildren(el('p', 'account-lead', (e && e.message) || ''));
    }
  }

  async function openStats(seasonId) {
    const root = statsOverlay();
    root.querySelector('.season-ladder-title').textContent = tt('season.statsTitle', 'Card usage');
    root.querySelector('.season-stats-close').textContent = tt('news.close', 'Close');
    const lead = root.querySelector('.season-stats-lead');
    const scroll = root.querySelector('.season-stats-scroll');
    const select = root.querySelector('.season-stats-select');
    root.classList.add('open');
    root.setAttribute('aria-hidden', 'false');
    document.body.classList.add('season-stats-open');
    scroll.replaceChildren(el('p', 'account-lead', '…'));
    let data;
    try {
      data = await global.accountPost('season_stats', seasonId ? { season_id: seasonId } : {});
    } catch (e) {
      scroll.replaceChildren(el('p', 'account-lead', (e && e.message) || ''));
      return;
    }
    select.replaceChildren();
    const seasons = data.seasons || [];
    seasons.forEach((s) => {
      const o = el('option', '', s.label);
      o.value = s.season_id;
      if (s.season_id === data.season_id) o.selected = true;
      select.appendChild(o);
    });
    select.hidden = seasons.length < 2;
    select.onchange = () => openStats(select.value);
    scroll.replaceChildren();
    if (data.season_id) {
      lead.textContent = data.label
        + ' · ' + tt('season.statsDecks', '{n} decks', { n: data.decks })
        + ' · ' + tt('season.statsMatches', '{n} ranked matches', { n: data.matches });
    } else {
      lead.textContent = tt('season.notStarted', 'The seasonal ladder starts in October 2026.');
    }
    const cards = data.cards || [];
    if (!cards.length) {
      scroll.appendChild(el('p', 'account-lead', tt('season.statsEmpty', 'No ranked matches recorded yet this season.')));
    } else {
      const max = Math.max(1, ...cards.map((c) => Number(c.usage_pct) || 0));
      const bars = el('div', 'social-bars season-stats-bars');
      cards.forEach((c) => {
        const row = el('div', 'social-bar season-stats-row');
        const art = el('button', 'social-bar-art season-stats-art');
        art.type = 'button';
        art.setAttribute('aria-label', c.name_en);
        const img = el('img', 'season-stats-face' + (String(c.card_type_en).toLowerCase() === 'live' ? ' is-live' : ''));
        img.alt = '';
        img.loading = 'lazy';
        img.decoding = 'async';
        img.src = typeof global.cachedCardImgUrl === 'function'
          ? global.cachedCardImgUrl(c.card_no, 96)
          : (global.CARDIMG || './cardimg.php') + '?card_no=' + encodeURIComponent(c.card_no) + '&w=96';
        art.appendChild(img);
        art.addEventListener('click', () => inspectStatCard(c.card_no));
        const name = el('span', 'season-stats-name');
        name.appendChild(el('span', '', c.name_en));
        name.appendChild(el('small', '', tt('season.statsRowSub', 'Win {win}% · {copies} avg copies',
          { win: c.win_pct.toFixed(1), copies: c.avg_copies.toFixed(1) })));
        const track = el('div', 'social-bar-track');
        const fill = el('div', 'social-bar-fill');
        fill.style.width = Math.round(100 * (Number(c.usage_pct) || 0) / max) + '%';
        track.appendChild(fill);
        row.appendChild(art);
        row.appendChild(name);
        row.appendChild(track);
        row.appendChild(el('span', 'social-bar-n', c.usage_pct.toFixed(1) + '%'));
        bars.appendChild(row);
      });
      scroll.appendChild(bars);
    }
    renderFeedbackForm(scroll, data);
  }

  function closeLadder() {
    const root = document.getElementById('overlay-season-ladder');
    if (!root) return;
    root.classList.remove('open');
    root.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('season-ladder-open');
    document.removeEventListener('keydown', onLadderKey);
    if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
    lastFocus = null;
  }

  function onLadderKey(event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      closeLadder();
    }
  }

  function openLadder(season, opts) {
    if (!seasonVisible(season)) return;
    const mine = !opts || opts.mine !== false;
    const root = ensureOverlay();
    const title = root.querySelector('.season-ladder-title');
    const lead = root.querySelector('.season-ladder-lead');
    const close = root.querySelector('.season-ladder-close');
    const list = root.querySelector('.season-ladder-list');
    const started = season.started !== false && season.active !== false;
    title.textContent = tt('season.ladderTitle', 'Season ranks');
    title.id = 'season-ladder-title';
    root.setAttribute('aria-labelledby', 'season-ladder-title');
    close.textContent = tt('news.close', 'Close');
    root.querySelector('.season-ladder-stats').textContent = tt('season.statsOpen', 'Card usage');
    if (started) {
      const label = seasonLabel(season);
      lead.textContent = label;
    } else {
      lead.textContent = tt('season.notStarted', 'The seasonal ladder starts in October 2026.');
    }
    list.replaceChildren();
    const here = Number(season.step) || 0;
    let currentRow = null;
    ladderSteps(season).forEach((step) => {
      const row = document.createElement('li');
      const current = Number(step.step) === here;
      row.className = 'season-ladder-row' + (current ? ' is-current' : '') + (step.tone ? ' is-' + step.tone : '');
      const img = document.createElement('img');
      img.src = step.icon;
      img.alt = '';
      img.width = 40;
      img.height = 40;
      img.decoding = 'async';
      const name = document.createElement('span');
      name.className = 'season-ladder-name';
      name.textContent = rankName(step);
      row.appendChild(img);
      row.appendChild(name);
      if (current) {
        const mark = document.createElement('span');
        mark.className = 'season-ladder-you';
        mark.textContent = mine ? tt('season.you', 'You') : rankName(step);
        if (!mine) mark.hidden = true;
        if (mine) row.appendChild(mark);
        if (started) {
          const pts = document.createElement('span');
          pts.className = 'season-ladder-pts';
          pts.textContent = String(Math.max(0, Number(season.points) || 0));
          row.appendChild(pts);
        }
        currentRow = row;
      }
      list.appendChild(row);
    });
    root.classList.add('open');
    root.setAttribute('aria-hidden', 'false');
    document.body.classList.add('season-ladder-open');
    document.addEventListener('keydown', onLadderKey);
    if (currentRow) currentRow.scrollIntoView({ block: 'nearest' });
    close.focus();
  }

  function createBadge(season, opts) {
    const size = opts && opts.size === 'sm' ? 'sm' : '';
    const wrap = document.createElement('button');
    wrap.type = 'button';
    wrap.className = 'season-badge' + (size ? ' season-badge--' + size : '');
    const pct = Math.max(0, Math.min(100, Number(season.progress != null ? season.progress : season.points) || 0));
    wrap.style.setProperty('--pct', String(pct));
    const label = seasonLabel(season);
    const named = rankName(season);
    wrap.title = label + (named ? (' · ' + named) : '');
    wrap.setAttribute('aria-label', wrap.title);
    const ring = document.createElement('span');
    ring.className = 'season-badge-ring';
    ring.setAttribute('aria-hidden', 'true');
    const img = document.createElement('img');
    img.src = season.icon;
    img.alt = '';
    img.decoding = 'async';
    wrap.appendChild(ring);
    wrap.appendChild(img);
    wrap.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      lastFocus = wrap;
      openLadder(season, opts);
    });
    return wrap;
  }

  function attachHub(host, season) {
    if (!host) return;
    host.querySelectorAll('.season-badge').forEach((node) => node.remove());
    let label = host.querySelector('.hub-user-label');
    if (!label) {
      label = document.createElement('span');
      label.className = 'hub-user-label';
      while (host.firstChild) label.appendChild(host.firstChild);
      host.appendChild(label);
    }
    if (!seasonVisible(season)) return;
    host.insertBefore(createBadge(season, { mine: true }), label);
  }

  function paintUserLine(host, season, username) {
    if (!host) return;
    host.replaceChildren();
    if (!seasonVisible(season)) {
      host.hidden = true;
      return;
    }
    host.hidden = false;
    host.appendChild(createBadge(season, { mine: true }));
    const name = document.createElement('span');
    name.textContent = username || '';
    host.appendChild(name);
  }

  function showRewardToasts(me) {
    const list = me && me.season_rewards;
    if (!Array.isArray(list) || typeof global.toast !== 'function') return;
    list.forEach((reward) => {
      if (!reward) return;
      const packs = Number(reward.pr_packs || 0);
      const msg = packs > 0
        ? tt('season.rewardToastPacks', 'Season reward: {coins} Coins, {gems} Star Gems, a {cards}-card PR pack', {
          coins: reward.coins,
          gems: reward.gems,
          cards: packs,
        })
        : tt('season.rewardToast', 'Season reward: {coins} Coins, {gems} Star Gems', {
          coins: reward.coins,
          gems: reward.gems,
        });
      global.toast(msg, 4600);
    });
  }

  function sideProgress(side) {
    if (!side) return 0;
    if (side.progress != null) return Math.max(0, Math.min(100, Number(side.progress) || 0));
    return Math.max(0, Math.min(100, Number(side.points) || 0));
  }

  function animatePct(el, from, to, ms) {
    return new Promise((resolve) => {
      if (!el) {
        resolve();
        return;
      }
      const start = performance.now();
      const a = Math.max(0, Math.min(100, Number(from) || 0));
      const b = Math.max(0, Math.min(100, Number(to) || 0));
      const dur = Math.max(200, Number(ms) || 700);
      function frame(now) {
        const t = Math.min(1, (now - start) / dur);
        const eased = t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
        el.style.setProperty('--pct', String(a + (b - a) * eased));
        if (t < 1) requestAnimationFrame(frame);
        else resolve();
      }
      requestAnimationFrame(frame);
    });
  }

  /**
   * Match-end radial: drain/fill the ring, swap icon on promo/demo, announce.
   * @param {HTMLElement|null} host
   * @param {object|null} change
   */
  async function playMatchChange(host, change) {
    if (!host || !change || !change.before || !change.after) {
      if (host) {
        host.hidden = true;
        host.replaceChildren();
      }
      return;
    }
    host.hidden = false;
    host.replaceChildren();
    host.className = 'win-season';

    const badge = document.createElement('div');
    badge.className = 'season-badge season-badge--win';
    const beforePct = sideProgress(change.before);
    badge.style.setProperty('--pct', String(beforePct));
    const ring = document.createElement('span');
    ring.className = 'season-badge-ring';
    ring.setAttribute('aria-hidden', 'true');
    const img = document.createElement('img');
    img.src = change.before.icon || '';
    img.alt = '';
    img.decoding = 'async';
    badge.appendChild(ring);
    badge.appendChild(img);

    const msg = document.createElement('p');
    msg.className = 'win-season-msg';
    msg.setAttribute('aria-live', 'polite');

    const pts = document.createElement('p');
    pts.className = 'win-season-pts';
    const delta = Number(change.delta) || 0;
    if (change.win) {
      pts.textContent = tt('season.pointsGain', '+{n} season pts', { n: delta });
    } else if (delta > 0 && (change.demoted || beforePct !== sideProgress(change.after) || change.before.step !== change.after.step)) {
      pts.textContent = tt('season.pointsLoss', '−{n} season pts', { n: delta });
    } else if (!change.win && change.before.step <= 1) {
      pts.textContent = tt('season.pointsProtected', 'C rank — points protected');
    } else {
      pts.textContent = tt('season.pointsLoss', '−{n} season pts', { n: delta });
    }

    host.appendChild(badge);
    host.appendChild(msg);
    host.appendChild(pts);

    const afterPct = sideProgress(change.after);
    const promoted = !!change.promoted || Number(change.after.step) > Number(change.before.step);
    const demoted = !!change.demoted || Number(change.after.step) < Number(change.before.step);

    if (promoted) {
      await animatePct(badge, beforePct, 100, 750);
      img.src = change.after.icon || img.src;
      badge.classList.add('is-rank-up');
      msg.textContent = tt('season.promoted', 'Promoted to {rank}', { rank: rankName(change.after) });
      await animatePct(badge, 0, afterPct, 700);
    } else if (demoted) {
      await animatePct(badge, beforePct, 0, 700);
      img.src = change.after.icon || img.src;
      badge.classList.add('is-rank-down');
      msg.textContent = tt('season.demoted', 'Demoted to {rank}', { rank: rankName(change.after) });
      await animatePct(badge, 0, afterPct, 750);
    } else {
      await animatePct(badge, beforePct, afterPct, 800);
      msg.textContent = rankName(change.after);
    }
  }

  function resolveMatchChange(state, playerId) {
    const map = state && state.ranked && state.ranked.season_changes;
    if (!map || typeof map !== 'object') return null;
    const seat = playerId === 'p1' || playerId === 'p2' ? playerId : null;
    if (seat && map[seat]) return map[seat];
    return null;
  }

  global.TCGSeason = {
    createBadge: createBadge,
    attachHub: attachHub,
    paintUserLine: paintUserLine,
    paintCountdown: paintCountdown,
    updateHubRankedSub: updateHubRankedSub,
    seasonLabel: seasonLabel,
    countdownLine: countdownLine,
    daysRemaining: daysRemaining,
    showRewardToasts: showRewardToasts,
    playMatchChange: playMatchChange,
    resolveMatchChange: resolveMatchChange,
    rankName: rankName,
    openLadder: openLadder,
    openStats: openStats,
    openFeedbackInbox: openFeedbackInbox,
    closeLadder: closeLadder,
    active: seasonActive,
    visible: seasonVisible,
  };
})(window);
