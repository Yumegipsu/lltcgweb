/**
 * Profile / friends rail + overlays. Signed-in menus only (hidden on playmat).
 */
(function (global) {
  'use strict';

  function tt(key, fallback, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.tt;
    return typeof fn === 'function' ? fn(key, fallback, vars) : (fallback || key);
  }
  function applyI18n(root) {
    if (global.LLTCG_I18N && global.LLTCG_I18N.applyI18n) global.LLTCG_I18N.applyI18n(root);
  }
  function accountPost(action, body) {
    return global.accountPost(action, body || {});
  }
  function reportReasonLabel(field) {
    const f = String(field || '');
    if (f === 'alt_abuse' || f === 'leaderboard_alt') {
      return tt('profile.reportReasonAlt', 'Leaderboard Alt Abuse');
    }
    return tt('profile.reportReasonBio', 'Profile/deck bio');
  }
  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function myId() {
    const A = global.A || {};
    return String(A.user?.id || A.profile?.user?.id || '');
  }
  function profileRankedMode() {
    try {
      if (typeof global.rankedGameMode === 'function') return global.rankedGameMode();
      if (typeof global.currentGameMode === 'function') return global.currentGameMode();
    } catch (e) { /* ignore */ }
    return 'standard';
  }
  function signedIn() {
    return !!myId();
  }
  function isMod() {
    const A = global.A || {};
    return !!A.user?.is_social_mod;
  }
  function cardImg(no, width) {
    const w = width == null ? 180 : width;
    if (!no) return '';
    if (typeof global.cachedCardImgUrl === 'function') return global.cachedCardImgUrl(no, w);
    const base = global.CARDIMG || './cardimg.php';
    return `${base}?card_no=${encodeURIComponent(no)}${w ? `&w=${w}` : ''}`;
  }
  function unitLogoUrl(unit) {
    const u = String(unit || '');
    const aliases = {
      "μ's": "µ's",
      Muse: "µ's",
      "Mu's": "µ's",
      Sunshine: 'Aqours',
      Superstar: 'Liella!',
      Niji: 'Nijigasaki',
      Hasu: 'Hasunosora',
    };
    const key = aliases[u] || u;
    if (typeof global.sleeveShopUnitIcon === 'function') return global.sleeveShopUnitIcon(key);
    const icons = global.SLEEVE_SHOP_UNIT_ICONS;
    return (icons && (icons[key] || icons.Other)) || '';
  }
  function findLiveCardNo(name) {
    const q = String(name || '').trim().toLowerCase();
    if (!q) return '';
    const G = global.G || {};
    const bag = G.allCards || G.cardsByNo || {};
    const list = Array.isArray(bag) ? bag : Object.values(bag);
    for (let i = 0; i < list.length; i++) {
      const c = list[i];
      if (!c) continue;
      const type = String(c.card_type_en || c.type_en || '').toLowerCase();
      const jp = String(c.card_type || '');
      if (type !== 'live' && jp !== 'ライブ') continue;
      const names = [c.name_en, c.name, c.live_name, c.play_track && c.play_track.live_name];
      if (names.some((n) => String(n || '').trim().toLowerCase() === q)) {
        return String(c.card_no || c.no || '');
      }
    }
    return '';
  }
  function lookupCard(no) {
    const G = global.G || {};
    const A = global.A || {};
    return (G.allCards && G.allCards[no])
      || (A.collection || []).find((r) => r && r.card_no === no)?.card
      || { card_no: no, name: no };
  }
  function isLiveCardMeta(cardOrNo) {
    const card = (cardOrNo && typeof cardOrNo === 'object')
      ? cardOrNo
      : lookupCard(cardOrNo);
    if (typeof global.isLiveCard === 'function' && card && (card.card_type || card.card_type_en)) {
      return !!global.isLiveCard(card);
    }
    const en = String(card.card_type_en || card.type_en || '').toLowerCase();
    const jp = String(card.card_type || '');
    return en === 'live' || jp === 'ライブ';
  }
  function cardFaceHtml(cardOrNo) {
    const no = String((cardOrNo && typeof cardOrNo === 'object')
      ? (cardOrNo.card_no || cardOrNo.no || '')
      : (cardOrNo || ''));
    if (!no) return '';
    const img = `<img src="${esc(cardImg(no))}" alt="">`;
    return isLiveCardMeta(cardOrNo || no) ? `<span class="social-live-art">${img}</span>` : img;
  }
  function liveClass(cardOrNo) {
    return isLiveCardMeta(cardOrNo) ? ' is-live' : '';
  }
  function inspectCard(no) {
    if (!no || typeof global.showCard !== 'function') return;
    global.showCard(lookupCard(no), null, global.G?.gameState, global.G?.playerId);
  }

  let _pickSlot = 0;

  function cardLabel(no) {
    const c = lookupCard(no);
    const fn = global.cardLocaleName;
    return (typeof fn === 'function' ? fn(c) : '') || c.name_en || c.name || no;
  }

  async function ensureCollection() {
    const A = global.A;
    if (A && Array.isArray(A.collection) && A.collection.length) return A.collection;
    if (typeof global.refreshCollection === 'function') {
      await global.refreshCollection();
      return (global.A && global.A.collection) || [];
    }
    const res = await accountPost('collection', {});
    const list = res.collection || [];
    if (A) {
      A.collection = list;
      A.collectionMap = {};
      list.forEach((row) => { A.collectionMap[row.card_no] = row.qty; });
    }
    return list;
  }

  function ownedCollectionCards() {
    const A = global.A || {};
    const G = global.G || {};
    const seen = new Set();
    const out = [];
    (A.collection || []).forEach((row) => {
      const no = String(row && row.card_no || '');
      const qty = Number(row && (row.qty != null ? row.qty : row.count) || 0);
      if (!no || qty < 1 || seen.has(no)) return;
      seen.add(no);
      const card = (row && row.card) || (G.allCards && G.allCards[no]) || { card_no: no, name: no };
      out.push({ card_no: no, card, qty });
    });
    out.sort((a, b) => cardLabel(a.card_no).localeCompare(cardLabel(b.card_no), undefined, { sensitivity: 'base' }));
    return out;
  }

  function showcaseSlots(profile) {
    const by = {};
    (profile.showcase || []).forEach((s, i) => {
      const slot = intvalSafe(s.slot) || (i + 1);
      if (slot >= 1 && slot <= 3) by[slot] = String(s.card_no || '');
    });
    return [1, 2, 3].map((i) => ({ slot: i, card_no: by[i] || '' }));
  }

  function intvalSafe(n) {
    const v = parseInt(n, 10);
    return Number.isFinite(v) ? v : 0;
  }

  function setShowcaseSlot(slot, cardNo) {
    const btn = document.querySelector('#profile-showcase [data-slot="' + slot + '"]');
    if (!btn) return;
    const no = String(cardNo || '');
    btn.setAttribute('data-card', no);
    btn.classList.toggle('is-live', !!no && isLiveCardMeta(no));
    if (no) {
      btn.classList.remove('is-empty');
      btn.innerHTML = cardFaceHtml(no);
    } else {
      btn.classList.remove('is-live');
      btn.classList.add('is-empty');
      btn.textContent = '+';
    }
  }

  function hideShowcasePicker() {
    _pickSlot = 0;
    const box = document.getElementById('profile-card-picker');
    if (box) box.hidden = true;
    document.querySelectorAll('#profile-showcase .social-card-thumb').forEach((b) => {
      b.classList.remove('is-picking');
    });
  }

  function fillShowcasePickerGrid(query) {
    const grid = document.getElementById('profile-card-grid');
    if (!grid) return;
    const q = String(query || '').trim().toLowerCase();
    const cards = ownedCollectionCards().filter((row) => {
      if (!q) return true;
      const hay = [row.card_no, cardLabel(row.card_no), row.card.name_en, row.card.name].filter(Boolean).join(' ').toLowerCase();
      return hay.includes(q);
    });
    if (!cards.length) {
      grid.innerHTML = `<p class="social-picker-empty">${esc(tt('profile.noOwnedCards', 'No matching cards in your collection.'))}</p>`;
      return;
    }
    grid.innerHTML = cards.map((row) =>
      `<button type="button" class="social-picker-card${liveClass(row.card || row.card_no)}" data-pick="${esc(row.card_no)}" title="${esc(cardLabel(row.card_no))}">${cardFaceHtml(row.card || row.card_no)}</button>`
    ).join('');
    grid.querySelectorAll('[data-pick]').forEach((b) => {
      b.addEventListener('click', () => {
        if (_pickSlot < 1) return;
        setShowcaseSlot(_pickSlot, b.getAttribute('data-pick'));
        hideShowcasePicker();
      });
    });
  }

  async function openShowcasePicker(slot) {
    _pickSlot = slot;
    document.querySelectorAll('#profile-showcase .social-card-thumb').forEach((b) => {
      b.classList.toggle('is-picking', parseInt(b.getAttribute('data-slot'), 10) === slot);
    });
    const box = document.getElementById('profile-card-picker');
    const grid = document.getElementById('profile-card-grid');
    if (!box || !grid) return;
    box.hidden = false;
    grid.innerHTML = `<p class="social-picker-empty">${esc(tt('social.loading', 'Loading…'))}</p>`;
    try {
      await ensureCollection();
    } catch (e) {
      grid.innerHTML = `<p class="social-picker-empty">${esc(e.message || 'Could not load collection')}</p>`;
      return;
    }
    const search = document.getElementById('profile-card-search');
    if (search) search.value = '';
    fillShowcasePickerGrid('');
  }

  let _screen = 'auth';
  let _friendsTab = 'friends';
  let _editing = false;
  let _deckOpen = false;
  let _profileCache = null;

  /** Menus whose shell is already wider than the 720px hub column. */
  const WIDE_RAIL_SCREENS = {
    deck: 1,
    sticker: 1,
    'playmat-shop': 1,
    'sleeve-shop': 1,
    tournament: 1,
  };

  function playSocialSfx(id, volume) {
    try {
      if (typeof global.sfxPlay === 'function') global.sfxPlay(id, { volume: volume ?? 0.9 });
      else global.LLTCG_SFX?.play?.(id, { volume: volume ?? 0.9 });
    } catch (e) { /* ignore */ }
  }

  function closeSocialMenu(opts) {
    const rail = document.getElementById('social-rail');
    const btn = document.getElementById('btn-social-menu');
    const wasOpen = !!(rail && rail.classList.contains('is-open'));
    rail?.classList.remove('is-open');
    btn?.setAttribute('aria-expanded', 'false');
    if (wasOpen && !opts?.silent) playSocialSfx('menu_back', 0.85);
  }

  function toggleSocialMenu() {
    const rail = document.getElementById('social-rail');
    const btn = document.getElementById('btn-social-menu');
    if (!rail || rail.hidden) return;
    const open = !rail.classList.contains('is-open');
    rail.classList.toggle('is-open', open);
    btn?.setAttribute('aria-expanded', open ? 'true' : 'false');
    playSocialSfx(open ? 'menu_tap' : 'menu_back', open ? 0.95 : 0.85);
  }

  function syncSocialRail(screenId) {
    _screen = screenId || _screen;
    const rail = document.getElementById('social-rail');
    if (!rail) return;
    rail.classList.remove('social-rail--edge', 'social-rail--booster', 'social-rail--pack', 'social-rail--card-list', 'social-rail--lobby');
    if (_screen === 'booster') rail.classList.add('social-rail--booster');
    else if (_screen === 'lobby') rail.classList.add('social-rail--lobby');
    else if (_screen === 'card-list') rail.classList.add('social-rail--card-list');
    else if (WIDE_RAIL_SCREENS[_screen]) rail.classList.add('social-rail--edge');
    const hide = _screen === 'game' || _screen === 'pack-results' || !signedIn();
    rail.hidden = hide;
    if (hide) closeSocialMenu({ silent: true });
    const logged = signedIn();
    rail.querySelectorAll('button[data-social]').forEach((btn) => {
      btn.disabled = !logged;
      btn.title = logged ? '' : tt('social.signInHint', 'Sign in with Discord to use Profile and Friends');
    });
    const modBtn = document.getElementById('btn-social-mod');
    if (modBtn) modBtn.hidden = !isMod();
    if (logged) showPendingNotices();
  }

  function openOverlay(id) {
    const el = document.getElementById(id);
    if (!el) return;
    let z = 8760;
    document.querySelectorAll('.social-overlay.open').forEach((n) => {
      n.classList.remove('social-stack-top');
      if (n === el) return;
      const nz = parseInt(n.style.zIndex || window.getComputedStyle(n).zIndex, 10);
      if (Number.isFinite(nz)) z = Math.max(z, nz);
    });
    /* Friends is later in the DOM than Profile, so equal z-index keeps Profile hidden.
       Re-append + bump z so View from Recents stacks on top. Card inspect stays at 8900. */
    if (el.parentNode) el.parentNode.appendChild(el);
    el.style.setProperty('z-index', String(Math.min(z + 20, 8880)));
    el.classList.add('open', 'social-stack-top');
    el.setAttribute('aria-hidden', 'false');
    el.hidden = false;
    document.body.classList.add('social-overlay-open');
    closeSocialMenu({ silent: true });
    playSocialSfx('screen_open', 0.9);
    applyI18n(el);
  }
  function closeOverlay(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('open', 'social-stack-top');
    el.setAttribute('aria-hidden', 'true');
    el.style.removeProperty('z-index');
    if (el.hasAttribute('hidden') || id === 'overlay-titles' || id === 'overlay-invite-friend'
        || id === 'overlay-social-notice') {
      el.hidden = true;
    }
    if (!document.querySelector('.social-overlay.open')) {
      document.body.classList.remove('social-overlay-open');
    }
  }
  function closeAllSocial() {
    document.querySelectorAll('.social-overlay.open').forEach((n) => closeOverlay(n.id));
  }

  function setErr(id, msg) {
    const n = document.getElementById(id);
    if (n) n.textContent = msg || '';
  }

  async function openProfile(userId) {
    if (!signedIn()) return;
    const uid = userId || myId();
    openOverlay('overlay-profile');
    const root = document.getElementById('profile-body');
    if (root) root.innerHTML = tt('social.loading', 'Loading…');
    try {
      const data = await accountPost('social_profile', { user_id: uid, game_mode: profileRankedMode() });
      _editing = false;
      _deckOpen = false;
      renderProfile(data);
    } catch (e) {
      if (root) root.textContent = e.message || tt('social.error', 'Could not load profile');
    }
  }

  function copyText(text, btn) {
    const v = String(text || '');
    if (!v) return;
    const done = () => {
      if (btn) {
        btn.classList.add('is-copied');
        const prev = btn.getAttribute('data-label') || btn.textContent;
        btn.setAttribute('data-label', prev);
        btn.textContent = tt('profile.copied', 'Copied');
        setTimeout(() => {
          btn.classList.remove('is-copied');
          btn.textContent = prev;
        }, 1400);
      }
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(v).then(done).catch(() => {});
    } else {
      const ta = document.createElement('textarea');
      ta.value = v;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) { /* ignore */ }
      ta.remove();
      done();
    }
  }

  function friendActionsHtml(data, p) {
    if (data.is_self) return '';
    const st = data.friend_status || (data.is_friend ? 'friends' : 'none');
    if (st === 'friends') {
      return `<p class="social-friend-status">${esc(tt('friends.alreadyFriends', 'Friends'))}</p>`;
    }
    if (st === 'outgoing') {
      return `<p class="social-friend-status">${esc(tt('friends.requestSent', 'Friend request sent'))}</p>`;
    }
    if (st === 'incoming') {
      return `<div class="social-friend-actions">
        <button type="button" class="btn-grad" id="btn-profile-accept">${esc(tt('friends.accept', 'Accept'))}</button>
        <button type="button" class="btn-ghost" id="btn-profile-decline">${esc(tt('friends.decline', 'Decline'))}</button>
      </div>`;
    }
    return `<div class="social-friend-actions">
      <button type="button" class="btn-grad" id="btn-profile-add-friend">${esc(tt('friends.addFromProfile', 'Send friend request'))}</button>
    </div>`;
  }

  function friendIdBtn(code) {
    const id = String(code || '');
    if (!id) return '';
    return `<button type="button" class="social-friend-id" data-copy="${esc(id)}">${esc(tt('profile.friendId', 'Friend ID'))}: ${esc(id)}</button>`;
  }

  function placeLabel(place) {
    const n = Number(place) || 0;
    if (n === 1) return tt('tournament.place.first', '1st');
    if (n === 2) return tt('tournament.place.second', '2nd');
    if (n === 3) return tt('tournament.place.third', '3rd');
    return tt('tournament.place.nth', '{n}th', { n: n });
  }

  function tournamentSectionHtml(p) {
    const tr = p.tournament;
    if (!tr || typeof tr !== 'object') return '';
    const wins = Number(tr.match_wins) || 0;
    const losses = Number(tr.match_losses) || 0;
    const coins = Number(tr.coins_earned) || 0;
    const events = Number(tr.events_played) || 0;
    const places = Array.isArray(tr.placements) ? tr.placements : [];
    let list = '';
    if (!places.length) {
      list = `<p class="social-vis-hint">${esc(tt('profile.tournamentEmpty', 'No tournament placements yet.'))}</p>`;
    } else {
      list = '<ul class="social-tournament-list">' + places.map((row) => {
        const title = row.title || row.tournament_id || 'Tournament';
        const place = placeLabel(row.place);
        const prize = Number(row.coins) || 0;
        const prizeBit = prize > 0
          ? ` · ${esc(tt('profile.tournamentPrize', '{n} Coins', { n: prize }))}`
          : '';
        return `<li><span class="social-tournament-place">${esc(place)}</span>`
          + `<span class="social-tournament-title">${esc(title)}</span>${prizeBit}</li>`;
      }).join('') + '</ul>';
    }
    return `
      <h4>${esc(tt('profile.tournament', 'Tournaments'))}</h4>
      <p>${esc(tt('profile.tournamentRecord', 'Tournament matches'))}: ${wins}–${losses}
         · ${esc(tt('profile.tournamentEvents', 'Events'))}: ${events}
         · ${esc(tt('profile.tournamentCoins', 'Coins earned'))}: ${coins}</p>
      <h5 class="social-tournament-sub">${esc(tt('profile.tournamentPlacements', 'Recent placements'))}</h5>
      ${list}
    `;
  }

  function renderProfile(data) {
    const p = data.profile || {};
    const self = !!data.is_self;
    const root = document.getElementById('profile-body');
    if (!root) return;
    _profileCache = data;
    const bioMax = 100;
    const vis = p.featured_deck?.visibility || 'private';
    const deck = p.featured_deck || {};
    const show = showcaseSlots(p).map((s) => {
      const no = s.card_no || '';
      const canPick = self && _editing;
      const disabled = !canPick && !no ? 'disabled' : '';
      const empty = no ? '' : ' is-empty';
      return `<button type="button" class="social-card-thumb${empty}${no ? liveClass(no) : ''}" data-slot="${s.slot}" data-card="${esc(no)}" ${disabled}>${
        no ? cardFaceHtml(no) : '+'
      }</button>`;
    }).join('');
    const ranked = p.ranked || {};
    const bioText = String(p.bio || '').trim();
    const deckDesc = String(deck.desc || '').trim();
    const visLabel = vis === 'public'
      ? tt('profile.visPublic', 'Public')
      : vis === 'friends'
        ? tt('profile.visFriends', 'Friends')
        : tt('profile.visPrivate', 'Private');
    const titleHtml = `<div class="player-title-slot" id="profile-title-slot"></div>`;
    const hasTitle = !!(p.title && p.title.url);
    const titleAction = self && !hasTitle
      ? `<button type="button" class="btn-ghost" id="btn-profile-titles">${esc(tt('titles.set', 'Set title'))}</button>`
      : '';
    root.innerHTML = `
      <div class="social-head">
        <img class="social-avatar" alt="" src="${esc(p.avatar_url || '')}">
        <div>
          <h3>${esc(p.username || 'Player')}</h3>
          ${friendIdBtn(p.friend_code)}
          ${titleHtml}
          ${titleAction}
        </div>
      </div>
      ${friendActionsHtml(data, p)}
      ${bioText ? `<p class="social-bio-text">${esc(bioText)}</p>` : ''}
      ${self && !_editing ? `<button type="button" class="btn-ghost" id="btn-profile-edit">${tt('profile.edit', 'Edit')}</button>` : ''}
      ${self && _editing ? `<div class="field"><label for="profile-bio">${tt('profile.bio', 'Bio')}</label>
        <textarea class="social-bio" id="profile-bio" maxlength="${bioMax}">${esc(p.bio || '')}</textarea>
        <div class="social-char-count" id="profile-bio-count"></div></div>` : ''}
      ${p.bio_locked && self ? `<p class="social-err">${tt('profile.bioLocked', 'Bio editing is locked.')}</p>` : ''}
      <h4 data-i18n="profile.showcase">${tt('profile.showcase', 'Showcase')}</h4>
      <div class="social-showcase" id="profile-showcase">${show}</div>
      ${self && _editing ? `<p class="social-vis-hint">${esc(tt('profile.showcaseHint', 'Tap a slot to choose a card from your collection.'))}</p>
        <div id="profile-card-picker" class="social-card-picker" hidden>
          <div class="field">
            <label for="profile-card-search">${tt('profile.searchCollection', 'Search collection')}</label>
            <input id="profile-card-search" type="search" autocomplete="off">
          </div>
          <div class="social-picker-actions">
            <button type="button" class="btn-ghost" id="profile-card-clear">${tt('profile.clearSlot', 'Clear slot')}</button>
            <button type="button" class="btn-ghost" id="profile-card-cancel">${tt('social.close', 'Close')}</button>
          </div>
          <div class="social-picker-grid" id="profile-card-grid"></div>
        </div>` : ''}
      <p>${tt('profile.rankedWl', 'Ranked')}: ${ranked.wins || 0}–${ranked.losses || 0}
         · ${tt('profile.unranked', 'Unranked games')}: ${p.unranked_games || 0}</p>
      ${tournamentSectionHtml(p)}
      <button type="button" class="btn-ghost" id="btn-profile-stats">${tt('profile.gameStats', 'Game stats')}</button>
      <h4>${tt('profile.featuredDeck', 'Featured deck')}</h4>
      ${self && _editing ? `<div class="field"><label for="profile-deck-vis">${tt('profile.visibility', 'Visibility')}</label>
        <span class="llc-select-wrap"><select id="profile-deck-vis">
          <option value="private">${tt('profile.visPrivate', 'Private')}</option>
          <option value="friends">${tt('profile.visFriends', 'Friends')}</option>
          <option value="public">${tt('profile.visPublic', 'Public')}</option>
        </select></span></div>
        <div class="field"><label for="profile-deck-desc">${tt('profile.deckDesc', 'Deck description')}</label>
        <textarea id="profile-deck-desc" maxlength="200">${esc(deck.desc || '')}</textarea></div>
        <div class="field"><label for="profile-deck-id">${tt('profile.featuredDeck', 'Featured deck')}</label>
        <span class="llc-select-wrap"><select id="profile-deck-id"><option value="0">${tt('profile.useEquipped', 'Currently equipped')}</option></select></span></div>
        <button type="button" class="btn-grad" id="btn-profile-save">${tt('profile.save', 'Save')}</button>
        <button type="button" class="btn-ghost" id="btn-profile-edit-cancel">${tt('social.close', 'Close')}</button>` : ''}
      ${self && !_editing ? `<p class="social-vis-hint">${esc(visLabel)}</p>` : ''}
      <div id="profile-deck-view"></div>
      ${!self ? `<div class="social-report">
        <button type="button" class="btn-ghost" id="btn-profile-report">${tt('profile.report', 'Report')}</button>
        <div class="social-report-confirm" id="profile-report-confirm">
          <p>${esc(tt('profile.reportAsk', 'Choose a reason. Moderators will review this report.'))}</p>
          <label class="social-report-opt">
            <input type="radio" name="profile-report-reason" value="profile_bio" checked>
            ${esc(tt('profile.reportReasonBio', 'Profile/deck bio'))}
          </label>
          <label class="social-report-opt">
            <input type="radio" name="profile-report-reason" value="alt_abuse">
            ${esc(tt('profile.reportReasonAlt', 'Leaderboard Alt Abuse'))}
          </label>
          <div class="social-picker-actions">
            <button type="button" class="btn-grad" id="btn-profile-report-yes">${tt('profile.reportConfirm', 'Yes, report')}</button>
            <button type="button" class="btn-ghost" id="btn-profile-report-no">${tt('social.close', 'Cancel')}</button>
          </div>
        </div>
      </div>` : ''}
      <p class="social-err" id="profile-err"></p>
    `;
    const visEl = document.getElementById('profile-deck-vis');
    if (visEl) visEl.value = vis;
    const deckSel = document.getElementById('profile-deck-id');
    if (deckSel && Array.isArray(deck.decks)) {
      deck.decks.forEach((d) => {
        const o = document.createElement('option');
        o.value = String(d.id);
        o.textContent = d.name || ('Slot ' + d.slot);
        if (String(d.id) === String(deck.featured_deck_id || '')) o.selected = true;
        deckSel.appendChild(o);
      });
    }
    const bio = document.getElementById('profile-bio');
    const count = document.getElementById('profile-bio-count');
    const tick = () => { if (count && bio) count.textContent = `${bio.value.length}/${bioMax}`; };
    if (bio) { bio.addEventListener('input', tick); tick(); }
    const titleSlot = document.getElementById('profile-title-slot');
    if (titleSlot && typeof window.TCGTitles?.mountTitleEl === 'function') {
      window.TCGTitles.mountTitleEl(titleSlot, p.title || null, {
        emptyLabel: tt('titles.noneSet', 'No title set'),
        button: self,
        onClick: self ? () => { if (typeof window.openTitlePicker === 'function') window.openTitlePicker(); } : null,
      });
    }
    document.getElementById('btn-profile-titles')?.addEventListener('click', () => {
      if (typeof window.openTitlePicker === 'function') window.openTitlePicker();
    });
    window.refreshProfileTitleDisplay = (title) => {
      if (_profileCache?.profile) _profileCache.profile.title = title || null;
      const slot = document.getElementById('profile-title-slot');
      const isSelf = !!_profileCache?.is_self;
      if (slot && typeof window.TCGTitles?.mountTitleEl === 'function') {
        window.TCGTitles.mountTitleEl(slot, title || null, {
          emptyLabel: tt('titles.noneSet', 'No title set'),
          button: isSelf,
          onClick: isSelf ? () => { if (typeof window.openTitlePicker === 'function') window.openTitlePicker(); } : null,
        });
      }
      let setBtn = document.getElementById('btn-profile-titles');
      const has = !!(title && title.url);
      if (isSelf && !has && !setBtn && slot?.parentElement) {
        setBtn = document.createElement('button');
        setBtn.type = 'button';
        setBtn.className = 'btn-ghost';
        setBtn.id = 'btn-profile-titles';
        setBtn.textContent = tt('titles.set', 'Set title');
        setBtn.addEventListener('click', () => {
          if (typeof window.openTitlePicker === 'function') window.openTitlePicker();
        });
        slot.parentElement.insertBefore(setBtn, slot.nextSibling);
      } else if (has && setBtn) {
        setBtn.remove();
      } else if (setBtn && !has) {
        setBtn.textContent = tt('titles.set', 'Set title');
        setBtn.hidden = false;
      }
    };
    root.querySelectorAll('#profile-showcase .social-card-thumb').forEach((b) => {
      b.addEventListener('click', () => {
        const slot = parseInt(b.getAttribute('data-slot'), 10);
        const no = b.getAttribute('data-card') || '';
        if (self && _editing) openShowcasePicker(slot);
        else if (no) inspectCard(no);
      });
    });
    root.querySelectorAll('#profile-deck-view [data-card], .social-preview-card[data-card]').forEach((b) => {
      b.addEventListener('click', () => inspectCard(b.getAttribute('data-card')));
    });
    document.getElementById('profile-card-search')?.addEventListener('input', (e) => {
      fillShowcasePickerGrid(e.target.value);
    });
    document.getElementById('profile-card-clear')?.addEventListener('click', () => {
      if (_pickSlot >= 1) setShowcaseSlot(_pickSlot, '');
      hideShowcasePicker();
    });
    document.getElementById('profile-card-cancel')?.addEventListener('click', () => hideShowcasePicker());
    root.querySelectorAll('[data-copy]').forEach((b) => {
      b.addEventListener('click', () => copyText(b.getAttribute('data-copy'), b));
    });
    document.getElementById('btn-profile-edit')?.addEventListener('click', () => {
      _editing = true;
      renderProfile(_profileCache);
      ensureCollection().catch(() => {});
    });
    document.getElementById('btn-profile-edit-cancel')?.addEventListener('click', () => {
      _editing = false;
      renderProfile(_profileCache);
    });
    document.getElementById('btn-profile-stats')?.addEventListener('click', () => openStats(p.id));
    document.getElementById('btn-profile-save')?.addEventListener('click', () => saveProfile(p));
    document.getElementById('btn-profile-add-friend')?.addEventListener('click', async () => {
      setErr('profile-err', '');
      const btn = document.getElementById('btn-profile-add-friend');
      if (btn) btn.disabled = true;
      try {
        await accountPost('social_friend_add', { user_id: p.id });
        await openProfile(p.id);
      } catch (e) {
        if (btn) btn.disabled = false;
        setErr('profile-err', e.message);
      }
    });
    document.getElementById('btn-profile-accept')?.addEventListener('click', async () => {
      setErr('profile-err', '');
      try {
        await accountPost('social_friend_accept', { user_id: p.id });
        await openProfile(p.id);
      } catch (e) { setErr('profile-err', e.message); }
    });
    document.getElementById('btn-profile-decline')?.addEventListener('click', async () => {
      setErr('profile-err', '');
      try {
        await accountPost('social_friend_decline', { user_id: p.id });
        await openProfile(p.id);
      } catch (e) { setErr('profile-err', e.message); }
    });
    document.getElementById('btn-profile-report')?.addEventListener('click', () => {
      const box = document.getElementById('profile-report-confirm');
      if (!box) return;
      box.classList.add('is-open');
      box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
    document.getElementById('btn-profile-report-no')?.addEventListener('click', () => {
      document.getElementById('profile-report-confirm')?.classList.remove('is-open');
    });
    document.getElementById('btn-profile-report-yes')?.addEventListener('click', async () => {
      const reason = document.querySelector('input[name="profile-report-reason"]:checked')?.value || 'profile_bio';
      const yes = document.getElementById('btn-profile-report-yes');
      if (yes) yes.disabled = true;
      const deckDesc = String(p.featured_deck?.desc || '');
      const snippet = reason === 'alt_abuse'
        ? String(p.username || p.friend_code || p.id || '')
        : [p.bio, deckDesc].filter(Boolean).join('\n').slice(0, 200);
      try {
        await accountPost('social_report', { user_id: p.id, field: reason, reason, snippet });
        setErr('profile-err', tt('profile.reported', 'Report sent.'));
        document.getElementById('profile-report-confirm')?.classList.remove('is-open');
      } catch (e) {
        setErr('profile-err', e.message);
        if (yes) yes.disabled = false;
      }
    });
    renderDeckComp(deck, deckDesc);
    applyI18n(root);
  }

  const COST_ORDER = ['1-3', '4', '5-8', '9', '10-11', '12-15', '16+'];
  const HEART_COLORS = [
    { key: 'pink', aliases: ['桃'] },
    { key: 'red', aliases: ['赤'] },
    { key: 'yellow', aliases: ['黄'] },
    { key: 'green', aliases: ['緑'] },
    { key: 'blue', aliases: ['青'] },
    { key: 'purple', aliases: ['紫'] },
    { key: 'any', aliases: ['gray', 'grey', 'colourless', 'colorless'] },
    { key: 'all' },
  ];
  const BLADE_HEART_FILES = {
    pink: 'blade_heart01.png', red: 'blade_heart02.png', yellow: 'blade_heart03.png',
    green: 'blade_heart04.png', blue: 'blade_heart05.png', purple: 'blade_heart06.png',
    any: 'heart00.png', all: 'icon_b_all.png',
  };
  const HEART_FILES = {
    pink: 'heart01.png', red: 'heart02.png', yellow: 'heart03.png',
    green: 'heart04.png', blue: 'heart05.png', purple: 'heart06.png',
    any: 'heart00.png', all: 'icon_b_all.png',
  };

  function heartCount(blades, spec) {
    let n = Number(blades[spec.key] || 0);
    (spec.aliases || []).forEach((a) => { n += Number(blades[a] || 0); });
    return n;
  }

  function logChart(title, cols) {
    const max = Math.max(1, ...cols.map((c) => c.n));
    return `<div class="social-log-chart">
      <div class="social-log-title">${esc(title)}</div>
      <div class="social-log-cols">${cols.map((c) => {
        const h = c.n > 0 ? Math.max(8, Math.round(100 * c.n / max)) : 0;
        return `<div class="social-log-col">
          <div class="social-log-cap">${c.n}</div>
          <div class="social-log-track"><div class="social-log-bar" style="height:${h}%"></div></div>
          <div class="social-log-foot">${c.foot}</div>
        </div>`;
      }).join('')}</div>
    </div>`;
  }

  function deckPreviewHtml(deck) {
    const prev = deck.preview || {};
    const items = []
      .concat(prev.members || [])
      .concat(prev.lives || []);
    const row = items.length
      ? items.map((c) =>
        `<span class="social-preview-card${liveClass(c)}"><span class="social-preview-face">${cardFaceHtml(c)}</span><em>×${c.count || 1}</em></span>`
      ).join('')
      : '<span class="social-preview-empty">—</span>';
    return `<button type="button" class="social-deck-preview" id="btn-deck-open">
      <div class="social-preview-row">${row}</div>
      <span class="social-preview-hint">${esc(tt('profile.openDeck', 'View full deck'))}</span>
    </button>`;
  }

  function renderDeckComp(deck, deckDesc) {
    const box = document.getElementById('profile-deck-view');
    if (!box) return;
    if (!deck.visible) {
      box.innerHTML = `<p>${tt('profile.deckHidden', 'This deck is private.')}</p>`;
      return;
    }
    const c = deck.composition || {};
    const buckets = c.cost_buckets || {};
    const blades = c.blade_hearts || {};
    const hearts = c.hearts || {};
    const desc = String(deckDesc || deck.desc || '').trim();
    const name = String(deck.name || '').trim();
    if (!_deckOpen) {
      box.innerHTML = `
        ${name ? `<p class="social-deck-name"><strong>${esc(name)}</strong></p>` : ''}
        ${desc ? `<p class="social-bio-text">${esc(desc)}</p>` : ''}
        ${deckPreviewHtml(deck)}`;
      document.getElementById('btn-deck-open')?.addEventListener('click', () => {
        _deckOpen = true;
        renderDeckComp(deck, desc);
      });
      return;
    }
    const costCols = COST_ORDER.map((k) => ({
      n: Number(buckets[k] || 0),
      foot: esc(k),
    }));
    const bladeCols = HEART_COLORS.map((spec) => ({
      n: heartCount(blades, spec),
      foot: `<img class="social-log-heart" src="icons/${BLADE_HEART_FILES[spec.key]}" alt="${esc(spec.key)}">`,
    }));
    const heartCols = HEART_COLORS.filter((spec) => spec.key !== 'all').map((spec) => ({
      n: heartCount(hearts, spec),
      foot: `<img class="social-log-heart" src="icons/${HEART_FILES[spec.key]}" alt="${esc(spec.key)}">`,
    }));
    const grid = (deck.cards || []).map((card) =>
      `<button type="button" class="social-card-thumb${liveClass(card)}" data-card="${esc(card.card_no)}">${cardFaceHtml(card)}</button>`
    ).join('');
    box.innerHTML = `
      ${name ? `<p class="social-deck-name"><strong>${esc(name)}</strong></p>` : ''}
      ${desc ? `<p class="social-bio-text">${esc(desc)}</p>` : ''}
      <button type="button" class="btn-ghost" id="btn-deck-close">${tt('profile.closeDeck', 'Hide full deck')}</button>
      <div class="social-log-wrap">
        ${logChart(tt('profile.cost', 'Cost'), costCols)}
        ${logChart(tt('profile.bladeHearts', 'Blade hearts'), bladeCols)}
        ${logChart(tt('profile.hearts', 'Hearts'), heartCols)}
      </div>
      <div class="social-deck-grid">${grid}</div>`;
    document.getElementById('btn-deck-close')?.addEventListener('click', () => {
      _deckOpen = false;
      renderDeckComp(deck, desc);
    });
    box.querySelectorAll('[data-card]').forEach((b) => {
      b.addEventListener('click', () => inspectCard(b.getAttribute('data-card')));
    });
  }

  async function saveProfile() {
    setErr('profile-err', '');
    const showcase = [];
    document.querySelectorAll('#profile-showcase [data-card]').forEach((b, i) => {
      showcase.push({ slot: i + 1, card_no: b.getAttribute('data-card') || '' });
    });
    try {
      const data = await accountPost('social_save_profile', {
        bio: document.getElementById('profile-bio')?.value || '',
        featured_deck_visibility: document.getElementById('profile-deck-vis')?.value || 'private',
        featured_deck_desc: document.getElementById('profile-deck-desc')?.value || '',
        featured_deck_id: parseInt(document.getElementById('profile-deck-id')?.value || '0', 10),
        showcase,
      });
      _editing = false;
      _deckOpen = false;
      renderProfile(data);
    } catch (e) {
      setErr('profile-err', e.message);
    }
  }

  function formatMode(mode) {
    const m = String(mode || '');
    if (m === 'ranked') return tt('profile.modeRanked', 'Ranked');
    if (m === 'casual' || m === 'unranked') return tt('profile.modeCasual', 'Casual');
    if (m === 'match') return tt('profile.modeMatch', 'Match');
    return m || tt('profile.modeMatch', 'Match');
  }

  function statBars(rows, labelKey, kind) {
    const list = rows || [];
    const max = Math.max(1, ...list.map((r) => r.count));
    return list.map((r) => {
      const label = r[labelKey] || r.idol || r.unit || r.live || '';
      let cardNo = String(r.card_no || '');
      let face = r.logo || r.portrait || '';
      let imgClass = 'social-idol-face';
      if (kind === 'unit') {
        if (!face) face = unitLogoUrl(label);
        imgClass = 'social-unit-logo';
      } else if (kind === 'live') {
        if (!cardNo) cardNo = findLiveCardNo(label);
        if (cardNo) face = cardImg(cardNo, 96);
        imgClass = 'social-live-thumb';
      }
      const img = face
        ? `<img class="${imgClass}" src="${esc(face)}" alt="">`
        : '<span class="social-idol-face is-empty"></span>';
      const art = cardNo
        ? `<button type="button" class="social-bar-art" data-card="${esc(cardNo)}">${img}</button>`
        : `<span class="social-bar-art">${img}</span>`;
      return `<div class="social-bar">${art}<span>${esc(label)}</span><div class="social-bar-track"><div class="social-bar-fill" style="width:${Math.round(100 * r.count / max)}%"></div></div><span class="social-bar-n">${r.count}</span></div>`;
    }).join('');
  }

  async function openStats(userId) {
    openOverlay('overlay-profile-stats');
    const root = document.getElementById('profile-stats-body');
    if (root) root.innerHTML = tt('social.loading', 'Loading…');
    try {
      const data = await accountPost('social_stats', { user_id: userId });
      const modes = (data.modes || []).map((m) => {
        const pct = (m.win_pct != null ? m.win_pct : m.winPct);
        const known = !(m.wl_known === false || m.wl_known === false);
        const rec = known
          ? `${m.wins}–${m.losses}`
          : `${m.games} ${tt('profile.games', 'games')}`;
        const sub = known && m.games ? `${m.games} · ${pct}%` : '';
        return `<div class="social-stat-chip"><span class="social-stat-chip-label">${esc(formatMode(m.mode))}</span><strong>${esc(rec)}</strong>${sub ? `<span class="social-stat-chip-sub">${esc(sub)}</span>` : ''}</div>`;
      }).join('');
      const opps = (data.opponents || []).filter((o) => (Number(o.wins) + Number(o.losses)) > 0).map((o) =>
        `<button type="button" class="social-opp-row" data-uid="${esc(o.id)}">${o.avatar_url || o.avatar_url ? `<img class="av" src="${esc(o.avatar_url || o.avatar_url)}" alt="">` : '<span class="av av-empty"></span>'}<span class="social-opp-name">${esc(o.username)}</span><span class="social-record">${Number(o.wins)}–${Number(o.losses)}</span></button>`
      ).join('');
      const idols = statBars(data.idols, 'idol', 'idol');
      const units = statBars(data.units, 'unit', 'unit');
      const lives = statBars(data.lives, 'live', 'live');
      const hist = (data.history || []).map((h) => {
        const res = String(h.result || '');
        const name = h.opponent && h.opponent.username ? h.opponent.username : '';
        return `<div class="social-hist-row"><span class="social-result social-result--${esc(res)}">${esc(res)}</span><span class="social-hist-name">${esc(name)}</span><span class="social-hist-mode">${esc(formatMode(h.mode))}</span></div>`;
      }).join('');
      root.innerHTML = `
        <div class="social-stats">
          <section class="social-stat-block"><h3>${tt('profile.byMode', 'By mode')}</h3><div class="social-stat-chips">${modes || '<p class="social-stat-empty">—</p>'}</div></section>
          <section class="social-stat-block"><h3>${tt('profile.opponents', 'Most-played opponents')}</h3><div class="social-opp-list">${opps || '<p class="social-stat-empty">—</p>'}</div></section>
          <section class="social-stat-block"><h3>${tt('profile.idols', 'Top idols')}</h3><div class="social-bars">${idols || '<p class="social-stat-empty">—</p>'}</div></section>
          <section class="social-stat-block"><h3>${tt('profile.units', 'Top units')}</h3><div class="social-bars">${units || '<p class="social-stat-empty">—</p>'}</div></section>
          <section class="social-stat-block"><h3>${tt('profile.lives', 'Live Success')}</h3><div class="social-bars">${lives || '<p class="social-stat-empty">—</p>'}</div></section>
          <section class="social-stat-block"><h3>${tt('profile.history', 'Match history')}</h3><div class="social-hist-list">${hist || '<p class="social-stat-empty">—</p>'}</div></section>
        </div>`;
      root.querySelectorAll('[data-uid]').forEach((b) => {
        b.addEventListener('click', () => { closeOverlay('overlay-profile-stats'); openProfile(b.getAttribute('data-uid')); });
      });
      root.querySelectorAll('[data-card]').forEach((b) => {
        b.addEventListener('click', () => inspectCard(b.getAttribute('data-card')));
      });
    } catch (e) {
      if (root) root.textContent = e.message;
    }
  }

  async function openFriends() {
    if (!signedIn()) return;
    openOverlay('overlay-friends');
    await refreshFriends();
  }

  async function refreshFriends() {
    const root = document.getElementById('friends-body');
    if (!root) return;
    try {
      const data = await accountPost('social_friends', {});
      const listFor = (arr, kind) => (arr || []).map((u) => {
        let acts = `<button type="button" class="btn-ghost" data-open="${esc(u.id)}">${tt('friends.view', 'View')}</button>`;
        if (kind === 'in') {
          acts += `<button type="button" class="btn-ghost" data-acc="${esc(u.id)}">${tt('friends.accept', 'Accept')}</button>
                   <button type="button" class="btn-ghost" data-dec="${esc(u.id)}">${tt('friends.decline', 'Decline')}</button>`;
        } else if (kind === 'friends') {
          acts += `<button type="button" class="btn-ghost" data-rm="${esc(u.id)}">${tt('friends.remove', 'Remove')}</button>`;
        }
        return `<div class="social-row"><img class="av" src="${esc(u.avatar_url || '')}" alt=""><span>${esc(u.username)}</span>${acts}</div>`;
      }).join('');
      const pane = _friendsTab === 'requests'
        ? `<h4>${tt('friends.incoming', 'Incoming')}</h4>${listFor(data.incoming, 'in') || '<p>—</p>'}
           <h4>${tt('friends.outgoing', 'Outgoing')}</h4>${listFor(data.outgoing, 'out') || '<p>—</p>'}`
        : _friendsTab === 'recent'
          ? `<p class="social-vis-hint">${esc(tt('friends.recentHint', "Users you've played against recently"))}</p>${listFor(data.recent, 'recent') || '<p>—</p>'}`
          : listFor(data.friends, 'friends') || '<p>—</p>';
      const addForm = _friendsTab === 'requests'
        ? `<form id="friends-add-form" class="social-add-form">
          <div class="field">
            <label for="friends-code-input">${tt('friends.add', 'Add')}</label>
            <input id="friends-code-input" maxlength="12" placeholder="${esc(tt('friends.codePlaceholder', 'LCXXXXXX'))}">
          </div>
          <button type="submit" class="btn-grad">${tt('friends.add', 'Add')}</button>
        </form>`
        : '';
      root.innerHTML = `
        <p class="social-friend-id-row">${friendIdBtn(data.friend_code)}
           <span>(${data.count || 0}/${data.cap || 25})</span></p>
        <div class="social-tabs">
          <button type="button" data-tab="friends" aria-selected="${_friendsTab === 'friends'}">${tt('friends.tabFriends', 'Friends')}</button>
          <button type="button" data-tab="requests" aria-selected="${_friendsTab === 'requests'}">${tt('friends.tabRequests', 'Requests')}</button>
          <button type="button" data-tab="recent" aria-selected="${_friendsTab === 'recent'}">${tt('friends.tabRecent', 'Recent')}</button>
        </div>
        ${addForm}
        <p class="social-err" id="friends-err"></p>
        <div>${pane}</div>`;
      root.querySelectorAll('[data-tab]').forEach((b) => {
        b.addEventListener('click', () => { _friendsTab = b.getAttribute('data-tab'); refreshFriends(); });
      });
      document.getElementById('friends-add-form')?.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        setErr('friends-err', '');
        try {
          await accountPost('social_friend_add', {
            friend_code: document.getElementById('friends-code-input')?.value || '',
            code: document.getElementById('friends-code-input')?.value || '',
          });
          await refreshFriends();
        } catch (e) { setErr('friends-err', e.message); }
      });
      root.querySelectorAll('[data-copy]').forEach((b) => {
        b.addEventListener('click', () => copyText(b.getAttribute('data-copy'), b));
      });
      root.querySelectorAll('[data-open]').forEach((b) => b.addEventListener('click', () => openProfile(b.getAttribute('data-open'))));
      root.querySelectorAll('[data-acc]').forEach((b) => b.addEventListener('click', async () => {
        setErr('friends-err', '');
        try {
          await accountPost('social_friend_accept', { user_id: b.getAttribute('data-acc') });
          refreshFriends();
        } catch (e) { setErr('friends-err', e.message); }
      }));
      root.querySelectorAll('[data-dec]').forEach((b) => b.addEventListener('click', async () => {
        setErr('friends-err', '');
        try {
          await accountPost('social_friend_decline', { user_id: b.getAttribute('data-dec') });
          refreshFriends();
        } catch (e) { setErr('friends-err', e.message); }
      }));
      root.querySelectorAll('[data-rm]').forEach((b) => b.addEventListener('click', async () => {
        await accountPost('social_friend_remove', { user_id: b.getAttribute('data-rm') }); refreshFriends();
      }));
    } catch (e) {
      root.textContent = e.message;
    }
  }

  async function openMod() {
    if (!isMod()) return;
    openOverlay('overlay-profile-mod');
    const root = document.getElementById('profile-mod-body');
    const bansRoot = document.getElementById('profile-mod-bans');
    try {
      const data = await accountPost('social_mod_inbox', {});
      root.innerHTML = (data.reports || []).map((r) => `
        <div class="social-row" style="flex-wrap:wrap">
          <div><strong>${esc(r.username || r.target_id)}</strong> · ${esc(reportReasonLabel(r.field))} · ${tt('profileMod.warns', 'Warnings')}: ${r.profile_warnings || 0}</div>
          <p>${esc(r.snippet || r.bio || '')}</p>
          <button type="button" class="btn-ghost" data-act="clear_bio" data-id="${esc(r.target_id)}" data-rid="${r.id}" data-reason="${esc(r.field || '')}">${tt('profileMod.clearBio', 'Clear bio')}</button>
          <button type="button" class="btn-ghost" data-act="warn" data-id="${esc(r.target_id)}" data-rid="${r.id}" data-reason="${esc(r.field || '')}">${tt('profileMod.warn', 'Warn')}</button>
          <button type="button" class="btn-ghost" data-act="lock_bio" data-id="${esc(r.target_id)}" data-rid="${r.id}" data-reason="${esc(r.field || '')}">${tt('profileMod.lockBio', 'Lock bio')}</button>
          <button type="button" class="btn-ghost" data-act="ban" data-id="${esc(r.target_id)}" data-rid="${r.id}" data-reason="${esc(r.field || '')}">${tt('profileMod.ban', 'Ban account')}</button>
          <button type="button" class="btn-ghost" data-act="dismiss" data-id="${esc(r.target_id)}" data-rid="${r.id}" data-reason="${esc(r.field || '')}">${tt('profileMod.dismiss', 'Dismiss')}</button>
        </div>`).join('') || `<p>${tt('profileMod.empty', 'No open reports.')}</p>`;
      root.querySelectorAll('[data-act]').forEach((b) => {
        b.addEventListener('click', async () => {
          const act = b.getAttribute('data-act');
          if (act === 'ban' && !window.confirm(tt('profileMod.banConfirm', 'Ban this Discord account? Records are wiped and they cannot sign in. A snapshot is kept for unban.'))) {
            return;
          }
          await accountPost('social_mod_action', {
            user_id: b.getAttribute('data-id'),
            report_id: parseInt(b.getAttribute('data-rid') || '0', 10),
            mod_action: act,
            reason: b.getAttribute('data-reason') || '',
          });
          openMod();
        });
      });
    } catch (e) {
      root.textContent = e.message;
    }
    if (bansRoot) {
      try {
        const bans = await accountPost('social_ban_list', {});
        bansRoot.innerHTML = (bans.bans || []).map((row) => `
          <div class="social-row" style="flex-wrap:wrap">
            <div><strong>${esc(row.username || row.discord_id)}</strong> · ${esc(row.discord_id)} · ${esc(reportReasonLabel(row.reason))}</div>
            <button type="button" class="btn-ghost" data-unban="${esc(row.discord_id)}">${tt('profileMod.unban', 'Unban & restore')}</button>
          </div>`).join('') || `<p>${tt('profileMod.emptyBans', 'No banned accounts.')}</p>`;
        bansRoot.querySelectorAll('[data-unban]').forEach((b) => {
          b.addEventListener('click', async () => {
            if (!window.confirm(tt('profileMod.unbanConfirm', 'Unban this account and restore its snapshot?'))) return;
            await accountPost('social_ban_unban', { user_id: b.getAttribute('data-unban') });
            openMod();
          });
        });
      } catch (e) {
        bansRoot.textContent = e.message;
      }
    }
  }

  let _pendingNotice = null;

  async function showPendingNotices() {
    const notices = (global.A && global.A.profile && global.A.profile.notices) || [];
    const pending = notices.filter((n) => n && n.kind === 'warn');
    if (!pending.length) return;
    const n = pending[0];
    _pendingNotice = n;
    const overlay = document.getElementById('overlay-social-notice');
    const body = document.getElementById('social-notice-body');
    const reason = String(n.reason || '');
    const msg = (reason === 'alt_abuse' || reason === 'leaderboard_alt')
      ? tt('profileMod.warnNoticeAlt', 'You were warned for leaderboard alt abuse.')
      : tt('profileMod.warnNoticeBio', 'You were warned for your profile or deck bio.');
    if (body) body.textContent = msg;
    if (overlay) {
      overlay.hidden = false;
      openOverlay('overlay-social-notice');
    }
  }

  async function ackPendingNotice() {
    const n = _pendingNotice;
    if (!n) {
      closeOverlay('overlay-social-notice');
      const el = document.getElementById('overlay-social-notice');
      if (el) el.hidden = true;
      return;
    }
    try {
      await accountPost('social_notice_ack', { notice_id: n.id });
    } catch (e) { /* ignore */ }
    if (global.A && global.A.profile && Array.isArray(global.A.profile.notices)) {
      global.A.profile.notices = global.A.profile.notices.filter((x) => Number(x.id) !== Number(n.id));
    }
    _pendingNotice = null;
    closeOverlay('overlay-social-notice');
    const el = document.getElementById('overlay-social-notice');
    if (el) el.hidden = true;
    showPendingNotices();
  }

  function bind() {
    document.getElementById('btn-social-menu')?.addEventListener('click', (ev) => {
      ev.stopPropagation();
      toggleSocialMenu();
    });
    document.addEventListener('click', (ev) => {
      const rail = document.getElementById('social-rail');
      if (!rail || rail.hidden || !rail.classList.contains('is-open')) return;
      if (rail.contains(ev.target)) return;
      closeSocialMenu();
    });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') closeSocialMenu();
    });
    document.getElementById('btn-social-profile')?.addEventListener('click', () => openProfile());
    document.getElementById('btn-social-friends')?.addEventListener('click', () => openFriends());
    document.getElementById('btn-social-mod')?.addEventListener('click', () => openMod());
    document.getElementById('btn-profile-close')?.addEventListener('click', () => closeOverlay('overlay-profile'));
    document.getElementById('btn-profile-stats-close')?.addEventListener('click', () => closeOverlay('overlay-profile-stats'));
    document.getElementById('btn-friends-close')?.addEventListener('click', () => closeOverlay('overlay-friends'));
    document.getElementById('btn-profile-mod-close')?.addEventListener('click', () => closeOverlay('overlay-profile-mod'));
    document.getElementById('btn-social-notice-ok')?.addEventListener('click', () => ackPendingNotice());
  }

  global.syncSocialRail = syncSocialRail;
  global.openSocialProfile = openProfile;
  global.openSocialOverlay = openOverlay;
  global.closeSocialOverlay = closeOverlay;
  global.closeAllSocialOverlays = closeAllSocial;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
  else bind();
})(window);
