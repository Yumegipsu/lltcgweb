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
  let _ratesCache = null;

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

  function syncTickets(n) {
    const tickets = Number.isFinite(Number(n))
      ? Number(n)
      : ((_info && _info.scouting_tickets) || 0);
    if (global.A) {
      global.A.scoutingTickets = tickets;
      if (global.A.profile) global.A.profile.scouting_tickets = tickets;
      if (global.A.user) global.A.user.scouting_tickets = tickets;
    }
    const node = el('gacha-tickets');
    if (node) node.textContent = tickets.toLocaleString();
    const bar = el('gacha-tickets-bar');
    const actions = el('gacha-ticket-actions');
    const show = tickets > 0;
    if (bar) bar.hidden = !show;
    if (actions) actions.hidden = !show;
    const single = el('btn-gacha-ticket-single');
    const multi = el('btn-gacha-ticket-multi');
    const sc = (_info && _info.ticket_single_cost) || 1;
    const mc = (_info && _info.ticket_multi_cost) || 10;
    const mcount = (_info && _info.ticket_multi_count) || 10;
    if (single) {
      const label = single.querySelector('span') || single;
      label.textContent = tt('gacha.ticketSingle', 'Scout ×1 ({n} ticket)', { n: sc });
      single.disabled = tickets < sc;
    }
    if (multi) {
      const label = multi.querySelector('span') || multi;
      label.textContent = tt('gacha.ticketMulti', 'Scout ×{count} ({n} tickets)', { count: mcount, n: mc });
      multi.disabled = tickets < mc;
    }
  }

  function updateRateCopy(info) {
    const ratesEl = el('gacha-rates');
    if (ratesEl) {
      ratesEl.textContent = tt(
        'gacha.ratesLead',
        'Tap Info for rarity rates and card odds'
      );
    }
    const pool = info.pool || {};
    const poolEl = el('gacha-pool-info');
    if (poolEl) {
      poolEl.textContent = tt(
        'gacha.poolInfo',
        '{total} cards in pool',
        { total: pool.total || 0 }
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
    syncTickets(info.scouting_tickets);
  }

  function formatRatePercent(n) {
    if (typeof global.formatPackRatePercent === 'function') {
      return global.formatPackRatePercent(n);
    }
    const v = Number(n);
    if (!Number.isFinite(v) || v <= 0) return '0%';
    if (v >= 10) return v.toFixed(2).replace(/\.?0+$/, '') + '%';
    if (v >= 1) return v.toFixed(3).replace(/\.?0+$/, '') + '%';
    return v.toFixed(4).replace(/\.?0+$/, '') + '%';
  }

  function packDisplayName(row) {
    const id = String(row && row.id || '');
    if (id === 'starters') return tt('gacha.packStarters', 'Starter decks');
    if (id === 'pr_cards') return tt('gacha.packPr', 'PR cards');
    const loc = (global.LLTCG_I18N && global.LLTCG_I18N.getLocale)
      ? String(global.LLTCG_I18N.getLocale() || 'en')
      : 'en';
    if (loc === 'ja' && row && row.name_jp) return String(row.name_jp);
    return String((row && (row.name_en || row.id)) || '');
  }

  function appendPackList(parent, title, rows, emptyLabel) {
    const wrap = document.createElement('div');
    wrap.className = 'gacha-rates-packs';
    const h = document.createElement('h3');
    h.textContent = title;
    wrap.appendChild(h);
    const ul = document.createElement('ul');
    ul.className = 'gacha-rates-packlist';
    const list = Array.isArray(rows) ? rows : [];
    if (!list.length) {
      const li = document.createElement('li');
      li.className = 'gacha-rates-pack gacha-rates-pack--empty';
      li.textContent = emptyLabel;
      ul.appendChild(li);
    } else {
      list.forEach((row) => {
        const li = document.createElement('li');
        li.className = 'gacha-rates-pack';
        li.textContent = packDisplayName(row);
        ul.appendChild(li);
      });
    }
    wrap.appendChild(ul);
    parent.appendChild(wrap);
  }

  function cardLocaleNameSafe(row) {
    if (typeof global.cardLocaleName === 'function') return global.cardLocaleName(row) || row.card_no || '?';
    if (global.LLTCG_I18N && typeof global.LLTCG_I18N.cardLocaleName === 'function') {
      return global.LLTCG_I18N.cardLocaleName(row) || row.card_no || '?';
    }
    return row.name_en || row.card_no || '?';
  }

  function renderGachaRatesModal(rates) {
    const body = el('gacha-rates-body');
    if (!body) return;
    body.replaceChildren();

    const title = el('gacha-rates-title');
    if (title) title.textContent = tt('gacha.ratesTitle', 'Standard Gacha');
    const lead = el('gacha-rates-lead');
    if (lead) {
      lead.textContent = tt(
        'gacha.ratesLeadDetail',
        'Per pull · tap a card for details'
      );
    }

    const rarityWrap = document.createElement('div');
    rarityWrap.className = 'booster-rates-rarity';
    const rarityTitle = document.createElement('h3');
    rarityTitle.textContent = tt('gacha.ratesSection', 'Rarity rates');
    rarityWrap.appendChild(rarityTitle);
    const rarityGrid = document.createElement('div');
    rarityGrid.className = 'booster-rates-rarity-grid';
    (rates?.rarity_rates || []).forEach((row) => {
      const chip = document.createElement('span');
      chip.className = 'booster-rarity-chip';
      if (row.count != null) {
        chip.title = tt('gacha.rarityCount', '{n} cards', { n: row.count });
      }
      chip.innerHTML = `${row.rarity}<span class="brp">${formatRatePercent(row.percent)}</span>`;
      rarityGrid.appendChild(chip);
    });
    if (!rarityGrid.childElementCount) {
      const empty = document.createElement('p');
      empty.className = 'booster-rates-lead';
      empty.style.margin = '0';
      empty.textContent = tt('gacha.ratesEmpty', 'No rarity data for this pool.');
      rarityGrid.appendChild(empty);
    }
    rarityWrap.appendChild(rarityGrid);
    body.appendChild(rarityWrap);

    const pool = rates.pool || {};
    const poolWrap = document.createElement('div');
    poolWrap.className = 'gacha-rates-pool';
    const poolTitle = document.createElement('h3');
    poolTitle.textContent = tt('gacha.poolSection', 'Pool size');
    poolWrap.appendChild(poolTitle);
    const poolLine = document.createElement('p');
    poolLine.className = 'booster-rates-lead';
    poolLine.style.margin = '0';
    poolLine.textContent = tt(
      'gacha.poolInfo',
      '{total} cards in pool',
      { total: pool.total || 0 }
    );
    poolWrap.appendChild(poolLine);
    body.appendChild(poolWrap);

    const cardsWrap = document.createElement('div');
    cardsWrap.className = 'booster-rates-cards';
    const cardsTitle = document.createElement('h3');
    cardsTitle.textContent = tt('gacha.cardsSection', 'Card pull rates');
    cardsWrap.appendChild(cardsTitle);
    const scroll = document.createElement('div');
    scroll.className = 'booster-rates-scroll';
    const list = document.createElement('ul');
    list.className = 'booster-rates-cardlist';
    const G = global.G || {};
    const allCards = G.allCards || {};
    (rates?.cards || []).forEach((row) => {
      const li = document.createElement('li');
      li.className = 'booster-rates-card';
      const thumbBtn = document.createElement('button');
      thumbBtn.type = 'button';
      thumbBtn.className = 'booster-rates-thumb';
      thumbBtn.title = tt('gacha.viewCard', 'View card details');
      const base = allCards[row.card_no] || row;
      const cardObj = typeof global.enrichCard === 'function' ? global.enrichCard(base) : base;
      if (typeof global.appendCardFace === 'function') {
        global.appendCardFace(thumbBtn, cardObj, {
          sideways: typeof global.isLiveCard === 'function' ? global.isLiveCard(cardObj) : false,
          lazy: true,
          thumbWidth: 96,
        });
      } else {
        const img = document.createElement('img');
        img.src = cardImg(row.card_no, 96);
        img.alt = '';
        img.loading = 'lazy';
        thumbBtn.appendChild(img);
      }
      thumbBtn.addEventListener('click', (ev) => {
        ev.stopPropagation();
        if (typeof global.showCatalogCard === 'function') global.showCatalogCard(cardObj);
      });
      const meta = document.createElement('div');
      meta.className = 'booster-rates-meta';
      const idEl = document.createElement('div');
      idEl.className = 'booster-rates-id';
      idEl.textContent = row.card_no || '';
      const nameEl = document.createElement('div');
      nameEl.className = 'booster-rates-name';
      nameEl.textContent = cardLocaleNameSafe(row);
      const subEl = document.createElement('div');
      subEl.className = 'booster-rates-sub';
      subEl.textContent = row.rarity || '';
      meta.appendChild(idEl);
      meta.appendChild(nameEl);
      meta.appendChild(subEl);
      const pct = document.createElement('div');
      pct.className = 'booster-rates-pct';
      pct.textContent = formatRatePercent(row.percent);
      li.appendChild(thumbBtn);
      li.appendChild(meta);
      li.appendChild(pct);
      list.appendChild(li);
    });
    if (!list.childElementCount) {
      const empty = document.createElement('p');
      empty.className = 'booster-rates-loading';
      empty.textContent = tt('gacha.cardsEmpty', 'No cards in this pool.');
      scroll.appendChild(empty);
    } else {
      scroll.appendChild(list);
    }
    cardsWrap.appendChild(scroll);
    body.appendChild(cardsWrap);

    appendPackList(
      body,
      tt('gacha.includedSection', 'Included packs'),
      rates.packs_included,
      '—'
    );
    appendPackList(
      body,
      tt('gacha.excludedSection', 'Not included'),
      rates.packs_excluded,
      '—'
    );

    const notes = [
      tt('gacha.noteEqual', 'Each pull first rolls a scout band, then picks one card uniformly from that band.'),
      tt('gacha.notePerPull', 'Percents are chance per single Scout ×1 pull.'),
      tt('gacha.noteGuarantee', 'Scout 10+1 guarantees at least one SR+ (gold/rainbow); UR within that guarantee stays rare.'),
      tt('gacha.noteBands', 'Grey: N/R/L/PE… · Gold: P/SRE/RM… · Rainbow: SEC/LLE.'),
      tt('gacha.noteNoPrDuo', 'PR, DUO, and Premium Booster cards are not in this pool.'),
      tt('gacha.noteMellow', 'MELLOW MOMENT is not in this pool yet.'),
    ];
    const notesEl = document.createElement('ul');
    notesEl.className = 'booster-rates-notes';
    notes.forEach((text) => {
      const li = document.createElement('li');
      li.textContent = text;
      notesEl.appendChild(li);
    });
    body.appendChild(notesEl);
  }

  async function openGachaRates() {
    const body = el('gacha-rates-body');
    if (body) {
      body.replaceChildren();
      const loading = document.createElement('p');
      loading.className = 'booster-rates-loading';
      loading.textContent = tt('gacha.ratesLoading', 'Loading rates…');
      body.appendChild(loading);
    }
    const title = el('gacha-rates-title');
    if (title) title.textContent = tt('gacha.ratesTitle', 'Standard Gacha');
    const lead = el('gacha-rates-lead');
    if (lead) lead.textContent = tt('gacha.ratesLoading', 'Loading rates…');
    if (typeof global.openM === 'function') global.openM('modal-gacha-rates');
    else {
      const modal = el('modal-gacha-rates');
      if (modal) modal.classList.add('open');
    }
    try {
      if (!_ratesCache) {
        const res = await accountPost('gacha_rates', {});
        _ratesCache = res.rates || res;
      }
      renderGachaRatesModal(_ratesCache);
    } catch (e) {
      if (body) {
        body.replaceChildren();
        const err = document.createElement('p');
        err.className = 'booster-rates-loading';
        err.textContent = e.message || tt('gacha.ratesError', 'Could not load gacha rates');
        body.appendChild(err);
      }
    }
  }

  function closeGachaRates() {
    if (typeof global.closeM === 'function') global.closeM('modal-gacha-rates');
    else {
      const modal = el('modal-gacha-rates');
      if (modal) modal.classList.remove('open');
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
      syncTickets(_info.scouting_tickets);
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
    global.A._gachaLastCurrency = res.currency === 'scouting_tickets' ? 'tickets' : 'star_gems';
    syncGems(res.star_gems);
    syncTickets(res.scouting_tickets);
    if (typeof global.showPackResults === 'function') {
      const isMulti = (res.mode || _lastMode) === 'multi' || cards.length >= 10;
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

  async function openGacha(mode, currency) {
    if (_pullBusy) return;
    if (!isUnlocked()) {
      toast(tt('gacha.lockedToast', 'Gacha is not available yet.'), 2800);
      return;
    }
    const err = el('gacha-err');
    if (err) err.textContent = '';
    _pullBusy = true;
    const payWith = currency === 'tickets' ? 'tickets' : 'star_gems';
    _lastMode = mode === 'multi' ? 'multi' : 'single';
    if (global.A) {
      global.A._gachaLastMode = _lastMode;
      global.A._gachaLastCurrency = payWith;
    }
    try {
      sfx('pack_open');
      const res = await accountPost('open_gacha', { mode: _lastMode, currency: payWith });
      await loadIdolMap();
      const pulls = res.pulls || [];
      await playSpectacle(pulls);
      showGachaResults(res);
      _info = {
        ...(_info || {}),
        star_gems: res.star_gems,
        scouting_tickets: res.scouting_tickets,
      };
      syncGems(res.star_gems);
      syncTickets(res.scouting_tickets);
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
        void openGacha(global.A._gachaLastMode || 'single', global.A._gachaLastCurrency || 'star_gems');
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
    el('btn-gacha-single')?.addEventListener('click', () => openGacha('single', 'star_gems'));
    el('btn-gacha-multi')?.addEventListener('click', () => openGacha('multi', 'star_gems'));
    el('btn-gacha-ticket-single')?.addEventListener('click', () => openGacha('single', 'tickets'));
    el('btn-gacha-ticket-multi')?.addEventListener('click', () => openGacha('multi', 'tickets'));
    el('btn-gacha-rates')?.addEventListener('click', (ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      void openGachaRates();
    });
    el('btn-gacha-rates-close')?.addEventListener('click', () => closeGachaRates());
    el('modal-gacha-rates')?.addEventListener('click', (e) => {
      if (e.target === el('modal-gacha-rates')) closeGachaRates();
    });
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
