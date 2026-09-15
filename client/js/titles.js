/**
 * Profile / leaderboard title badges + picker (Fan titles unlock via Stage plays).
 */
(function (global) {
  'use strict';

  let _pickerCache = null;
  let _pickerEquipped = null;

  function tt(key, fallback, vars) {
    if (typeof global.tt === 'function') return global.tt(key, fallback, vars);
    if (typeof global.t === 'function') {
      try { return global.t(key, vars) || fallback; } catch (e) { /* ignore */ }
    }
    return fallback;
  }

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function titleTip(title) {
    if (!title) return '';
    const name = title.name || '';
    const hint = title.unlock_hint
      || (title.unlock_plays
        ? tt('titles.unlockHint', 'Play {idol} as a Stage Member {n} times.', {
          idol: title.idol || title.idol_short || 'this Member',
          n: title.unlock_plays,
        })
        : '');
    if (title.unlocked === false && hint) return name + ' — ' + hint;
    if (hint) return name + ' — ' + hint;
    return name;
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
    if (opts && typeof opts.onClick === 'function') {
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
    const ov = document.getElementById('overlay-titles');
    if (!ov) return;
    ov.classList.add('open');
    ov.setAttribute('aria-hidden', 'false');
    ov.hidden = false;
    document.body.classList.add('social-overlay-open');
  }

  function closeTitlePickerOverlay() {
    const ov = document.getElementById('overlay-titles');
    if (!ov) return;
    ov.classList.remove('open');
    ov.setAttribute('aria-hidden', 'true');
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
    noneBtn.title = tt('titles.unequip', 'No title');
    noneBtn.innerHTML = `<span>${esc(tt('titles.unequip', 'No title'))}</span>`;
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
      btn.disabled = !unlocked;
      if (unlocked && t.url) {
        const img = document.createElement('img');
        img.src = t.url;
        img.alt = t.name || '';
        img.decoding = 'async';
        img.draggable = false;
        btn.appendChild(img);
      } else {
        btn.setAttribute('aria-label', titleTip(t) || (t.name || 'Locked title'));
      }
      if (unlocked) {
        btn.addEventListener('click', () => equipTitle(t.id));
      }
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
    titleTip,
  };
  global.openTitlePicker = openTitlePicker;
})(typeof window !== 'undefined' ? window : globalThis);
