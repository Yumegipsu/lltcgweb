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
      + '  </div>'
      + '</div>';
    document.body.appendChild(root);
    root.querySelector('.season-ladder-close').addEventListener('click', closeLadder);
    root.addEventListener('click', (event) => {
      if (event.target === root) closeLadder();
    });
    return root;
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
    if (started) {
      const label = season.label || tt('season.label', 'Season {n}', { n: season.season_number || 1 });
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
    const label = season.label || tt('season.label', 'Season {n}', { n: season.season_number || 1 });
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

  global.TCGSeason = {
    createBadge: createBadge,
    attachHub: attachHub,
    paintUserLine: paintUserLine,
    showRewardToasts: showRewardToasts,
    openLadder: openLadder,
    closeLadder: closeLadder,
    active: seasonActive,
    visible: seasonVisible,
  };
})(window);
