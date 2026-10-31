/**
 * Portrait board mount — reparents existing game nodes into a mockup CSS grid.
 * Keeps board-render IDs intact; only changes DOM ancestry under #screen-game.
 * Supports unmount so web portrait auto-mode can restore landscape DOM on rotate.
 */
(function (global) {
  'use strict';

  /** @type {Map<string, { parent: Element, next: ChildNode|null }>} */
  const homeMap = new Map();
  let homeSeq = 0;
  let resizeBound = false;

  function portraitActive() {
    return !!(global.document?.documentElement?.classList.contains('tcg-portrait-play')
      || global.document?.documentElement?.dataset?.tcgPortraitPlay === '1');
  }

  function el(id) {
    return global.document.getElementById(id);
  }

  function wrap(cls) {
    const d = global.document.createElement('div');
    d.className = cls;
    return d;
  }

  function labelZone(node, text) {
    if (!node) return;
    node.dataset.portraitLabel = text;
  }

  function matMountNode(id) {
    const node = el(id);
    if (!node) return null;
    // Overhaul wraps #game-stage in <ll-stage-board> (display:inline by default) —
    // move the host so the field fills the grid cell.
    return node.closest('ll-stage-board') || node;
  }

  function rememberHome(node) {
    if (!node || node.dataset.portraitHomeId) return;
    const id = 'ph' + (++homeSeq);
    node.dataset.portraitHomeId = id;
    homeMap.set(id, {
      parent: node.parentElement,
      next: node.nextSibling,
    });
  }

  function restoreHome(node) {
    if (!node) return;
    const id = node.dataset.portraitHomeId;
    if (!id) return;
    const home = homeMap.get(id);
    delete node.dataset.portraitHomeId;
    homeMap.delete(id);
    if (!home?.parent || !home.parent.isConnected) return;
    try {
      if (home.next && home.next.parentNode === home.parent) {
        home.parent.insertBefore(node, home.next);
      } else {
        home.parent.appendChild(node);
      }
    } catch (_) {
      try { home.parent.appendChild(node); } catch (__) { /* ignore */ }
    }
  }

  function placeHudCornerNames() {
    const board = el('portrait-board');
    const hud = board?.querySelector('.pb-hud');
    if (!hud) return;
    const myHost = el('portrait-my-name-host');
    const oppHost = el('portrait-opp-name-host');
    // Anchor names to the HUD panel corners (you: bottom-left, opp: top-right).
    if (myHost && myHost.parentElement !== hud) hud.appendChild(myHost);
    if (oppHost && oppHost.parentElement !== hud) hud.appendChild(oppHost);
    const myName = el('my-name');
    const oppName = el('opp-name');
    if (myName && myHost && myName.parentElement !== myHost) myHost.appendChild(myName);
    if (oppName && oppHost && oppName.parentElement !== oppHost) oppHost.appendChild(oppName);
  }

  function ensureFieldHosts() {
    const board = el('portrait-board');
    if (!board) return false;
    const myField = board.querySelector('.pb-my-field');
    const oppField = board.querySelector('.pb-opp-field');
    const myHand = board.querySelector('.pb-my-hand');
    const myMat = matMountNode('game-stage');
    const oppMat = el('opp-stage');
    const handStrip = document.querySelector('#portrait-board .my-hand-strip')
      || document.querySelector('.my-hand-strip');
    if (myField && myMat && myMat.parentElement !== myField) myField.appendChild(myMat);
    if (oppField && oppMat && oppMat.parentElement !== oppField) oppField.appendChild(oppMat);
    if (myHand && handStrip && handStrip.parentElement !== myHand) myHand.appendChild(handStrip);
    // Info sheet must leave the hidden landscape wrap or the scrim shows with no panel.
    const cardHover = el('card-hover-panel');
    const game = el('screen-game');
    if (cardHover && game && cardHover.parentElement !== game) game.appendChild(cardHover);
    placeHudCornerNames();
    return true;
  }

  function mount() {
    if (!portraitActive()) return false;
    if (el('portrait-board')) {
      ensureFieldHosts();
      syncHandVars();
      try {
        if (typeof global.tcgPortraitEnsurePhaseMenu === 'function') {
          global.tcgPortraitEnsurePhaseMenu();
        }
        if (typeof global.tcgPortraitBindZonePreviews === 'function') {
          global.tcgPortraitBindZonePreviews();
        }
        if (typeof global.tcgPortraitEnsureDeckDrawers === 'function') {
          global.tcgPortraitEnsureDeckDrawers();
        }
      } catch (_) { /* ignore */ }
      return true;
    }

    const viewport = el('game-viewport-frame');
    const wide = viewport?.querySelector?.('.game-wide-wrap');
    if (!viewport || !wide) return false;

    const oppHand = wide.querySelector('.opp-hand-strip');
    const oppMat = el('opp-stage');
    const myMat = matMountNode('game-stage');
    const myHand = wide.querySelector('.my-hand-strip');
    const phase = wide.querySelector('.phase-panel');
    const stats = wide.querySelector('.players-stats-panel');
    const stageBoard = wide.querySelector('.stage-board-panel');
    const phaseBar = el('phase-action-bar');
    const sbfoot = wide.querySelector('.sbfoot');
    const playCost = el('play-cost-hud');
    const liveScore = el('live-score-hud');
    const cardHover = el('card-hover-panel');
    const myNrgPill = wide.querySelector('.player-stat-strip.mine .cpill.nrg');
    const oppNrgPill = wide.querySelector('.player-stat-strip.opp .cpill.nrg');
    const myName = el('my-name');
    const oppName = el('opp-name');

    if (!oppHand || !oppMat || !myMat || !myHand) return false;

    [
      oppHand, oppMat, myMat, myHand, phase, stats, stageBoard, phaseBar, sbfoot,
      playCost, liveScore, cardHover, myNrgPill, oppNrgPill, myName, oppName,
    ].forEach(rememberHome);

    const board = wrap('portrait-board');
    board.id = 'portrait-board';

    const oppHandSec = wrap('pb-opp-hand');
    const oppFieldSec = wrap('pb-opp-field');
    const hudSec = wrap('pb-hud');
    const myFieldSec = wrap('pb-my-field');
    const myHandSec = wrap('pb-my-hand');
    const chromeSec = wrap('pb-chrome');

    // Mockup top HUD: [energy][wins] / names | phase | [wins][energy] / names
    const wins = wrap('pb-wins-row');
    wins.innerHTML =
      '<div class="pb-side mine">' +
        '<div class="pb-side-top">' +
          '<span class="pb-nrg" id="portrait-my-nrg-host"></span>' +
          '<span class="pb-win-val" id="portrait-my-wins">0/3</span>' +
        '</div>' +
        '<div class="pb-name-host" id="portrait-my-name-host"></div>' +
      '</div>' +
      '<div class="pb-win-center" id="portrait-hud-center"></div>' +
      '<div class="pb-side opp">' +
        '<div class="pb-side-top">' +
          '<span class="pb-win-val" id="portrait-opp-wins">0/3</span>' +
          '<span class="pb-nrg" id="portrait-opp-nrg-host"></span>' +
        '</div>' +
        '<div class="pb-name-host" id="portrait-opp-name-host"></div>' +
      '</div>';

    oppHandSec.appendChild(oppHand);
    oppFieldSec.appendChild(oppMat);

    hudSec.appendChild(wins);
    const myHost = wins.querySelector('#portrait-my-nrg-host');
    const oppHost = wins.querySelector('#portrait-opp-nrg-host');
    if (myNrgPill && myHost) myHost.appendChild(myNrgPill);
    if (oppNrgPill && oppHost) oppHost.appendChild(oppNrgPill);

    if (myName) wins.querySelector('#portrait-my-name-host')?.appendChild(myName);
    if (oppName) wins.querySelector('#portrait-opp-name-host')?.appendChild(oppName);

    if (phase) wins.querySelector('#portrait-hud-center')?.appendChild(phase);
    if (stats) {
      stats.classList.add('portrait-stats-shell');
      hudSec.appendChild(stats);
    }
    if (stageBoard) hudSec.appendChild(stageBoard);
    if (phaseBar) hudSec.appendChild(phaseBar);
    if (playCost) hudSec.appendChild(playCost);
    if (liveScore) hudSec.appendChild(liveScore);
    placeHudCornerNames();

    myFieldSec.appendChild(myMat);
    myHandSec.appendChild(myHand);
    if (sbfoot) chromeSec.appendChild(sbfoot);

    if (cardHover && cardHover.parentElement !== el('screen-game')) {
      el('screen-game')?.appendChild(cardHover);
    }

    board.appendChild(oppHandSec);
    board.appendChild(oppFieldSec);
    board.appendChild(hudSec);
    board.appendChild(myFieldSec);
    board.appendChild(myHandSec);
    board.appendChild(chromeSec);

    viewport.insertBefore(board, wide);
    wide.classList.add('portrait-wide-hidden');
    wide.setAttribute('aria-hidden', 'true');

    // Zone labels (mockup copy, shortened for phone)
    labelZone(el('opp-stage-right'), 'Right side area');
    labelZone(el('opp-stage-center'), 'Center area');
    labelZone(el('opp-stage-left'), 'Left side area');
    labelZone(el('my-stage-left'), 'Left side area');
    labelZone(el('my-stage-center'), 'Center area');
    labelZone(el('my-stage-right'), 'Right side area');
    labelZone(el('opp-live-0'), 'Live card storage');
    labelZone(el('opp-live-1'), 'Live card storage');
    labelZone(el('opp-live-2'), 'Live card storage');
    labelZone(el('my-live-0'), 'Live card storage');
    labelZone(el('my-live-1'), 'Live card storage');
    labelZone(el('my-live-2'), 'Live card storage');

    oppMat.querySelector('.playmat-img')?.classList.remove('playmat-img-flipped');

    document.documentElement.classList.add('tcg-portrait-board-mounted');
    syncHandVars();
    try {
      // Portrait hides .playmat-img; re-apply so CSS-var ::before mats paint after remount.
      if (typeof global.LLTCG_PLAYMATS?.applyMatchPlaymats === 'function'
          && global.G?.gameState) {
        global.LLTCG_PLAYMATS.applyMatchPlaymats(global.G.gameState, {
          myId: global.G.playerId,
        });
      }
    } catch (_) { /* ignore */ }
    try {
      if (typeof global.tcgPortraitEnsurePhaseMenu === 'function') {
        global.tcgPortraitEnsurePhaseMenu();
      }
      if (typeof global.tcgPortraitBindZonePreviews === 'function') {
        global.tcgPortraitBindZonePreviews();
      }
      if (typeof global.tcgPortraitEnsureDeckDrawers === 'function') {
        global.tcgPortraitEnsureDeckDrawers();
      }
    } catch (_) { /* ignore */ }
    // Relayout hands after reparent so strip width / centering settle (avoids start-of-match jumps).
    try {
      requestAnimationFrame(function () {
        syncHandVars();
        if (typeof global.layoutHandFan === 'function') {
          global.layoutHandFan(el('hand-row'), { animate: false });
          global.layoutHandFan(el('opp-hand-zone'), { animate: false });
        }
      });
    } catch (_) { /* ignore */ }
    if (!resizeBound) {
      resizeBound = true;
      try {
        global.addEventListener('resize', syncHandVars, { passive: true });
      } catch (_) { /* ignore */ }
    }
    return true;
  }

  function unmount() {
    const board = el('portrait-board');
    const viewport = el('game-viewport-frame');
    const wide = viewport?.querySelector?.('.game-wide-wrap');
    if (!board && !document.documentElement.classList.contains('tcg-portrait-board-mounted')) {
      return false;
    }

    const nodes = Array.from(document.querySelectorAll('[data-portrait-home-id]'));
    nodes.forEach(restoreHome);

    // Fallback restores if home map was lost after a hot reload.
    const stack = el('playmat-mat-stack');
    const sideLeft = document.querySelector('#screen-game .side-panel.side-left');
    const oppHand = document.querySelector('.opp-hand-strip');
    const myHand = document.querySelector('.my-hand-strip');
    const oppMat = el('opp-stage');
    const myMat = matMountNode('game-stage');
    const seam = stack?.querySelector('.mat-seam');
    if (stack && oppHand && oppHand.parentElement !== stack) {
      stack.insertBefore(oppHand, stack.firstChild);
    }
    if (stack && oppMat && oppMat.parentElement !== stack) {
      const after = oppHand?.nextSibling || stack.firstChild;
      stack.insertBefore(oppMat, after);
    }
    if (stack && myMat && myMat.parentElement !== stack) {
      stack.insertBefore(myMat, seam ? seam.nextSibling : null);
    }
    if (stack && myHand && myHand.parentElement !== stack) {
      stack.appendChild(myHand);
    }
    const cardHover = el('card-hover-panel');
    if (sideLeft && cardHover && cardHover.parentElement !== sideLeft) {
      sideLeft.appendChild(cardHover);
    }

    board?.remove();
    if (wide) {
      wide.classList.remove('portrait-wide-hidden');
      wide.removeAttribute('aria-hidden');
    }
    oppMat?.querySelector('.playmat-img')?.classList.add('playmat-img-flipped');

    document.documentElement.classList.remove('tcg-portrait-board-mounted');
    const root = document.documentElement;
    root.style.removeProperty('--p-card-w');
    root.style.removeProperty('--p-card-h');
    root.style.removeProperty('--p-hand-mine');
    root.style.removeProperty('--p-hand-opp');
    root.style.removeProperty('--p-card-w-opp');

    try {
      if (typeof global.tcgPortraitRemoveDeckDrawers === 'function') {
        global.tcgPortraitRemoveDeckDrawers();
      }
    } catch (_) { /* ignore */ }

    try {
      if (typeof global.tcgPortraitUnmountDeckChrome === 'function') {
        global.tcgPortraitUnmountDeckChrome();
      }
    } catch (_) { /* ignore */ }

    try {
      if (typeof global.layoutHandFan === 'function') {
        global.layoutHandFan(el('hand-row'), { animate: false });
        global.layoutHandFan(el('opp-hand-zone'), { animate: false });
      }
      if (global.G?.gameState && typeof global.renderGame === 'function') {
        global.renderGame(global.G.gameState, { skipLog: true });
      }
    } catch (_) { /* ignore */ }
    return true;
  }

  function syncHandVars() {
    const board = el('portrait-board');
    if (!board) return;
    const root = document.documentElement;
    const size = root.dataset.tcgPortraitSize || 'phone';
    const rawW = global.visualViewport?.width || global.innerWidth || board.clientWidth || 360;
    // Tablets: keep a phone-like column so the mat does not stretch edge-to-edge.
    const layoutW = size === 'tablet' ? Math.min(rawW, 720) : rawW;
    let uiScale = 1;
    if (size === 'phone') {
      const rawScale = root.dataset.tcgPortraitUiScale
        || root.style.getPropertyValue('--p-ui-scale')
        || global.getComputedStyle?.(root)?.getPropertyValue('--p-ui-scale');
      const parsed = parseFloat(String(rawScale || '').trim());
      if (Number.isFinite(parsed) && parsed > 0) uiScale = parsed;
    }
    let cardMul = 1.55;
    // Phone: keep most of the card face in the hand rail so iOS Safari players
    // can read what they hold (season feedback: hands felt buried / unreadable).
    let mineFrac = 0.72;
    let oppFrac = 0.50;
    if (size === 'square') {
      cardMul = 1.28;
      mineFrac = 0.50;
      oppFrac = 0.40;
    } else if (size === 'tablet') {
      cardMul = 1.42;
      mineFrac = 0.54;
      oppFrac = 0.48;
    } else {
      // Small phones: shrink hand with the same density factor as HUD chrome.
      cardMul = 1.48 * uiScale;
      mineFrac = 0.72 * (0.92 + 0.08 * uiScale);
      oppFrac = 0.48 * (0.9 + 0.1 * uiScale);
    }
    const cardSlots = 5;
    const cardW = Math.max(40, (layoutW / cardSlots) * cardMul);
    const cardH = cardW * (88 / 63);
    root.style.setProperty('--p-card-w', cardW.toFixed(2) + 'px');
    root.style.setProperty('--p-card-h', cardH.toFixed(2) + 'px');
    root.style.setProperty('--p-hand-mine', (cardH * mineFrac).toFixed(2) + 'px');
    root.style.setProperty('--p-hand-opp', (cardH * oppFrac).toFixed(2) + 'px');
    root.style.setProperty('--p-card-w-opp', (cardW * 0.92).toFixed(2) + 'px');
    if (size === 'tablet') {
      root.style.setProperty('--p-board-max-w', '720px');
    } else {
      root.style.removeProperty('--p-board-max-w');
    }
  }

  function onRender(s, myId) {
    if (!portraitActive()) {
      if (document.documentElement.classList.contains('tcg-portrait-board-mounted')) unmount();
      return;
    }
    mount();
    ensureFieldHosts();
    syncHandVars();
    try {
      if (typeof global.tcgPortraitBindZonePreviews === 'function') {
        global.tcgPortraitBindZonePreviews();
      }
      if (typeof global.tcgPortraitEnsureDeckDrawers === 'function') {
        global.tcgPortraitEnsureDeckDrawers();
      }
      if (typeof global.tcgPortraitSyncDeckDrawers === 'function') {
        global.tcgPortraitSyncDeckDrawers(s, myId);
      }
    } catch (_) { /* ignore */ }
    const me = s.players[myId];
    const oppId = myId === 'p1' ? 'p2' : 'p1';
    const opp = s.players[oppId];
    if (typeof global.tcgPortraitSyncWinCounts === 'function') {
      global.tcgPortraitSyncWinCounts(s, myId);
    } else {
      const myWins = el('portrait-my-wins');
      const oppWins = el('portrait-opp-wins');
      if (myWins) myWins.textContent = String((me?.success_lives || []).length) + '/3';
      if (oppWins) oppWins.textContent = String((opp?.success_lives || []).length) + '/3';
    }

    try {
      if (typeof global.layoutHandFan === 'function') {
        global.layoutHandFan(el('hand-row'), { animate: false });
        global.layoutHandFan(el('opp-hand-zone'), { animate: false });
      }
    } catch (_) { /* ignore */ }
  }

  function boot() {
    if (!portraitActive()) return;
    const tryMount = () => {
      if (mount()) return;
      setTimeout(tryMount, 200);
    };
    tryMount();
  }

  global.tcgPortraitMountBoard = mount;
  global.tcgPortraitUnmountBoard = unmount;
  global.tcgPortraitSyncHandVars = syncHandVars;
  global.tcgPortraitOnRender = onRender;

  if (global.document.readyState === 'loading') {
    global.document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(typeof window !== 'undefined' ? window : globalThis);
