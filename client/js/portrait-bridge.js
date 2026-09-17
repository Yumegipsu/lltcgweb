/**
 * Portrait / Capacitor bridge — log sheet, Android back, offline, low-end flags.
 * Safe no-op when html.tcg-portrait-play is absent.
 */
(function (global) {
  'use strict';

  function portraitActive() {
    return !!(global.document
      && global.document.documentElement
      && (global.document.documentElement.classList.contains('tcg-portrait-play')
        || global.document.documentElement.dataset.tcgPortraitPlay === '1'));
  }

  function t(key, fallback) {
    try {
      let v;
      if (typeof global.t === 'function') v = global.t(key);
      else if (global.LLTCG_I18N && typeof global.LLTCG_I18N.t === 'function') {
        v = global.LLTCG_I18N.t(key);
      }
      if (v != null && v !== '' && v !== key) return v;
    } catch (_) { /* ignore */ }
    return fallback;
  }

  function ensureChrome() {
    if (!portraitActive() || !global.document || global.document.getElementById('portrait-log-sheet')) return;

    const offline = global.document.createElement('div');
    offline.id = 'portrait-offline-banner';
    offline.setAttribute('role', 'status');
    offline.textContent = t('mobile.offlineNeedNetwork', 'No network — reconnect to play.');
    global.document.body.appendChild(offline);

    const logSheet = global.document.createElement('div');
    logSheet.id = 'portrait-log-sheet';
    logSheet.setAttribute('aria-hidden', 'true');
    logSheet.innerHTML =
      '<div class="portrait-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="portrait-log-title">' +
      '<div class="portrait-sheet-head">' +
      '<h3 id="portrait-log-title"></h3>' +
      '<button type="button" class="btn-ghost portrait-sheet-close" id="portrait-log-close" aria-label="Close">✕</button>' +
      '</div>' +
      '<div class="portrait-log-host" id="portrait-log-host"></div>' +
      '</div>';
    global.document.body.appendChild(logSheet);
    const title = logSheet.querySelector('#portrait-log-title');
    if (title) title.textContent = t('game.gameLog', 'Game Log');

    const inspect = global.document.createElement('div');
    inspect.id = 'portrait-inspect-sheet';
    inspect.setAttribute('aria-hidden', 'true');
    inspect.innerHTML =
      '<div class="portrait-sheet-panel" role="dialog" aria-modal="true">' +
      '<div class="portrait-sheet-head">' +
      '<h3>' + t('common.preview', 'Preview') + '</h3>' +
      '<button type="button" class="btn-ghost portrait-sheet-close" id="portrait-inspect-close" aria-label="Close">✕</button>' +
      '</div>' +
      '<div id="portrait-inspect-host"></div>' +
      '</div>';
    global.document.body.appendChild(inspect);

    const foot = global.document.querySelector('#screen-game .sbfoot');
    if (foot && !global.document.getElementById('btn-portrait-log')) {
      const btn = global.document.createElement('button');
      btn.type = 'button';
      btn.id = 'btn-portrait-log';
      btn.className = 'btn-ghost';
      btn.textContent = t('mobile.openLog', 'Log');
      foot.insertBefore(btn, foot.firstChild);
    }

    ensurePhaseMenu();

    const game = global.document.getElementById('screen-game');
    if (game && !global.document.getElementById('portrait-play-scrim')) {
      const scrim = global.document.createElement('div');
      scrim.id = 'portrait-play-scrim';
      scrim.setAttribute('aria-hidden', 'true');
      game.appendChild(scrim);
      scrim.addEventListener('click', function () {
        if (typeof global.clearPlaySelection === 'function') global.clearPlaySelection();
      });
    }
  }

  /* ── Hold-to-preview: Energy zone + Success Lives ── */
  let zonePreviewPtrId = null;
  let zonePreviewBound = false;

  function ensureZonePreview() {
    const game = global.document.getElementById('screen-game');
    if (!game) return null;
    let root = global.document.getElementById('portrait-zone-preview');
    if (root) return root;
    root = global.document.createElement('div');
    root.id = 'portrait-zone-preview';
    root.className = 'pb-zone-preview';
    root.hidden = true;
    root.setAttribute('aria-hidden', 'true');
    root.innerHTML = '<div class="pb-zone-preview-rail" id="portrait-zone-preview-rail"></div>';
    game.appendChild(root);
    return root;
  }

  function hideZonePreview() {
    const root = global.document.getElementById('portrait-zone-preview');
    const rail = global.document.getElementById('portrait-zone-preview-rail');
    if (root) {
      root.hidden = true;
      root.classList.remove('show', 'mine', 'opp', 'kind-energy', 'kind-success');
      root.setAttribute('aria-hidden', 'true');
      root.style.removeProperty('--pb-prev-left');
      root.style.removeProperty('--pb-prev-top');
      root.style.removeProperty('--pb-prev-width');
      root.style.removeProperty('--pb-prev-height');
    }
    if (rail) rail.innerHTML = '';
    zonePreviewPtrId = null;
  }

  function resolvePreviewPid(side) {
    const myId = global.G?.playerId || 'p1';
    if (side === 'mine') return myId;
    return myId === 'p1' ? 'p2' : 'p1';
  }

  function positionZonePreview(side) {
    const root = ensureZonePreview();
    if (!root) return false;
    const board = global.document.getElementById('portrait-board');
    const field = board?.querySelector(side === 'mine' ? '.pb-my-field' : '.pb-opp-field');
    const hud = board?.querySelector('.pb-hud');
    if (!field) return false;
    const fr = field.getBoundingClientRect();
    const hr = hud?.getBoundingClientRect();
    const pad = 8;
    let top = fr.top + pad;
    let bottom = fr.bottom - pad;
    // Keep clear of the HUD controls the player is holding.
    if (hr) {
      if (side === 'mine' && hr.bottom > top) top = Math.min(fr.bottom - 48, hr.bottom + 6);
      if (side === 'opp' && hr.top < bottom) bottom = Math.max(fr.top + 48, hr.top - 6);
    }
    const height = Math.max(56, bottom - top);
    root.style.setProperty('--pb-prev-left', Math.round(fr.left + pad) + 'px');
    root.style.setProperty('--pb-prev-top', Math.round(top) + 'px');
    root.style.setProperty('--pb-prev-width', Math.round(Math.max(40, fr.width - pad * 2)) + 'px');
    root.style.setProperty('--pb-prev-height', Math.round(height) + 'px');
    return height >= 48;
  }

  function fillEnergyPreview(rail, zone) {
    zone.forEach(function (ec) {
      const used = typeof global.energyChipActive === 'function'
        ? !global.energyChipActive(ec)
        : ec?.active === false;
      const wrap = global.document.createElement('div');
      wrap.className = 'pb-zone-preview-card energy' + (used ? ' used' : '');
      const chip = global.document.createElement('div');
      chip.className = 'echip pb-zone-preview-echip';
      if (typeof global.syncEnergyChipFace === 'function') {
        global.syncEnergyChipFace(chip, ec, used);
      } else if (typeof global.appendEnergyChipFace === 'function') {
        global.appendEnergyChipFace(chip, ec, used);
      } else if (typeof global.appendCardFace === 'function') {
        global.appendCardFace(chip, ec, { sideways: used });
      }
      wrap.appendChild(chip);
      rail.appendChild(wrap);
    });
  }

  function fillSuccessPreview(rail, lives) {
    lives.forEach(function (card) {
      const wrap = global.document.createElement('div');
      wrap.className = 'pb-zone-preview-card success';
      const live = global.document.createElement('div');
      live.className = 'lcard live-card';
      const sideways = typeof global.liveStorageUseArtSpin === 'function'
        ? !!global.liveStorageUseArtSpin(card)
        : true;
      if (typeof global.appendCardFace === 'function') {
        global.appendCardFace(live, card, { sideways: sideways });
      }
      wrap.appendChild(live);
      rail.appendChild(wrap);
    });
  }

  function showZonePreview(side, kind) {
    if (!portraitActive()) return;
    const root = ensureZonePreview();
    const rail = global.document.getElementById('portrait-zone-preview-rail');
    if (!root || !rail) return;
    const pid = resolvePreviewPid(side);
    const p = global.G?.gameState?.players?.[pid];
    if (!p) return;
    const cards = kind === 'energy'
      ? (p.energy_zone || [])
      : (p.success_lives || []);
    if (!cards.length) return;
    if (!positionZonePreview(side)) return;
    rail.innerHTML = '';
    rail.className = 'pb-zone-preview-rail kind-' + kind;
    if (kind === 'energy') fillEnergyPreview(rail, cards);
    else fillSuccessPreview(rail, cards);
    // Keep the HUD counter honest — preview always reads live G.gameState.
    if (kind === 'success') {
      const badge = global.document.getElementById(
        side === 'mine' ? 'portrait-my-wins' : 'portrait-opp-wins'
      );
      if (badge) badge.textContent = String(cards.length) + '/3';
      if (!global.G._portraitWinCounts) global.G._portraitWinCounts = {};
      global.G._portraitWinCounts[side === 'mine' ? 'my' : 'opp'] = cards.length;
    }
    root.classList.remove('mine', 'opp', 'kind-energy', 'kind-success');
    root.classList.add('show', side === 'mine' ? 'mine' : 'opp', 'kind-' + kind);
    root.hidden = false;
    root.setAttribute('aria-hidden', 'false');
  }

  /** Prefer live match state when an anim frame has fewer Success Lives. */
  function pickWinsBoard(s) {
    const live = global.G?.gameState;
    if (!s?.players) return live || s;
    if (!live?.players || live === s) return s;
    const pid = global.G?.playerId || 'p1';
    const oppId = pid === 'p1' ? 'p2' : 'p1';
    const score = function (board) {
      return (board.players?.[pid]?.success_lives || []).length
        + (board.players?.[oppId]?.success_lives || []).length;
    };
    return score(live) > score(s) ? live : s;
  }

  function syncPortraitWinCounts(s, myId) {
    if (!portraitActive()) return;
    const board = pickWinsBoard(s);
    const live = global.G?.gameState;
    if (!board?.players && !live?.players) return;
    const pid = myId || global.G?.playerId || 'p1';
    const oppId = pid === 'p1' ? 'p2' : 'p1';
    const count = function (src, who) {
      return (src?.players?.[who]?.success_lives || []).length;
    };
    // Success Lives only rise during a match — never let an anim frame wipe the HUD.
    if (!global.G) global.G = {};
    if (!global.G._portraitWinCounts) global.G._portraitWinCounts = { my: 0, opp: 0 };
    const trusted = global.G._portraitWinCounts;
    const myN = Math.max(count(board, pid), count(live, pid), trusted.my || 0);
    const oppN = Math.max(count(board, oppId), count(live, oppId), trusted.opp || 0);
    trusted.my = myN;
    trusted.opp = oppN;
    const myWins = global.document.getElementById('portrait-my-wins');
    const oppWins = global.document.getElementById('portrait-opp-wins');
    if (myWins) myWins.textContent = String(myN) + '/3';
    if (oppWins) oppWins.textContent = String(oppN) + '/3';
  }

  function onZonePreviewDown(e, side, kind) {
    if (!portraitActive() || e.button != null && e.button !== 0) return;
    if (zonePreviewPtrId != null && e.pointerId !== zonePreviewPtrId) return;
    const pid = resolvePreviewPid(side);
    const p = global.G?.gameState?.players?.[pid];
    const cards = kind === 'energy' ? (p?.energy_zone || []) : (p?.success_lives || []);
    if (!cards.length) return;
    zonePreviewPtrId = e.pointerId;
    try { e.currentTarget.setPointerCapture(e.pointerId); } catch (_) { /* ignore */ }
    if (typeof e.preventDefault === 'function') e.preventDefault();
    showZonePreview(side, kind);
  }

  function onZonePreviewUp(e) {
    if (zonePreviewPtrId != null && e.pointerId !== zonePreviewPtrId) return;
    try {
      if (e.currentTarget && e.currentTarget.hasPointerCapture?.(e.pointerId)) {
        e.currentTarget.releasePointerCapture(e.pointerId);
      }
    } catch (_) { /* ignore */ }
    hideZonePreview();
  }

  function bindZonePreviewTarget(node, side, kind) {
    if (!node || node.dataset.pbZonePreviewBound === '1') return;
    node.dataset.pbZonePreviewBound = '1';
    node.classList.add('pb-hold-preview-target');
    node.addEventListener('pointerdown', function (e) { onZonePreviewDown(e, side, kind); });
    node.addEventListener('pointerup', onZonePreviewUp);
    node.addEventListener('pointercancel', onZonePreviewUp);
    node.addEventListener('lostpointercapture', onZonePreviewUp);
    node.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  }

  function bindZonePreviewTargets() {
    if (!portraitActive()) return;
    ensureZonePreview();
    bindZonePreviewTarget(global.document.getElementById('portrait-my-nrg-host'), 'mine', 'energy');
    bindZonePreviewTarget(global.document.getElementById('portrait-opp-nrg-host'), 'opp', 'energy');
    bindZonePreviewTarget(global.document.getElementById('portrait-my-wins'), 'mine', 'success');
    bindZonePreviewTarget(global.document.getElementById('portrait-opp-wins'), 'opp', 'success');
    if (!zonePreviewBound) {
      zonePreviewBound = true;
      global.addEventListener('blur', hideZonePreview);
      global.document.addEventListener('visibilitychange', function () {
        if (global.document.visibilityState !== 'visible') hideZonePreview();
      });
      global.addEventListener('resize', hideZonePreview, { passive: true });
    }
  }

  /* ── Right-edge Deck / Waiting Room drawers ── */
  let deckDrawersBound = false;

  function deckDrawerLabel(kind) {
    if (kind === 'deck') return t('game.mainDeck', 'Deck');
    return t('game.waitingRoom', 'WR');
  }

  function ensureDeckDrawers() {
    if (!portraitActive()) return null;
    const board = global.document.getElementById('portrait-board');
    const game = global.document.getElementById('screen-game');
    if (!board || !game) return null;
    let root = global.document.getElementById('portrait-deck-drawers');
    if (root) return root;

    root = global.document.createElement('div');
    root.id = 'portrait-deck-drawers';
    root.className = 'pb-deck-drawers';
    root.setAttribute('aria-hidden', 'false');

    function buildDrawer(side) {
      const who = side === 'mine' ? 'my' : 'opp';
      const d = global.document.createElement('div');
      d.className = 'pb-deck-drawer ' + side;
      d.dataset.side = side;
      d.innerHTML =
        '<button type="button" class="pb-deck-drawer-handle" aria-expanded="false" aria-controls="portrait-deck-tray-' + side + '" title="' +
          (side === 'mine' ? t('game.yourPiles', 'Your piles') : t('game.oppPiles', 'Opponent piles')) +
        '"><span class="pb-deck-drawer-chevron" aria-hidden="true"></span></button>' +
        '<div class="pb-deck-drawer-tray" id="portrait-deck-tray-' + side + '">' +
          '<div class="pb-deck-drawer-item deck" role="img" aria-label="' + deckDrawerLabel('deck') + '">' +
            '<div class="pb-deck-drawer-face deck-face"></div>' +
            '<span class="pb-deck-drawer-count" id="portrait-' + who + '-dn">0</span>' +
            '<span class="pb-deck-drawer-lbl">' + deckDrawerLabel('deck') + '</span>' +
          '</div>' +
          '<button type="button" class="pb-deck-drawer-item wr" data-who="' + who + '" aria-label="' + deckDrawerLabel('wr') + '">' +
            '<div class="pb-deck-drawer-face wr-face"></div>' +
            '<span class="pb-deck-drawer-count" id="portrait-' + who + '-wn">0</span>' +
            '<span class="pb-deck-drawer-lbl">' + deckDrawerLabel('wr') + '</span>' +
          '</button>' +
        '</div>';
      return d;
    }

    root.appendChild(buildDrawer('opp'));
    root.appendChild(buildDrawer('mine'));
    game.appendChild(root);
    bindDeckDrawerUi(root);
    positionDeckDrawers();
    syncDeckDrawerCounts(global.G?.gameState, global.G?.playerId);
    return root;
  }

  function removeDeckDrawers() {
    const root = global.document.getElementById('portrait-deck-drawers');
    if (!root) return false;
    root.remove();
    return true;
  }

  function closeDeckDrawer(side) {
    const root = global.document.getElementById('portrait-deck-drawers');
    if (!root) return false;
    const drawers = side
      ? [root.querySelector('.pb-deck-drawer.' + side)]
      : [...root.querySelectorAll('.pb-deck-drawer.open')];
    let closed = false;
    drawers.forEach(function (d) {
      if (!d || !d.classList.contains('open')) return;
      d.classList.remove('open');
      const handle = d.querySelector('.pb-deck-drawer-handle');
      if (handle) handle.setAttribute('aria-expanded', 'false');
      closed = true;
    });
    if (closed) positionDeckDrawers();
    return closed;
  }

  function closeAllDeckDrawers() {
    return closeDeckDrawer(null);
  }

  function openDeckDrawer(side) {
    const root = ensureDeckDrawers();
    if (!root) return;
    // Only one open at a time so the board stays readable.
    closeAllDeckDrawers();
    const d = root.querySelector('.pb-deck-drawer.' + side);
    if (!d) return;
    // Refresh from committed match state so anim frames cannot leave badges at 0.
    syncDeckDrawerCounts(global.G?.gameState, global.G?.playerId);
    positionDeckDrawers();
    d.classList.add('open');
    const handle = d.querySelector('.pb-deck-drawer-handle');
    if (handle) handle.setAttribute('aria-expanded', 'true');
  }

  function toggleDeckDrawer(side) {
    const d = global.document.querySelector('#portrait-deck-drawers .pb-deck-drawer.' + side);
    if (!d) {
      ensureDeckDrawers();
      openDeckDrawer(side);
      return;
    }
    if (d.classList.contains('open')) closeDeckDrawer(side);
    else openDeckDrawer(side);
  }

  function positionDeckDrawers() {
    const root = global.document.getElementById('portrait-deck-drawers');
    const board = global.document.getElementById('portrait-board');
    if (!root || !board) return;
    // Prefer CSS env via padding on root.
    root.style.paddingRight = 'max(0px, env(safe-area-inset-right, 0px))';

    [['mine', '.pb-my-field'], ['opp', '.pb-opp-field']].forEach(function (pair) {
      const side = pair[0];
      const sel = pair[1];
      const field = board.querySelector(sel);
      const drawer = root.querySelector('.pb-deck-drawer.' + side);
      if (!field || !drawer) return;
      const fr = field.getBoundingClientRect();
      // Height follows content: the tray keeps Deck + Waiting Room at full size
      // and the handle stays short, centered on the tray.
      drawer.style.right = '0px';
      drawer.style.height = 'auto';
      const h = drawer.offsetHeight || 56;
      const vh = global.innerHeight || global.document.documentElement.clientHeight || 0;
      const minTop = 6;
      const maxTop = Math.max(minTop, vh - h - 6);
      const top = Math.max(minTop, Math.min(fr.top + (fr.height - h) / 2, maxTop));
      drawer.style.top = Math.round(top) + 'px';
    });
  }

  function readZoneCount(player, zoneKey) {
    if (typeof global.zoneCardCount === 'function') {
      return global.zoneCardCount(player, zoneKey);
    }
    const arr = player?.[zoneKey];
    const countKey = zoneKey + '_count';
    if (Array.isArray(arr) && arr.length > 0) return arr.length;
    if (player?.[countKey] != null) return Number(player[countKey]) || 0;
    if (Array.isArray(arr)) return arr.length;
    return 0;
  }

  function pileCountSignal(board, pid) {
    const p = board?.players?.[pid];
    if (!p) return 0;
    return readZoneCount(p, 'main_deck')
      + readZoneCount(p, 'waiting_room')
      + readZoneCount(p, 'energy_deck');
  }

  /** Anim / spectacle boards sometimes strip decks; prefer the richer match state. */
  function pickPileCountBoard(s) {
    const live = global.G?.gameState;
    if (!s?.players) return live || s;
    if (!live?.players || live === s) return s;
    const pid = global.G?.playerId || 'p1';
    const oppId = pid === 'p1' ? 'p2' : 'p1';
    const liveScore = pileCountSignal(live, pid) + pileCountSignal(live, oppId);
    const sScore = pileCountSignal(s, pid) + pileCountSignal(s, oppId);
    return liveScore > sScore ? live : s;
  }

  function matBadgeCount(matId) {
    const raw = global.document.getElementById(matId)?.textContent;
    const n = parseInt(String(raw || '').trim(), 10);
    return Number.isFinite(n) ? n : null;
  }

  function boardLooksPileStubbed(board) {
    if (!board?.players) return true;
    for (const pid of ['p1', 'p2']) {
      const p = board.players[pid];
      if (!p) continue;
      const deckEmpty = !Array.isArray(p.main_deck) || p.main_deck.length === 0;
      // Real spectator/opponent filters always send main_deck_count. Missing count
      // on an otherwise-active board is a card-flight / spectacle playback stub.
      if (deckEmpty && p.main_deck_count == null) {
        if ((p.hand || []).length
            || (p.waiting_room || []).length
            || (p.live_zone || []).length
            || Object.values(p.stage || {}).some(Boolean)) {
          return true;
        }
      }
    }
    return false;
  }

  function writeDrawerCount(root, portraitId, matId, stateCount, board) {
    const node = root.querySelector('#' + portraitId)
      || global.document.getElementById(portraitId);
    if (!node) return;
    let v = Number(stateCount);
    if (!Number.isFinite(v) || v < 0) v = 0;
    if (v === 0) {
      const mat = matBadgeCount(matId);
      if (mat != null && mat > 0) v = mat;
    }
    if (!global.G) global.G = {};
    if (!global.G._portraitPileCounts) global.G._portraitPileCounts = {};
    const trusted = global.G._portraitPileCounts;
    const stubbed = boardLooksPileStubbed(board);
    if (v === 0 && stubbed && trusted[portraitId] > 0) {
      v = trusted[portraitId];
    } else if (!stubbed) {
      trusted[portraitId] = v;
    } else if (v > 0) {
      trusted[portraitId] = v;
    }
    node.textContent = String(v);
  }

  function syncDeckDrawerCounts(s, myId) {
    if (!portraitActive()) return;
    const root = ensureDeckDrawers();
    if (!root) return;
    const board = pickPileCountBoard(s);
    if (!board?.players) return;
    const pid = myId || global.G?.playerId || 'p1';
    const oppId = pid === 'p1' ? 'p2' : 'p1';
    const me = board.players[pid];
    const opp = board.players[oppId];
    writeDrawerCount(root, 'portrait-my-dn', 'my-dn', readZoneCount(me, 'main_deck'), board);
    writeDrawerCount(root, 'portrait-my-wn', 'my-wn', readZoneCount(me, 'waiting_room'), board);
    writeDrawerCount(root, 'portrait-opp-dn', 'opp-dn', readZoneCount(opp, 'main_deck'), board);
    writeDrawerCount(root, 'portrait-opp-wn', 'opp-wn', readZoneCount(opp, 'waiting_room'), board);

    // Hide drawers during Performance spectacle.
    const spectacle = !!global.document.body.classList.contains('perf-spectacle-active');
    root.classList.toggle('pb-deck-drawers-hidden', spectacle);
    if (spectacle) closeAllDeckDrawers();
    else positionDeckDrawers();
  }

  function openWaitingRoomFromDrawer(who) {
    if (typeof global.viewZone === 'function') {
      global.viewZone(who, 'waiting_room');
    }
  }

  function bindDeckDrawerUi(root) {
    if (!root || root._pbDeckDrawerBound) return;
    root._pbDeckDrawerBound = true;
    root.addEventListener('click', function (e) {
      const t = e.target;
      if (!t || !t.closest) return;
      const handle = t.closest('.pb-deck-drawer-handle');
      if (handle) {
        e.preventDefault();
        e.stopPropagation();
        const side = handle.closest('.pb-deck-drawer')?.dataset?.side;
        if (side) toggleDeckDrawer(side);
        return;
      }
      const wr = t.closest('.pb-deck-drawer-item.wr');
      if (wr) {
        e.preventDefault();
        e.stopPropagation();
        const who = wr.getAttribute('data-who') || 'my';
        openWaitingRoomFromDrawer(who);
      }
    });
  }

  function bindDeckDrawerLifecycle() {
    if (deckDrawersBound) return;
    deckDrawersBound = true;
    global.addEventListener('resize', function () {
      positionDeckDrawers();
    }, { passive: true });
    // Close when spectacle toggles
    try {
      const mo = new MutationObserver(function () {
        if (global.document.body.classList.contains('perf-spectacle-active')) {
          closeAllDeckDrawers();
          const root = global.document.getElementById('portrait-deck-drawers');
          root?.classList.add('pb-deck-drawers-hidden');
        } else {
          global.document.getElementById('portrait-deck-drawers')
            ?.classList.remove('pb-deck-drawers-hidden');
          syncDeckDrawerCounts(global.G?.gameState, global.G?.playerId);
          syncPortraitWinCounts(global.G?.gameState, global.G?.playerId);
          positionDeckDrawers();
        }
      });
      mo.observe(global.document.body, { attributes: true, attributeFilter: ['class'] });
    } catch (_) { /* ignore */ }
  }

  /** Keep the phase timer between End Main and the burger in portrait. */
  function ensurePortraitPhaseTimerSlot() {
    const actions = global.document.querySelector('#portrait-phase-row .pb-phase-actions');
    const timer = global.document.getElementById('phase-timer-wrap');
    const menuWrap = actions?.querySelector('.pb-menu-wrap');
    if (!actions || !timer || !menuWrap) return;
    if (timer.parentElement === actions && timer.nextElementSibling === menuWrap) return;
    actions.insertBefore(timer, menuWrap);
  }

  /** Soft page reload — same recovery path as refreshing the web tab. */
  function reloadPortraitApp() {
    try {
      closePortraitMenu();
    } catch (_) { /* ignore */ }
    try {
      global.location.reload();
    } catch (_) {
      try {
        global.location.href = String(global.location.href || '');
      } catch (__) { /* ignore */ }
    }
  }

  /** Inject Refresh if an older portrait menu mount is missing it. */
  function ensurePortraitRefreshMenuItem() {
    const pop = global.document.getElementById('portrait-menu-pop');
    if (!pop || global.document.getElementById('btn-portrait-menu-refresh')) return;
    const btn = global.document.createElement('button');
    btn.type = 'button';
    btn.className = 'pb-menu-item';
    btn.id = 'btn-portrait-menu-refresh';
    btn.setAttribute('role', 'menuitem');
    const logItem = global.document.getElementById('btn-portrait-menu-log');
    if (logItem && logItem.parentElement === pop) {
      pop.insertBefore(btn, logItem);
    } else {
      pop.appendChild(btn);
    }
  }

  /** Inject PiP if an older portrait menu mount is missing it. */
  function ensurePortraitPipMenuItem() {
    const pop = global.document.getElementById('portrait-menu-pop');
    if (!pop || global.document.getElementById('btn-portrait-menu-pip')) return;
    const btn = global.document.createElement('button');
    btn.type = 'button';
    btn.className = 'pb-menu-item';
    btn.id = 'btn-portrait-menu-pip';
    btn.setAttribute('role', 'menuitem');
    btn.setAttribute('aria-pressed', 'false');
    btn.hidden = true;
    const hh = global.document.getElementById('btn-portrait-menu-hidden-hands');
    const stamps = global.document.getElementById('btn-portrait-menu-stamps');
    if (hh && hh.parentElement === pop) {
      pop.insertBefore(btn, hh.nextSibling);
    } else if (stamps && stamps.parentElement === pop) {
      pop.insertBefore(btn, stamps);
    } else {
      const refreshItem = global.document.getElementById('btn-portrait-menu-refresh');
      if (refreshItem && refreshItem.parentElement === pop) pop.insertBefore(btn, refreshItem);
      else pop.appendChild(btn);
    }
  }

  /** Desktop Stamps lives in the sidebar; portrait chrome hides it — expose via burger. */
  function ensurePortraitStampMenuItem() {
    const pop = global.document.getElementById('portrait-menu-pop');
    if (!pop || global.document.getElementById('btn-portrait-menu-stamps')) return;
    const btn = global.document.createElement('button');
    btn.type = 'button';
    btn.className = 'pb-menu-item';
    btn.id = 'btn-portrait-menu-stamps';
    btn.setAttribute('role', 'menuitem');
    btn.hidden = true;
    const refreshItem = global.document.getElementById('btn-portrait-menu-refresh');
    if (refreshItem && refreshItem.parentElement === pop) {
      pop.insertBefore(btn, refreshItem);
    } else {
      const logItem = global.document.getElementById('btn-portrait-menu-log');
      if (logItem && logItem.parentElement === pop) pop.insertBefore(btn, logItem);
      else pop.appendChild(btn);
    }
  }

  /** Refresh burger items for play vs spectate (POV / hidden hands / Leave). */
  function stampMenuAvailable() {
    if (global.G && global.G.isSpectator) return false;
    const stamps = global.TCG_STAMPS;
    if (stamps && typeof stamps.isStampMatch === 'function') {
      return !!stamps.isStampMatch(global.G?.gameState);
    }
    const btn = global.document.getElementById('btn-stamp');
    return !!(btn && !btn.hidden && !btn.disabled);
  }

  function syncPortraitMenuForMode() {
    ensurePortraitRefreshMenuItem();
    ensurePortraitStampMenuItem();
    ensurePortraitPipMenuItem();
    ensurePortraitPhaseTimerSlot();
    const spectating = !!(global.G && global.G.isSpectator);
    const pov = global.document.getElementById('btn-portrait-menu-pov');
    const hh = global.document.getElementById('btn-portrait-menu-hidden-hands');
    const pip = global.document.getElementById('btn-portrait-menu-pip');
    const resignItem = global.document.getElementById('btn-portrait-menu-resign');
    const logItem = global.document.getElementById('btn-portrait-menu-log');
    const refreshItem = global.document.getElementById('btn-portrait-menu-refresh');
    const stampItem = global.document.getElementById('btn-portrait-menu-stamps');
    if (logItem) logItem.textContent = t('mobile.openLog', 'Log');
    if (refreshItem) refreshItem.textContent = t('mobile.refresh', 'Refresh');
    if (stampItem) {
      const show = stampMenuAvailable();
      stampItem.hidden = !show;
      stampItem.disabled = !show;
      stampItem.textContent = t('mobile.stamps', 'Stamps');
    }
    if (pov) {
      pov.hidden = !spectating;
      pov.textContent = t('spectate.switchPerspective', 'Switch perspective');
    }
    if (hh) {
      const locked = !!(global.G && global.G.spectateHiddenHandsLocked);
      hh.hidden = !spectating || locked;
      hh.disabled = locked;
      const on = !!(global.G && global.G.spectateHiddenHands);
      hh.textContent = locked
        ? t('spectate.hiddenHandsLocked', 'Hand fog (locked)')
        : (on
          ? t('spectate.hiddenHandsOn', 'Show hands')
          : t('spectate.hiddenHands', 'Hidden hands'));
      hh.classList.toggle('is-active', on && !locked);
      hh.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    if (pip) {
      const pipApi = global.TCGSpectatePip;
      const offered = !!(pipApi && typeof pipApi.isOffered === 'function' && pipApi.isOffered());
      const showPip = spectating && offered;
      pip.hidden = !showPip;
      pip.disabled = !showPip;
      const pipOn = !!(pipApi && pipApi.isActive && pipApi.isActive());
      pip.textContent = pipOn
        ? t('spectate.pipExit', 'Exit PiP')
        : t('spectate.pip', 'Picture-in-Picture');
      pip.classList.toggle('is-active', pipOn);
      pip.setAttribute('aria-pressed', pipOn ? 'true' : 'false');
    }
    if (resignItem) {
      if (spectating) {
        resignItem.textContent = t('spectate.leave', 'Leave');
        resignItem.classList.remove('pb-menu-danger');
      } else {
        const realResign = global.document.getElementById('btn-resign');
        resignItem.textContent = (realResign && realResign.textContent.trim())
          || t('game.resign', 'Resign');
        resignItem.classList.add('pb-menu-danger');
      }
    }
  }

  /** Burger menu beside End Main Phase — Stamps / Refresh / Log / Resign; spectate adds POV / hands. */
  function ensurePhaseMenu() {
    if (!global.document.getElementById('portrait-board')) return;
    bindZonePreviewTargets();
    ensureDeckDrawers();
    bindDeckDrawerLifecycle();
    if (global.document.getElementById('portrait-phase-row')) {
      syncPortraitMenuForMode();
      return;
    }

    const hud = global.document.querySelector('#portrait-board .pb-hud');
    const bar = global.document.getElementById('phase-action-bar');
    if (!hud) return;

    const row = global.document.createElement('div');
    row.id = 'portrait-phase-row';
    row.className = 'pb-phase-row';

    const actions = global.document.createElement('div');
    actions.className = 'pb-phase-actions';

    if (bar) {
      const parent = bar.parentElement;
      if (parent) parent.insertBefore(row, bar);
      else hud.appendChild(row);
      actions.appendChild(bar);
    } else {
      hud.appendChild(row);
    }

    const menuWrap = global.document.createElement('div');
    menuWrap.className = 'pb-menu-wrap';
    menuWrap.innerHTML =
      '<button type="button" class="pb-menu-btn" id="btn-portrait-menu" aria-label="Menu" aria-expanded="false" aria-controls="portrait-menu-pop">☰</button>' +
      '<div class="pb-menu-pop" id="portrait-menu-pop" hidden role="menu">' +
        '<button type="button" class="pb-menu-item" id="btn-portrait-menu-pov" role="menuitem" hidden></button>' +
        '<button type="button" class="pb-menu-item" id="btn-portrait-menu-hidden-hands" role="menuitem" hidden aria-pressed="false"></button>' +
        '<button type="button" class="pb-menu-item" id="btn-portrait-menu-pip" role="menuitem" hidden aria-pressed="false"></button>' +
        '<button type="button" class="pb-menu-item" id="btn-portrait-menu-stamps" role="menuitem" hidden></button>' +
        '<button type="button" class="pb-menu-item" id="btn-portrait-menu-refresh" role="menuitem"></button>' +
        '<button type="button" class="pb-menu-item" id="btn-portrait-menu-log" role="menuitem"></button>' +
        '<button type="button" class="pb-menu-item pb-menu-danger" id="btn-portrait-menu-resign" role="menuitem"></button>' +
      '</div>';
    actions.appendChild(menuWrap);
    ensurePortraitPhaseTimerSlot();
    row.appendChild(actions);
    syncPortraitMenuForMode();
  }

  function closePortraitMenu() {
    const pop = global.document.getElementById('portrait-menu-pop');
    const btn = global.document.getElementById('btn-portrait-menu');
    const hud = global.document.querySelector('.pb-hud');
    if (pop) {
      pop.hidden = true;
      pop.classList.remove('open');
    }
    if (btn) btn.setAttribute('aria-expanded', 'false');
    hud?.classList.remove('pb-menu-open');
  }

  function togglePortraitMenu() {
    const pop = global.document.getElementById('portrait-menu-pop');
    const btn = global.document.getElementById('btn-portrait-menu');
    const hud = global.document.querySelector('.pb-hud');
    if (!pop || !btn) return;
    const open = pop.hidden;
    pop.hidden = !open;
    pop.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    hud?.classList.toggle('pb-menu-open', open);
  }

  function ensurePlayPanelHost() {
    const panel = global.document.getElementById('card-hover-panel');
    const game = global.document.getElementById('screen-game');
    if (panel && game && panel.parentElement !== game) {
      game.appendChild(panel);
    }
    return panel;
  }

  function forcePlayPanelVisible(panel) {
    if (!panel) return;
    panel.classList.add('visible');
    panel.style.setProperty('display', 'flex', 'important');
    panel.style.setProperty('visibility', 'visible', 'important');
    panel.style.setProperty('opacity', '1', 'important');
    panel.style.setProperty('z-index', '93000', 'important');
    const empty = global.document.getElementById('hc-empty');
    if (empty) empty.style.display = 'none';
  }

  function clearPlayPanelForcedStyles(panel) {
    if (!panel) return;
    panel.classList.remove('visible');
    panel.style.removeProperty('display');
    panel.style.removeProperty('visibility');
    panel.style.removeProperty('opacity');
    panel.style.removeProperty('z-index');
  }

  /**
   * LIVE Phase raised cards — same top info sheet as Main member select.
   * Call after refreshLiveSelPreviewPanel / when clearing liveSel.
   */
  function syncLiveSelSheet(s, myId) {
    if (!portraitActive()) return;
    ensureChrome();
    const game = global.document.getElementById('screen-game');
    const panel = ensurePlayPanelHost();
    const liveSel = global.G?.liveSel || [];
    const hasLiveSel = liveSel.length > 0;
    const hasMemberSel = !!(global.G?.selCard);
    if (!hasLiveSel) {
      if (!hasMemberSel) {
        game?.classList.remove('portrait-play-selecting');
        clearPlayPanelForcedStyles(panel);
      }
      return;
    }
    game?.classList.add('portrait-play-selecting');
    forcePlayPanelVisible(panel);
  }

  function syncPlaySheet(card, s, myId) {
    if (!portraitActive()) return;
    ensureChrome();
    const game = global.document.getElementById('screen-game');
    const panel = ensurePlayPanelHost();
    if (!card) {
      // Keep LIVE raise sheet if cards are still raised.
      if ((global.G?.liveSel || []).length > 0) {
        syncLiveSelSheet(s, myId);
        return;
      }
      game?.classList.remove('portrait-play-selecting');
      clearPlayPanelForcedStyles(panel);
      return;
    }
    game?.classList.add('portrait-play-selecting');
    try {
      if (typeof global.showHoverCardPreview === 'function') {
        global.showHoverCardPreview(card, s || global.G?.gameState, myId || global.G?.playerId);
      } else if (typeof global.refreshHandPreviewPanel === 'function') {
        global.refreshHandPreviewPanel(card, s || global.G?.gameState, myId || global.G?.playerId);
      }
      if (typeof global.fillCardInfoActions === 'function' && global.el) {
        global.fillCardInfoActions(global.el('hc-actions'), card, s || global.G?.gameState, myId || global.G?.playerId, {
          interactive: true,
          onPlay: function () {
            if (typeof global.clearPlaySelection === 'function') global.clearPlaySelection();
          },
        });
      } else if (typeof global.fillMemberPlayActions === 'function' && global.el) {
        global.fillMemberPlayActions(global.el('hc-actions'), card, s || global.G?.gameState, myId || global.G?.playerId, {
          interactive: true,
          onPlay: function () {
            if (typeof global.clearPlaySelection === 'function') global.clearPlaySelection();
          },
        });
      }
    } catch (_) { /* ignore */ }
    forcePlayPanelVisible(panel);
  }

  function closeSheet(id) {
    const node = global.document.getElementById(id);
    if (!node) return false;
    if (!node.classList.contains('open')) return false;
    node.classList.remove('open');
    node.setAttribute('aria-hidden', 'true');
    return true;
  }

  function portraitLogMoveTarget() {
    return global.document.getElementById('match-side-feed')
      || global.document.getElementById('game-log');
  }

  function openLogSheet() {
    ensureChrome();
    const sheet = global.document.getElementById('portrait-log-sheet');
    const host = global.document.getElementById('portrait-log-host');
    const feed = portraitLogMoveTarget();
    if (!sheet || !host || !feed) return;
    // Move live log (+ chat) into sheet (put back on close)
    if (feed.parentElement !== host) {
      host._logHome = feed.parentElement;
      host.appendChild(feed);
    }
    feed.classList.add('portrait-log-active');
    const log = global.document.getElementById('game-log');
    if (log) {
      log.style.display = '';
      log.style.maxHeight = 'none';
      log.style.overflow = 'visible';
    }
    // Ensure the sheet paints after content-visibility:hidden while closed.
    sheet.style.contentVisibility = 'visible';
    sheet.classList.add('open');
    sheet.setAttribute('aria-hidden', 'false');
    try {
      if (log) log.scrollTop = log.scrollHeight;
      const chatMsgs = global.document.getElementById('match-chat-messages');
      if (chatMsgs) chatMsgs.scrollTop = chatMsgs.scrollHeight;
      host.scrollTop = host.scrollHeight;
    } catch (_) { /* ignore */ }
  }

  function closeLogSheet() {
    const host = global.document.getElementById('portrait-log-host');
    const feed = portraitLogMoveTarget();
    const sheet = global.document.getElementById('portrait-log-sheet');
    if (host && feed && host._logHome) {
      host._logHome.appendChild(feed);
      feed.classList.remove('portrait-log-active');
      const log = global.document.getElementById('game-log');
      if (log) {
        log.style.display = '';
        log.style.maxHeight = '';
        log.style.overflow = '';
      }
    }
    if (sheet) sheet.style.contentVisibility = '';
    return closeSheet('portrait-log-sheet');
  }

  function openInspectFromHover() {
    const panel = global.document.getElementById('card-hover-panel');
    const host = global.document.getElementById('portrait-inspect-host');
    const sheet = global.document.getElementById('portrait-inspect-sheet');
    if (!panel || !host || !sheet) return;
    host.innerHTML = '';
    const clone = panel.cloneNode(true);
    clone.id = 'portrait-inspect-clone';
    clone.style.display = 'block';
    host.appendChild(clone);
    sheet.classList.add('open');
    sheet.setAttribute('aria-hidden', 'false');
  }

  function anyOverlayOpen() {
    return !!(
      global.document.querySelector('.overlay.open')
      || global.document.getElementById('portrait-log-sheet')?.classList.contains('open')
      || global.document.getElementById('portrait-inspect-sheet')?.classList.contains('open')
      || (global.document.getElementById('stamp-picker') && !global.document.getElementById('stamp-picker').hidden)
    );
  }

  function handleBack() {
    if (!portraitActive()) return false;
    if (typeof global.closeSleeveShopLightbox === 'function' && global.closeSleeveShopLightbox()) {
      return true;
    }
    if (global.document.getElementById('portrait-zone-preview')?.classList.contains('show')) {
      hideZonePreview();
      return true;
    }
    if (closeAllDeckDrawers()) return true;
    if (global.document.getElementById('screen-game')?.classList.contains('portrait-play-selecting')) {
      if ((global.G?.liveSel || []).length > 0) {
        global.G.liveSel = [];
        const s = global.G?.gameState;
        const myId = global.G?.playerId;
        if (typeof global.setLiveSelMultiPreviewVisible === 'function') {
          global.setLiveSelMultiPreviewVisible(false);
        }
        if (typeof global.refreshLiveSelPreviewPanel === 'function' && s && myId) {
          global.refreshLiveSelPreviewPanel(s, myId);
        }
        if (typeof global.updateLiveSetButton === 'function' && s && myId) {
          global.updateLiveSetButton(s, myId);
        }
        if (typeof global.updateLiveScoreHud === 'function' && s && myId) {
          global.updateLiveScoreHud(s, myId);
        }
        if (typeof global.syncHandSelectionClasses === 'function' && s && myId) {
          global.syncHandSelectionClasses(s, myId);
        }
        syncLiveSelSheet(s, myId);
        return true;
      }
      if (typeof global.clearPlaySelection === 'function') global.clearPlaySelection();
      return true;
    }
    if (closeLogSheet()) return true;
    if (closeSheet('portrait-inspect-sheet')) return true;
    if (typeof global.tcgPortraitDeckHandleBack === 'function' && global.tcgPortraitDeckHandleBack()) return true;
    const menuPop = global.document.getElementById('portrait-menu-pop');
    if (menuPop && !menuPop.hidden) {
      closePortraitMenu();
      return true;
    }
    const stamp = global.document.getElementById('stamp-picker');
    if (stamp && !stamp.hidden) {
      if (global.TCG_STAMPS && typeof global.TCG_STAMPS.closePicker === 'function') {
        global.TCG_STAMPS.closePicker();
      } else {
        stamp.classList.remove('open');
        stamp.hidden = true;
      }
      return true;
    }
    const openOverlay = global.document.querySelector('.overlay.open');
    if (openOverlay) {
      openOverlay.classList.remove('open');
      openOverlay.setAttribute('aria-hidden', 'true');
      return true;
    }
    const game = global.document.getElementById('screen-game');
    if (game && game.classList.contains('active')) {
      if (global.G && global.G.isSpectator) {
        const leaveBtn = global.document.getElementById('btn-spectate-leave');
        if (leaveBtn) {
          leaveBtn.click();
          return true;
        }
        if (typeof global.leaveSpectatorMode === 'function') {
          void global.leaveSpectatorMode();
          return true;
        }
      }
      // System / edge back during a live match: soft-refresh the client so stuck
      // players can recover. Resign stays on the portrait menu / #btn-resign only.
      if (global.G && (global.G.roomId || global.G.isTutorial || global.G.replayMode)) {
        reloadPortraitApp();
        return true;
      }
    }
    return false;
  }

  function syncOffline() {
    if (!portraitActive()) return;
    ensureChrome();
    const banner = global.document.getElementById('portrait-offline-banner');
    if (!banner) return;
    const offline = global.navigator && global.navigator.onLine === false;
    banner.classList.toggle('show', offline);
  }

  function detectLowEnd() {
    if (!portraitActive()) return;
    try {
      const mem = global.navigator && global.navigator.deviceMemory;
      const reduced = global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches;
      // Only flag truly constrained devices. cores<=4 / mem<=4 was matching most
      // Android phones and silently skipped the LIVE Performance spectacle.
      const low = reduced || (typeof mem === 'number' && mem > 0 && mem <= 2);
      if (low) {
        global.document.documentElement.classList.add('tcg-low-end');
        global.TCG_LOW_END = true;
      }
    } catch (_) { /* ignore */ }
  }

  function bindUi() {
    if (!portraitActive()) return;
    ensureChrome();
    detectLowEnd();
    syncOffline();

    // Warm DNS / TLS for match API + hub (mid-range first paint).
    try {
      [['preconnect', 'https://loveliveradio.ca'],
       ['preconnect', 'https://stream.loveliveradio.ca'],
       ['dns-prefetch', 'https://loveliveradio.ca'],
       ['dns-prefetch', 'https://stream.loveliveradio.ca']].forEach(function (pair) {
        var rel = pair[0], href = pair[1];
        if (global.document.querySelector('link[rel="' + rel + '"][href="' + href + '"]')) return;
        var link = global.document.createElement('link');
        link.rel = rel;
        link.href = href;
        if (rel === 'preconnect') link.crossOrigin = 'anonymous';
        global.document.head.appendChild(link);
      });
    } catch (_) { /* ignore */ }

    global.document.getElementById('btn-portrait-log')?.addEventListener('click', openLogSheet);
    global.document.getElementById('portrait-log-close')?.addEventListener('click', closeLogSheet);
    global.document.getElementById('portrait-log-sheet')?.addEventListener('click', function (e) {
      if (e.target && e.target.id === 'portrait-log-sheet') closeLogSheet();
    });
    global.document.getElementById('portrait-inspect-close')?.addEventListener('click', function () {
      closeSheet('portrait-inspect-sheet');
    });
    global.document.getElementById('portrait-inspect-sheet')?.addEventListener('click', function (e) {
      if (e.target && e.target.id === 'portrait-inspect-sheet') closeSheet('portrait-inspect-sheet');
    });

    // Burger menu (Log / Resign) — bind once via delegation on screen-game
    const game = global.document.getElementById('screen-game');
    if (game && !game._portraitMenuBound) {
      game._portraitMenuBound = true;
      game.addEventListener('click', function (e) {
        const t = e.target;
        if (!t || !t.closest) return;
        if (t.closest('#btn-portrait-menu')) {
          e.preventDefault();
          syncPortraitMenuForMode();
          togglePortraitMenu();
          return;
        }
        if (t.closest('#btn-portrait-menu-pov')) {
          e.preventDefault();
          closePortraitMenu();
          if (typeof global.toggleSpectatorPerspective === 'function') {
            global.toggleSpectatorPerspective();
          } else {
            global.document.getElementById('btn-spectate-pov')?.click();
          }
          return;
        }
        if (t.closest('#btn-portrait-menu-hidden-hands')) {
          e.preventDefault();
          if (global.G && global.G.spectateHiddenHandsLocked) {
            closePortraitMenu();
            return;
          }
          if (typeof global.toggleSpectateHiddenHands === 'function') {
            global.toggleSpectateHiddenHands();
          } else {
            global.document.getElementById('btn-spectate-hidden-hands')?.click();
          }
          syncPortraitMenuForMode();
          return;
        }
        if (t.closest('#btn-portrait-menu-pip')) {
          e.preventDefault();
          closePortraitMenu();
          if (global.TCGSpectatePip && typeof global.TCGSpectatePip.toggle === 'function') {
            void global.TCGSpectatePip.toggle();
          } else {
            global.document.getElementById('btn-spectate-pip')?.click();
          }
          syncPortraitMenuForMode();
          return;
        }
        if (t.closest('#btn-portrait-menu-stamps')) {
          e.preventDefault();
          e.stopPropagation();
          closePortraitMenu();
          if (!stampMenuAvailable()) return;
          if (global.TCG_STAMPS && typeof global.TCG_STAMPS.openPicker === 'function') {
            global.TCG_STAMPS.openPicker();
          } else {
            global.document.getElementById('btn-stamp')?.click();
          }
          return;
        }
        if (t.closest('#btn-portrait-menu-refresh')) {
          e.preventDefault();
          reloadPortraitApp();
          return;
        }
        if (t.closest('#btn-portrait-menu-log')) {
          e.preventDefault();
          closePortraitMenu();
          openLogSheet();
          return;
        }
        if (t.closest('#btn-portrait-menu-resign')) {
          e.preventDefault();
          closePortraitMenu();
          if (global.G && global.G.isSpectator) {
            const leaveBtn = global.document.getElementById('btn-spectate-leave');
            if (leaveBtn) leaveBtn.click();
            else if (typeof global.leaveSpectatorMode === 'function') void global.leaveSpectatorMode();
            return;
          }
          const resign = global.document.getElementById('btn-resign');
          if (resign) resign.click();
          return;
        }
        if (!t.closest('.pb-menu-wrap')) closePortraitMenu();
      });
    }

    // Phase menu may mount after board reparent — retry briefly
    ensurePhaseMenu();
    bindZonePreviewTargets();
    ensureDeckDrawers();
    setTimeout(ensurePhaseMenu, 400);
    setTimeout(bindZonePreviewTargets, 400);
    setTimeout(ensureDeckDrawers, 400);
    // Long-press / select on stage cards often fills #card-hover-panel — surface as sheet once painted.
    try {
      const hover = global.document.getElementById('card-hover-panel');
      if (hover && typeof MutationObserver !== 'undefined') {
        new MutationObserver(function () {
          const img = global.document.getElementById('hc-img');
          if (img && img.classList.contains('show') && portraitActive()) {
            // Debounce: only open when game screen active and no other overlay
            if (global.document.getElementById('screen-game')?.classList.contains('active')
              && !anyOverlayOpen()) {
              // Avoid auto-spam — user opens via double-tap on preview host button if we add one later
            }
          }
        }).observe(hover, { attributes: true, subtree: true, attributeFilter: ['class', 'src'] });
      }
    } catch (_) { /* ignore */ }

    global.addEventListener('online', syncOffline);
    global.addEventListener('offline', syncOffline);

    // Capacitor Android back button
    try {
      var Cap = global.Capacitor;
      var App = Cap && Cap.Plugins && Cap.Plugins.App;
      if (App && typeof App.addListener === 'function') {
        App.addListener('backButton', function (ev) {
          if (handleBack()) {
            if (ev && typeof ev.preventDefault === 'function') ev.preventDefault();
            return;
          }
          if (App.exitApp) App.exitApp();
        });
      }
    } catch (_) { /* ignore */ }

    // Browser Escape / Android WebView back polyfill
    global.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && handleBack()) e.preventDefault();
    });
  }

  function applySafeAreaFallbacks() {
    if (!portraitActive() || !global.document) return;
    var root = global.document.documentElement;
    var probe = global.document.createElement('div');
    probe.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none;'
      + 'padding-top:env(safe-area-inset-top,0px);'
      + 'padding-bottom:env(safe-area-inset-bottom,0px);';
    global.document.body.appendChild(probe);
    var cs = global.getComputedStyle(probe);
    var topPx = parseFloat(cs.paddingTop) || 0;
    var botPx = parseFloat(cs.paddingBottom) || 0;
    probe.remove();
    // Capacitor / some Android WebViews report 0 while drawing under the status bar / cutout.
    var ua = String(global.navigator && global.navigator.userAgent || '');
    var nativeShell = /LoveCaAndroid/i.test(ua)
      || !!(global.Capacitor && (global.Capacitor.isNativePlatform
        ? global.Capacitor.isNativePlatform()
        : global.Capacitor.Plugins));
    if (nativeShell && topPx < 1) {
      root.style.setProperty('--tcg-safe-top-fallback', '36px');
    }
    if (nativeShell && botPx < 1) {
      root.style.setProperty('--tcg-safe-bottom-fallback', '16px');
    }
  }

  function boot() {
    if (!portraitActive()) return;
    applySafeAreaFallbacks();
    bindUi();
  }

  global.tcgPortraitPlayActive = global.tcgPortraitPlayActive || function () {
    return portraitActive();
  };
  global.tcgPortraitHandleBack = handleBack;
  global.tcgPortraitOpenLog = openLogSheet;
  global.tcgPortraitOpenInspect = openInspectFromHover;
  global.tcgPortraitSyncPlaySheet = syncPlaySheet;
  global.tcgPortraitSyncLiveSelSheet = syncLiveSelSheet;
  global.tcgPortraitEnsurePhaseMenu = ensurePhaseMenu;
  global.tcgPortraitSyncMenu = syncPortraitMenuForMode;
  global.tcgPortraitBindZonePreviews = bindZonePreviewTargets;
  global.tcgPortraitEnsureDeckDrawers = ensureDeckDrawers;
  global.tcgPortraitRemoveDeckDrawers = removeDeckDrawers;
  global.tcgPortraitSyncDeckDrawers = syncDeckDrawerCounts;
  global.tcgPortraitSyncWinCounts = syncPortraitWinCounts;
  global.tcgPortraitOpenDeckDrawer = openDeckDrawer;
  global.tcgPortraitCloseDeckDrawers = closeAllDeckDrawers;
  global.tcgPortraitShowZonePreview = showZonePreview;
  global.tcgPortraitHideZonePreview = hideZonePreview;

  if (global.document.readyState === 'loading') {
    global.document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(typeof window !== 'undefined' ? window : globalThis);
