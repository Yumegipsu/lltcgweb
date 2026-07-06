/**
 * LLSIF-style match stamps — PvP picker, playback, favorites, profile display.
 */
(function (global) {
  'use strict';

  const MANIFEST_URL = 'stamps_manifest.json?v=1';
  const ASSET_BASE = 'assets/stamps/';
  const LS_AUDIO = 'tcg_stamp_audio_enabled';
  const LS_FAV = 'tcg_stamp_favorites_cache';
  const COOLDOWN_MS = 2100;
  const PROFILE_MAX = 6;

  const state = {
    manifest: null,
    byId: { ja: {}, en: {} },
    favorites: { ja: [], en: [], profile: [] },
    lastSeen: { p1: 0, p2: 0 },
    pickerOpen: false,
    pickerTab: 'ja',
    localeTab: 'ja',
    profilePick: false,
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

  function isHumanPvpMatch(s) {
    if (global.G?.isCPU || global.G?.isTutorial) return false;
    if (global.G?.isSpectator) {
      s = s || global.G?.gameState;
      if (!s?.players?.p1 || !s?.players?.p2) return false;
      return typeof global.isHumanPvpFinishedState === 'function' && global.isHumanPvpFinishedState(s);
    }
    return typeof global.isHumanPvpFinishedState === 'function' && global.isHumanPvpFinishedState(s || global.G?.gameState);
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

  async function loadManifest() {
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

  function applyFavorites(fav) {
    if (!fav || typeof fav !== 'object') return;
    state.favorites = {
      ja: Array.isArray(fav.ja) ? fav.ja.slice() : [],
      en: Array.isArray(fav.en) ? fav.en.slice() : [],
      profile: Array.isArray(fav.profile) ? fav.profile.slice(0, PROFILE_MAX) : [],
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

  function ensureLayers() {
    ['my-stamp-layer', 'opp-stamp-layer'].forEach((id) => {
      if (el(id)) return;
    });
  }

  function layerForPlayer(pid) {
    const myId = global.G?.playerId || global.G?.gameState?.my_id;
    const viewId = global.G?.isSpectator ? (global.G?.gameState?.view_as || 'p1') : myId;
    const isMine = pid === viewId;
    return el(isMine ? 'my-stamp-layer' : 'opp-stamp-layer');
  }

  function showStampPop(stamp, locale, fromPid) {
    if (!stamp) return;
    const layer = layerForPlayer(fromPid);
    if (!layer) return;
    const wrap = document.createElement('div');
    wrap.className = 'tcg-stamp-pop ' + animClassForFlsh(stamp.flsh);
    const img = document.createElement('img');
    img.className = 'tcg-stamp-pop-img';
    img.src = assetUrl(stamp.image);
    img.alt = stamp.label || '';
    img.draggable = false;
    wrap.appendChild(img);
    if (stamp.label) {
      const lbl = document.createElement('div');
      lbl.className = 'tcg-stamp-pop-label';
      lbl.textContent = stamp.label;
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
    if (!s?.stamp_pop || !isHumanPvpMatch(s)) return;
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
    const show = isHumanPvpMatch(s) && s?.status !== 'finished' && s?.status !== 'waiting';
    btn.hidden = !show;
    btn.disabled = global.G?.isSpectator || !show;
  }

  function isFavorite(id, tab) {
    const list = state.favorites[tab] || [];
    return list.includes(id);
  }

  function toggleFavorite(id, tab) {
    const list = state.favorites[tab] || (state.favorites[tab] = []);
    const idx = list.indexOf(id);
    if (idx >= 0) list.splice(idx, 1);
    else list.push(id);
    writeLocalFavorites(state.favorites);
    void saveFavoritesRemote();
    renderPickerGrid();
  }

  function toggleProfileFavorite(id) {
    const list = state.favorites.profile || (state.favorites.profile = []);
    const idx = list.indexOf(id);
    if (idx >= 0) list.splice(idx, 1);
    else {
      if (list.length >= PROFILE_MAX) list.shift();
      list.push(id);
    }
    writeLocalFavorites(state.favorites);
    void saveFavoritesRemote();
    renderPickerGrid();
    renderProfileStampPick();
  }

  async function saveFavoritesRemote() {
    if (typeof global.accountPost !== 'function' || !global.A?.token) return;
    try {
      const res = await global.accountPost('stamp_favorites_set', { favorites: state.favorites });
      if (res?.stamp_favorites) applyFavorites(res.stamp_favorites);
      if (global.A?.profile) {
        global.A.profile.stamp_favorites = state.favorites;
        global.A.profile.stamp_profile = res?.stamp_profile || global.A.profile.stamp_profile;
      }
    } catch (e) {
      /* keep local cache */
    }
  }

  function stampsForTab(tab) {
    const loc = state.localeTab === 'en' ? 'en' : 'ja';
    if (tab === 'favorites') {
      const fav = new Set(state.favorites[loc] || []);
      const src = state.manifest?.locales?.[loc] || [];
      return src.filter((row) => fav.has(row.id));
    }
    if (state.profilePick) {
      return state.manifest?.locales?.[loc] || [];
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
        ? t('stamps.favoritesEmpty', 'No favorites yet — tap ☆ on a stamp.')
        : t('stamps.empty', 'No stamps.');
      grid.appendChild(empty);
      return;
    }
    rows.forEach((stamp) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'stamp-picker-tile';
      btn.title = stamp.label || stamp.id;
      const img = document.createElement('img');
      img.src = assetUrl(stamp.image);
      img.alt = '';
      btn.appendChild(img);
      if (!state.profilePick && state.pickerTab !== 'favorites') {
        const star = document.createElement('span');
        star.className = 'stamp-picker-star' + (isFavorite(stamp.id, loc) ? ' is-on' : '');
        star.textContent = '☆';
        star.addEventListener('click', (e) => {
          e.stopPropagation();
          toggleFavorite(stamp.id, loc);
        });
        btn.appendChild(star);
      }
      if (state.profilePick) {
        const on = (state.favorites.profile || []).includes(stamp.id);
        btn.classList.toggle('is-profile-pick', on);
        btn.addEventListener('click', () => toggleProfileFavorite(stamp.id));
      } else {
        btn.addEventListener('click', () => {
          void sendStamp(stamp.id, loc);
        });
      }
      grid.appendChild(btn);
    });
  }

  function setPickerTab(tab) {
    if (tab === 'ja' || tab === 'en') state.localeTab = tab;
    state.pickerTab = tab;
    document.querySelectorAll('.stamp-picker-tab').forEach((b) => {
      b.classList.toggle('active', b.dataset.tab === tab);
    });
    const favTab = el('stamp-picker-tab-favorites');
    if (favTab) favTab.hidden = state.profilePick;
    renderPickerGrid();
  }

  function openPicker(opts = {}) {
    state.profilePick = !!opts.profile;
    state.pickerOpen = true;
    const pop = el('stamp-picker');
    if (pop) {
      pop.hidden = false;
      pop.classList.add('open');
    }
    const title = el('stamp-picker-title');
    if (title) {
      title.textContent = state.profilePick
        ? t('stamps.profilePickTitle', 'Profile stamps')
        : t('stamps.pickerTitle', 'Send stamp');
    }
    void loadManifest().then(() => {
      setPickerTab(state.profilePick ? 'ja' : (state.pickerTab || 'ja'));
    });
  }

  function closePicker() {
    state.pickerOpen = false;
    const wasProfile = state.profilePick;
    state.profilePick = false;
    const pop = el('stamp-picker');
    if (pop) {
      pop.classList.remove('open');
      pop.hidden = true;
    }
    if (wasProfile) renderProfileStampPick();
  }

  async function sendStamp(stampId, locale) {
    if (global.G?.isSpectator) return;
    const now = Date.now();
    if (now - state.lastSendAt < COOLDOWN_MS) {
      if (typeof global.toast === 'function') global.toast(t('stamps.cooldown', 'Wait a moment…'), 1800);
      return;
    }
    if (typeof global.sendAct !== 'function') return;
    state.lastSendAt = now;
    closePicker();
    try {
      await global.sendAct('send_stamp', { stamp_id: stampId, locale: locale === 'en' ? 'en' : 'ja' });
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

  function renderProfileStampPick() {
    const grid = el('banner-stamp-grid');
    if (!grid) return;
    void loadManifest().then(() => {
      grid.replaceChildren();
      const ids = state.favorites.profile || [];
      const allJa = state.manifest?.locales?.ja || [];
      const allEn = state.manifest?.locales?.en || [];
      const map = {};
      [...allJa, ...allEn].forEach((r) => { if (r?.id) map[r.id] = r; });
      ids.forEach((id) => {
        const stamp = map[id];
        if (!stamp) return;
        const tile = document.createElement('div');
        tile.className = 'banner-stamp-tile';
        const img = document.createElement('img');
        img.src = assetUrl(stamp.image);
        img.alt = stamp.label || '';
        tile.appendChild(img);
        grid.appendChild(tile);
      });
      const hint = el('banner-stamp-hint');
      if (hint) {
        hint.textContent = ids.length
          ? t('stamps.profileHint', 'Shown under your leaderboard banner (max 6).')
          : t('stamps.profileHintEmpty', 'Optional — pick up to 6 stamps for your profile.');
      }
    });
  }

  function renderLeaderboardStampProfile(container, profile) {
    if (!container) return;
    let row = container.querySelector('.rank-stamp-profile');
    if (!profile?.length) {
      row?.remove();
      return;
    }
    if (!row) {
      row = document.createElement('div');
      row.className = 'rank-stamp-profile';
      container.appendChild(row);
    }
    row.replaceChildren();
    profile.forEach((st) => {
      const img = document.createElement('img');
      img.className = 'rank-stamp-profile-img';
      img.src = st.image || assetUrl(stampLookupImage(st));
      img.alt = st.label || '';
      img.title = st.label || '';
      row.appendChild(img);
    });
  }

  function stampLookupImage(st) {
    const row = findStamp(st.id, st.locale);
    return row?.image || '';
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
    el('btn-banner-stamps-edit')?.addEventListener('click', () => openPicker({ profile: true }));
    document.querySelectorAll('.stamp-picker-tab').forEach((btn) => {
      btn.addEventListener('click', () => setPickerTab(btn.dataset.tab));
    });
    document.addEventListener('click', (e) => {
      const pop = el('stamp-picker');
      const btn = el('btn-stamp');
      if (!state.pickerOpen || !pop) return;
      if (pop.contains(e.target) || btn?.contains(e.target)) return;
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
    mergeProfileFromAccount();
    void loadManifest();
  }

  global.TCG_STAMPS = {
    init,
    onState: onStampState,
    syncGameUi: syncGameButton,
    openPicker,
    closePicker,
    isHumanPvpMatch,
    applyFavorites,
    renderProfileStampPick,
    renderLeaderboardStampProfile,
    stampAudioEnabled,
    setStampAudioEnabled,
    loadManifest,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(typeof window !== 'undefined' ? window : globalThis);
