/**
 * Profile / leaderboard title badges + picker (Fan titles unlock via Stage plays).
 */
(function (global) {
  'use strict';

  const TITLE_LONG_PRESS_MS = 420;
  const TITLE_DRAG_THRESHOLD = 10;

  let _pickerCache = null;
  let _pickerEquipped = null;

  function interpolate(str, vars) {
    if (!vars || typeof str !== 'string') return str;
    return str.replace(/\{([^}]+)\}/g, (_m, name) => (
      vars[name] != null ? String(vars[name]) : _m
    ));
  }

  function tt(key, fallback, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.tt;
    if (typeof fn === 'function') return fn(key, fallback, vars);
    return fallback != null ? interpolate(String(fallback), vars) : key;
  }

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function titleUnlockHint(title) {
    if (!title) return '';
    if (title.unlock_hint) return String(title.unlock_hint);
    const idol = title.idol || title.idol_short || 'this Member';
    const n = title.unlock_plays || 500;
    const have = title.progress;
    if (title.unlocked) {
      return tt(
        'titles.unlockDone',
        'Unlocked by playing {idol} as a Stage Member {n} times.',
        { idol, n }
      );
    }
    if (have != null && Number.isFinite(Number(have))) {
      return tt(
        'titles.unlockProgress',
        'Play {idol} as a Stage Member {n} times. ({have}/{n})',
        { idol, n, have }
      );
    }
    return tt(
      'titles.unlockHint',
      'Play {idol} as a Stage Member {n} times.',
      { idol, n }
    );
  }

  function titleTip(title) {
    if (!title) return '';
    const name = title.name || '';
    const hint = titleUnlockHint(title);
    if (hint) return name ? (name + ' — ' + hint) : hint;
    return name;
  }

  function closeTitlePreview() {
    const prev = document.getElementById('title-preview');
    if (!prev) return;
    prev.hidden = true;
    prev.setAttribute('aria-hidden', 'true');
  }

  function showTitlePreview(title) {
    if (!title) return;
    const prev = document.getElementById('title-preview');
    const art = document.getElementById('title-preview-art');
    const nameEl = document.getElementById('title-preview-name');
    const metaEl = document.getElementById('title-preview-meta');
    const hintEl = document.getElementById('title-preview-hint');
    if (!prev || !art || !nameEl) return;

    const style = title.style === 'portrait' ? 'portrait' : 'wide';
    art.className = 'title-preview-art is-' + style;
    art.replaceChildren();
    if (title.url) {
      const img = document.createElement('img');
      img.src = title.url;
      img.alt = title.name || '';
      img.decoding = 'async';
      img.draggable = false;
      art.appendChild(img);
    }

    nameEl.textContent = title.name || '';
    const bits = [];
    if (title.unit) bits.push(String(title.unit));
    if (title.unlocked === false) bits.push(tt('titles.locked', 'Locked'));
    else if (title.unlocked === true) bits.push(tt('titles.unlocked', 'Unlocked'));
    if (metaEl) metaEl.textContent = bits.join(' · ');
    if (hintEl) {
      hintEl.textContent = titleUnlockHint(title);
    }

    prev.hidden = false;
    prev.setAttribute('aria-hidden', 'false');
  }

  /** Hold / right-click preview; optional onTap for short press. */
  function bindTitleHold(node, title, onTap) {
    if (!node || !title) return;
    node.addEventListener('contextmenu', (e) => {
      e.preventDefault();
      showTitlePreview(title);
    });
    node.addEventListener('pointerdown', (e) => {
      if (e.button !== 0) return;
      try { node.setPointerCapture(e.pointerId); } catch (_) { /* ignore */ }
      node.classList.add('is-hold-pending');
      const ptr = { sx: e.clientX, sy: e.clientY, moved: false, longFired: false, timer: null };
      ptr.timer = setTimeout(() => {
        if (ptr.moved) return;
        ptr.longFired = true;
        node.classList.remove('is-hold-pending');
        showTitlePreview(title);
      }, TITLE_LONG_PRESS_MS);
      const onMove = (ev) => {
        if (Math.hypot(ev.clientX - ptr.sx, ev.clientY - ptr.sy) > TITLE_DRAG_THRESHOLD) {
          ptr.moved = true;
        }
      };
      const onUp = (ev) => {
        if (ptr.timer) clearTimeout(ptr.timer);
        node.classList.remove('is-hold-pending');
        node.removeEventListener('pointermove', onMove);
        node.removeEventListener('pointerup', onUp);
        node.removeEventListener('pointercancel', onUp);
        try { node.releasePointerCapture(ev.pointerId); } catch (_) { /* ignore */ }
        if (!ptr.moved && !ptr.longFired && typeof onTap === 'function') onTap();
      };
      node.addEventListener('pointermove', onMove);
      node.addEventListener('pointerup', onUp);
      node.addEventListener('pointercancel', onUp);
    });
  }

  /** Render an equipped title (no chrome / border) into a container element. */
  function mountTitleEl(container, title, opts) {
    if (!container) return null;
    container.replaceChildren();
    container.classList.add('player-title-slot');
    if (!title || !title.url) {
      container.classList.add('is-empty');
      container.removeAttribute('title');
      if (opts && opts.emptyLabel) {
        const span = document.createElement('span');
        span.className = 'player-title-empty';
        span.textContent = opts.emptyLabel;
        container.appendChild(span);
      }
      return null;
    }
    container.classList.remove('is-empty');
    const style = title.style === 'portrait' ? 'portrait' : 'wide';
    const wrap = document.createElement(opts && opts.button ? 'button' : 'div');
    if (opts && opts.button) {
      wrap.type = 'button';
      wrap.className = 'player-title player-title--' + style + ' player-title--btn';
    } else {
      wrap.className = 'player-title player-title--' + style;
    }
    wrap.title = titleTip(title);
    const img = document.createElement('img');
    img.className = 'player-title-img';
    img.src = title.url;
    img.alt = title.name || '';
    img.decoding = 'async';
    img.draggable = false;
    wrap.appendChild(img);
    if (opts && opts.button) {
      bindTitleHold(wrap, title, typeof opts.onClick === 'function' ? opts.onClick : null);
    } else if (typeof opts?.onClick === 'function') {
      wrap.addEventListener('click', opts.onClick);
    }
    container.appendChild(wrap);
    return wrap;
  }

  function appendTitleUnderName(parent, title) {
    if (!parent || !title || !title.url) return;
    const slot = document.createElement('div');
    slot.className = 'player-title-slot player-title-slot--inline';
    mountTitleEl(slot, title);
    parent.appendChild(slot);
  }

  async function accountPost(action, body) {
    if (typeof global.accountPost === 'function') {
      return global.accountPost(action, body);
    }
    throw new Error('accountPost unavailable');
  }

  function openTitlePickerOverlay() {
    if (typeof global.openSocialOverlay === 'function') {
      global.openSocialOverlay('overlay-titles');
      return;
    }
    const ov = document.getElementById('overlay-titles');
    if (!ov) return;
    if (ov.parentNode) ov.parentNode.appendChild(ov);
    ov.style.setProperty('z-index', '8800');
    ov.classList.add('open', 'social-stack-top');
    ov.setAttribute('aria-hidden', 'false');
    ov.hidden = false;
    document.body.classList.add('social-overlay-open');
  }

  function closeTitlePickerOverlay() {
    closeTitlePreview();
    if (typeof global.closeSocialOverlay === 'function') {
      global.closeSocialOverlay('overlay-titles');
      return;
    }
    const ov = document.getElementById('overlay-titles');
    if (!ov) return;
    ov.classList.remove('open', 'social-stack-top');
    ov.setAttribute('aria-hidden', 'true');
    ov.style.removeProperty('z-index');
    ov.hidden = true;
    if (!document.querySelector('.social-overlay.open')) {
      document.body.classList.remove('social-overlay-open');
    }
  }

  function renderTitlePickerGrid(data) {
    const grid = document.getElementById('titles-picker-grid');
    const status = document.getElementById('titles-picker-status');
    if (!grid) return;
    grid.replaceChildren();
    const titles = (data && data.titles) || [];
    _pickerCache = titles;
    _pickerEquipped = data && data.equipped_title_id ? String(data.equipped_title_id) : null;

    const noneBtn = document.createElement('button');
    noneBtn.type = 'button';
    noneBtn.className = 'title-pick-slot title-pick-slot--none'
      + (!_pickerEquipped ? ' is-equipped' : '');
    noneBtn.title = tt('titles.noneSet', 'No title set');
    noneBtn.innerHTML = `<span>${esc(tt('titles.noneSet', 'No title set'))}</span>`;
    noneBtn.addEventListener('click', () => equipTitle(''));
    grid.appendChild(noneBtn);

    titles.forEach((t) => {
      const unlocked = !!t.unlocked;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'title-pick-slot title-pick-slot--' + (t.style === 'portrait' ? 'portrait' : 'wide')
        + (unlocked ? '' : ' is-locked')
        + (_pickerEquipped === String(t.id) ? ' is-equipped' : '');
      btn.title = titleTip(t);
      // Keep enabled so hold-to-preview works on locked slots.
      if (t.url) {
        const img = document.createElement('img');
        img.src = t.url;
        img.alt = t.name || '';
        img.decoding = 'async';
        img.draggable = false;
        btn.appendChild(img);
      } else {
        btn.setAttribute('aria-label', titleTip(t) || (t.name || 'Locked title'));
      }
      bindTitleHold(btn, t, unlocked ? () => equipTitle(t.id) : null);
      grid.appendChild(btn);
    });

    if (status) {
      const unlockedN = titles.filter((t) => t.unlocked).length;
      status.textContent = tt(
        'titles.progress',
        '{have} / {total} titles unlocked',
        { have: unlockedN, total: titles.length }
      );
    }
  }

  async function openTitlePicker() {
    const err = document.getElementById('titles-picker-err');
    if (err) err.textContent = '';
    closeTitlePreview();
    openTitlePickerOverlay();
    const body = document.getElementById('titles-picker-body');
    if (body) body.classList.add('is-loading');
    try {
      const data = await accountPost('titles_list', {});
      renderTitlePickerGrid(data);
    } catch (e) {
      if (err) err.textContent = e.message || tt('titles.loadError', 'Could not load titles');
    } finally {
      if (body) body.classList.remove('is-loading');
    }
  }

  async function equipTitle(titleId) {
    const err = document.getElementById('titles-picker-err');
    if (err) err.textContent = '';
    try {
      const res = await accountPost('title_set', { title_id: titleId || '' });
      _pickerEquipped = res.title_id ? String(res.title_id) : null;
      if (global.A) {
        if (!global.A.profile) global.A.profile = {};
        global.A.profile.title = res.title || null;
        if (global.A.you) global.A.you.title = res.title || null;
      }
      if (_pickerCache) {
        renderTitlePickerGrid({
          titles: _pickerCache,
          equipped_title_id: _pickerEquipped,
        });
      }
      if (typeof global.refreshProfileTitleDisplay === 'function') {
        global.refreshProfileTitleDisplay(res.title || null);
      }
      if (global.A && global.A.lastLeaderboardData && typeof global.renderLeaderboardRows === 'function') {
        const rows = global.A.lastLeaderboardData.leaderboard || [];
        const me = String(global.A.user?.id || '');
        rows.forEach((row) => {
          if (String(row.user_id || '') === me) row.title = res.title || null;
        });
        if (global.A.lastLeaderboardData.you) {
          global.A.lastLeaderboardData.you.title = res.title || null;
        }
        global.renderLeaderboardRows(global.A.lastLeaderboardData);
      }
    } catch (e) {
      if (err) err.textContent = e.message || tt('titles.equipError', 'Could not equip title');
    }
  }

  function wireTitlePickerChrome() {
    const close = document.getElementById('btn-titles-close');
    if (close && !close.dataset.bound) {
      close.dataset.bound = '1';
      close.addEventListener('click', closeTitlePickerOverlay);
    }
    const ov = document.getElementById('overlay-titles');
    if (ov && !ov.dataset.bound) {
      ov.dataset.bound = '1';
      ov.addEventListener('click', (ev) => {
        if (ev.target === ov) closeTitlePickerOverlay();
      });
    }
    const dismiss = document.getElementById('title-preview-dismiss');
    const previewClose = document.getElementById('title-preview-close');
    if (dismiss && !dismiss.dataset.bound) {
      dismiss.dataset.bound = '1';
      dismiss.addEventListener('click', closeTitlePreview);
    }
    if (previewClose && !previewClose.dataset.bound) {
      previewClose.dataset.bound = '1';
      previewClose.addEventListener('click', closeTitlePreview);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireTitlePickerChrome);
  } else {
    wireTitlePickerChrome();
  }

  global.TCGTitles = {
    mountTitleEl,
    appendTitleUnderName,
    openTitlePicker,
    closeTitlePickerOverlay,
    showTitlePreview,
    closeTitlePreview,
    titleTip,
  };
  global.openTitlePicker = openTitlePicker;
})(typeof window !== 'undefined' ? window : globalThis);
