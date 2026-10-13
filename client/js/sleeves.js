/** Card sleeves — catalog, deck picker, and match back/peek apply. */
(function (global) {
  'use strict';

  const DEFAULT_BACK = 'lltcg-back.png';
  const CONFORM_KEY = 'tcg_sleeve_conform';

  /** @type {{ id: string, name: string, src: string, group?: string, idol?: string, orientation?: string }[]} */
  let SLEEVE_CATALOG = [];
  let catalogLoadPromise = null;

  function normalizeSleeveId(raw) {
    const id = String(raw == null ? '' : raw).trim().toLowerCase();
    if (!id || id === 'none' || id === 'default') return '';
    if (!/^[a-z0-9][a-z0-9._-]{0,63}$/.test(id)) return '';
    return id;
  }

  /** Strip vendor branding + pack-count suffixes from catalog titles. */
  function cleanSleeveDisplayName(name) {
    let s = String(name == null ? '' : name).trim();
    if (!s) return '';
    s = s.replace(/\bbushiroad\b\s*/gi, '');
    s = s.replace(/\(\s*\d+\s*[- ]?\s*packs?\s*\)/gi, '');
    s = s.replace(/\s{2,}/g, ' ');
    s = s.replace(/\s+([:,])/g, '$1');
    s = s.replace(/([:,])\s*/g, '$1 ');
    return s.replace(/^[\s\-:]+|[\s\-:]+$/g, '');
  }

  function tSleeve(key, fallback, vars) {
    const tFn = typeof global.t === 'function' ? global.t : null;
    if (!tFn) return fallback;
    const v = tFn(key, vars);
    return (v && v !== key) ? v : fallback;
  }

  function localizeIdolLabel(raw, form) {
    const s = String(raw || '').trim();
    if (!s) return s;
    const I18N = global.LLTCG_I18N;
    if (I18N && typeof I18N.idolLocaleName === 'function') {
      const loc = I18N.idolLocaleName(s, { form: form === 'full' ? 'full' : 'given' });
      if (loc) return loc;
    }
    return s;
  }

  function localizeUnitLabel(raw) {
    const s = String(raw || '').trim();
    if (!s) return s;
    const I18N = global.LLTCG_I18N;
    if (I18N && typeof I18N.unitLocaleName === 'function') {
      const loc = I18N.unitLocaleName(s);
      if (loc) return loc;
    }
    return s;
  }

  /** Localize one person / unit fragment (may contain &). Prefer full names in titles. */
  function localizeSleevePersonName(raw, form) {
    const s = String(raw || '').trim();
    if (!s) return s;
    const prefer = form === 'given' ? 'given' : 'full';
    const parts = s.split(/\s*[&＆]\s*/);
    if (parts.length > 1) {
      return parts.map((p) => localizeIdolLabel(p.trim(), prefer) || p.trim()).filter(Boolean).join(' & ');
    }
    // Group / school labels inside 『』
    const unitish = localizeUnitLabel(s);
    if (unitish && unitish !== s) return unitish;
    const seriesKey = ({
      '蓮ノ空女学院': 'sleeveShop.series.hasunosoraSchool',
      'ラブライブ！蓮ノ空女学院スクールアイドルクラブ': 'sleeveShop.series.hasunosora',
      'ラブライブ！シリーズ': 'sleeveShop.series.loveliveSeries',
    })[s];
    if (seriesKey) return tSleeve(seriesKey, s);
    return localizeIdolLabel(s, prefer) || s;
  }

  function localizeSleeveSeriesTitle(title) {
    let s = String(title || '');
    const pairs = [
      ['Love Live! Nijigasaki High School Idol Club', 'sleeveShop.series.nijigasaki'],
      ['Love Live! Hasunosora Girls\' High School Idol Club', 'sleeveShop.series.hasunosora'],
      ['Hasunosora Girls\' High School Idol Club', 'sleeveShop.series.hasunosoraShort'],
      ['Love Live! Super Star!!', 'sleeveShop.series.superstar'],
      ['Love Live! Superstar!!', 'sleeveShop.series.superstar'],
      ['Love Live! Sunshine!!', 'sleeveShop.series.sunshine'],
      ['ラブライブ！虹ヶ咲学園スクールアイドル同好会', 'sleeveShop.series.nijigasaki'],
      ['ラブライブ！蓮ノ空女学院スクールアイドルクラブ', 'sleeveShop.series.hasunosora'],
      ['蓮ノ空女学院スクールアイドルクラブ', 'sleeveShop.series.hasunosoraShort'],
      ['ラブライブ！スーパースター!!', 'sleeveShop.series.superstar'],
      ['ラブライブ！サンシャイン!!', 'sleeveShop.series.sunshine'],
      ['ラブライブ！シリーズ', 'sleeveShop.series.loveliveSeries'],
      ['Love Live! Series', 'sleeveShop.series.loveliveSeries'],
      ['蓮ノ空女学院', 'sleeveShop.series.hasunosoraSchool'],
      ['Hasunosora Girls\' High School', 'sleeveShop.series.hasunosoraSchool'],
      ['Love Live!', 'sleeveShop.series.lovelive'],
      ['ラブライブ！', 'sleeveShop.series.lovelive'],
      ['Sukufesu Series Thanksgiving', 'sleeveShop.series.sukufesuThanks'],
      ['スクフェスシリーズ感謝祭', 'sleeveShop.series.sukufesuThanks'],
      ["Mu's", 'sleeveShop.series.muse'],
      ["µ's", 'sleeveShop.series.muse'],
      ["μ's", 'sleeveShop.series.muse'],
    ];
    pairs.sort((a, b) => b[0].length - a[0].length);
    pairs.forEach(([en, key]) => {
      if (!s.includes(en)) return;
      const rep = tSleeve(key, en);
      s = s.split(en).join(rep);
    });
    // EN "Series - Person" / Part / pack notes
    s = s.replace(/(\s-\s)([A-Za-z][A-Za-z .''-]+?)(?=(\s+Part\.\d+)?(\s*\([^)]*\))?$)/, (_, dash, person) => {
      return dash + localizeSleevePersonName(person.trim(), 'full');
    });
    // JP 『name』 or 『a&b』 — keep corner brackets only for Japanese UI.
    s = s.replace(/『([^』]+)』/g, (_, inner) => {
      const person = localizeSleevePersonName(inner.trim(), 'full');
      const loc = (global.LLTCG_I18N && typeof global.LLTCG_I18N.getLocale === 'function')
        ? global.LLTCG_I18N.getLocale()
        : 'en';
      return loc === 'ja' ? ('『' + person + '』') : person;
    });
    return s;
  }

  function stripRebirthVer(s) {
    return String(s || '')
      .replace(/\s*Reバース\s*ver\.?\s*$/i, '')
      .replace(/\s*ReBirth\s*ver\.?\s*$/i, '')
      .trim();
  }

  /** Localized sleeve catalog title for shop / deck picker. Future titles keep working via patterns. */
  function formatSleeveDisplayName(sleeveOrName) {
    const rawIn = typeof sleeveOrName === 'string'
      ? sleeveOrName
      : (sleeveOrName && (sleeveOrName.name || sleeveOrName.id)) || '';
    let raw = cleanSleeveDisplayName(rawIn);
    if (!raw) return '';

    // Vendor prefixes (JP catalog leftovers)
    raw = raw.replace(/^ブシロード\s*/u, '');
    raw = raw.replace(/^Bushiroad\s*/i, '');

    const bday = raw.match(/^(.+?)\s+Birthday Visual\s+(\d{4})$/i);
    if (bday) {
      const person = localizeSleevePersonName(bday[1].trim(), 'full');
      const year = bday[2];
      return tSleeve(
        'sleeveShop.bdayName',
        person + ' Birthday Visual ' + year,
        { name: person, year }
      );
    }

    const hg = raw.match(/^Sleeve Collection HG Vol\.?\s*(\d+)\s*:?\s*(.+)$/i);
    if (hg) {
      const vol = hg[1];
      const title = localizeSleeveSeriesTitle(hg[2].trim());
      return tSleeve(
        'sleeveShop.hgVolName',
        'Sleeve Collection HG Vol.' + vol + ': ' + title,
        { vol, title }
      );
    }

    let rebirthSuffix = '';
    const stripped = stripRebirthVer(raw);
    if (stripped !== raw) {
      rebirthSuffix = ' (' + tSleeve('sleeveShop.rebirthVer', 'ReBirth ver.') + ')';
      raw = stripped;
    }

    const extraVol = raw.match(/^スリーブコレクション\s*エクストラ\s*Vol\.?\s*(\d+)\s*(.+)$/u)
      || raw.match(/^Sleeve Collection Extra Vol\.?\s*(\d+)\s*:?\s*(.+)$/i);
    if (extraVol) {
      const vol = extraVol[1];
      const title = localizeSleeveSeriesTitle(extraVol[2].trim());
      return tSleeve(
        'sleeveShop.extraVolName',
        'Sleeve Collection Extra Vol.' + vol + ': ' + title,
        { vol, title }
      ) + rebirthSuffix;
    }

    const extra = raw.match(/^スリーブコレクション\s*エクストラ\s*(.+)$/u)
      || raw.match(/^Sleeve Collection Extra\s*:?\s*(.+)$/i);
    if (extra) {
      const title = localizeSleeveSeriesTitle(extra[1].trim());
      return tSleeve(
        'sleeveShop.extraName',
        'Sleeve Collection Extra: ' + title,
        { title }
      ) + rebirthSuffix;
    }

    const deluxe = raw.match(/^Reバース\s*for\s*you\s*スリーブ\s*&\s*カード\s*デラックスセット\s*(.+)$/u)
      || raw.match(/^ReBirth\s*for\s*you\s*Sleeve\s*&\s*Card\s*Deluxe Set\s*:?\s*(.+)$/i);
    if (deluxe) {
      const title = localizeSleeveSeriesTitle(deluxe[1].trim());
      return tSleeve(
        'sleeveShop.rebirthDeluxeName',
        'ReBirth for you Sleeve & Card Deluxe Set: ' + title,
        { title }
      ) + rebirthSuffix;
    }

    return localizeSleeveSeriesTitle(raw) + rebirthSuffix;
  }

  function getSleeve(id) {
    const key = normalizeSleeveId(id);
    if (!key) return null;
    return SLEEVE_CATALOG.find((s) => s.id === key) || null;
  }

  /** Prefer catalog src; fall back to assets path if missing from catalog. */
  function resolveSleeveSrc(id) {
    const key = normalizeSleeveId(id);
    if (!key) return '';
    const s = getSleeve(key);
    if (s && s.src) return String(s.src);
    return 'assets/sleeves/' + key + '.webp';
  }

  function absoluteAssetUrl(path) {
    const p = String(path || '').trim();
    if (!p) return '';
    if (/^(https?:|data:|blob:)/i.test(p)) return p;
    // Custom properties used from sleeves.css resolve url() against that stylesheet
    // (…/client/css/), so relative "assets/sleeves/…" becomes client/css/assets/…
    // and 404s. Always expand to a document-absolute URL before setting CSS vars.
    try {
      return new URL(p, document.baseURI || location.href).href;
    } catch (e) {
      return p;
    }
  }

  function sleeveImageUrl(id) {
    return resolveSleeveSrc(id);
  }

  function cssImageUrl(path) {
    const abs = absoluteAssetUrl(path);
    if (!abs) return '';
    return 'url("' + abs.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '")';
  }

  function sleeveConformEnabled() {
    try {
      return localStorage.getItem(CONFORM_KEY) === '1';
    } catch (e) {
      return false;
    }
  }

  function syncSleeveConformSetting() {
    document.documentElement.classList.toggle('sleeve-conform', sleeveConformEnabled());
    const chk = document.getElementById('chk-sleeve-conform');
    if (chk) chk.checked = sleeveConformEnabled();
  }

  function initSleeveConformSetting() {
    try {
      if (localStorage.getItem(CONFORM_KEY) === null) localStorage.setItem(CONFORM_KEY, '0');
    } catch (e) {}
    syncSleeveConformSetting();
    const chk = document.getElementById('chk-sleeve-conform');
    if (chk && !chk.dataset.sleeveBound) {
      chk.dataset.sleeveBound = '1';
      chk.addEventListener('change', () => {
        try { localStorage.setItem(CONFORM_KEY, chk.checked ? '1' : '0'); } catch (e) {}
        syncSleeveConformSetting();
      });
    }
  }

  function isLandscapeSleeve(sleeveOrId) {
    const s = typeof sleeveOrId === 'string' ? getSleeve(sleeveOrId) : sleeveOrId;
    return !!(s && String(s.orientation || '').toLowerCase() === 'landscape');
  }

  let lastMatchSleeveState = null;
  let pendingCatalogReapply = false;

  function paintFromApkCache(targets, cssVar, path) {
    try {
      const Apk = global.LLTCG_APK_ASSETS;
      if (!path || !Apk || typeof Apk.blobUrlFor !== 'function') return;
      if (typeof Apk.isNativeApk === 'function' && !Apk.isNativeApk()) return;
      const abs = /^(https?:|blob:|data:)/i.test(path)
        ? path
        : new URL(path, document.baseURI || location.href).href;
      Apk.blobUrlFor(abs).then((blob) => {
        if (!blob) return;
        const css = 'url("' + blob + '")';
        (Array.isArray(targets) ? targets : [targets]).forEach((root) => {
          if (root && root.style) root.style.setProperty(cssVar, css);
        });
      }).catch(() => {});
    } catch (e) { /* ignore */ }
  }

  function applySeatSleeve(targets, seat, sleeveId) {
    const id = normalizeSleeveId(sleeveId);
    const sleeve = getSleeve(id);
    const url = id ? resolveSleeveSrc(id) : DEFAULT_BACK;
    const has = true;
    const landscape = !!(id && isLandscapeSleeve(sleeve || id));
    const list = Array.isArray(targets) ? targets : [targets];
    list.forEach((root) => {
      if (!root || !root.classList) return;
      root.classList.toggle('has-' + seat + '-sleeve', has);
      root.classList.toggle('has-' + seat + '-sleeve-landscape', landscape);
      root.style.setProperty('--' + seat + '-sleeve-image', cssImageUrl(url));
    });
    paintFromApkCache(list, '--' + seat + '-sleeve-image', url);
  }

  function clearMatchSleeves(root) {
    const targets = [document.documentElement, root].filter(Boolean);
    targets.forEach((el) => {
      el.classList.remove('has-my-sleeve', 'has-opp-sleeve', 'has-my-sleeve-landscape', 'has-opp-sleeve-landscape');
      el.style.removeProperty('--my-sleeve-image');
      el.style.removeProperty('--opp-sleeve-image');
    });
    document.querySelectorAll('[data-sleeve-seat]').forEach((node) => {
      node.style.removeProperty('--my-sleeve-image');
      node.style.removeProperty('--opp-sleeve-image');
      delete node.dataset.sleeveSeat;
    });
  }

  function applyMatchSleeves(state, opts) {
    const root = document.getElementById('screen-game');
    const targets = [document.documentElement, root].filter(Boolean);
    if (!targets.length) return;
    // Prefer the seat used for this board paint, then server my_id (token seat), then G.
    // Never derive "my" from a stale G.playerId when state.my_id disagrees — that would
    // put the opponent's sleeve on the bottom mat (and vice versa).
    let myId = opts && (opts.myId === 'p1' || opts.myId === 'p2') ? opts.myId : null;
    if (!myId && state && (state.my_id === 'p1' || state.my_id === 'p2')) {
      myId = state.my_id;
    }
    if (!myId && global.G && (global.G.playerId === 'p1' || global.G.playerId === 'p2')) {
      myId = global.G.playerId;
    }
    if (!myId && global.G && global.G.isSpectator) {
      const view = global.G.spectatorViewAs;
      myId = (view === 'p1' || view === 'p2') ? view : 'p1';
    }
    if (!state || !state.players || !myId) {
      clearMatchSleeves(root);
      lastMatchSleeveState = null;
      return;
    }
    if (global.G && !global.G.isSpectator
        && (state.my_id === 'p1' || state.my_id === 'p2')
        && global.G.playerId !== state.my_id) {
      global.G.playerId = state.my_id;
      myId = state.my_id;
    }
    lastMatchSleeveState = state;
    const oppId = myId === 'p1' ? 'p2' : 'p1';
    const mySleeve = state.players[myId] && state.players[myId].sleeve_id;
    const oppSleeve = state.players[oppId] && state.players[oppId].sleeve_id;
    applySeatSleeve(targets, 'my', mySleeve);
    applySeatSleeve(targets, 'opp', oppSleeve);
    // Bind vars onto seat roots so mat/hand CSS cannot pick up a swapped html var.
    const myUrl = cssImageUrl(resolveSleeveSrc(mySleeve) || DEFAULT_BACK);
    const oppUrl = cssImageUrl(resolveSleeveSrc(oppSleeve) || DEFAULT_BACK);
    const bindSeat = (sel, seat, url) => {
      document.querySelectorAll(sel).forEach((node) => {
        if (!node || !node.style) return;
        node.dataset.sleeveSeat = seat;
        if (url) node.style.setProperty('--' + seat + '-sleeve-image', url);
        else node.style.removeProperty('--' + seat + '-sleeve-image');
        const raw = seat === 'my' ? (resolveSleeveSrc(mySleeve) || DEFAULT_BACK) : (resolveSleeveSrc(oppSleeve) || DEFAULT_BACK);
        paintFromApkCache([node], '--' + seat + '-sleeve-image', raw);
      });
    };
    bindSeat('.my-mat, .my-hand-strip, #perf-col-mine', 'my', myUrl);
    bindSeat('.opp-mat, .opp-hand-strip, #perf-col-opp', 'opp', oppUrl);
    // Catalog may still be loading on first paint — re-apply when items arrive.
    const needsCatalog = [mySleeve, oppSleeve].some((raw) => {
      const id = normalizeSleeveId(raw);
      return id && !getSleeve(id);
    });
    if (needsCatalog && !pendingCatalogReapply) {
      pendingCatalogReapply = true;
      void loadCatalog().then(() => {
        pendingCatalogReapply = false;
        if (lastMatchSleeveState) {
          applyMatchSleeves(lastMatchSleeveState, {
            myId: (global.G && (global.G.playerId === 'p1' || global.G.playerId === 'p2'))
              ? global.G.playerId
              : (global.G && global.G.isSpectator ? (global.G.spectatorViewAs === 'p2' ? 'p2' : 'p1') : null),
          });
        }
      });
    }
  }

  function makeSleeveTile(id, selected, sleeve) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'deck-sleeve-tile' + (selected ? ' is-selected' : '');
    btn.dataset.sleeveId = id;
    btn.setAttribute('role', 'option');
    btn.setAttribute('aria-selected', selected ? 'true' : 'false');
    const thumb = document.createElement('span');
    thumb.className = 'deck-sleeve-tile__art';
    if (sleeve && sleeve.src) {
      thumb.style.backgroundImage = cssImageUrl(sleeve.src);
      if (isLandscapeSleeve(sleeve)) thumb.classList.add('is-landscape');
    } else {
      thumb.classList.add('deck-sleeve-tile__art--none');
      thumb.style.backgroundImage = cssImageUrl(DEFAULT_BACK);
    }
    const label = document.createElement('span');
    label.className = 'deck-sleeve-tile__label';
    const tFn = typeof global.t === 'function' ? global.t : null;
    label.textContent = sleeve
      ? formatSleeveDisplayName(sleeve)
      : ((tFn && tFn('deck.sleeveNone')) || 'None');
    btn.appendChild(thumb);
    btn.appendChild(label);
    btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
    btn.title = label.textContent;
    return btn;
  }

  function makeCurrentSleeveButton(selectedId, sleeve) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'deck-sleeve-current';
    btn.id = 'btn-deck-sleeve-current';
    const art = document.createElement('span');
    art.className = 'deck-sleeve-current__art';
    if (sleeve && sleeve.src) {
      art.style.backgroundImage = cssImageUrl(sleeve.src);
      if (isLandscapeSleeve(sleeve)) art.classList.add('is-landscape');
    } else {
      art.classList.add('deck-sleeve-current__art--none');
      art.style.backgroundImage = cssImageUrl(DEFAULT_BACK);
    }
    const tFn = typeof global.t === 'function' ? global.t : null;
    const label = sleeve
      ? formatSleeveDisplayName(sleeve)
      : ((tFn && tFn('deck.sleeveNone')) || 'None');
    btn.appendChild(art);
    btn.setAttribute('aria-haspopup', 'dialog');
    btn.setAttribute('aria-controls', 'overlay-deck-sleeve');
    btn.setAttribute('aria-label', label);
    btn.title = label;
    btn.dataset.sleeveId = selectedId || '';
    return btn;
  }

  function ownedSet() {
    const list = (global.A && Array.isArray(global.A.ownedSleeves)) ? global.A.ownedSleeves : [];
    return new Set(list.map(normalizeSleeveId).filter(Boolean));
  }

  let deckSleevePickHandler = null;
  let deckSleeveOverlayBound = false;

  function closeDeckSleeveOverlay() {
    const overlay = document.getElementById('overlay-deck-sleeve');
    if (!overlay) return;
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
  }

  function fillDeckSleeveOverlayGrid(selectedId) {
    const grid = document.getElementById('deck-sleeve-overlay-grid');
    if (!grid) return;
    const selected = normalizeSleeveId(selectedId);
    const owned = ownedSet();
    const visible = SLEEVE_CATALOG.filter((s) => owned.has(s.id));
    grid.replaceChildren();
    grid.appendChild(makeSleeveTile('', selected === '', null));
    visible.forEach((s) => {
      grid.appendChild(makeSleeveTile(s.id, s.id === selected, s));
    });
    grid.querySelectorAll('.deck-sleeve-tile').forEach((btn) => {
      btn.addEventListener('click', () => {
        const next = normalizeSleeveId(btn.dataset.sleeveId);
        closeDeckSleeveOverlay();
        if (typeof deckSleevePickHandler === 'function') deckSleevePickHandler(next);
      });
    });
  }

  function openDeckSleeveOverlay(selectedId) {
    const overlay = document.getElementById('overlay-deck-sleeve');
    if (!overlay) return;
    fillDeckSleeveOverlayGrid(selectedId);
    if (typeof global.applyI18n === 'function') global.applyI18n(overlay);
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    const closeBtn = document.getElementById('btn-deck-sleeve-overlay-close');
    closeBtn?.focus?.();
  }

  function bindDeckSleeveOverlayOnce() {
    if (deckSleeveOverlayBound) return;
    const overlay = document.getElementById('overlay-deck-sleeve');
    if (!overlay) return;
    deckSleeveOverlayBound = true;
    document.getElementById('btn-deck-sleeve-overlay-close')?.addEventListener('click', () => {
      closeDeckSleeveOverlay();
    });
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeDeckSleeveOverlay();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      if (!overlay.classList.contains('open')) return;
      closeDeckSleeveOverlay();
    });
  }

  function renderDeckSleevePicker(selectedId, onPick) {
    const host = document.getElementById('deck-sleeve-picker');
    if (!host) return;
    bindDeckSleeveOverlayOnce();
    deckSleevePickHandler = typeof onPick === 'function' ? onPick : null;
    const selected = normalizeSleeveId(selectedId);
    const owned = ownedSet();
    const visible = SLEEVE_CATALOG.filter((s) => owned.has(s.id));
    const sleeve = selected ? getSleeve(selected) : null;
    host.replaceChildren();
    const current = makeCurrentSleeveButton(selected, sleeve);
    current.addEventListener('click', () => openDeckSleeveOverlay(selected));
    host.appendChild(current);

    const hint = document.getElementById('deck-sleeve-empty-hint');
    if (hint) {
      const tFn = typeof global.t === 'function' ? global.t : null;
      // Only the empty state needs words; the "owned from the shop" line was filler.
      hint.hidden = visible.length > 0;
      hint.textContent = visible.length
        ? ''
        : ((tFn && tFn('deck.sleeveEmptyHint')) || 'Buy sleeves in the Sleeve Shop.');
    }

    // Keep overlay selection in sync if it is already open.
    const overlay = document.getElementById('overlay-deck-sleeve');
    if (overlay?.classList.contains('open')) fillDeckSleeveOverlayGrid(selected);
  }

  async function loadCatalog() {
    if (SLEEVE_CATALOG.length) return SLEEVE_CATALOG;
    if (catalogLoadPromise) return catalogLoadPromise;
    catalogLoadPromise = (async () => {
      try {
        const r = await fetch('sleeves_catalog.json', { cache: 'no-cache' });
        if (!r.ok) return SLEEVE_CATALOG;
        const data = await r.json();
        const items = Array.isArray(data?.items) ? data.items : (Array.isArray(data) ? data : []);
        SLEEVE_CATALOG = items
          .map((row) => ({
            id: normalizeSleeveId(row.id),
            name: cleanSleeveDisplayName(row.name || row.id || ''),
            src: String(row.src || ''),
            group: String(row.group || ''),
            idol: String(row.idol || ''),
            orientation: String(row.orientation || 'portrait').toLowerCase() === 'landscape'
              ? 'landscape'
              : 'portrait',
          }))
          .filter((s) => s.id && s.src);
        global.LLTCG_SLEEVES.catalog = SLEEVE_CATALOG;
        if (lastMatchSleeveState) applyMatchSleeves(lastMatchSleeveState);
      } catch (e) {
        /* keep empty */
      }
      if (!SLEEVE_CATALOG.length) catalogLoadPromise = null;
      return SLEEVE_CATALOG;
    })();
    return catalogLoadPromise;
  }

  /**
   * Slide sleeve art over default card back, then shine.
   * @param {string} sleeveId
   * @param {{ force?: boolean }} [opts]
   */
  function playEquipIntro(sleeveId, opts) {
    const id = normalizeSleeveId(sleeveId);
    const sleeve = getSleeve(id);
    if (!id || !sleeve?.src) return Promise.resolve();
    if (typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return Promise.resolve();
    }
    return new Promise((resolve) => {
      let overlay = document.getElementById('sleeve-equip-intro');
      if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'sleeve-equip-intro';
        overlay.className = 'sleeve-equip-intro';
        overlay.innerHTML = '<div class="sleeve-equip-intro__stage">'
          + '<div class="sleeve-equip-intro__back"></div>'
          + '<div class="sleeve-equip-intro__sleeve"></div>'
          + '<div class="sleeve-equip-intro__shine"></div>'
          + '</div>';
        document.body.appendChild(overlay);
      }
      const back = overlay.querySelector('.sleeve-equip-intro__back');
      const sleeveEl = overlay.querySelector('.sleeve-equip-intro__sleeve');
      if (back) back.style.backgroundImage = cssImageUrl(DEFAULT_BACK);
      if (sleeveEl) {
        sleeveEl.style.backgroundImage = cssImageUrl(sleeve.src);
        sleeveEl.classList.toggle('is-landscape', isLandscapeSleeve(sleeve));
        sleeveEl.classList.remove('is-in');
      }
      overlay.classList.add('is-open');
      requestAnimationFrame(() => {
        sleeveEl?.classList.add('is-in');
        overlay.classList.add('is-shine');
      });
      const done = () => {
        overlay.classList.remove('is-open', 'is-shine');
        sleeveEl?.classList.remove('is-in');
        resolve();
      };
      setTimeout(done, 1400);
    });
  }

  global.LLTCG_SLEEVES = {
    get catalog() { return SLEEVE_CATALOG; },
    set catalog(v) { SLEEVE_CATALOG = Array.isArray(v) ? v : []; },
    defaultBack: DEFAULT_BACK,
    normalize: normalizeSleeveId,
    get: getSleeve,
    imageUrl: sleeveImageUrl,
    isLandscape: isLandscapeSleeve,
    displayName: formatSleeveDisplayName,
    idolName: localizeIdolLabel,
    unitName: localizeUnitLabel,
    applyMatchSleeves,
    clearMatchSleeves,
    renderPicker: renderDeckSleevePicker,
    closePickerOverlay: closeDeckSleeveOverlay,
    loadCatalog,
    playEquipIntro,
    conformEnabled: sleeveConformEnabled,
    syncConform: syncSleeveConformSetting,
    initConform: initSleeveConformSetting,
  };

  void loadCatalog();
})(window);
