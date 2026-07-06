/**
 * LLSIF-style match stamps — PvP picker, playback, favorites, profile settings.
 */
(function (global) {
  'use strict';

  const MANIFEST_URL = 'stamps_manifest.json?v=3';
  const I18N_URL = 'stamps_i18n.json?v=1';
  const ASSET_BASE = 'assets/stamps/';
  const LS_AUDIO = 'tcg_stamp_audio_enabled';
  const LS_FAV = 'tcg_stamp_favorites_cache';
  const COOLDOWN_MS = 2100;
  const PROFILE_MAX = 20;

  const state = {
    manifest: null,
    labelI18n: null,
    byId: { ja: {}, en: {} },
    favorites: { ja: [], en: [], profile: [] },
    lastSeen: { p1: 0, p2: 0 },
    pickerOpen: false,
    pickerTab: 'ja',
    localeTab: 'ja',
    profileEditorTab: 'ja',
    profileEditorOpen: false,
    lastSendAt: 0,
    loading: null,
    roomId: null,
  };

  function el(id) {
    return typeof global.el === 'function' ? global.el(id) : document.getElementById(id);
  }

  function t(key, vars) {
    return global.LLTCG_I18N?.t ? global.LLTCG_I18N.t(key, vars) : key;
  }

  function isDebugStampsEnabled() {
    if (global.TCG_DEBUG?.on === true) return true;
    try {
      return document.documentElement.getAttribute('data-tcg-debug') === '1';
    } catch (e) {
      return false;
    }
  }

  function isHumanPvpMatch(s) {
    if (global.G?.isCPU || global.G?.isTutorial) return false;
    if (global.G?.isSpectator) {
      s = s || global.G?.gameState;
      if (!s?.players?.p1 || !s?.players?.p2) return false;
      return typeof global.isHumanPvpFinishedState === 'function' && global.isHumanPvpFinishedState(s);
    }
    return typeof global.isHumanPvpFinishedState === 'function' && global.isHumanPvpFinishedState(s || global.G?.gameState);
  }

  function isStampMatch(s) {
    if (global.G?.isSpectator || global.G?.isTutorial) return false;
    s = s || global.G?.gameState;
    if (!s?.players?.p1 || !s?.players?.p2) return false;
    const status = s.status;
    if (status === 'finished' || status === 'waiting') return false;
    if (isHumanPvpMatch(s)) return true;
    if (isDebugStampsEnabled() && global.G?.isCPU) return true;
    return false;
  }

  function stampAudioEnabled() {
    try {
      const v = localStorage.getItem(LS_AUDIO);
      if (v === null) return true;
      return v !== '0' && v !== 'false';
    } catch (e) {
      return true;
    }
  }

  function setStampAudioEnabled(on) {
    try {
      localStorage.setItem(LS_AUDIO, on ? '1' : '0');
    } catch (e) {}
    syncAudioToggleUi();
  }

  function syncAudioToggleUi() {
    const chk = el('chk-stamp-audio');
    const chkMenu = el('chk-stamp-audio-menu');
    const on = stampAudioEnabled();
    if (chk) chk.checked = on;
    if (chkMenu) chkMenu.checked = on;
  }

  function animClassForFlsh(flsh) {
    const base = String(flsh || '').split('/').pop()?.replace('.flsh', '') || 'stamp_04';
    const map = {
      stamp_01: 'tcg-stamp-anim-01',
      stamp_02: 'tcg-stamp-anim-02',
      stamp_03: 'tcg-stamp-anim-03',
      stamp_04: 'tcg-stamp-anim-04',
      stamp_05: 'tcg-stamp-anim-05',
    };
    return map[base] || map.stamp_04;
  }

  function assetUrl(rel) {
    if (!rel) return '';
    return ASSET_BASE + String(rel).replace(/^\/+/, '');
  }

  function findStamp(id, locale) {
    if (!id) return null;
    const loc = locale === 'en' ? 'en' : 'ja';
    return state.byId[loc][id] || state.byId.ja[id] || state.byId.en[id] || null;
  }

  function stampLocaleFor(id, hint) {
    if (hint === 'en' || hint === 'ja') return hint;
    if (state.byId.ja[id]) return 'ja';
    if (state.byId.en[id]) return 'en';
    return 'ja';
  }

  function buildStampMap() {
    const map = {};
    ['ja', 'en'].forEach((loc) => {
      (state.manifest?.locales?.[loc] || []).forEach((row) => {
        if (row?.id) map[row.id] = row;
      });
    });
    return map;
  }

  function uiLocale() {
    const loc = global.LLTCG_I18N?.getLocale?.();
    return loc === 'ja' || loc === 'es' ? loc : 'en';
  }

  function stampDisplayLabel(stampOrId, fallbackLabel) {
    const id = typeof stampOrId === 'string' ? stampOrId : stampOrId?.id;
    const fallback = fallbackLabel
      ?? (typeof stampOrId === 'object' ? stampOrId?.label : '')
      ?? '';
    if (!id) return fallback;
    const row = state.labelI18n?.[id];
    if (!row) return fallback;
    const loc = uiLocale();
    const text = row[loc] || row.en || row.ja || fallback;
    return text || fallback;
  }

  async function loadStampI18n() {
    if (state.labelI18n) return state.labelI18n;
    try {
      const r = await fetch(I18N_URL, { cache: 'no-cache' });
      if (!r.ok) throw new Error('Stamp i18n missing');
      const data = await r.json();
      state.labelI18n = data?.labels && typeof data.labels === 'object' ? data.labels : {};
    } catch (e) {
      state.labelI18n = {};
    }
    return state.labelI18n;
  }

  async function loadManifest() {
    await loadStampI18n();
    if (state.manifest) return state.manifest;
    if (state.loading) return state.loading;
    state.loading = fetch(MANIFEST_URL, { cache: 'no-cache' })
      .then((r) => {
        if (!r.ok) throw new Error('Stamp manifest missing');
        return r.json();
      })
      .then((data) => {
        state.manifest = data;
        ['ja', 'en'].forEach((loc) => {
          state.byId[loc] = {};
          (data.locales?.[loc] || []).forEach((row) => {
            if (row?.id) state.byId[loc][row.id] = row;
          });
        });
        return data;
      })
      .finally(() => {
        state.loading = null;
      });
    return state.loading;
  }

  function readLocalFavorites() {
    try {
      const raw = localStorage.getItem(LS_FAV);
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  }

  function writeLocalFavorites(fav) {
    try {
      localStorage.setItem(LS_FAV, JSON.stringify(fav));
    } catch (e) {}
  }

  function normalizeProfileList(fav) {
    let profile = Array.isArray(fav?.profile) ? fav.profile.slice() : [];
    if (!profile.length) {
      const legacy = [...(fav?.ja || []), ...(fav?.en || [])];
      profile = [...new Set(legacy.map((id) => String(id).trim()).filter(Boolean))];
    }
    return profile.slice(0, PROFILE_MAX);
  }

  function applyFavorites(fav) {
    if (!fav || typeof fav !== 'object') return;
    state.favorites = {
      ja: Array.isArray(fav.ja) ? fav.ja.slice() : [],
      en: Array.isArray(fav.en) ? fav.en.slice() : [],
      profile: normalizeProfileList(fav),
    };
    writeLocalFavorites(state.favorites);
  }

  function mergeProfileFromAccount() {
    const prof = global.A?.profile;
    if (prof?.stamp_favorites) applyFavorites(prof.stamp_favorites);
    else {
      const local = readLocalFavorites();
      if (local) applyFavorites(local);
    }
  }

  let voiceAudio = null;
  function playStampAudio(stamp) {
    if (!stampAudioEnabled()) return;
    const src = stamp.voice ? assetUrl(stamp.voice) : (stamp.se ? assetUrl(stamp.se) : '');
    if (!src) return;
    try {
      if (!voiceAudio) voiceAudio = new Audio();
      voiceAudio.pause();
      voiceAudio.src = src;
      voiceAudio.volume = global.LLTCG_SFX?.getVolume?.() ?? 0.85;
      void voiceAudio.play();
    } catch (e) {}
  }

  function layerForPlayer(pid) {
    const myId = global.G?.playerId || global.G?.gameState?.my_id;
    const viewId = global.G?.isSpectator ? (global.G?.gameState?.view_as || 'p1') : myId;
    const isMine = pid === viewId;
    return el(isMine ? 'my-stamp-layer' : 'opp-stamp-layer');
  }

  function syncStampLayerPositions() {
    const frame = el('game-viewport-frame');
    const myMat = el('game-stage');
    const oppMat = el('opp-stage');
    const myLayer = el('my-stamp-layer');
    const oppLayer = el('opp-stamp-layer');
    if (!frame || !myMat || !oppMat || !myLayer || !oppLayer) return;
    const fr = frame.getBoundingClientRect();
    const place = (mat, layer) => {
      const r = mat.getBoundingClientRect();
      layer.style.left = `${r.left - fr.left}px`;
      layer.style.top = `${r.top - fr.top}px`;
      layer.style.width = `${r.width}px`;
      layer.style.height = `${r.height}px`;
    };
    place(oppMat, oppLayer);
    place(myMat, myLayer);
  }

  function bindStampLayerSync() {
    if (state.layerSyncBound) return;
    state.layerSyncBound = true;
    const frame = el('game-viewport-frame');
    if (frame && typeof ResizeObserver !== 'undefined') {
      const ro = new ResizeObserver(() => syncStampLayerPositions());
      ro.observe(frame);
    }
    window.addEventListener('resize', syncStampLayerPositions);
    syncStampLayerPositions();
  }

  function showStampPop(stamp, locale, fromPid) {
    if (!stamp) return;
    syncStampLayerPositions();
    const layer = layerForPlayer(fromPid);
    if (!layer) return;
    const wrap = document.createElement('div');
    wrap.className = 'tcg-stamp-pop ' + animClassForFlsh(stamp.flsh);
    const img = document.createElement('img');
    img.className = 'tcg-stamp-pop-img';
    img.src = assetUrl(stamp.image);
    img.alt = stampDisplayLabel(stamp);
    img.draggable = false;
    wrap.appendChild(img);
    if (stamp.label || stamp.id) {
      const lbl = document.createElement('div');
      lbl.className = 'tcg-stamp-pop-label';
      lbl.textContent = stampDisplayLabel(stamp);
      wrap.appendChild(lbl);
    }
    layer.replaceChildren(wrap);
    playStampAudio(stamp);
    const ms = 2800;
    clearTimeout(wrap._tcgStampTimer);
    wrap._tcgStampTimer = setTimeout(() => {
      wrap.classList.add('tcg-stamp-pop-out');
      setTimeout(() => wrap.remove(), 420);
    }, ms);
  }

  function onStampState(s) {
    if (!s?.stamp_pop || !isStampMatch(s)) return;
    ['p1', 'p2'].forEach((pid) => {
      const pop = s.stamp_pop[pid];
      if (!pop?.id) return;
      const n = pop.n || 0;
      if (n <= (state.lastSeen[pid] || 0)) return;
      state.lastSeen[pid] = n;
      const stamp = findStamp(pop.id, pop.locale);
      if (stamp) showStampPop(stamp, pop.locale, pid);
    });
  }

  function syncGameButton(s) {
    const btn = el('btn-stamp');
    if (!btn) return;
    const rid = global.G?.roomId;
    if (rid && rid !== state.roomId) {
      state.roomId = rid;
      state.lastSeen = { p1: 0, p2: 0 };
      el('my-stamp-layer')?.replaceChildren();
      el('opp-stamp-layer')?.replaceChildren();
    }
    const show = isStampMatch(s);
    btn.hidden = !show;
    btn.disabled = global.G?.isSpectator || !show;
    if (show) syncStampLayerPositions();
  }

  function isProfileFavorite(id) {
    return (state.favorites.profile || []).includes(id);
  }

  function toggleProfileFavorite(id) {
    const list = state.favorites.profile || (state.favorites.profile = []);
    const idx = list.indexOf(id);
    if (idx >= 0) {
      list.splice(idx, 1);
    } else {
      if (list.length >= PROFILE_MAX) {
        if (typeof global.toast === 'function') {
          global.toast(t('stamps.profileFull', { max: PROFILE_MAX }), 2400);
        }
        return;
      }
      list.push(id);
    }
    writeLocalFavorites(state.favorites);
    void saveFavoritesRemote();
    renderProfileEditorGrid();
    updateProfileEditorCount();
    renderOptionsStampPreview();
    renderPickerGrid();
  }

  async function saveFavoritesRemote() {
    if (typeof global.accountPost !== 'function' || !global.A?.token) return;
    try {
      const res = await global.accountPost('stamp_favorites_set', { favorites: state.favorites });
      if (res?.stamp_favorites) applyFavorites(res.stamp_favorites);
      if (global.A?.profile) {
        global.A.profile.stamp_favorites = state.favorites;
      }
    } catch (e) {
      /* keep local cache */
    }
  }

  function stampsForTab(tab) {
    const loc = state.localeTab === 'en' ? 'en' : 'ja';
    if (tab === 'favorites') {
      const fav = new Set(state.favorites.profile || []);
      const seen = new Set();
      const rows = [];
      ['ja', 'en'].forEach((bucket) => {
        (state.manifest?.locales?.[bucket] || []).forEach((row) => {
          if (!fav.has(row.id) || seen.has(row.id)) return;
          seen.add(row.id);
          rows.push(row);
        });
      });
      return rows;
    }
    return state.manifest?.locales?.[tab] || [];
  }

  function renderPickerGrid() {
    const grid = el('stamp-picker-grid');
    if (!grid) return;
    grid.replaceChildren();
    const tab = state.pickerTab === 'favorites' ? 'favorites' : state.localeTab;
    const loc = state.localeTab === 'en' ? 'en' : 'ja';
    const rows = stampsForTab(tab);
    if (!rows.length) {
      const empty = document.createElement('p');
      empty.className = 'stamp-picker-empty';
      empty.textContent = tab === 'favorites'
        ? t('stamps.favoritesEmpty')
        : t('stamps.empty');
      grid.appendChild(empty);
      return;
    }
    rows.forEach((stamp) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'stamp-picker-tile';
      btn.title = stampDisplayLabel(stamp) || stamp.id;
      const img = document.createElement('img');
      img.src = assetUrl(stamp.image);
      img.alt = '';
      btn.appendChild(img);
      if (state.pickerTab !== 'favorites') {
        const star = document.createElement('span');
        star.className = 'stamp-picker-star' + (isProfileFavorite(stamp.id) ? ' is-on' : '');
        star.textContent = '☆';
        star.addEventListener('click', (e) => {
          e.stopPropagation();
          toggleProfileFavorite(stamp.id);
        });
        btn.appendChild(star);
      }
      btn.addEventListener('click', () => {
        void sendStamp(stamp.id, stampLocaleFor(stamp.id, loc));
      });
      grid.appendChild(btn);
    });
  }

  function setPickerTab(tab) {
    if (tab === 'ja' || tab === 'en') state.localeTab = tab;
    state.pickerTab = tab;
    document.querySelectorAll('#stamp-picker .stamp-picker-tab').forEach((b) => {
      b.classList.toggle('active', b.dataset.tab === tab);
    });
    renderPickerGrid();
  }

  function openPicker(opts = {}) {
    state.pickerOpen = true;
    const pop = el('stamp-picker');
    if (pop) {
      pop.hidden = false;
      pop.classList.add('open');
    }
    const title = el('stamp-picker-title');
    if (title) title.textContent = t('stamps.pickerTitle');
    void loadManifest().then(() => {
      setPickerTab(state.pickerTab || 'ja');
    });
  }

  function closePicker() {
    state.pickerOpen = false;
    const pop = el('stamp-picker');
    if (pop) {
      pop.classList.remove('open');
      pop.hidden = true;
    }
  }

  function updateProfileEditorCount() {
    const countEl = el('stamp-profile-count');
    if (!countEl) return;
    const n = (state.favorites.profile || []).length;
    countEl.textContent = t('stamps.profileCount', { n, max: PROFILE_MAX });
  }

  function renderProfileEditorGrid() {
    const grid = el('stamp-profile-grid');
    if (!grid) return;
    grid.replaceChildren();
    const rows = state.manifest?.locales?.[state.profileEditorTab] || [];
    if (!rows.length) {
      const empty = document.createElement('p');
      empty.className = 'stamp-picker-empty';
      empty.textContent = t('stamps.empty');
      empty.style.gridColumn = '1 / -1';
      grid.appendChild(empty);
      return;
    }
    rows.forEach((stamp) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'stamp-picker-tile';
      const on = isProfileFavorite(stamp.id);
      btn.classList.toggle('is-profile-pick', on);
      btn.title = stampDisplayLabel(stamp) || stamp.id;
      const img = document.createElement('img');
      img.src = assetUrl(stamp.image);
      img.alt = '';
      btn.appendChild(img);
      btn.addEventListener('click', () => toggleProfileFavorite(stamp.id));
      grid.appendChild(btn);
    });
  }

  function setProfileEditorTab(tab) {
    state.profileEditorTab = tab === 'en' ? 'en' : 'ja';
    document.querySelectorAll('#stamp-profile-tabs .stamp-picker-tab').forEach((b) => {
      b.classList.toggle('active', b.dataset.tab === state.profileEditorTab);
    });
    renderProfileEditorGrid();
  }

  function openProfileEditor() {
    state.profileEditorOpen = true;
    void loadManifest().then(() => {
      setProfileEditorTab('ja');
      updateProfileEditorCount();
      if (typeof global.openM === 'function') global.openM('modal-stamp-profile');
      else el('modal-stamp-profile')?.classList.add('open');
      if (typeof global.LLTCG_I18N?.applyI18n === 'function') {
        global.LLTCG_I18N.applyI18n(el('modal-stamp-profile'));
      }
    });
  }

  function closeProfileEditor() {
    state.profileEditorOpen = false;
    if (typeof global.closeM === 'function') global.closeM('modal-stamp-profile');
    else el('modal-stamp-profile')?.classList.remove('open');
    renderOptionsStampPreview();
  }

  async function sendStamp(stampId, locale) {
    if (global.G?.isSpectator) return;
    const now = Date.now();
    if (now - state.lastSendAt < COOLDOWN_MS) {
      if (typeof global.toast === 'function') global.toast(t('stamps.cooldown'), 1800);
      return;
    }
    if (typeof global.sendAct !== 'function') return;
    state.lastSendAt = now;
    closePicker();
    try {
      const payload = {
        stamp_id: stampId,
        locale: locale === 'en' ? 'en' : 'ja',
      };
      if (isDebugStampsEnabled()) payload.debug_mode = true;
      await global.sendAct('send_stamp', payload);
      const myId = global.G?.playerId;
      if (myId) {
        const stamp = findStamp(stampId, locale);
        if (stamp) showStampPop(stamp, locale, myId);
      }
    } catch (e) {
      state.lastSendAt = 0;
      if (typeof global.toast === 'function') global.toast(e.message || 'Stamp failed', 2800);
    }
  }

  function renderOptionsStampPreview() {
    const grid = el('options-stamp-grid');
    const hint = el('options-stamp-hint');
    if (!grid) return;
    void loadManifest().then(() => {
      grid.replaceChildren();
      const ids = state.favorites.profile || [];
      const map = buildStampMap();
      ids.forEach((id) => {
        const stamp = map[id];
        if (!stamp) return;
        const tile = document.createElement('div');
        tile.className = 'options-stamp-slot';
        const img = document.createElement('img');
        img.src = assetUrl(stamp.image);
        img.alt = stampDisplayLabel(stamp);
        img.title = stampDisplayLabel(stamp);
        tile.appendChild(img);
        grid.appendChild(tile);
      });
      if (hint) {
        const key = ids.length ? 'stamps.profileHint' : 'stamps.profileHintEmpty';
        hint.textContent = t(key);
        hint.setAttribute('data-i18n', key);
      }
    });
  }

  function bindUi() {
    if (document.body.dataset.stampsBound) return;
    document.body.dataset.stampsBound = '1';

    el('btn-stamp')?.addEventListener('click', (e) => {
      e.stopPropagation();
      if (state.pickerOpen) closePicker();
      else openPicker();
    });
    el('btn-stamp-picker-close')?.addEventListener('click', () => closePicker());
    el('btn-options-stamps-edit')?.addEventListener('click', (e) => {
      e.stopPropagation();
      openProfileEditor();
    });
    el('btn-stamp-profile-close')?.addEventListener('click', () => closeProfileEditor());
    el('btn-stamp-profile-done')?.addEventListener('click', () => closeProfileEditor());
    document.querySelectorAll('#stamp-picker .stamp-picker-tab').forEach((btn) => {
      btn.addEventListener('click', () => setPickerTab(btn.dataset.tab));
    });
    document.querySelectorAll('#stamp-profile-tabs .stamp-picker-tab').forEach((btn) => {
      btn.addEventListener('click', () => setProfileEditorTab(btn.dataset.tab));
    });
    el('modal-stamp-profile')?.addEventListener('click', (e) => {
      if (e.target === el('modal-stamp-profile')) closeProfileEditor();
    });
    document.addEventListener('click', (e) => {
      const pop = el('stamp-picker');
      const btn = el('btn-stamp');
      const editBtn = el('btn-options-stamps-edit');
      if (!state.pickerOpen || !pop) return;
      if (pop.contains(e.target) || btn?.contains(e.target) || editBtn?.contains(e.target)) return;
      closePicker();
    });

    const bindAudio = (id) => {
      const chk = el(id);
      if (!chk || chk.dataset.bound) return;
      chk.dataset.bound = '1';
      chk.addEventListener('change', () => setStampAudioEnabled(chk.checked));
    };
    bindAudio('chk-stamp-audio');
    bindAudio('chk-stamp-audio-menu');
    syncAudioToggleUi();
  }

  function init() {
    bindUi();
    bindStampLayerSync();
    mergeProfileFromAccount();
    void loadManifest().then(() => {
      renderOptionsStampPreview();
    });
  }

  global.TCG_STAMPS = {
    init,
    onState: onStampState,
    syncGameUi: syncGameButton,
    openPicker,
    closePicker,
    openProfileEditor,
    closeProfileEditor,
    isHumanPvpMatch,
    isStampMatch,
    isDebugStampsEnabled,
    applyFavorites,
    renderOptionsStampPreview,
    stampAudioEnabled,
    setStampAudioEnabled,
    stampDisplayLabel,
    loadStampI18n,
    loadManifest,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(typeof window !== 'undefined' ? window : globalThis);
