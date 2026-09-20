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
  let _lastTicketCount = 1;
  let _unlocked = false;
  let _ratesCache = null;
  let _ticketQty = 1;

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

  function ticketBalance() {
    if (Number.isFinite(Number(global.A?.scoutingTickets))) {
      return Math.max(0, Number(global.A.scoutingTickets));
    }
    return Math.max(0, Number((_info && _info.scouting_tickets) || 0));
  }

  function ticketMaxSpend() {
    return Math.max(1, Math.min(20, ticketBalance() || 1));
  }

  function clampTicketQty(n) {
    const max = ticketMaxSpend();
    const v = Math.floor(Number(n) || 1);
    return Math.max(1, Math.min(max, v));
  }

  function syncTicketQtyUi() {
    _ticketQty = clampTicketQty(_ticketQty);
    const input = el('gacha-ticket-qty');
    if (input) {
      input.value = String(_ticketQty);
      input.max = String(ticketMaxSpend());
      input.min = '1';
    }
    const dec = el('btn-gacha-ticket-dec');
    const inc = el('btn-gacha-ticket-inc');
    if (dec) dec.disabled = _ticketQty <= 1;
    if (inc) inc.disabled = _ticketQty >= ticketMaxSpend();
    const have = el('gacha-ticket-have');
    if (have) {
      have.textContent = tt('gacha.ticketHave', 'You have {n}', { n: ticketBalance() });
    }
    const hint = el('gacha-ticket-qty-hint');
    if (hint) {
      hint.textContent = tt('gacha.ticketQtyHint', 'Scout ×{n} · {n} ticket(s)', { n: _ticketQty });
    }
    const confirm = el('btn-gacha-ticket-confirm');
    if (confirm) confirm.disabled = ticketBalance() < 1;
  }

  function openTicketPicker() {
    if (ticketBalance() < 1) {
      toast(tt('gacha.noTickets', 'No Scouting Tickets'), 2200);
      return;
    }
    _ticketQty = clampTicketQty(_lastTicketCount || 1);
    syncTicketQtyUi();
    if (typeof global.openM === 'function') global.openM('modal-gacha-tickets');
    else {
      const modal = el('modal-gacha-tickets');
      if (modal) modal.classList.add('open');
    }
  }

  function closeTicketPicker() {
    if (typeof global.closeM === 'function') global.closeM('modal-gacha-tickets');
    else {
      const modal = el('modal-gacha-tickets');
      if (modal) modal.classList.remove('open');
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
    const useBtn = el('btn-gacha-use-tickets');
    if (useBtn) {
      const label = useBtn.querySelector('span') || useBtn;
      label.textContent = tt('gacha.useTickets', 'Use Scouting Tickets');
      useBtn.disabled = !show;
    }
    if (el('modal-gacha-tickets')?.classList.contains('open')) {
      syncTicketQtyUi();
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
      tt('gacha.noteNewSetEmbargo', 'New standard booster packs join this pool one month after release.'),
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
      syncSimPanel(_info);
    } catch (e) {
      showScr('gacha');
      if (err) err.textContent = e.message || tt('gacha.loadError', 'Could not load gacha');
    }
  }

  function isAdminSim() {
    return !!(global.A && global.A.user && global.A.user.is_social_mod);
  }

  function syncSimPanel(info) {
    const panel = el('gacha-sim-panel');
    if (!panel) return;
    const show = isAdminSim() || !!(info && info.sim_available);
    panel.hidden = !show;
  }

  function simCount() {
    const sel = el('gacha-sim-count');
    const n = sel ? Number(sel.value) : 11;
    return Number.isFinite(n) && n > 0 ? Math.min(20, Math.floor(n)) : 11;
  }

  async function runGachaSim(force) {
    if (_pullBusy) return;
    if (!isAdminSim() && !(_info && _info.sim_available)) {
      toast(tt('gacha.simDenied', 'Admin only'), 2200);
      return;
    }
    const err = el('gacha-err');
    if (err) err.textContent = '';
    _pullBusy = true;
    const count = simCount();
    const body = {
      count,
      mode: count > 1 ? 'multi' : 'single',
      force: force === 'custom' ? 'custom' : 'random',
    };
    if (body.force === 'custom') {
      body.ur_count = Math.max(0, Number(el('gacha-sim-ur')?.value || 0));
      body.sr_count = Math.max(0, Number(el('gacha-sim-sr')?.value || 0));
    }
    try {
      sfx('screen_open');
      const res = await accountPost('gacha_sim', body);
      await loadIdolMap();
      const pulls = res.pulls || [];
      await playSpectacle(pulls);
      await showGachaResults(res, { simulated: true });
    } catch (e) {
      closeGachaOverlay();
      if (err) err.textContent = e.message || tt('gacha.pullError', 'Could not scout');
      toast(e.message || tt('gacha.pullError', 'Could not scout'), 2800);
    } finally {
      _pullBusy = false;
    }
  }

  function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  function closeGachaOverlay() {
    const ov = el('overlay-gacha-pull');
    if (global.GachaSpotlight) global.GachaSpotlight.stop();
    if (ov) {
      ov.hidden = true;
      ov.setAttribute('aria-hidden', 'true');
    }
    document.body.classList.remove('gacha-pull-open');
  }

  async function flySpotlightCardsToResults() {
    const minis = typeof global.GachaSpotlight?.getSlotMinis === 'function'
      ? global.GachaSpotlight.getSlotMinis()
      : [];
    const targets = Array.from(document.querySelectorAll('#pack-results-grid .pack-results-card'));
    if (!minis.length || !targets.length) return;
    const reduce = !!global.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
    const clones = [];
    minis.forEach((mini, i) => {
      if (!mini || !targets[i]) return;
      const from = mini.getBoundingClientRect();
      const img = mini.querySelector('img');
      const clone = document.createElement('div');
      clone.className = 'gacha-fly-clone';
      if (img && img.src) {
        const im = document.createElement('img');
        im.src = img.src;
        im.alt = '';
        clone.appendChild(im);
      }
      Object.assign(clone.style, {
        left: `${from.left}px`,
        top: `${from.top}px`,
        width: `${from.width}px`,
        height: `${from.height}px`,
        opacity: '1',
      });
      document.body.appendChild(clone);
      // Hide source so only the flying clone is visible.
      mini.style.opacity = '0';
      clones.push({ clone, target: targets[i], item: targets[i].closest('.pack-results-item') });
    });
    if (!clones.length) return;
    await sleep(reduce ? 16 : 32);
    clones.forEach(({ clone, target }) => {
      const to = target.getBoundingClientRect();
      clone.style.left = `${to.left}px`;
      clone.style.top = `${to.top}px`;
      clone.style.width = `${to.width}px`;
      clone.style.height = `${to.height}px`;
    });
    await sleep(reduce ? 40 : 580);
    clones.forEach(({ clone, item }) => {
      clone.style.opacity = '0';
      if (item) {
        item.classList.remove('is-awaiting-fly');
        item.classList.add('is-fly-landed');
      }
    });
    await sleep(reduce ? 20 : 160);
    clones.forEach(({ clone }) => clone.remove());
    sfx('screen_open');
  }

  async function playSpectacle(pulls) {
    const ov = el('overlay-gacha-pull');
    const spot = el('gacha-spot');
    if (!ov || !spot || !global.GachaSpotlight) return;

    ov.hidden = false;
    ov.setAttribute('aria-hidden', 'false');
    document.body.classList.add('gacha-pull-open');

    const results = global.GachaSpotlight.fromPulls(pulls, (p) => cardImg(p.card_no, 0));
    await global.GachaSpotlight.play(results, {
      root: spot,
      sfx,
      labels: {
        skip: tt('gacha.skip', 'Skip'),
        hint: tt('gacha.tapContinue', 'Tap to continue'),
      },
    });
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

  async function showGachaResults(res, opts) {
    const pulls = res.pulls || [];
    const cards = toResultCards(pulls);
    const simulated = !!(opts && opts.simulated) || !!res.simulated;
    global.A = global.A || {};
    global.A._fromGacha = true;
    global.A._gachaLastMode = res.mode || _lastMode;
    global.A._gachaLastCurrency = res.currency === 'scouting_tickets' ? 'tickets' : 'star_gems';
    syncGems(res.star_gems);
    syncTickets(res.scouting_tickets);
    if (typeof global.showPackResults !== 'function') {
      closeGachaOverlay();
      showScr('gacha');
      return;
    }
    const isMulti = (res.mode || _lastMode) === 'multi' || cards.length >= 10;
    const title = simulated
      ? tt('gacha.simResultsTitle', 'Gacha (simulation)')
      : tt('gacha.title', 'Gacha');
    // Build results under the overlay, then fly spotlight cards into place.
    global.showPackResults(cards, title, {
      godPack: false,
      starGemsEarned: simulated ? 0 : (res.star_gems_earned || 0),
      gachaMulti: isMulti,
      awaitingFly: true,
      skipRevealSfx: true,
    });
    if (simulated) {
      const sub = el('pack-results-sub');
      if (sub) {
        sub.textContent = tt(
          'gacha.simResultsSub',
          'Simulation only — cards were not added to your collection.'
        );
      }
    }
    await flySpotlightCardsToResults();
    closeGachaOverlay();
    const again = el('btn-pack-again');
    const another = el('btn-pack-another');
    if (again) {
      again.hidden = !!simulated;
      if (!simulated) {
        again.textContent = tt('gacha.scoutAgain', 'Scout again');
      }
    }
    if (another) {
      another.textContent = tt('gacha.backToGacha', 'Back to Gacha');
    }
  }

  async function openGacha(mode, currency, count) {
    if (_pullBusy) return;
    if (!isUnlocked()) {
      toast(tt('gacha.lockedToast', 'Gacha is not available yet.'), 2800);
      return;
    }
    const err = el('gacha-err');
    if (err) err.textContent = '';
    _pullBusy = true;
    const payWith = currency === 'tickets' ? 'tickets' : 'star_gems';
    const body = { currency: payWith };
    if (payWith === 'tickets') {
      const n = clampTicketQty(count != null ? count : (_lastTicketCount || 1));
      if (ticketBalance() < n) {
        _pullBusy = false;
        toast(tt('gacha.noTickets', 'No Scouting Tickets'), 2200);
        return;
      }
      body.count = n;
      body.mode = n >= 10 ? 'multi' : 'single';
      _lastMode = body.mode;
      _lastTicketCount = n;
    } else {
      body.mode = mode === 'multi' ? 'multi' : 'single';
      _lastMode = body.mode;
    }
    if (global.A) {
      global.A._gachaLastMode = _lastMode;
      global.A._gachaLastCurrency = payWith;
      global.A._gachaLastTicketCount = _lastTicketCount;
    }
    try {
      closeTicketPicker();
      sfx('screen_open');
      const res = await accountPost('open_gacha', body);
      await loadIdolMap();
      const pulls = res.pulls || [];
      await playSpectacle(pulls);
      await showGachaResults(res);
      _info = {
        ...(_info || {}),
        star_gems: res.star_gems,
        scouting_tickets: res.scouting_tickets,
      };
      syncGems(res.star_gems);
      syncTickets(res.scouting_tickets);
    } catch (e) {
      closeGachaOverlay();
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
        const cur = global.A._gachaLastCurrency || 'star_gems';
        if (cur === 'tickets') {
          void openGacha('single', 'tickets', global.A._gachaLastTicketCount || 1);
        } else {
          void openGacha(global.A._gachaLastMode || 'single', 'star_gems');
        }
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
    el('btn-gacha-use-tickets')?.addEventListener('click', () => openTicketPicker());
    el('btn-gacha-tickets-close')?.addEventListener('click', () => closeTicketPicker());
    el('modal-gacha-tickets')?.addEventListener('click', (e) => {
      if (e.target === el('modal-gacha-tickets')) closeTicketPicker();
    });
    el('btn-gacha-ticket-dec')?.addEventListener('click', () => {
      _ticketQty = clampTicketQty(_ticketQty - 1);
      syncTicketQtyUi();
    });
    el('btn-gacha-ticket-inc')?.addEventListener('click', () => {
      _ticketQty = clampTicketQty(_ticketQty + 1);
      syncTicketQtyUi();
    });
    el('gacha-ticket-qty')?.addEventListener('input', () => {
      _ticketQty = clampTicketQty(el('gacha-ticket-qty')?.value);
      syncTicketQtyUi();
    });
    el('btn-gacha-ticket-confirm')?.addEventListener('click', () => {
      void openGacha('single', 'tickets', _ticketQty);
    });
    el('btn-gacha-sim-random')?.addEventListener('click', () => void runGachaSim('random'));
    el('btn-gacha-sim-forced')?.addEventListener('click', () => void runGachaSim('custom'));
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
