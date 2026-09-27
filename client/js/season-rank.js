/**
 * Seasonal rank badge (icon + radial progress) for the hub, ranked menu,
 * leaderboards, and profile.
 */
(function (global) {
  'use strict';

  function tt(key, fallback, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.tt;
    if (typeof fn === 'function') return fn(key, fallback, vars);
    return fallback != null ? String(fallback) : key;
  }

  function seasonActive(season) {
    return !!(season && season.active && season.icon);
  }

  function createBadge(season, opts) {
    const size = opts && opts.size === 'sm' ? 'sm' : '';
    const wrap = document.createElement('span');
    wrap.className = 'season-badge' + (size ? ' season-badge--' + size : '');
    const pct = Math.max(0, Math.min(100, Number(season.progress != null ? season.progress : season.points) || 0));
    wrap.style.setProperty('--pct', String(pct));
    const label = season.label || tt('season.label', 'Season {n}', { n: season.season_number || 1 });
    const letter = season.letter || '';
    wrap.title = label + (letter ? (' · ' + letter) : '');
    wrap.setAttribute('aria-label', wrap.title);
    const ring = document.createElement('span');
    ring.className = 'season-badge-ring';
    ring.setAttribute('aria-hidden', 'true');
    const img = document.createElement('img');
    img.src = season.icon;
    img.alt = letter;
    img.decoding = 'async';
    wrap.appendChild(ring);
    wrap.appendChild(img);
    return wrap;
  }

  function attachHub(host, season) {
    if (!host) return;
    host.querySelectorAll('.season-badge').forEach((node) => node.remove());
    if (!seasonActive(season)) return;
    host.insertBefore(createBadge(season), host.firstChild);
  }

  function paintUserLine(host, season, username) {
    if (!host) return;
    host.replaceChildren();
    if (!seasonActive(season)) {
      host.hidden = true;
      return;
    }
    host.hidden = false;
    host.appendChild(createBadge(season));
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
        ? tt('season.rewardToastPacks', 'Season reward: {coins} Coins, {gems} Star Gems, {packs} PR packs', {
          coins: reward.coins,
          gems: reward.gems,
          packs: packs,
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
    active: seasonActive,
  };
})(window);
