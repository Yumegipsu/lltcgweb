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

  async function loadIdolMap() {
    if (_idolMap) return _idolMap;
    try {
      const res = await fetch('./gacha_idol_map.json?v=1', { cache: 'no-store' });
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

  async function loadGachaScreen() {
    const err = el('gacha-err');
    if (err) err.textContent = '';
    showScr('gacha');
    await loadIdolMap();
    try {
      _info = await accountPost('gacha_info', {});
      syncGems(_info.star_gems);
      updateRateCopy(_info);
    } catch (e) {
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

  function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
  }

  async function playSpotlight(pull) {
    const ov = el('overlay-gacha-pull');
    const stage = el('gacha-pull-stage');
    const flash = el('gacha-pull-flash');
    const cardImgEl = el('gacha-pull-card');
    const tierEl = el('gacha-pull-tier');
    const renderWrap = el('gacha-pull-render-wrap');
    const renderImg = el('gacha-pull-render');
    const idolIcon = el('gacha-pull-idol-icon');
    const idolName = el('gacha-pull-idol-name');
    const hint = el('gacha-pull-hint');
    if (!ov || !stage || !cardImgEl) return;

    const tier = String(pull.tier || 'n');
    stage.className = 'gacha-pull-stage is-' + tier;
    if (flash) flash.className = 'gacha-pull-flash is-' + tier;
    ov.hidden = false;
    ov.setAttribute('aria-hidden', 'false');
    document.body.classList.add('gacha-pull-open');

    if (hint) hint.textContent = tt('gacha.tapContinue', 'Tap to continue');
    if (tierEl) {
      const label = tier === 'ur'
        ? tt('gacha.tierUr', 'UR')
        : (tier === 'sr' ? tt('gacha.tierSr', 'SR') : tt('gacha.tierN', 'N'));
      tierEl.textContent = label;
    }

    const meta = idolMeta(pull);
    const showChar = tier === 'ur' || tier === 'sr';
    if (renderWrap) {
      if (showChar && meta.render) {
        renderWrap.hidden = false;
        if (renderImg) {
          renderImg.src = meta.render;
          renderImg.alt = meta.name || '';
          renderImg.onerror = () => { renderWrap.hidden = true; };
        }
        if (idolName) idolName.textContent = meta.name || '';
        if (idolIcon) {
          if (meta.icon) {
            idolIcon.hidden = false;
            idolIcon.src = meta.icon;
            idolIcon.onerror = () => { idolIcon.hidden = true; };
          } else {
            idolIcon.hidden = true;
          }
        }
        sfx(tier === 'ur' ? 'yell_reveal' : 'pack_reveal');
        await sleep(tier === 'ur' ? 900 : 550);
      } else {
        renderWrap.hidden = true;
      }
    }

    cardImgEl.src = cardImg(pull.card_no, 360);
    cardImgEl.alt = pull.name_en || pull.card_no || '';
    sfx(tier === 'ur' ? 'pack_reveal' : 'card_flip');
    stage.classList.add('is-show-card');
    await waitTap(ov);
    stage.classList.remove('is-show-card');
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
      global.showPackResults(cards, tt('gacha.title', 'Gacha'), {
        godPack: false,
        starGemsEarned: res.star_gems_earned || 0,
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
    const err = el('gacha-err');
    if (err) err.textContent = '';
    _pullBusy = true;
    _lastMode = mode === 'multi' ? 'multi' : 'single';
    try {
      sfx('pack_open');
      const res = await accountPost('open_gacha', { mode: _lastMode });
      await loadIdolMap();
      const pulls = res.pulls || [];
      // Spotlight every UR, and first SR; skip spam on long N-only multis.
      for (const pull of pulls) {
        if (pull.tier === 'ur' || pull.tier === 'sr') {
          await playSpotlight(pull);
        }
      }
      // Always spotlight the last card if none were SR/UR so single N still has flair.
      if (!pulls.some((p) => p.tier === 'ur' || p.tier === 'sr') && pulls[0]) {
        await playSpotlight(pulls[pulls.length - 1]);
      }
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
})(typeof window !== 'undefined' ? window : globalThis);
