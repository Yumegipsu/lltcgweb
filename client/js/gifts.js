/**
 * Hub Gifts menu — distributed gifts (see gifts.php). Lists every gift the player can see,
 * lets them claim each once, and glows the hub button while something is unclaimed.
 */
(function (global) {
  'use strict';

  /** [[text]] in gift copy marks the part that links to the gift's external URL. */
  const LINK_RE = /\[\[(.+?)\]\]/;

  let _gifts = [];
  let _loaded = false;
  let _busy = false;
  let _refreshing = null;

  function tt(key, fallback, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.tt;
    return typeof fn === 'function' ? fn(key, fallback, vars) : (fallback || key);
  }

  function el(id) {
    return document.getElementById(id);
  }

  function signedIn() {
    return !!(global.A && global.A.user);
  }

  function toast(msg, ms) {
    if (typeof global.toast === 'function') global.toast(msg, ms);
  }

  function giftTitle(g) {
    return g.title_key ? tt(g.title_key + '.title', g.title_en || g.id) : (g.title_en || g.id);
  }

  function giftBody(g) {
    return g.title_key ? tt(g.title_key + '.body', g.body_en || '') : (g.body_en || '');
  }

  function safeHttpsUrl(url) {
    const u = String(url || '').trim();
    return /^https:\/\//i.test(u) ? u : '';
  }

  /** Text with an optional [[linked part]]; built from DOM nodes so gift copy is never parsed as HTML. */
  function renderBody(target, text, linkUrl) {
    target.replaceChildren();
    const url = safeHttpsUrl(linkUrl);
    const m = LINK_RE.exec(text);
    if (!m) {
      target.textContent = text;
      return;
    }
    target.appendChild(document.createTextNode(text.slice(0, m.index)));
    if (url) {
      const a = document.createElement('a');
      a.className = 'news-ext-link gift-card__link';
      a.href = url;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.textContent = m[1];
      target.appendChild(a);
    } else {
      target.appendChild(document.createTextNode(m[1]));
    }
    target.appendChild(document.createTextNode(text.slice(m.index + m[0].length)));
  }

  function rewardLabel(r) {
    const n = Math.max(1, Number(r.amount || r.qty || 1));
    switch (r.type) {
      case 'muse_premium_ticket':
        return tt('gifts.reward.musePremiumTicket', "μ's Premium 10-Pull Ticket ×{n}", { n });
      case 'pr_pack':
        return tt('gifts.reward.prPack', 'PR pack ({n} cards)', { n });
      case 'coins':
        return tt('gifts.reward.coins', '{n} Coins', { n });
      case 'star_gems':
        return tt('gifts.reward.starGems', '{n} Star Gems', { n });
      case 'scouting_ticket':
        return tt('gifts.reward.scoutingTicket', 'Scouting Ticket ×{n}', { n });
      case 'scouting_ticket_sr':
        return tt('gifts.reward.scoutingTicketSr', 'SR Scouting Ticket ×{n}', { n });
      case 'scouting_ticket_ur':
        return tt('gifts.reward.scoutingTicketUr', 'UR Scouting Ticket ×{n}', { n });
      case 'card':
        return tt('gifts.reward.card', 'Card {id} ×{n}', { id: r.card_no || '', n });
      case 'sleeve':
        return tt('gifts.reward.sleeve', 'Card sleeve');
      case 'playmat':
        return tt('gifts.reward.playmat', 'Playmat');
      default:
        return '';
    }
  }

  /** Countdown under gifts that have expires_at (e.g. "Expires in 29d"). */
  function expiresLabel(g) {
    const exp = Number(g && g.expires_at);
    if (!Number.isFinite(exp) || exp <= 0) return '';
    const now = Math.floor(Date.now() / 1000);
    const left = exp - now;
    if (left <= 0) return tt('gifts.expired', 'Expired');
    const days = Math.ceil(left / 86400);
    if (days >= 2) return tt('gifts.expiresInDays', 'Expires in {n}d', { n: days });
    if (days === 1) return tt('gifts.expiresIn1Day', 'Expires in 1d');
    const hours = Math.max(1, Math.ceil(left / 3600));
    return tt('gifts.expiresInHours', 'Expires in {n}h', { n: hours });
  }

  function buildCard(g) {
    const card = document.createElement('article');
    card.className = 'gift-card' + (g.claimed ? ' gift-card--claimed' : ' gift-card--new');
    card.dataset.giftId = g.id;

    if (g.image) {
      const frame = document.createElement('div');
      frame.className = 'gift-card__art';
      const img = document.createElement('img');
      img.alt = '';
      img.decoding = 'async';
      img.src = g.image;
      img.addEventListener('error', () => { frame.hidden = true; });
      frame.appendChild(img);
      card.appendChild(frame);
    }

    const main = document.createElement('div');
    main.className = 'gift-card__main';
    const title = document.createElement('h3');
    title.className = 'gift-card__title';
    title.textContent = giftTitle(g);
    main.appendChild(title);

    const body = document.createElement('p');
    body.className = 'gift-card__body';
    renderBody(body, giftBody(g), g.link_url);
    main.appendChild(body);

    const labels = (g.rewards || []).map(rewardLabel).filter(Boolean);
    if (labels.length) {
      const rw = document.createElement('p');
      rw.className = 'gift-card__rewards';
      rw.textContent = labels.join(' · ');
      main.appendChild(rw);
    }

    const expText = expiresLabel(g);
    if (expText) {
      const exp = document.createElement('p');
      exp.className = 'gift-card__expires';
      exp.textContent = expText;
      main.appendChild(exp);
    }

    const foot = document.createElement('div');
    foot.className = 'gift-card__foot';
    if (g.claimed) {
      const done = document.createElement('span');
      done.className = 'gift-card__claimed';
      done.textContent = tt('gifts.claimed', 'Claimed');
      foot.appendChild(done);
    } else {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn-grad gift-card__claim';
      btn.textContent = tt('gifts.claim', 'Claim');
      btn.addEventListener('click', () => void claim(g.id, btn));
      foot.appendChild(btn);
    }
    main.appendChild(foot);
    card.appendChild(main);
    return card;
  }

  function render() {
    const box = el('gifts-list');
    if (!box) return;
    box.replaceChildren();
    if (!_gifts.length) {
      const empty = document.createElement('p');
      empty.className = 'news-empty gifts-empty';
      empty.textContent = tt('gifts.empty', 'No gifts right now.');
      box.appendChild(empty);
      return;
    }
    // Unclaimed first, then history (server order is newest-first inside each group).
    const ordered = _gifts.filter((g) => !g.claimed).concat(_gifts.filter((g) => g.claimed));
    ordered.forEach((g) => box.appendChild(buildCard(g)));
  }

  function unclaimedCount() {
    return _gifts.filter((g) => !g.claimed).length;
  }

  function applyFabState() {
    const n = unclaimedCount();
    document.querySelectorAll('.social-rail-btn.is-gifts').forEach((btn) => {
      btn.classList.toggle('has-new', n > 0);
      const badge = btn.querySelector('.social-rail-gift-badge');
      if (badge) {
        badge.textContent = n > 9 ? '9+' : String(n);
        badge.hidden = n < 1;
      }
      const label = tt('gifts.label', 'Gifts');
      btn.setAttribute('aria-label', n > 0 ? label + ' — ' + tt('news.newBadge', 'New!') : label);
    });
  }

  function syncBalances(res) {
    if (!global.A || !res) return;
    if (res.star_gems != null) global.A.starGems = res.star_gems;
    if (res.coins != null) global.A.coins = res.coins;
    if (res.scouting_tickets != null) global.A.scoutingTickets = res.scouting_tickets;
    if (res.scouting_tickets_sr != null) global.A.scoutingTicketsSr = res.scouting_tickets_sr;
    if (res.scouting_tickets_ur != null) global.A.scoutingTicketsUr = res.scouting_tickets_ur;
    if (res.muse_premium_tickets != null) global.A.musePremiumTickets = res.muse_premium_tickets;
  }

  async function load() {
    if (_refreshing) return _refreshing;
    _refreshing = (async () => {
      try {
        const res = await global.accountPost('gifts_list', {});
        _gifts = Array.isArray(res.gifts) ? res.gifts : [];
        _loaded = true;
        syncBalances(res);
      } catch (e) {
        if (!_loaded) _gifts = [];
        throw e;
      } finally {
        _refreshing = null;
      }
      applyFabState();
      return _gifts;
    })();
    return _refreshing;
  }

  async function claim(id, btn) {
    if (_busy) return;
    _busy = true;
    if (btn) btn.disabled = true;
    try {
      const res = await global.accountPost('gift_claim', { gift_id: id });
      syncBalances(res);
      const g = _gifts.find((x) => x.id === id);
      if (g) g.claimed = true;
      render();
      applyFabState();
      toast(tt('gifts.claimedToast', 'Gift claimed!'), 2400);
    } catch (e) {
      if (btn) btn.disabled = false;
      const already = /already claimed/i.test(String(e && e.message));
      toast(already
        ? tt('gifts.alreadyClaimed', 'You already claimed this gift.')
        : tt('gifts.claimError', 'Could not claim the gift.'), 2800);
      if (already) void load().then(render).catch(() => {});
    } finally {
      _busy = false;
    }
  }

  function overlay() {
    return el('overlay-gifts');
  }

  global.closeGiftsOverlay = function closeGiftsOverlay() {
    const ov = overlay();
    if (!ov) return;
    ov.classList.remove('open');
    ov.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('news-overlay-open');
    const fab = el('btn-social-gifts');
    if (fab && typeof fab.focus === 'function') fab.focus();
  };

  global.openGiftsOverlay = async function openGiftsOverlay() {
    const ov = overlay();
    if (!ov) return;
    ov.classList.add('open');
    ov.setAttribute('aria-hidden', 'false');
    document.body.classList.add('news-overlay-open');
    if (global.LLTCG_I18N && typeof global.LLTCG_I18N.applyI18n === 'function') {
      global.LLTCG_I18N.applyI18n(ov);
    }
    render();
    try {
      await load();
    } catch (e) {
      const box = el('gifts-list');
      if (box && !_gifts.length) {
        box.replaceChildren();
        const err = document.createElement('p');
        err.className = 'news-empty gifts-empty';
        err.textContent = tt('gifts.loadError', 'Could not load gifts.');
        box.appendChild(err);
      }
      return;
    }
    render();
    el('gifts-scroll')?.scrollTo(0, 0);
  };

  /** Hub became active: refresh the unclaimed badge (silent when signed out / offline). */
  global.refreshGiftsBadge = async function refreshGiftsBadge() {
    if (!el('btn-social-gifts') || !signedIn()) return;
    try {
      await load();
    } catch (e) { /* offline or signed out: leave the button as is */ }
  };

  function bind() {
    const fab = el('btn-social-gifts');
    if (fab && !fab.dataset.giftsBound) {
      fab.dataset.giftsBound = '1';
      fab.addEventListener('click', () => void global.openGiftsOverlay());
    }
    const closeBtn = el('btn-gifts-close');
    if (closeBtn && !closeBtn.dataset.giftsBound) {
      closeBtn.dataset.giftsBound = '1';
      closeBtn.addEventListener('click', () => global.closeGiftsOverlay());
    }
    const ov = overlay();
    if (ov && !ov.dataset.giftsBound) {
      ov.dataset.giftsBound = '1';
      ov.addEventListener('click', (ev) => {
        if (ev.target === ov) global.closeGiftsOverlay();
      });
      document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && ov.classList.contains('open')) global.closeGiftsOverlay();
      });
    }
    // Piggyback on the hub-enter hook news.js owns (scripts load news.js first).
    const prev = global.onTcgMainMenuEnter;
    if (typeof prev === 'function' && !prev.__giftsWrapped) {
      const wrapped = function () {
        const r = prev.apply(this, arguments);
        void global.refreshGiftsBadge();
        return r;
      };
      wrapped.__giftsWrapped = true;
      global.onTcgMainMenuEnter = wrapped;
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }

  if (global.LLTCG_I18N && typeof global.LLTCG_I18N.onLocaleChange === 'function') {
    global.LLTCG_I18N.onLocaleChange(() => {
      applyFabState();
      if (overlay()?.classList.contains('open')) render();
    });
  }
})(window);
