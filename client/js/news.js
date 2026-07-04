/**
 * Title-screen news overlay — list + detail views, locale-aware posts from news.json.
 */
(function (global) {
  'use strict';

  const NEWS_JSON = './news.json?v=1';
  let _posts = null;
  let _loadPromise = null;
  let _view = 'list';
  let _activeId = null;

  function t(key, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.t;
    return typeof fn === 'function' ? fn(key, vars) : key;
  }

  function locale() {
    return (global.LLTCG_I18N && global.LLTCG_I18N.getLocale && global.LLTCG_I18N.getLocale()) || 'en';
  }

  function formatBody(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/\n/g, '<br>');
  }

  function postField(post, field) {
    if (!post || !field) return '';
    const block = post[field];
    if (!block || typeof block !== 'object') return '';
    const loc = locale();
    return block[loc] || block.en || '';
  }

  function formatPostDate(dateStr) {
    if (!dateStr) return '';
    try {
      const d = new Date(dateStr + 'T12:00:00');
      if (Number.isNaN(d.getTime())) return dateStr;
      return d.toLocaleDateString(locale() === 'ja' ? 'ja-JP' : undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
      });
    } catch (e) {
      return dateStr;
    }
  }

  async function loadPosts() {
    if (_posts) return _posts;
    if (_loadPromise) return _loadPromise;
    _loadPromise = fetch(NEWS_JSON, { cache: 'no-store' })
      .then((r) => {
        if (!r.ok) throw new Error('news HTTP ' + r.status);
        return r.json();
      })
      .then((data) => {
        _posts = (Array.isArray(data?.posts) ? data.posts : [])
          .slice()
          .sort((a, b) => String(b.date || '').localeCompare(String(a.date || '')));
        return _posts;
      })
      .catch((e) => {
        console.warn('[news] load failed', e);
        _posts = [];
        return _posts;
      })
      .finally(() => {
        _loadPromise = null;
      });
    return _loadPromise;
  }

  function overlayEl() {
    return document.getElementById('overlay-news');
  }

  function isOpen() {
    return overlayEl()?.classList.contains('open');
  }

  function titleScreenActive() {
    const auth = document.getElementById('screen-auth');
    const hub = document.getElementById('screen-hub');
    return !!(auth?.classList.contains('active') || hub?.classList.contains('active'));
  }

  function setView(view, postId) {
    _view = view === 'detail' ? 'detail' : 'list';
    _activeId = _view === 'detail' ? postId : null;
    const listPane = document.getElementById('news-list-pane');
    const detailPane = document.getElementById('news-detail-pane');
    const shell = document.querySelector('#overlay-news .news-shell');
    if (listPane) listPane.hidden = _view !== 'list';
    if (detailPane) detailPane.hidden = _view !== 'detail';
    if (shell) shell.classList.toggle('news-shell--detail', _view === 'detail');
  }

  function renderList(posts) {
    const box = document.getElementById('news-list');
    if (!box) return;
    box.replaceChildren();
    if (!posts.length) {
      const empty = document.createElement('p');
      empty.className = 'news-empty';
      empty.textContent = t('news.empty');
      box.appendChild(empty);
      return;
    }
    posts.forEach((post) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'llc-menu-item llc-menu-hover news-list-item';
      btn.dataset.postId = post.id || '';
      const body = document.createElement('span');
      body.className = 'llc-menu-item-body';
      const title = document.createElement('span');
      title.className = 'llc-menu-item-title';
      title.textContent = postField(post, 'title') || post.id || t('news.untitled');
      const sub = document.createElement('span');
      sub.className = 'llc-menu-item-sub';
      sub.textContent = formatPostDate(post.date);
      body.append(title, sub);
      btn.appendChild(body);
      btn.addEventListener('click', () => openDetail(post.id));
      box.appendChild(btn);
    });
  }

  function renderDetail(post) {
    const titleEl = document.getElementById('news-detail-title');
    const bannerEl = document.getElementById('news-detail-banner');
    const bodyEl = document.getElementById('news-detail-body');
    const dateEl = document.getElementById('news-detail-date');
    if (!post) return;
    if (titleEl) titleEl.textContent = postField(post, 'title') || post.id || '';
    if (dateEl) dateEl.textContent = formatPostDate(post.date);
    if (bannerEl) {
      const src = String(post.banner || '').trim();
      if (src) {
        bannerEl.hidden = false;
        bannerEl.src = src;
        bannerEl.alt = '';
      } else {
        bannerEl.hidden = true;
        bannerEl.removeAttribute('src');
      }
    }
    if (bodyEl) bodyEl.innerHTML = formatBody(postField(post, 'body'));
  }

  async function openDetail(postId) {
    const posts = await loadPosts();
    const post = posts.find((p) => p.id === postId);
    if (!post) return;
    setView('detail', postId);
    renderDetail(post);
    document.getElementById('news-detail-scroll')?.scrollTo(0, 0);
  }

  function backToList() {
    setView('list');
    document.getElementById('news-list-scroll')?.scrollTo(0, 0);
  }

  global.closeNewsOverlay = function closeNewsOverlay() {
    const ov = overlayEl();
    if (!ov) return;
    ov.classList.remove('open');
    ov.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('news-overlay-open');
    setView('list');
  };

  global.openNewsOverlay = async function openNewsOverlay() {
    if (!titleScreenActive()) return;
    const ov = overlayEl();
    if (!ov) return;
    setView('list');
    ov.classList.add('open');
    ov.setAttribute('aria-hidden', 'false');
    document.body.classList.add('news-overlay-open');
    const posts = await loadPosts();
    renderList(posts);
    document.getElementById('news-list-scroll')?.scrollTo(0, 0);
    if (global.LLTCG_I18N && typeof global.LLTCG_I18N.applyI18n === 'function') {
      global.LLTCG_I18N.applyI18n(ov);
    }
  };

  global.refreshNewsOverlay = async function refreshNewsOverlay() {
    if (!isOpen()) return;
    const posts = await loadPosts();
    if (_view === 'detail' && _activeId) {
      renderDetail(posts.find((p) => p.id === _activeId));
    } else {
      renderList(posts);
    }
  };

  function bindUi() {
    document.querySelectorAll('.news-fab').forEach((btn) => {
      if (btn.dataset.newsBound) return;
      btn.dataset.newsBound = '1';
      btn.addEventListener('click', () => void global.openNewsOverlay());
    });
    const closeBtn = document.getElementById('btn-news-close');
    const closeDetailBtn = document.getElementById('btn-news-close-detail');
    const backBtn = document.getElementById('btn-news-back');
    const ov = overlayEl();
    const closeAll = () => global.closeNewsOverlay();
    if (closeBtn && !closeBtn.dataset.newsBound) {
      closeBtn.dataset.newsBound = '1';
      closeBtn.addEventListener('click', closeAll);
    }
    if (closeDetailBtn && !closeDetailBtn.dataset.newsBound) {
      closeDetailBtn.dataset.newsBound = '1';
      closeDetailBtn.addEventListener('click', closeAll);
    }
    if (backBtn && !backBtn.dataset.newsBound) {
      backBtn.dataset.newsBound = '1';
      backBtn.addEventListener('click', () => backToList());
    }
    if (ov && !ov.dataset.newsBound) {
      ov.dataset.newsBound = '1';
      ov.addEventListener('click', (ev) => {
        if (ev.target === ov) global.closeNewsOverlay();
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindUi);
  } else {
    bindUi();
  }

  if (global.LLTCG_I18N && typeof global.LLTCG_I18N.onLocaleChange === 'function') {
    global.LLTCG_I18N.onLocaleChange(() => void global.refreshNewsOverlay());
  }
})(window);
