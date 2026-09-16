/**
 * Scout general gacha — menu, Star Gem pulls, SIFAS-style spotlight reveal.
 */
(function (global) {
  'use strict';

  function tt(key, fallback, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.tt;
    return typeof fn === 'function' ? fn(key, fallback, vars) : (fallback || key);
  }
  function accountPost(action, body) {
    return global.accountPost(action, body || {});
  }
  function el(id) {
    return document.getElementById(id);
  }
  function sfx(id) {
    try {
      if (global.LLTCG_SFX && typeof global.LLTCG_SFX.play === 'function') {
        global.LLTCG_SFX.play(id);
      } else if (typeof global.sfxPlay === 'function') {
        global.sfxPlay(id);
      }
    } catch (_) { /* ignore */ }
  }
  function cardImg(no, w) {
    if (!no) return '';
    if (typeof global.cachedCardImgUrl === 'function') return global.cachedCardImgUrl(no, w || 280);
    const base = global.CARDIMG || './cardimg.php';
    return `${base}?card_no=${encodeURIComponent(no)}&w=${w || 280}`;
  }
  function showScr(n) {
    if (typeof global.showScr === 'function') global.showScr(n);
  }
  function toast(msg, ms) {
    if (typeof global.toast === 'function') global.toast(msg, ms);
  }

  let _info = null;
  let _idolMap = null;
  let _pullBusy = false;
  let _lastMode = 'single';
  let _unlocked = false;

  function isUnlocked() {
    if (_info && typeof _info.unlocked === 'boolean') return !!_info.unlocked;
    return _unlocked;
  }

  function syncScoutTileLock(unlocked) {
    _unlocked = !!unlocked;
    const tile = el('btn-scout-gacha');
    if (!tile) return;
    tile.classList.toggle('is-locked', !_unlocked);
    tile.setAttribute('aria-disabled', _unlocked ? 'false' : 'true');
    const sub = tile.querySelector('.shop-hub-tile__sub');
    if (sub) {
      sub.textContent = _unlocked
        ? tt('scout.gachaSub', 'General Scout pulls')
        : tt('scout.gachaSubLocked', 'Coming soon');
    }
    const label = tile.querySelector('.shop-hub-tile__label');
    if (label && !_unlocked) {
      // Keep title; locked state is on the tile + sub.
      label.setAttribute('data-gacha-locked', '1');
    } else if (label) {
      label.removeAttribute('data-gacha-locked');
    }
  }

  async function refreshGachaAccess() {
    try {
      _info = await accountPost('gacha_info', {});
      syncScoutTileLock(!!_info.unlocked);
      return !!_info.unlocked;
    } catch (_) {
      syncScoutTileLock(false);
      return false;
    }
  }

  async function loadIdolMap() {
    if (_idolMap) return _idolMap;
    try {
      const res = await fetch('./gacha_idol_map.json?v=2', { cache: 'no-store' });
      _idolMap = res.ok ? await res.json() : {};
    } catch (_) {
      _idolMap = {};
    }
    return _idolMap;
  }

  function idolMeta(pull) {
    const map = _idolMap || {};
    const key = String(pull.idol_key || '').toLowerCase();
    const entry = map[key] || null;
    const name = (entry && (entry.name_en || entry.first)) || pull.name_en || '';
    const render = entry && entry.render
      ? entry.render
      : (key ? `assets/gacha/renders/${key}.png` : '');
    const icon = (entry && entry.portrait) || '';
    return { key, name, render, icon };
  }

  function syncGems(n) {
    const gems = Number.isFinite(Number(n)) ? Number(n) : (_info && _info.star_gems) || 0;
    if (global.A) {
      global.A.starGems = gems;
      if (global.A.profile) global.A.profile.star_gems = gems;
    }
    const node = el('gacha-gems');
    if (node) node.textContent = gems.toLocaleString();
    if (typeof global.refreshStarGemsUi === 'function') global.refreshStarGemsUi();
    else {
      const hub = el('hub-star-gems-count');
      if (hub) hub.textContent = gems.toLocaleString();
    }
  }

  function updateRateCopy(info) {
    const rates = info.rates || {};
    const ratesEl = el('gacha-rates');
    if (ratesEl) {
      ratesEl.textContent = tt(
        'gacha.ratesLead',
        'N ~{n}% · SR ~{sr}% · UR ~{ur}%',
        {
          n: rates.n != null ? rates.n : 90,
          sr: rates.sr != null ? rates.sr : 9.2,
          ur: rates.ur != null ? rates.ur : 0.8,
        }
      );
    }
    const pool = info.pool || {};
    const poolEl = el('gacha-pool-info');
    if (poolEl) {
      poolEl.textContent = tt(
        'gacha.poolInfo',
        '{total} cards in pool · UR {ur} · SR {sr} · N {n}',
        {
          total: pool.total || 0,
          ur: pool.ur || 0,
          sr: pool.sr || 0,
          n: pool.n || 0,
        }
      );
    }
    const single = el('btn-gacha-single');
    const multi = el('btn-gacha-multi');
    const sc = info.single_cost || 20;
    const mc = info.multi_cost || 200;
    if (single) {
      single.textContent = tt('gacha.single', 'Scout ×1 ({n})', { n: sc });
    }
    if (multi) {
      multi.textContent = tt('gacha.multi', 'Scout 10+1 ({n})', { n: mc });
    }
  }

  /** School idols currently featured in Loveca (excludes Bluebird / rivals / side units). */
  const SCOUT_RENDER_UNITS = {
    "µ's": 1,
    Aqours: 1,
    Nijigasaki: 1,
    'Liella!': 1,
    Hasunosora: 1,
  };

  async function pickRandomRenderUrl() {
    const map = await loadIdolMap();
    const urls = Object.keys(map || {})
      .map((k) => map[k])
      .filter((e) => e && SCOUT_RENDER_UNITS[e.unit] && typeof e.render === 'string' && e.render)
      .map((e) => e.render);
    if (!urls.length) return '';
    return urls[Math.floor(Math.random() * urls.length)];
  }

  async function refreshScoutGachaTileArt() {
    const render = el('scout-hub-gacha-render');
    if (!render) return;
    const url = await pickRandomRenderUrl();
    if (!url) {
      render.removeAttribute('src');
      render.hidden = true;
      return;
    }
    render.hidden = false;
    render.src = String(url);
  }

  async function loadGachaScreen() {
    const err = el('gacha-err');
    if (err) err.textContent = '';
    await loadIdolMap();
    try {
      _info = await accountPost('gacha_info', {});
      syncScoutTileLock(!!_info.unlocked);
      if (!_info.unlocked) {
        toast(tt('gacha.lockedToast', 'Gacha is not available yet.'), 2800);
        if (typeof global.loadScoutHubScreen === 'function') {
          void global.loadScoutHubScreen();
        } else {
          showScr('scout');
        }
        return;
      }
      showScr('gacha');
      syncGems(_info.star_gems);
      updateRateCopy(_info);
    } catch (e) {
      showScr('gacha');
      if (err) err.textContent = e.message || tt('gacha.loadError', 'Could not load gacha');
    }
  }

  function waitTap(node) {
    return new Promise((resolve) => {
      const done = () => {
        node.removeEventListener('click', done);
        node.removeEventListener('keydown', onKey);
        resolve();
      };
      const onKey = (ev) => {
        if (ev.key === 'Enter' || ev.key === ' ' || ev.key === 'Escape') {
          ev.preventDefault();
          done();
        }
      };
      node.addEventListener('click', done);
      node.addEventListener('keydown', onKey);
    });
  }

  async function playSpectacle(pulls) {
    const ov = el('overlay-gacha-pull');
    const spot = el('gacha-spot');
    if (!ov || !spot || !global.GachaSpotlight) return;

    ov.hidden = false;
    ov.setAttribute('aria-hidden', 'false');
    document.body.classList.add('gacha-pull-open');

    const results = global.GachaSpotlight.fromPulls(pulls, (p) => cardImg(p.card_no, 280));
    await global.GachaSpotlight.play(results, {
      root: spot,
      sfx,
      labels: {
        ur: tt('gacha.tierUr', 'UR'),
        sr: tt('gacha.tierSr', 'SR'),
        urSub: tt('gacha.flourishUr', 'guaranteed in this scout'),
        srSub: tt('gacha.flourishSr', 'high rarity ahead'),
        skip: tt('gacha.skip', 'Skip'),
        hint: tt('gacha.tapContinue', 'Tap to continue'),
      },
    });

    // Allow a beat to admire / tilt UR cards, then tap to leave.
    await waitTap(ov);

    if (global.GachaSpotlight) global.GachaSpotlight.stop();
    ov.hidden = true;
    ov.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('gacha-pull-open');
  }

  function toResultCards(pulls) {
    return (pulls || []).map((p) => ({
      card_no: p.card_no,
      rarity: p.rarity,
      converted: !!p.converted,
      star_gems: p.star_gems || 0,
      name_en: p.name_en,
    }));
  }

  function showGachaResults(res) {
    const pulls = res.pulls || [];
    const cards = toResultCards(pulls);
    global.A = global.A || {};
    global.A._fromGacha = true;
    global.A._gachaLastMode = res.mode || _lastMode;
    syncGems(res.star_gems);
    if (typeof global.showPackResults === 'function') {
      const isMulti = (res.mode || _lastMode) === 'multi' || cards.length >= 11;
      global.showPackResults(cards, tt('gacha.title', 'Gacha'), {
        godPack: false,
        starGemsEarned: res.star_gems_earned || 0,
        gachaMulti: isMulti,
      });
      const again = el('btn-pack-again');
      const another = el('btn-pack-another');
      if (again) {
        again.hidden = false;
        again.textContent = tt('gacha.scoutAgain', 'Scout again');
      }
      if (another) {
        another.textContent = tt('gacha.backToGacha', 'Back to Gacha');
      }
    } else {
      showScr('gacha');
    }
  }

  async function openGacha(mode) {
    if (_pullBusy) return;
    if (!isUnlocked()) {
      toast(tt('gacha.lockedToast', 'Gacha is not available yet.'), 2800);
      return;
    }
    const err = el('gacha-err');
    if (err) err.textContent = '';
    _pullBusy = true;
    _lastMode = mode === 'multi' ? 'multi' : 'single';
    try {
      sfx('pack_open');
      const res = await accountPost('open_gacha', { mode: _lastMode });
      await loadIdolMap();
      const pulls = res.pulls || [];
      // Predetermined pulls → SIFAS spotlight (beams + rarity flips) → results grid.
      await playSpectacle(pulls);
      showGachaResults(res);
      _info = { ...(_info || {}), star_gems: res.star_gems };
    } catch (e) {
      if (err) err.textContent = e.message || tt('gacha.pullError', 'Could not scout');
      toast(e.message || tt('gacha.pullError', 'Could not scout'), 2800);
    } finally {
      _pullBusy = false;
    }
  }

  function wirePackResultsForGacha() {
    const again = el('btn-pack-again');
    const another = el('btn-pack-another');
    const hub = el('btn-pack-hub');
    if (again && !again.dataset.gachaBound) {
      again.dataset.gachaBound = '1';
      again.addEventListener('click', (ev) => {
        if (!(global.A && global.A._fromGacha)) return;
        ev.stopImmediatePropagation();
        global.A._fromGacha = false;
        void openGacha(global.A._gachaLastMode || 'single');
      }, true);
    }
    if (another && !another.dataset.gachaBound) {
      another.dataset.gachaBound = '1';
      another.addEventListener('click', (ev) => {
        if (!(global.A && global.A._fromGacha)) return;
        ev.stopImmediatePropagation();
        global.A._fromGacha = false;
        void loadGachaScreen();
      }, true);
    }
    if (hub && !hub.dataset.gachaBound) {
      hub.dataset.gachaBound = '1';
      hub.addEventListener('click', () => {
        if (global.A) global.A._fromGacha = false;
      }, true);
    }
  }

  function bind() {
    el('btn-gacha-back')?.addEventListener('click', () => {
      if (typeof global.loadScoutHubScreen === 'function') global.loadScoutHubScreen();
      else showScr('scout');
    });
    el('btn-gacha-single')?.addEventListener('click', () => openGacha('single'));
    el('btn-gacha-multi')?.addEventListener('click', () => openGacha('multi'));
    wirePackResultsForGacha();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }

  global.loadGachaScreen = loadGachaScreen;
  global.openGachaPull = openGacha;
  global.refreshGachaAccess = refreshGachaAccess;
  global.refreshScoutGachaTileArt = refreshScoutGachaTileArt;
  global.syncGachaScoutTile = syncScoutTileLock;
})(typeof window !== 'undefined' ? window : globalThis);
