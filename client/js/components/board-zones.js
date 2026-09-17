/**
 * Zone web components — wrap existing board DOM; emit UI events only.
 * See docs/overhaul/02-client-components.md
 */
(function (global) {
  'use strict';

  function emit(el, name, detail) {
    el.dispatchEvent(new CustomEvent(name, { bubbles: true, composed: true, detail: detail || {} }));
  }

  class LlStageBoard extends HTMLElement {
    connectedCallback() {
      this.setAttribute('data-ll-zone', 'stage');
      if (!this._bound) {
        this._bound = true;
        this.addEventListener('click', (ev) => {
          const slot = ev.target?.closest?.('[data-slot]');
          if (slot) emit(this, 'll-slot-pick', { slot: slot.getAttribute('data-slot'), source: 'stage' });
        });
      }
    }

    /** @param {object} vm from llBoardViewModel */
    applyViewModel(vm) {
      this._vm = vm;
      this.dataset.seq = String(vm?.seq ?? '');
      this.dataset.phase = String(vm?.phase ?? '');
    }
  }

  class LlHandZone extends HTMLElement {
    connectedCallback() {
      this.setAttribute('data-ll-zone', 'hand');
      if (!this._bound) {
        this._bound = true;
        this.addEventListener('click', (ev) => {
          const card = ev.target?.closest?.('[data-id],.card,[data-instance-id]');
          if (!card) return;
          const id = card.getAttribute('data-id')
            || card.getAttribute('data-instance-id')
            || card.dataset?.id;
          if (id) emit(this, 'll-card-click', { instanceId: id, source: 'hand' });
        });
      }
    }

    applyViewModel(vm) {
      this._vm = vm;
      this.dataset.handCount = String((vm?.myHand || []).length);
    }
  }

  class LlLiveZone extends HTMLElement {
    connectedCallback() {
      this.setAttribute('data-ll-zone', 'live');
    }

    applyViewModel(vm) {
      this._vm = vm;
      this.dataset.liveCount = String((vm?.myLive || []).length);
    }
  }

  class LlPromptHost extends HTMLElement {
    connectedCallback() {
      this.setAttribute('data-ll-zone', 'prompt');
    }
  }

  class LlSidePanel extends HTMLElement {
    connectedCallback() {
      this.setAttribute('data-ll-zone', 'side');
    }

    applyViewModel(vm) {
      this._vm = vm;
      this.dataset.logLen = String((vm?.log || []).length);
    }
  }

  const defs = [
    ['ll-stage-board', LlStageBoard],
    ['ll-hand-zone', LlHandZone],
    ['ll-live-zone', LlLiveZone],
    ['ll-prompt-host', LlPromptHost],
    ['ll-side-panel', LlSidePanel],
  ];
  for (const [name, cls] of defs) {
    if (!customElements.get(name)) customElements.define(name, cls);
  }

  /** Upgrade known ids into custom elements without breaking existing markup. */
  global.llUpgradeBoardComponents = function llUpgradeBoardComponents(root) {
    const doc = root || document;
    function wrap(id, tag) {
      const el = doc.getElementById(id);
      if (!el || el.closest(tag)) return el?.closest?.(tag) || el;
      const host = doc.createElement(tag);
      el.parentNode.insertBefore(host, el);
      host.appendChild(el);
      return host;
    }
    wrap('game-stage', 'll-stage-board');
    wrap('hand-row', 'll-hand-zone');
    // Prefer the feed host so Log + Chat share one flex child under .side-log.
    if (doc.getElementById('match-side-feed')) {
      wrap('match-side-feed', 'll-side-panel');
    } else {
      wrap('game-log', 'll-side-panel');
    }
    const live = doc.querySelector('.z-live, #my-live, [data-zone="live"]');
    if (live && !live.closest('ll-live-zone')) {
      const host = doc.createElement('ll-live-zone');
      live.parentNode.insertBefore(host, live);
      host.appendChild(live);
    }
    // Portrait board may already be mounted — keep mat host inside the grid cell.
    try {
      if (typeof global.tcgPortraitMountBoard === 'function'
        && doc.documentElement?.classList?.contains('tcg-portrait-play')) {
        global.tcgPortraitMountBoard();
      }
    } catch (_) { /* ignore */ }
  };

  global.llApplyBoardViewModel = function llApplyBoardViewModel(vm) {
    document.querySelectorAll('ll-stage-board,ll-hand-zone,ll-live-zone,ll-side-panel').forEach((el) => {
      if (typeof el.applyViewModel === 'function') el.applyViewModel(vm);
    });
  };

  if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
      try {
        global.llUpgradeBoardComponents(document);
      } catch (_) { /* board may not be mounted yet */ }
    });
  }
})(typeof window !== 'undefined' ? window : globalThis);
