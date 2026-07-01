/* Love Live TCG — LLSIFAS sound effects (assets/sfx/*.wav) */
(function () {
  'use strict';

  var SFX_KEY = 'tcg_sfx_enabled';
  var SFX_VOLUME_KEY = 'tcg_sfx_volume';
  var DEFAULT_VOLUME = 0.85;
  var BASE = './assets/sfx/';
  var manifest = null;
  var pool = Object.create(null);
  var lastPlay = Object.create(null);
  var DEFAULT_GAP_MS = 45;
  var CARD_GAP_MS = 22;
  var CARD_MOVE_GAP_MS = 100;
  var CARD_MOVE_DEBOUNCE_KEY = '__card_move__';
  var CARD_HOVER_DEBOUNCE_KEY = '__card_hover__';
  var CARD_HOVER_GAP_MS = 150;
  var SPLASH_DEBOUNCE_KEY = '__splash__';
  var SPLASH_GAP_MS = 80;
  var CARD_MOVE_IDS = {
    card_draw: true,
    card_slide: true,
    card_place: true,
    card_fly: true,
    card_to_wr: true,
    card_play: true,
  };

  function enabled() {
    try { return localStorage.getItem(SFX_KEY) !== '0'; } catch (e) { return true; }
  }

  function setEnabled(on) {
    try { localStorage.setItem(SFX_KEY, on ? '1' : '0'); } catch (e2) { /* ignore */ }
  }

  function getVolume() {
    try {
      var raw = localStorage.getItem(SFX_VOLUME_KEY);
      if (raw == null || raw === '') return DEFAULT_VOLUME;
      var v = Number(raw);
      if (!Number.isFinite(v)) return DEFAULT_VOLUME;
      return Math.max(0, Math.min(1, v));
    } catch (e) {
      return DEFAULT_VOLUME;
    }
  }

  function setVolume(v) {
    var n = Number(v);
    if (!Number.isFinite(n)) n = DEFAULT_VOLUME;
    n = Math.max(0, Math.min(1, n));
    try { localStorage.setItem(SFX_VOLUME_KEY, String(n)); } catch (e2) { /* ignore */ }
    return n;
  }

  function loadManifest() {
    if (manifest) return Promise.resolve(manifest);
    return fetch('./sfx_manifest.web.json?v=14', { cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) throw new Error('sfx manifest HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        manifest = data && typeof data === 'object' ? data : { events: {} };
        return manifest;
      })
      .catch(function () {
        manifest = { events: {} };
        return manifest;
      });
  }

  function eventMeta(id) {
    return manifest && manifest.events && manifest.events[id];
  }

  function pickVariant(id) {
    var ev = eventMeta(id);
    if (!ev) return null;
    var variants = ev.variants;
    if (variants && variants.length) {
      return variants[0];
    }
    if (ev.file) {
      return {
        file: ev.file,
        volume: ev.volume != null ? ev.volume : 1,
      };
    }
    return null;
  }

  function warmFile(file) {
    if (!file) return;
    if (!pool[file]) {
      var a = new Audio(BASE + file);
      a.preload = 'auto';
      pool[file] = a;
    }
  }

  function warm(id) {
    var pick = pickVariant(id);
    if (pick && pick.file) warmFile(pick.file);
  }

  function debounceKeyFor(id, pick) {
    if (CARD_MOVE_IDS[id]) return CARD_MOVE_DEBOUNCE_KEY;
    if (id === 'card_hover') return CARD_HOVER_DEBOUNCE_KEY;
    if (id && String(id).indexOf('splash_') === 0) return SPLASH_DEBOUNCE_KEY;
    return pick.file;
  }

  function gapFor(id) {
    if (CARD_MOVE_IDS[id]) return CARD_MOVE_GAP_MS;
    if (id === 'card_hover') return CARD_HOVER_GAP_MS;
    if (id && String(id).indexOf('card_') === 0) return CARD_GAP_MS;
    if (id && String(id).indexOf('splash_') === 0) return SPLASH_GAP_MS;
    return DEFAULT_GAP_MS;
  }

  function play(id, opts) {
    if (!enabled()) return;
    var pick = pickVariant(id);
    if (!pick || !pick.file) return;
    var now = Date.now();
    var gapKey = debounceKeyFor(id, pick);
    var minGap = gapFor(id);
    if (lastPlay[gapKey] && now - lastPlay[gapKey] < minGap) return;
    lastPlay[gapKey] = now;
    var userScale = opts && opts.volume != null ? Number(opts.volume) : 1;
    if (!Number.isFinite(userScale)) userScale = 1;
    var eventScale = pick.volume != null ? Number(pick.volume) : 1;
    if (!Number.isFinite(eventScale)) eventScale = 1;
    var vol = getVolume() * userScale * eventScale;
    vol = Math.max(0, Math.min(1, vol));
    if (vol <= 0.001) return;
    try {
      warmFile(pick.file);
      var node = pool[pick.file].cloneNode();
      node.volume = vol;
      void node.play();
    } catch (e) { /* autoplay / missing file */ }
  }

  function init() {
    return loadManifest().then(function (m) {
      [
        'menu_tap', 'menu_confirm', 'card_draw', 'card_slide', 'card_hover', 'card_pick', 'card_flip',
        'card_place', 'match_found', 'card_play', 'card_fly', 'card_to_wr',
        'energy_chip', 'phase_live', 'phase_performance', 'turn_tick', 'skill_tick',
        'yell_reveal', 'hearts_gain', 'live_success', 'live_fail',
        'splash_phase', 'splash_live', 'splash_performance', 'splash_live_start',
        'splash_success', 'splash_turn', 'splash_judge', 'splash_action',
        'splash_live_attempt',
      ].forEach(warm);
      return m;
    });
  }

  window.LLTCG_SFX = {
    SFX_KEY: SFX_KEY,
    SFX_VOLUME_KEY: SFX_VOLUME_KEY,
    DEFAULT_VOLUME: DEFAULT_VOLUME,
    enabled: enabled,
    setEnabled: setEnabled,
    getVolume: getVolume,
    setVolume: setVolume,
    loadManifest: loadManifest,
    play: play,
    warm: warm,
    init: init,
  };
})();
