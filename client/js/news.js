/**
 * Title-screen news overlay — list + detail views, locale-aware posts from news.json.
 * Tracks posts newer than the user's last main-menu visit for FAB glow + list highlights.
 */
(function (global) {
  'use strict';

  const NEWS_JSON = './news.json?v=17';
  const LAST_SEEN_KEY = 'lltcg.news.lastSeenNewestId';
  /** Matches catalog card_no tokens in news copy (PL!… / LL-…). */
  const NEWS_CARD_ID_RE = /(PL![A-Za-z0-9!＋._-]+|LL-[A-Za-z0-9!＋._-]+|PL!-[A-Za-z0-9!＋._-]+)/g;
  let _posts = null;
  let _loadPromise = null;
  let _view = 'list';
  let _activeId = null;
  /** Frozen at main-menu enter for this session (null = first visit / nothing new). */
  let _sessionNewIds = null;
  let _onMainMenu = false;

  function t(key, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.t;
    return typeof fn === 'function' ? fn(key, vars) : key;
  }

  function locale() {
    return (global.LLTCG_I18N && global.LLTCG_I18N.getLocale && global.LLTCG_I18N.getLocale()) || 'en';
  }

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function linkNewsCardIds(text) {
    return escapeHtml(text).replace(NEWS_CARD_ID_RE, (id) => {
      const no = String(id).trim();
      return `<button type="button" class="news-card-id" data-card-no="${escapeHtml(no)}">${escapeHtml(no)}</button>`;
    });
  }

  function formatBody(text) {
    return linkNewsCardIds(String(text || ''))
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

  function bannerStyle(post) {
    const raw = String(post?.bannerStyle ?? post?.banner_style ?? 'crop').trim().toLowerCase();
    if (raw === 'full') return 'full';
    if (raw === 'wide') return 'wide';
    return 'crop';
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

  function readLastSeenNewestId() {
    try {
      const v = localStorage.getItem(LAST_SEEN_KEY);
      return v && String(v).trim() ? String(v).trim() : '';
    } catch (e) {
      return '';
    }
  }

  function writeLastSeenNewestId(id) {
    const v = String(id || '').trim();
    if (!v) return;
    try {
      localStorage.setItem(LAST_SEEN_KEY, v);
    } catch (e) {
      /* ignore quota / private mode */
    }
  }

  /** Posts newer than the last-seen newest id (sorted newest-first). */
  function computeNewPostIds(posts) {
    const list = Array.isArray(posts) ? posts : [];
    const lastId = readLastSeenNewestId();
    if (!lastId) return [];
    const lastIdx = list.findIndex((p) => p && p.id === lastId);
    if (lastIdx === -1) {
      // Prior anchor missing from feed — treat only the current top post as new if feed non-empty.
      return list[0]?.id ? [list[0].id] : [];
    }
    return list.slice(0, lastIdx).map((p) => p.id).filter(Boolean);
  }

  function sessionNewIdSet() {
    return new Set(_sessionNewIds || []);
  }

  function isPostNewInSession(post) {
    const id = post?.id;
    return !!(id && sessionNewIdSet().has(id));
  }

  function applyNewsFabState(hasNew) {
    const label = t('news.newBadge');
    document.querySelectorAll('.news-fab').forEach((btn) => {
      btn.classList.toggle('news-fab--new', !!hasNew);
      const badge = btn.querySelector('.news-fab-badge');
      if (badge) {
        badge.textContent = label;
        badge.hidden = !hasNew;
      }
      if (hasNew) {
        btn.setAttribute('aria-label', `${t('news.label')} — ${label}`);
      } else {
        btn.setAttribute('aria-label', t('news.label'));
      }
    });
  }

  async function refreshNewsFabState() {
    const posts = await loadPosts();
    const newIds = _sessionNewIds != null ? _sessionNewIds : computeNewPostIds(posts);
    applyNewsFabState(newIds.length > 0);
    return newIds;
  }

  async function ensureCatalogLoaded() {
    const g = global.G;
    if (g && Object.keys(g.allCards || {}).length) return;
    if (typeof global.loadCards === 'function') await global.loadCards();
  }

  async function openNewsCardPreview(cardNo) {
    const no = String(cardNo || '').trim();
    if (!no) return;
    await ensureCatalogLoaded();
    const card = global.G?.allCards?.[no];
    if (card && typeof global.showCatalogCard === 'function') {
      global.showCatalogCard(card);
      return;
    }
    if (typeof global.toast === 'function') {
      global.toast(t('news.cardUnknown', { id: no }), 2600);
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
        const raw = Array.isArray(data?.posts) ? data.posts : [];
        _posts = raw
          .map((post, idx) => ({ post, idx }))
          .sort((a, b) => {
            const dateCmp = String(b.post.date || '').localeCompare(String(a.post.date || ''));
            if (dateCmp !== 0) return dateCmp;
            const pubA = a.post.publishedAt || a.post.published_at || '';
            const pubB = b.post.publishedAt || b.post.published_at || '';
            if (pubA || pubB) {
              const pubCmp = String(pubB).localeCompare(String(pubA));
              if (pubCmp !== 0) return pubCmp;
            }
            // Same calendar date: later entries in news.json are treated as newer.
            return b.idx - a.idx;
          })
          .map(({ post }) => post);
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
    const newTag = t('news.newBadge');
    posts.forEach((post) => {
      const isNew = isPostNewInSession(post);
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'llc-menu-item llc-menu-hover news-list-item' + (isNew ? ' news-list-item--new' : '');
      btn.dataset.postId = post.id || '';
      const body = document.createElement('span');
      body.className = 'llc-menu-item-body';
      const title = document.createElement('span');
      title.className = 'llc-menu-item-title';
      title.textContent = postField(post, 'title') || post.id || t('news.untitled');
      if (isNew) {
        const tag = document.createElement('span');
        tag.className = 'news-list-item-new-tag';
        tag.textContent = newTag;
        title.appendChild(tag);
      }
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
    const bannerWrapEl = document.getElementById('news-detail-banner-wrap');
    const bannerEl = document.getElementById('news-detail-banner');
    const bodyEl = document.getElementById('news-detail-body');
    const dateEl = document.getElementById('news-detail-date');
    if (!post) return;
    if (titleEl) titleEl.textContent = postField(post, 'title') || post.id || '';
    if (dateEl) dateEl.textContent = formatPostDate(post.date);
    if (bannerWrapEl && bannerEl) {
      const src = String(post.banner || '').trim();
      const style = bannerStyle(post);
      bannerWrapEl.classList.remove('news-detail-banner-wrap--crop', 'news-detail-banner-wrap--full', 'news-detail-banner-wrap--wide');
      bannerWrapEl.classList.add(
        style === 'full' ? 'news-detail-banner-wrap--full'
          : style === 'wide' ? 'news-detail-banner-wrap--wide'
            : 'news-detail-banner-wrap--crop'
      );
      if (src) {
        bannerWrapEl.hidden = false;
        bannerEl.hidden = false;
        bannerEl.src = src;
        bannerEl.alt = '';
      } else {
        bannerWrapEl.hidden = true;
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
    if (_sessionNewIds == null && _onMainMenu) {
      _sessionNewIds = computeNewPostIds(posts);
    }
    renderList(posts);
    document.getElementById('news-list-scroll')?.scrollTo(0, 0);
    if (global.LLTCG_I18N && typeof global.LLTCG_I18N.applyI18n === 'function') {
      global.LLTCG_I18N.applyI18n(ov);
    }
  };

  global.refreshNewsOverlay = async function refreshNewsOverlay() {
    if (!isOpen()) {
      if (_onMainMenu || titleScreenActive()) void refreshNewsFabState();
      return;
    }
    const posts = await loadPosts();
    if (_view === 'detail' && _activeId) {
      renderDetail(posts.find((p) => p.id === _activeId));
    } else {
      renderList(posts);
    }
    applyNewsFabState((_sessionNewIds || []).length > 0);
  };

  /**
   * Called when auth/hub becomes active. Freezes which posts count as "new"
   * for this visit (relative to the previous main-menu leave).
   */
  global.onTcgMainMenuEnter = async function onTcgMainMenuEnter() {
    if (_onMainMenu && _sessionNewIds != null) {
      applyNewsFabState(_sessionNewIds.length > 0);
      return;
    }
    _onMainMenu = true;
    const posts = await loadPosts();
    const lastId = readLastSeenNewestId();
    if (!lastId) {
      // First visit: establish baseline without a New! badge.
      if (posts[0]?.id) writeLastSeenNewestId(posts[0].id);
      _sessionNewIds = [];
    } else {
      _sessionNewIds = computeNewPostIds(posts);
    }
    applyNewsFabState(_sessionNewIds.length > 0);
  };

  /** Leaving main menu marks the current newest post as seen for the next visit. */
  global.onTcgMainMenuLeave = async function onTcgMainMenuLeave() {
    if (!_onMainMenu) return;
    _onMainMenu = false;
    const posts = await loadPosts();
    if (posts[0]?.id) writeLastSeenNewestId(posts[0].id);
    _sessionNewIds = null;
    applyNewsFabState(false);
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
    const bodyEl = document.getElementById('news-detail-body');
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
    if (bodyEl && !bodyEl.dataset.newsCardBound) {
      bodyEl.dataset.newsCardBound = '1';
      bodyEl.addEventListener('click', (ev) => {
        const btn = ev.target.closest('.news-card-id');
        if (!btn) return;
        ev.preventDefault();
        void openNewsCardPreview(btn.getAttribute('data-card-no'));
      });
    }
    if (ov && !ov.dataset.newsBound) {
      ov.dataset.newsBound = '1';
      ov.addEventListener('click', (ev) => {
        if (ev.target === ov) global.closeNewsOverlay();
      });
    }
    if (titleScreenActive()) {
      void global.onTcgMainMenuEnter();
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
