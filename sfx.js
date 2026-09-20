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
  var unlocked = false;
  var unlockBound = false;
  var audioCtx = null;
  var decoded = Object.create(null);

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
    return fetch('./sfx_manifest.web.json?v=15')
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

  function attachCachedSrc(file, audio) {
    try {
      var Apk = window.LLTCG_APK_ASSETS;
      if (!Apk || typeof Apk.blobUrlFor !== 'function') return;
      if (typeof Apk.isNativeApk === 'function' && !Apk.isNativeApk()) return;
      Apk.blobUrlFor(BASE + file).then(function (blobUrl) {
        if (!blobUrl || !audio) return;
        audio.src = blobUrl;
        try { audio.load(); } catch (e2) { /* ignore */ }
      }).catch(function () { /* network src remains */ });
    } catch (e) { /* ignore */ }
  }

  function warmFile(file) {
    if (!file) return;
    if (!pool[file]) {
      var a = new Audio(BASE + file);
      a.preload = 'auto';
      pool[file] = a;
      attachCachedSrc(file, a);
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

  function swallowPlay(p) {
    if (p && typeof p.catch === 'function') p.catch(function () { /* autoplay */ });
  }

  function getAudioCtx() {
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return null;
    if (!audioCtx) audioCtx = new AC();
    return audioCtx;
  }

  function loadSfxBuffer(file) {
    if (decoded[file]) return Promise.resolve(decoded[file]);
    var Apk = window.LLTCG_APK_ASSETS;
    var fromCache = (Apk && typeof Apk.arrayBufferFor === 'function' && Apk.isNativeApk && Apk.isNativeApk())
      ? Apk.arrayBufferFor(BASE + file)
      : Promise.resolve(null);
    return fromCache.then(function (cached) {
      if (cached) return cached;
      return fetch(BASE + file, { credentials: 'same-origin' }).then(function (r) {
        if (!r.ok) throw new Error('sfx HTTP ' + r.status);
        return r.arrayBuffer();
      });
    }).then(function (buf) {
      var ctx = getAudioCtx();
      if (!ctx) throw new Error('no audio ctx');
      return ctx.decodeAudioData(buf.slice(0));
    }).then(function (audioBuf) {
      decoded[file] = audioBuf;
      return audioBuf;
    });
  }

  function playBuffer(file, vol) {
    var ctx = getAudioCtx();
    if (!ctx) return Promise.reject(new Error('no audio ctx'));
    var start = function () {
      return loadSfxBuffer(file).then(function (audioBuf) {
        var src = ctx.createBufferSource();
        var gain = ctx.createGain();
        gain.gain.value = vol;
        src.buffer = audioBuf;
        src.connect(gain);
        gain.connect(ctx.destination);
        src.start(0);
      });
    };
    if (ctx.state === 'suspended') {
      return ctx.resume().then(start);
    }
    return start();
  }

  function unlockFromGesture() {
    unlocked = true;
    try {
      var ctx = getAudioCtx();
      if (ctx && ctx.state === 'suspended') swallowPlay(ctx.resume());
    } catch (e1) { /* ignore */ }
    try {
      var files = Object.keys(pool);
      if (files.length) {
        var probe = pool[files[0]].cloneNode();
        probe.muted = true;
        probe.volume = 0;
        swallowPlay(probe.play());
      }
    } catch (e) { /* ignore */ }
  }

  function bindUnlock() {
    if (unlockBound || typeof document === 'undefined') return;
    unlockBound = true;
    var once = function () {
      unlockFromGesture();
      document.removeEventListener('pointerdown', once, true);
      document.removeEventListener('keydown', once, true);
      document.removeEventListener('touchend', once, true);
    };
    document.addEventListener('pointerdown', once, true);
    document.addEventListener('keydown', once, true);
    document.addEventListener('touchend', once, true);
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
    playBuffer(pick.file, vol).then(function () {
      unlocked = true;
    }).catch(function () {
      if (!unlocked) return;
      try {
        warmFile(pick.file);
        var node = pool[pick.file].cloneNode();
        node.volume = vol;
        swallowPlay(node.play());
      } catch (e) { /* autoplay / missing file */ }
    });
  }

  function init() {
    bindUnlock();
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

  bindUnlock();

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
    unlock: unlockFromGesture,
  };
})();
