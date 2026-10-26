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
      + '    <div class="season-feedback-slot"></div>'
      + '    <div class="season-rates-slot"></div>'
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

  /** Feedback is for players who played ranked this season, during the last week. */
  function feedbackAvailable(fb) {
    return !!(fb && fb.open && fb.eligible);
  }

  function feedbackOverlay() {
    let root = document.getElementById('overlay-season-feedback');
    if (root) return root;
    root = el('div', 'season-ladder-overlay season-stats-overlay');
    root.id = 'overlay-season-feedback';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-hidden', 'true');
    root.innerHTML = ''
      + '<div class="season-ladder-shell season-stats-shell">'
      + '  <div class="season-ladder-panel">'
      + '    <div class="account-screen-head">'
      + '      <button type="button" class="btn-ghost season-feedback-close"></button>'
      + '      <h2 class="season-ladder-title"></h2>'
      + '    </div>'
      + '    <div class="season-ladder-scroll season-feedback-body"></div>'
      + '  </div>'
      + '</div>';
    document.body.appendChild(root);
    const close = () => {
      root.classList.remove('open');
      root.setAttribute('aria-hidden', 'true');
    };
    root.querySelector('.season-feedback-close').addEventListener('click', close);
    root.addEventListener('click', (e) => { if (e.target === root) close(); });
    return root;
  }

  /** The form itself, in its own dialog. onSaved(fb) runs after a successful send. */
  function openFeedbackDialog(fb, onSaved) {
    const root = feedbackOverlay();
    root.querySelector('.season-ladder-title').textContent = tt('season.feedbackTitle', 'Season feedback');
    root.querySelector('.season-feedback-close').textContent = tt('news.close', 'Close');
    const body = root.querySelector('.season-feedback-body');
    body.replaceChildren();
    body.appendChild(el('p', 'season-feedback-season', seasonLabelFromId(fb.season_id) || fb.label || ''));
    body.appendChild(el('p', 'account-lead', tt('season.feedbackLead',
      'What should we improve for the next season? Open until the season ends.')));
    if (fb.milestone) {
      body.appendChild(el('p', 'season-feedback-reward', tt('season.feedbackReward',
        'Send it to earn 1 SR+ Ticket — see Missions › Milestones')));
    }
    const ta = el('textarea', 'season-stats-textarea');
    ta.maxLength = 2000;
    ta.rows = 8;
    ta.value = fb.body || '';
    ta.placeholder = tt('season.feedbackPlaceholder', 'Your suggestions…');
    const count = el('p', 'season-feedback-count');
    const syncCount = () => { count.textContent = ta.value.length + ' / 2000'; };
    ta.addEventListener('input', syncCount);
    syncCount();
    const msg = el('p', 'season-stats-msg');
    if (fb.submitted) {
      msg.textContent = tt('season.feedbackSent', 'You already sent feedback this season. Sending again replaces it.');
    }
    const btn = el('button', 'btn-grad', fb.submitted
      ? tt('season.feedbackUpdate', 'Update feedback')
      : tt('season.feedbackSend', 'Send feedback'));
    btn.type = 'button';
    btn.addEventListener('click', async () => {
      const text = ta.value.trim();
      if (!text) return;
      btn.disabled = true;
      try {
        await global.accountPost('season_feedback_submit', { body: text });
        fb.submitted = true;
        fb.body = text;
        msg.textContent = tt('season.feedbackThanks', 'Thanks! Your feedback was sent.');
        btn.textContent = tt('season.feedbackUpdate', 'Update feedback');
        if (typeof onSaved === 'function') onSaved(fb);
      } catch (e) {
        msg.textContent = (e && e.message) || tt('season.feedbackError', 'Could not send feedback');
      }
      btn.disabled = false;
    });
    body.appendChild(ta);
    body.appendChild(count);
    body.appendChild(btn);
    body.appendChild(msg);
    root.classList.add('open');
    root.setAttribute('aria-hidden', 'false');
  }

  /** Call-out card shown on the season screens while the form is open for this player. */
  function feedbackBanner(fb) {
    const box = el('div', 'season-feedback-banner');
    const text = el('div', 'season-feedback-banner-text');
    text.appendChild(el('strong', '', tt('season.feedbackBannerTitle', 'Tell us how the season went')));
    const sub = el('span', '', '');
    const syncSub = () => {
      sub.textContent = fb.submitted
        ? tt('season.feedbackBannerSent', 'Thanks — you can still edit your feedback.')
        : tt('season.feedbackBannerSub', 'Open to everyone who played ranked · {n} day(s) left', { n: fb.days_left });
    };
    syncSub();
    text.appendChild(sub);
    if (fb.milestone) {
      text.appendChild(el('span', 'season-feedback-reward', tt('season.feedbackReward',
        'Send it to earn 1 SR+ Ticket — see Missions › Milestones')));
    }
    const btn = el('button', 'btn-grad', fb.submitted
      ? tt('season.feedbackEdit', 'Edit feedback')
      : tt('season.feedbackOpen', 'Give feedback'));
    btn.type = 'button';
    btn.addEventListener('click', () => openFeedbackDialog(fb, () => {
      syncSub();
      btn.textContent = tt('season.feedbackEdit', 'Edit feedback');
    }));
    box.appendChild(text);
    box.appendChild(btn);
    return box;
  }

  function renderFeedbackForm(host, data) {
    if (!feedbackAvailable(data.feedback)) return;
    host.appendChild(feedbackBanner(data.feedback));
  }

  /** Fill the ladder screen's feedback slot (signed-in players only). */
  async function fillLadderFeedbackSlot(slot) {
    slot.replaceChildren();
    if (typeof global.isSignedInAccount === 'function' && !global.isSignedInAccount()) return;
    try {
      const res = await global.accountPost('season_feedback_status', {});
      if (feedbackAvailable(res.feedback)) slot.appendChild(feedbackBanner(res.feedback));
    } catch (e) { /* the ladder works without it */ }
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

  /** Localized "2026 Season 1" from a YYYY-MM id (server label is English-only). */
  function seasonLabelFromId(id) {
    const m = /^(\d{4})-(\d{2})$/.exec(String(id || ''));
    if (!m) return '';
    const year = Number(m[1]);
    const month = Number(m[2]);
    const n = year === 2026 ? month - 9 : month;
    return seasonLabel({ season_id: String(id), season_number: n });
  }

  /** Card name as printed for the player's language (JA kanji, KO/ZH/TH maps, else English). */
  function statCardName(c) {
    const all = (global.G && global.G.allCards) || {};
    const no = String(c.card_no || '');
    const card = all[no] || all[no.replace(/＋/g, '+')] || all[no.replace(/\+/g, '＋')];
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.cardLocaleName;
    const localized = card && typeof fn === 'function' ? fn(card) : '';
    return localized || c.name_en || no;
  }

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
      const o = el('option', '', seasonLabelFromId(s.season_id));
      o.value = s.season_id;
      if (s.season_id === data.season_id) o.selected = true;
      select.appendChild(o);
    });
    select.hidden = seasons.length < 2;
    select.onchange = () => openStats(select.value);
    scroll.replaceChildren();
    if (data.season_id) {
      lead.textContent = seasonLabelFromId(data.season_id)
        + ' · ' + tt('season.statsDecks', '{n} decks', { n: data.decks })
        + ' · ' + tt('season.statsMatches', '{n} ranked matches', { n: data.matches });
    } else {
      lead.textContent = tt('season.notStarted', 'The seasonal ladder starts in October 2026.');
    }
    const sections = [
      [tt('season.statsMembers', 'Member cards'), data.members || []],
      [tt('season.statsLives', 'Live cards'), data.lives || []],
    ].filter((s) => s[1].length);
    if (!sections.length) {
      scroll.appendChild(el('p', 'account-lead', tt('season.statsEmpty', 'No ranked matches recorded yet this season.')));
    }
    sections.forEach(([heading, cards]) => {
      scroll.appendChild(el('h3', 'season-stats-heading', heading));
      // Bars compare cards within their own section (Members vs Members, Lives vs Lives).
      const max = Math.max(1, ...cards.map((c) => Number(c.usage_pct) || 0));
      const bars = el('div', 'social-bars season-stats-bars');
      cards.forEach((c) => {
        const row = el('div', 'social-bar season-stats-row');
        const art = el('button', 'social-bar-art season-stats-art');
        art.type = 'button';
        const cardName = statCardName(c);
        art.setAttribute('aria-label', cardName);
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
        name.appendChild(el('span', '', cardName));
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
    });
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

  /** "Coins ×500 · Star Gems ×150 · …" for a season / rank-up reward object. */
  function formatSeasonReward(r) {
    if (!r) return '';
    const parts = [];
    if (Number(r.coins) > 0) parts.push(tt('missions.rewardCoins', 'Coins ×{n}', { n: r.coins }));
    if (Number(r.gems) > 0) parts.push(tt('missions.rewardGems', 'Star Gems ×{n}', { n: r.gems }));
    if (Number(r.packs) > 0) parts.push(tt('season.rewardPack', 'PR pack ({n} cards)', { n: r.packs }));
    if (Number(r.tickets) > 0) parts.push(tt('missions.rewardTickets', 'Scouting Ticket ×{n}', { n: r.tickets }));
    if (Number(r.ticket_sr) > 0) parts.push(tt('missions.rewardTicketSr', 'SR+ Ticket ×{n}', { n: r.ticket_sr }));
    if (Number(r.ticket_ur) > 0) parts.push(tt('missions.rewardTicketUr', 'UR+ Ticket ×{n}', { n: r.ticket_ur }));
    return parts.join(' · ');
  }

  function rankingInfoOverlay() {
    let root = document.getElementById('overlay-season-info');
    if (root) return root;
    root = el('div', 'season-ladder-overlay season-info-overlay');
    root.id = 'overlay-season-info';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-hidden', 'true');
    root.innerHTML = ''
      + '<div class="season-ladder-shell season-info-shell">'
      + '  <div class="season-ladder-panel">'
      + '    <div class="account-screen-head">'
      + '      <button type="button" class="btn-ghost season-info-close"></button>'
      + '      <h2 class="season-ladder-title"></h2>'
      + '    </div>'
      + '    <div class="season-ladder-scroll season-info-body"></div>'
      + '  </div>'
      + '</div>';
    document.body.appendChild(root);
    const close = () => {
      root.classList.remove('open');
      root.setAttribute('aria-hidden', 'true');
      document.removeEventListener('keydown', root._onKey, true);
    };
    // Escape closes this window on top, not the ranks screen underneath.
    root._onKey = (event) => {
      if (event.key !== 'Escape') return;
      event.preventDefault();
      event.stopPropagation();
      close();
    };
    root._close = close;
    root.querySelector('.season-info-close').addEventListener('click', close);
    root.addEventListener('click', (e) => { if (e.target === root) close(); });
    return root;
  }

  /** One of the two point charts: rows = your rank, columns = the opponent's rank. */
  function rankChartTable(season, grid, kind) {
    const steps = ladderSteps(season).slice().reverse().filter((s) => Number(s.step) <= 6);
    const label = (step) => {
      // Pink S scores as Green S, so the top row / column stands for both.
      const base = rankName(step);
      if (Number(step.step) !== 6) return base;
      const pink = ladderSteps(season).find((x) => Number(x.step) === 7);
      return pink ? base + ' / ' + rankName(pink) : base;
    };
    const wrap = el('div', 'season-chart-wrap');
    const table = el('table', 'season-chart season-chart--' + kind);
    const head = el('tr');
    head.appendChild(el('th', 'season-chart-corner', tt('season.chartCorner', 'Your rank ↓ · Opponent\u2019s rank →')));
    steps.forEach((st) => {
      const th = el('th', 'season-chart-col');
      const img = document.createElement('img');
      img.src = st.icon;
      img.alt = label(st);
      img.width = 22;
      img.height = 22;
      img.decoding = 'async';
      th.title = label(st);
      th.setAttribute('aria-label', label(st));
      th.appendChild(img);
      head.appendChild(th);
    });
    table.appendChild(el('thead')).appendChild(head);
    const body = el('tbody');
    steps.forEach((row) => {
      const tr = el('tr');
      const th = el('th', 'season-chart-row');
      const img = document.createElement('img');
      img.src = row.icon;
      img.alt = '';
      img.width = 22;
      img.height = 22;
      img.decoding = 'async';
      th.appendChild(img);
      th.appendChild(el('span', '', label(row)));
      tr.appendChild(th);
      steps.forEach((col) => {
        const n = Number(((grid || [])[Number(row.step)] || [])[Number(col.step)]) || 0;
        const td = el('td', 'season-chart-cell' + (row.step === season.step || (Number(season.step) >= 7 && row.step === 6) ? ' is-you' : ''));
        if (kind === 'loss') {
          td.textContent = n > 0 ? '\u2212' + n : '\u2014';
          if (n <= 0) td.classList.add('is-none');
        } else {
          td.textContent = '+' + n;
        }
        tr.appendChild(td);
      });
      body.appendChild(tr);
    });
    table.appendChild(body);
    wrap.appendChild(table);
    return wrap;
  }

  /** "Ranking info": the rules and the full win / loss point charts, in a window above the ranks screen. */
  function openRankingInfo(season) {
    const rates = season && season.rates;
    if (!rates) return;
    const root = rankingInfoOverlay();
    root.querySelector('.season-ladder-title').textContent = tt('season.ratesTitle', 'Ranking info');
    root.querySelector('.season-info-close').textContent = tt('news.close', 'Close');
    const body = root.querySelector('.season-info-body');
    body.replaceChildren();
    const byStep = {};
    ladderSteps(season).forEach((s) => { byStep[Number(s.step)] = s; });
    const floorRank = byStep[Number(rates.floor_step)] ? rankName(byStep[Number(rates.floor_step)]) : '';
    const add = (text) => body.appendChild(el('p', 'season-info-text', text));
    add(tt('season.ratesWin', 'Points depend on both players\u2019 ranks: beating a higher rank pays more, and losing to a lower rank costs more. Pink S counts as Green S.'));
    if (Number(rates.rules_version) >= 2) {
      add(tt('season.ratesFloorV2', 'Losses cost no points below {rank}. From {rank} up, losses cost less than a win pays (see each rank below), and once you reach {rank} you can\u2019t drop below it for the rest of the season.',
        { rank: floorRank }));
    } else {
      add(tt('season.ratesFloorV1', 'C ranks lose no points, and you can\u2019t drop below {rank}.', { rank: floorRank }));
    }
    add(tt('season.ratesPinkS', 'Pink S is held by the top {n} players who have filled Green S.', { n: rates.pink_s_slots }));
    if (Number(rates.rules_version) >= 2) {
      add(tt('season.ratesRankUp', 'Rank-up rewards are paid once, the first time you reach each rank in a season. Season-end rewards are paid for your highest rank.'));
    }
    const matrix = rates.matrix || {};
    body.appendChild(el('h3', 'season-info-heading', tt('season.chartWin', 'If you win: points gained')));
    body.appendChild(rankChartTable(season, matrix.win, 'win'));
    body.appendChild(el('h3', 'season-info-heading', tt('season.chartLoss', 'If you lose: points lost')));
    body.appendChild(rankChartTable(season, matrix.loss, 'loss'));
    body.appendChild(el('p', 'season-info-note', tt('season.chartNoLoss', 'A dash (\u2014) means no points are lost at that rank.')));
    root.classList.add('open');
    root.setAttribute('aria-hidden', 'false');
    document.removeEventListener('keydown', root._onKey, true);
    document.addEventListener('keydown', root._onKey, true);
    body.scrollTop = 0;
  }

  /** The "Ranking info" button above the ranks. */
  function renderRatesBox(slot, season) {
    slot.replaceChildren();
    if (!(season && season.rates)) return;
    const btn = el('button', 'btn-ghost season-rates-btn', tt('season.ratesTitle', 'Ranking info'));
    btn.type = 'button';
    btn.addEventListener('click', () => openRankingInfo(season));
    slot.appendChild(btn);
  }

  /** Small detail lines under a rank on the ladder: its rank-up and season-end rewards. */
  function rankDetailLines(rates, stepIndex) {
    const row = rates && Array.isArray(rates.steps) ? rates.steps.find((r) => Number(r.step) === Number(stepIndex)) : null;
    if (!row) return [];
    const lines = [];
    if (row.rankup_reward) {
      lines.push(tt('season.rowRankUp', 'Rank-up: {rewards}', { rewards: formatSeasonReward(row.rankup_reward) }));
    }
    if (row.season_reward) {
      lines.push(tt('season.rowSeasonEnd', 'Season end: {rewards}', { rewards: formatSeasonReward(row.season_reward) }));
    }
    return lines;
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
    const feedbackSlot = root.querySelector('.season-feedback-slot');
    if (feedbackSlot) {
      if (mine) void fillLadderFeedbackSlot(feedbackSlot);
      else feedbackSlot.replaceChildren();
    }
    const ratesSlot = root.querySelector('.season-rates-slot');
    if (ratesSlot) renderRatesBox(ratesSlot, season);
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
      const nameText = document.createElement('span');
      nameText.textContent = rankName(step);
      name.appendChild(nameText);
      rankDetailLines(season.rates, step.step).forEach((line) => {
        const small = document.createElement('small');
        small.className = 'season-ladder-detail';
        small.textContent = line;
        name.appendChild(small);
      });
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

  function showRankUpToasts(list) {
    if (!Array.isArray(list) || typeof global.toast !== 'function') return;
    list.forEach((r) => {
      if (!r) return;
      global.toast(tt('season.rankUpToast', 'Rank-up reward ({rank}): {rewards}',
        { rank: rankName(r), rewards: formatSeasonReward(r) }), 5200);
    });
  }

  function showRewardToasts(me) {
    if (me && Array.isArray(me.season_rankups) && me.season_rankups.length) showRankUpToasts(me.season_rankups);
    const list = me && me.season_rewards;
    if (!Array.isArray(list) || typeof global.toast !== 'function') return;
    list.forEach((reward) => {
      if (!reward) return;
      const packs = Number(reward.pr_packs || 0);
      const hasTickets = Number(reward.ticket_sr || 0) > 0 || Number(reward.ticket_ur || 0) > 0;
      const msg = hasTickets
        ? tt('season.rewardToastFull', 'Season reward: {rewards}', {
          rewards: formatSeasonReward({ coins: reward.coins, gems: reward.gems, packs, ticket_sr: reward.ticket_sr, ticket_ur: reward.ticket_ur }),
        })
        : packs > 0
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
    } else if (change.protected) {
      pts.textContent = tt('season.pointsProtectedRank', '{rank} — points protected', { rank: rankName(change.before) });
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
    const rankUps = Array.isArray(change.rankup_rewards) ? change.rankup_rewards : [];
    rankUps.forEach((r) => {
      const line = document.createElement('p');
      line.className = 'win-season-rankup';
      line.textContent = tt('season.rankUpResult', 'Rank-up reward: {rewards}', { rewards: formatSeasonReward(r) });
      host.appendChild(line);
    });

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

  /** Both seats' season changes for a finished ranked match, winner first. */
  function resolveSpectatorChanges(state) {
    if (!state || state.mode !== 'ranked') return [];
    const out = [];
    ['p1', 'p2'].forEach((seat) => {
      const change = resolveMatchChange(state, seat);
      if (!change || !change.before || !change.after) return;
      const p = state.players && state.players[seat];
      out.push({ seat: seat, change: change, name: (p && p.name) || seat });
    });
    // Winner on the left, loser on the right.
    if (out.length === 2 && state.winner === out[1].seat) out.reverse();
    return out;
  }

  /**
   * Spectator end screen: both players' rank changes side by side. Returns true
   * when something was shown. The ranked result is applied just after the match
   * ends, so callers can retry (see syncSpectatorMatchChanges).
   */
  async function playSpectatorMatchChanges(host, state) {
    if (!host) return false;
    const entries = resolveSpectatorChanges(state);
    if (!entries.length) {
      host.hidden = true;
      host.replaceChildren();
      return false;
    }
    host.hidden = false;
    host.replaceChildren();
    host.className = 'win-season win-season--duo';
    const runs = entries.map((entry) => {
      const col = document.createElement('div');
      col.className = 'win-season-col';
      const name = document.createElement('p');
      name.className = 'win-season-name';
      name.textContent = entry.name;
      const slot = document.createElement('div');
      col.appendChild(name);
      col.appendChild(slot);
      host.appendChild(col);
      return playMatchChange(slot, entry.change);
    });
    await Promise.all(runs);
    return true;
  }

  /**
   * Show the spectator rank animation, re-reading the room a few times if the
   * ranked result has not landed yet (spectators can see the finish first).
   */
  async function syncSpectatorMatchChanges(host, state, opts) {
    if (!host || !state || state.mode !== 'ranked') {
      if (host) {
        host.hidden = true;
        host.replaceChildren();
      }
      return;
    }
    const roomId = state.room_id || (global.G && global.G.roomId) || '';
    let cur = state;
    const refetch = (opts && opts.refetch) || global.fetchLiveShowStateNow;
    for (let attempt = 0; attempt < 5; attempt++) {
      if (resolveSpectatorChanges(cur).length) {
        void playSpectatorMatchChanges(host, cur);
        return;
      }
      if (typeof refetch !== 'function') break;
      await new Promise((resolve) => setTimeout(resolve, 1200));
      const overlay = document.getElementById('overlay-win');
      if (!overlay || !overlay.classList.contains('open')) return;
      if (roomId && global.G && global.G.roomId && global.G.roomId !== roomId) return;
      try {
        const next = await refetch({ allowHeal: false });
        if (next && next.mode === 'ranked') cur = next;
      } catch (e) { /* keep waiting */ }
    }
    host.hidden = true;
    host.replaceChildren();
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
    playSpectatorMatchChanges: playSpectatorMatchChanges,
    syncSpectatorMatchChanges: syncSpectatorMatchChanges,
    resolveSpectatorChanges: resolveSpectatorChanges,
    rankName: rankName,
    openLadder: openLadder,
    openStats: openStats,
    openFeedbackInbox: openFeedbackInbox,
    closeLadder: closeLadder,
    active: seasonActive,
    visible: seasonVisible,
  };
})(window);
