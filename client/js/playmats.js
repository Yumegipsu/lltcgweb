/** Playmat cosmetics — catalog, deck picker + brightness, match board apply. */
(function (global) {
  'use strict';

  const DEFAULT_PLAYMAT = 'playmat.png';
  const BRIGHTNESS_MIN = 0.35;
  const BRIGHTNESS_MAX = 1.0;

  /** @type {{ id: string, name: string, src: string, group?: string, idol?: string, vol?: number|null }[]} */
  let PLAYMAT_CATALOG = [];
  let catalogLoadPromise = null;
  let lastMatchPlaymatState = null;
  let pendingCatalogReapply = false;

  function normalizePlaymatId(raw) {
    const id = String(raw == null ? '' : raw).trim().toLowerCase();
    if (!id || id === 'none' || id === 'default') return '';
    if (!/^[a-z0-9][a-z0-9._-]{0,63}$/.test(id)) return '';
    return id;
  }

  function normalizeBrightness(raw) {
    if (raw == null || raw === '') return 1.0;
    let v = Number(raw);
    if (!Number.isFinite(v)) return 1.0;
    if (v > 1 && v <= 100 && v >= 35) v = v / 100;
    if (v < BRIGHTNESS_MIN) return BRIGHTNESS_MIN;
    if (v > BRIGHTNESS_MAX) return BRIGHTNESS_MAX;
    return Math.round(v * 1000) / 1000;
  }

  function resolveAssetUrl(src) {
    const s = String(src || '').trim();
    if (!s) return '';
    // Absolute / protocol-relative / data URLs — leave alone.
    if (/^(?:[a-z][a-z0-9+.-]*:|\/\/|data:)/i.test(s)) return s;
    try {
      // Catalog paths are page-relative (e.g. assets/playmats/…). When those
      // land in CSS custom properties, url() resolves against the stylesheet
      // href (client/css/…) unless we absolutize against the document first.
      return new URL(s, global.document?.baseURI || global.location?.href || '').href;
    } catch (_) {
      return s.replace(/\\/g, '/');
    }
  }

  function cssImageUrl(src) {
    const s = resolveAssetUrl(src);
    if (!s) return '';
    return 'url("' + s.replace(/\\/g, '/').replace(/"/g, '\\"') + '")';
  }

  function getPlaymat(id) {
    const sid = normalizePlaymatId(id);
    if (!sid) return null;
    return PLAYMAT_CATALOG.find((p) => p.id === sid) || null;
  }

  function resolvePlaymatSrc(id) {
    const sid = normalizePlaymatId(id);
    if (!sid) return '';
    const mat = getPlaymat(sid);
    // Match rendering must not depend on the shop catalog request succeeding.
    // Every owned playmat asset follows this canonical path, so an equipped mat
    // can paint immediately while the catalog is still loading (or after a
    // transient catalog fetch failure).
    return mat && mat.src ? mat.src : ('assets/playmats/' + encodeURIComponent(sid) + '.webp');
  }

  function cleanPlaymatDisplayName(name) {
    let s = String(name == null ? '' : name).trim();
    if (!s) return '';
    s = s.replace(/\bbushiroad\b\s*/gi, '');
    s = s.replace(/^ラバーマットコレクション\s*(V2\s*)?/u, '');
    s = s.replace(/\s{2,}/g, ' ');
    return s.replace(/^[\s\-:]+|[\s\-:]+$/g, '');
  }

  function formatPlaymatDisplayName(mat) {
    if (!mat) return '';
    return cleanPlaymatDisplayName(mat.name || mat.id || '');
  }

  function paintFromApkCache(targets, cssVar, path) {
    try {
      const Apk = global.LLTCG_APK_ASSETS;
      if (!path || !Apk || typeof Apk.blobUrlFor !== 'function') return;
      if (typeof Apk.isNativeApk === 'function' && !Apk.isNativeApk()) return;
      const abs = /^(https?:|blob:|data:)/i.test(path)
        ? path
        : new URL(path, global.document?.baseURI || global.location?.href || '').href;
      Apk.blobUrlFor(abs).then((blob) => {
        if (!blob) return;
        const css = 'url("' + blob + '")';
        (Array.isArray(targets) ? targets : [targets]).forEach((root) => {
          if (root && root.style) root.style.setProperty(cssVar, css);
        });
      }).catch(() => {});
    } catch (e) { /* ignore */ }
  }

  function applySeatPlaymat(targets, seat, playmatId, brightness) {
    const id = normalizePlaymatId(playmatId);
    const bright = normalizeBrightness(brightness);
    const url = id ? resolvePlaymatSrc(id) : '';
    const has = !!(id && url);
    const list = Array.isArray(targets) ? targets : [targets];
    list.forEach((root) => {
      if (!root || !root.classList || !root.style) return;
      root.classList.toggle('has-' + seat + '-playmat', has);
      if (has) {
        root.style.setProperty('--' + seat + '-playmat-image', cssImageUrl(url));
        root.style.setProperty('--' + seat + '-playmat-brightness', String(bright));
      } else {
        root.style.removeProperty('--' + seat + '-playmat-image');
        root.style.removeProperty('--' + seat + '-playmat-brightness');
      }
    });
    if (has) paintFromApkCache(list, '--' + seat + '-playmat-image', url);
  }

  function clearMatchPlaymats(root) {
    const targets = [document.documentElement, root].filter(Boolean);
    targets.forEach((el) => {
      el.classList.remove('has-my-playmat', 'has-opp-playmat');
      el.style.removeProperty('--my-playmat-image');
      el.style.removeProperty('--opp-playmat-image');
      el.style.removeProperty('--my-playmat-brightness');
      el.style.removeProperty('--opp-playmat-brightness');
    });
    document.querySelectorAll('.playmat-img[data-playmat-seat]').forEach((img) => {
      img.removeAttribute('data-playmat-seat');
      img.style.removeProperty('filter');
      if (img.dataset.defaultPlaymatSrc) {
        img.src = img.dataset.defaultPlaymatSrc;
      } else {
        img.src = DEFAULT_PLAYMAT;
      }
      const frame = img.closest('.playmat-frame');
      frame?.classList.remove('has-custom-playmat');
    });
  }

  function paintPlaymatImg(sel, seat, playmatId, brightness) {
    const id = normalizePlaymatId(playmatId);
    const bright = normalizeBrightness(brightness);
    const url = id ? resolvePlaymatSrc(id) : '';
    document.querySelectorAll(sel).forEach((img) => {
      if (!img || img.tagName !== 'IMG') return;
      if (!img.dataset.defaultPlaymatSrc) {
        img.dataset.defaultPlaymatSrc = img.getAttribute('src') || DEFAULT_PLAYMAT;
      }
      img.dataset.playmatSeat = seat;
      const frame = img.closest('.playmat-frame');
      if (url) {
        img.src = resolveAssetUrl(url);
        img.style.filter = 'brightness(' + bright + ')';
        frame?.classList.add('has-custom-playmat');
        try {
          const Apk = global.LLTCG_APK_ASSETS;
          if (Apk && typeof Apk.blobUrlFor === 'function' && Apk.isNativeApk && Apk.isNativeApk()) {
            Apk.blobUrlFor(resolveAssetUrl(url)).then((blob) => {
              if (blob && img.isConnected) img.src = blob;
            }).catch(() => {});
          }
        } catch (e) { /* ignore */ }
      } else {
        img.src = img.dataset.defaultPlaymatSrc || DEFAULT_PLAYMAT;
        img.style.removeProperty('filter');
        frame?.classList.remove('has-custom-playmat');
      }
    });
  }

  function applyMatchPlaymats(state, opts) {
    const root = document.getElementById('screen-game');
    const targets = [document.documentElement, root].filter(Boolean);
    if (!targets.length) return;
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
      clearMatchPlaymats(root);
      lastMatchPlaymatState = null;
      return;
    }
    if (global.G && !global.G.isSpectator
        && (state.my_id === 'p1' || state.my_id === 'p2')
        && global.G.playerId !== state.my_id) {
      global.G.playerId = state.my_id;
      myId = state.my_id;
    }
    lastMatchPlaymatState = state;
    const oppId = myId === 'p1' ? 'p2' : 'p1';
    const myP = state.players[myId] || {};
    const oppP = state.players[oppId] || {};
    applySeatPlaymat(targets, 'my', myP.playmat_id, myP.playmat_brightness);
    applySeatPlaymat(targets, 'opp', oppP.playmat_id, oppP.playmat_brightness);
    paintPlaymatImg('.my-mat .playmat-img', 'my', myP.playmat_id, myP.playmat_brightness);
    paintPlaymatImg('.opp-mat .playmat-img', 'opp', oppP.playmat_id, oppP.playmat_brightness);

    const needsCatalog = [myP.playmat_id, oppP.playmat_id].some((raw) => {
      const id = normalizePlaymatId(raw);
      return id && !getPlaymat(id);
    });
    if (needsCatalog && !pendingCatalogReapply) {
      pendingCatalogReapply = true;
      void loadCatalog().then(() => {
        pendingCatalogReapply = false;
        if (lastMatchPlaymatState) {
          applyMatchPlaymats(lastMatchPlaymatState, {
            myId: (global.G && (global.G.playerId === 'p1' || global.G.playerId === 'p2'))
              ? global.G.playerId
              : (global.G && global.G.isSpectator ? (global.G.spectatorViewAs === 'p2' ? 'p2' : 'p1') : null),
          });
        }
      });
    }
  }

  function ownedSet() {
    const list = (global.A && Array.isArray(global.A.ownedPlaymats)) ? global.A.ownedPlaymats : [];
    return new Set(list.map(normalizePlaymatId).filter(Boolean));
  }

  let deckPlaymatPickHandler = null;
  let deckPlaymatOverlayBound = false;
  let draftPlaymatId = '';
  let draftBrightness = 1.0;

  function makePlaymatTile(id, selected, mat) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'deck-playmat-tile' + (selected ? ' is-selected' : '');
    btn.dataset.playmatId = id;
    btn.setAttribute('role', 'option');
    btn.setAttribute('aria-selected', selected ? 'true' : 'false');
    const thumb = document.createElement('span');
    thumb.className = 'deck-playmat-tile__art';
    if (mat && mat.src) {
      thumb.style.backgroundImage = cssImageUrl(mat.src);
    } else {
      thumb.classList.add('deck-playmat-tile__art--none');
      thumb.style.backgroundImage = cssImageUrl(DEFAULT_PLAYMAT);
    }
    const label = document.createElement('span');
    label.className = 'deck-playmat-tile__label';
    const tFn = typeof global.t === 'function' ? global.t : null;
    label.textContent = mat
      ? formatPlaymatDisplayName(mat)
      : ((tFn && tFn('deck.playmatNone')) || 'Default');
    btn.appendChild(thumb);
    btn.appendChild(label);
    btn.title = label.textContent;
    return btn;
  }

  function makeCurrentPlaymatButton(selectedId, mat, brightness) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'deck-playmat-current';
    btn.id = 'btn-deck-playmat-current';
    const art = document.createElement('span');
    art.className = 'deck-playmat-current__art';
    if (mat && mat.src) {
      art.style.backgroundImage = cssImageUrl(mat.src);
      art.style.filter = 'brightness(' + normalizeBrightness(brightness) + ')';
    } else {
      art.classList.add('deck-playmat-current__art--none');
      art.style.backgroundImage = cssImageUrl(DEFAULT_PLAYMAT);
    }
    const tFn = typeof global.t === 'function' ? global.t : null;
    const label = mat
      ? formatPlaymatDisplayName(mat)
      : ((tFn && tFn('deck.playmatNone')) || 'Default');
    btn.appendChild(art);
    btn.setAttribute('aria-haspopup', 'dialog');
    btn.setAttribute('aria-controls', 'overlay-deck-playmat');
    btn.setAttribute('aria-label', label);
    btn.title = label;
    btn.dataset.playmatId = selectedId || '';
    return btn;
  }

  function syncOverlayPreview() {
    const preview = document.getElementById('deck-playmat-overlay-preview');
    const range = document.getElementById('deck-playmat-brightness');
    const valueEl = document.getElementById('deck-playmat-brightness-value');
    const mat = draftPlaymatId ? getPlaymat(draftPlaymatId) : null;
    const bright = normalizeBrightness(draftBrightness);
    if (preview) {
      if (mat && mat.src) {
        preview.style.backgroundImage = cssImageUrl(mat.src);
        preview.classList.remove('is-default');
      } else {
        preview.style.backgroundImage = cssImageUrl(DEFAULT_PLAYMAT);
        preview.classList.add('is-default');
      }
      preview.style.filter = 'brightness(' + bright + ')';
    }
    if (range) {
      range.value = String(Math.round(bright * 100));
      range.disabled = !draftPlaymatId;
    }
    if (valueEl) {
      valueEl.textContent = Math.round(bright * 100) + '%';
    }
  }

  function fillDeckPlaymatOverlayGrid() {
    const grid = document.getElementById('deck-playmat-overlay-grid');
    if (!grid) return;
    const selected = normalizePlaymatId(draftPlaymatId);
    const owned = ownedSet();
    const visible = PLAYMAT_CATALOG.filter((p) => owned.has(p.id));
    grid.replaceChildren();
    grid.appendChild(makePlaymatTile('', selected === '', null));
    visible.forEach((p) => {
      grid.appendChild(makePlaymatTile(p.id, p.id === selected, p));
    });
    grid.querySelectorAll('.deck-playmat-tile').forEach((btn) => {
      btn.addEventListener('click', () => {
        draftPlaymatId = normalizePlaymatId(btn.dataset.playmatId);
        if (!draftPlaymatId) draftBrightness = 1.0;
        fillDeckPlaymatOverlayGrid();
        syncOverlayPreview();
      });
    });
    syncOverlayPreview();
  }

  function closeDeckPlaymatOverlay() {
    const overlay = document.getElementById('overlay-deck-playmat');
    if (!overlay) return;
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
  }

  function openDeckPlaymatOverlay(selectedId, brightness) {
    const overlay = document.getElementById('overlay-deck-playmat');
    if (!overlay) return;
    draftPlaymatId = normalizePlaymatId(selectedId);
    draftBrightness = draftPlaymatId ? normalizeBrightness(brightness) : 1.0;
    fillDeckPlaymatOverlayGrid();
    if (typeof global.applyI18n === 'function') global.applyI18n(overlay);
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    document.getElementById('btn-deck-playmat-overlay-close')?.focus?.();
  }

  function bindDeckPlaymatOverlayOnce() {
    if (deckPlaymatOverlayBound) return;
    const overlay = document.getElementById('overlay-deck-playmat');
    if (!overlay) return;
    deckPlaymatOverlayBound = true;
    document.getElementById('btn-deck-playmat-overlay-close')?.addEventListener('click', () => {
      closeDeckPlaymatOverlay();
    });
    document.getElementById('btn-deck-playmat-overlay-cancel')?.addEventListener('click', () => {
      closeDeckPlaymatOverlay();
    });
    document.getElementById('btn-deck-playmat-overlay-confirm')?.addEventListener('click', () => {
      const nextId = normalizePlaymatId(draftPlaymatId);
      const nextBright = nextId ? normalizeBrightness(draftBrightness) : 1.0;
      closeDeckPlaymatOverlay();
      if (typeof deckPlaymatPickHandler === 'function') {
        deckPlaymatPickHandler(nextId, nextBright);
      }
    });
    const range = document.getElementById('deck-playmat-brightness');
    range?.addEventListener('input', () => {
      if (!draftPlaymatId) return;
      draftBrightness = normalizeBrightness((Number(range.value) || 100) / 100);
      syncOverlayPreview();
    });
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeDeckPlaymatOverlay();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      if (!overlay.classList.contains('open')) return;
      closeDeckPlaymatOverlay();
    });
  }

  function renderDeckPlaymatPicker(selectedId, brightness, onPick) {
    const host = document.getElementById('deck-playmat-picker');
    if (!host) return;
    bindDeckPlaymatOverlayOnce();
    deckPlaymatPickHandler = typeof onPick === 'function' ? onPick : null;
    const selected = normalizePlaymatId(selectedId);
    const bright = selected ? normalizeBrightness(brightness) : 1.0;
    const owned = ownedSet();
    const visible = PLAYMAT_CATALOG.filter((p) => owned.has(p.id));
    const mat = selected ? getPlaymat(selected) : null;
    host.replaceChildren();
    const current = makeCurrentPlaymatButton(selected, mat, bright);
    current.addEventListener('click', () => openDeckPlaymatOverlay(selected, bright));
    host.appendChild(current);

    const hint = document.getElementById('deck-playmat-empty-hint');
    if (hint) {
      const tFn = typeof global.t === 'function' ? global.t : null;
      // Only the empty state needs words; the "owned from the shop" line was filler.
      hint.hidden = visible.length > 0;
      hint.textContent = visible.length
        ? ''
        : ((tFn && tFn('deck.playmatEmptyHint')) || 'Buy playmats in the Playmat Shop.');
    }

    const overlay = document.getElementById('overlay-deck-playmat');
    if (overlay?.classList.contains('open')) {
      draftPlaymatId = selected;
      draftBrightness = bright;
      fillDeckPlaymatOverlayGrid();
    }
  }

  function randomCatalogSrc() {
    if (!PLAYMAT_CATALOG.length) return DEFAULT_PLAYMAT;
    const row = PLAYMAT_CATALOG[Math.floor(Math.random() * PLAYMAT_CATALOG.length)];
    return (row && row.src) || DEFAULT_PLAYMAT;
  }

  async function loadCatalog() {
    if (PLAYMAT_CATALOG.length) return PLAYMAT_CATALOG;
    if (catalogLoadPromise) return catalogLoadPromise;
    catalogLoadPromise = (async () => {
      try {
        const r = await fetch('playmats_catalog.json', { cache: 'no-cache' });
        if (!r.ok) return PLAYMAT_CATALOG;
        const data = await r.json();
        const items = Array.isArray(data?.items) ? data.items : (Array.isArray(data) ? data : []);
        PLAYMAT_CATALOG = items
          .map((row) => ({
            id: normalizePlaymatId(row.id),
            name: cleanPlaymatDisplayName(row.name || row.id || ''),
            src: String(row.src || ''),
            group: String(row.group || ''),
            idol: String(row.idol || ''),
            vol: row.vol == null ? null : Number(row.vol),
            added_at: String(row.added_at || row.addedAt || ''),
          }))
          .filter((p) => p.id && p.src);
        global.LLTCG_PLAYMATS.catalog = PLAYMAT_CATALOG;
        if (lastMatchPlaymatState) applyMatchPlaymats(lastMatchPlaymatState);
      } catch (e) {
        /* keep empty */
      }
      if (!PLAYMAT_CATALOG.length) catalogLoadPromise = null;
      return PLAYMAT_CATALOG;
    })();
    return catalogLoadPromise;
  }

  global.LLTCG_PLAYMATS = {
    get catalog() { return PLAYMAT_CATALOG; },
    set catalog(v) { PLAYMAT_CATALOG = Array.isArray(v) ? v : []; },
    defaultSrc: DEFAULT_PLAYMAT,
    brightnessMin: BRIGHTNESS_MIN,
    brightnessMax: BRIGHTNESS_MAX,
    normalize: normalizePlaymatId,
    normalizeBrightness,
    get: getPlaymat,
    imageUrl: resolvePlaymatSrc,
    displayName: formatPlaymatDisplayName,
    applyMatchPlaymats,
    clearMatchPlaymats,
    renderPicker: renderDeckPlaymatPicker,
    closePickerOverlay: closeDeckPlaymatOverlay,
    loadCatalog,
    randomCatalogSrc,
  };

  void loadCatalog();
})(window);
