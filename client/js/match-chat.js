/**
 * In-match Log/Chat tabs — ephemeral Public / Friends / Spectate lobbies via WRAPPED_API SSE.
 */
(function (global) {
  'use strict';

  const MATCH_CHAT_OPT_KEY = 'tcg_match_chat_enabled';
  const CHAT_DOM_MAX_LINES = 40;
  const emojiById = new Map();
  const emojiByName = new Map();
  const seenIds = new Set();

  let eventSource = null;
  let activeTab = 'log';
  let activeRoom = 'public';
  let emotesLoaded = false;
  let autocompleteItems = [];
  let autocompleteIndex = -1;
  let uiBound = false;
  let reconnectTimer = null;

  function tt(key, fallback, vars) {
    const fn = global.LLTCG_I18N && global.LLTCG_I18N.tt;
    return typeof fn === 'function' ? fn(key, fallback, vars) : (fallback || key);
  }

  function G() {
    return global.G || {};
  }

  function matchChatUserEnabled() {
    try {
      return localStorage.getItem(MATCH_CHAT_OPT_KEY) !== '0';
    } catch (e) {
      return true;
    }
  }

  function setMatchChatUserEnabled(on) {
    try {
      localStorage.setItem(MATCH_CHAT_OPT_KEY, on ? '1' : '0');
    } catch (e) { /* ignore */ }
  }

  function isReplay() {
    if (typeof global.isReplayViewing === 'function') return !!global.isReplayViewing();
    return !!(G().replayMode);
  }

  function inGameScreen() {
    return !!document.getElementById('screen-game')?.classList.contains('active');
  }

  /** Ranked / unranked human PvP (incl. spectate); not CPU / tutorial / replay. */
  function matchChatEligible() {
    if (!matchChatUserEnabled()) return false;
    if (!inGameScreen()) return false;
    const g = G();
    if (g.isTutorial || g.isCPU || isReplay()) return false;
    if (!g.roomId) return false;
    return true;
  }

  function wrappedApi() {
    return global.WRAPPED_API || '/wrapped/api.php';
  }

  function authToken() {
    if (typeof global.getAuthToken === 'function') return global.getAuthToken() || '';
    try {
      return localStorage.getItem('llr_wrapped_token') || '';
    } catch (e) {
      return '';
    }
  }

  function myProfile() {
    const A = global.A || {};
    const u = A.user || A.profile?.user || {};
    return {
      id: String(u.id || ''),
      username: String(u.username || u.global_name || 'User'),
      avatar_hash: u.avatar || u.avatar_hash || null,
    };
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderColonEmojiSegment(segment) {
    const re = /:([a-zA-Z0-9_]{2,32}):/g;
    let out = '';
    let last = 0;
    let m;
    while ((m = re.exec(segment)) !== null) {
      out += escapeHtml(segment.slice(last, m.index));
      const em = emojiByName.get(m[1].toLowerCase());
      if (em && em.url) {
        const alt = ':' + escapeHtml(em.name) + ':';
        out +=
          '<img class="chat-emo" src="' +
          escapeHtml(em.url) +
          '" alt="' +
          alt +
          '" title="' +
          alt +
          '" loading="lazy" />';
      } else {
        out += escapeHtml(m[0]);
      }
      last = m.index + m[0].length;
    }
    out += escapeHtml(segment.slice(last));
    return out;
  }

  function renderRichText(raw) {
    const str = String(raw);
    let out = '';
    let i = 0;
    while (i < str.length) {
      const lt = str.indexOf('<', i);
      if (lt === -1) {
        out += renderColonEmojiSegment(str.slice(i));
        break;
      }
      out += renderColonEmojiSegment(str.slice(i, lt));
      const gt = str.indexOf('>', lt + 1);
      if (gt === -1) {
        out += escapeHtml(str.slice(lt));
        break;
      }
      const chunk = str.slice(lt, gt + 1);
      let mid = chunk.match(/^<a:([\w]+):(\d+)>$/);
      if (!mid) mid = chunk.match(/^<:([\w]+):(\d+)>$/);
      if (mid) {
        const em = emojiById.get(String(mid[2]));
        if (em && em.url) {
          const nm = em.name || mid[1];
          const alt = ':' + escapeHtml(nm) + ':';
          out +=
            '<img class="chat-emo" src="' +
            escapeHtml(em.url) +
            '" alt="' +
            alt +
            '" title="' +
            alt +
            '" loading="lazy" />';
        } else {
          out += escapeHtml(chunk);
        }
      } else {
        out += escapeHtml(chunk);
      }
      i = gt + 1;
    }
    return out;
  }

  function setStatus(msg) {
    const el = document.getElementById('match-chat-status');
    if (!el) return;
    if (!msg) {
      el.hidden = true;
      el.textContent = '';
      return;
    }
    el.hidden = false;
    el.textContent = msg;
  }

  function trimMessages(messagesEl) {
    while (messagesEl.children.length > CHAT_DOM_MAX_LINES) {
      const first = messagesEl.firstElementChild;
      if (!first) break;
      const mid = first.dataset?.msgId;
      if (mid) seenIds.delete(String(mid));
      first.remove();
    }
  }

  function appendLine(msg) {
    const messagesEl = document.getElementById('match-chat-messages');
    if (!messagesEl || msg == null || msg.id == null) return;
    const channel = String(msg.channel || 'public');
    if (channel !== activeRoom) return;
    const idKey = String(msg.id) + ':' + channel;
    if (seenIds.has(idKey)) return;
    seenIds.add(idKey);

    const me = myProfile().id;
    const div = document.createElement('div');
    div.className = 'match-chat-line channel-' + channel;
    div.dataset.msgId = String(msg.id);
    div.dataset.userId = String(msg.user_id || '');
    div.dataset.rawText = String(msg.text || '');
    div.dataset.channel = channel;

    const who = escapeHtml(msg.username || 'User');
    let html =
      '<span class="who">' +
      who +
      '</span><span class="txt">' +
      renderRichText(msg.text || '') +
      '</span>';
    if (me && String(msg.user_id) !== me) {
      html +=
        '<button type="button" class="match-chat-report" data-report="1">' +
        escapeHtml(tt('chat.report', 'Report')) +
        '</button>';
    }
    div.innerHTML = html;
    messagesEl.appendChild(div);
    trimMessages(messagesEl);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function clearVisibleMessages() {
    const messagesEl = document.getElementById('match-chat-messages');
    if (messagesEl) messagesEl.innerHTML = '';
    seenIds.clear();
  }

  function setComposerEnabled(on) {
    const ta = document.getElementById('match-chat-input');
    const send = document.getElementById('match-chat-send-btn');
    const emojiBtn = document.getElementById('match-chat-emoji-btn');
    if (ta) {
      ta.disabled = !on;
      ta.placeholder = on
        ? tt('chat.placeholder', 'Message…')
        : tt('chat.signIn', 'Sign in to chat…');
    }
    if (send) send.disabled = !on;
    if (emojiBtn) emojiBtn.disabled = !on;
  }

  function syncTabUi() {
    const tabs = document.getElementById('match-log-chat-tabs');
    const eligible = matchChatEligible();
    if (tabs) tabs.hidden = !eligible;

    const logEl = document.getElementById('game-log');
    const chatPanel = document.getElementById('match-chat-panel');
    const title = document.getElementById('match-side-title');

    if (!eligible) {
      activeTab = 'log';
      if (chatPanel) chatPanel.hidden = true;
      if (logEl) logEl.hidden = false;
      if (title) title.textContent = tt('game.gameLog', 'Game Log');
      disconnectStream();
      return;
    }

    document.querySelectorAll('.match-log-chat-tab').forEach((btn) => {
      const is = btn.getAttribute('data-match-tab') === activeTab;
      btn.classList.toggle('is-active', is);
      btn.setAttribute('aria-selected', is ? 'true' : 'false');
    });

    if (activeTab === 'chat') {
      if (logEl) logEl.hidden = true;
      if (chatPanel) chatPanel.hidden = false;
      if (title) title.textContent = tt('game.chatTab', 'Chat');
      ensureStream();
      loadEmotesOnce();
      setComposerEnabled(!!authToken());
    } else {
      if (logEl) logEl.hidden = false;
      if (chatPanel) chatPanel.hidden = true;
      if (title) title.textContent = tt('game.gameLog', 'Game Log');
    }

    const specBtn = document.getElementById('btn-match-chat-spectate');
    const isSpec = !!G().isSpectator;
    if (specBtn) {
      specBtn.hidden = !isSpec;
      if (!isSpec && activeRoom === 'spectate') {
        setRoom('public');
      }
    }
    document.querySelectorAll('.match-chat-room').forEach((btn) => {
      const room = btn.getAttribute('data-chat-room');
      btn.classList.toggle('is-active', room === activeRoom);
    });
  }

  function setTab(tab) {
    activeTab = tab === 'chat' ? 'chat' : 'log';
    syncTabUi();
  }

  function setRoom(room) {
    const next = String(room || 'public');
    if (next === 'spectate' && !G().isSpectator) return;
    if (next !== 'public' && next !== 'friends' && next !== 'spectate') return;
    if (activeRoom === next) return;
    activeRoom = next;
    clearVisibleMessages();
    syncTabUi();
  }

  function disconnectStream() {
    if (reconnectTimer) {
      clearTimeout(reconnectTimer);
      reconnectTimer = null;
    }
    if (eventSource) {
      try {
        eventSource.close();
      } catch (e) { /* ignore */ }
      eventSource = null;
    }
  }

  function ensureStream() {
    if (!matchChatEligible() || activeTab !== 'chat') {
      disconnectStream();
      return;
    }
    const token = authToken();
    if (!token) {
      disconnectStream();
      setComposerEnabled(false);
      return;
    }
    if (eventSource) return;

    const role = G().isSpectator ? 'spectator' : 'player';
    const url =
      wrappedApi() +
      '?action=tcg_match_chat_stream&role=' +
      encodeURIComponent(role) +
      '&token=' +
      encodeURIComponent(token);
    try {
      eventSource = new EventSource(url);
    } catch (e) {
      eventSource = null;
      scheduleReconnect();
      return;
    }
    eventSource.addEventListener('message', (ev) => {
      try {
        const msg = JSON.parse(ev.data);
        appendLine(msg);
      } catch (e) { /* ignore */ }
    });
    eventSource.addEventListener('ready', () => {
      setStatus('');
    });
    eventSource.onerror = () => {
      disconnectStream();
      scheduleReconnect();
    };
  }

  function scheduleReconnect() {
    if (reconnectTimer || !matchChatEligible() || activeTab !== 'chat') return;
    reconnectTimer = setTimeout(() => {
      reconnectTimer = null;
      ensureStream();
    }, 2500);
  }

  async function loadEmotesOnce() {
    if (emotesLoaded) return;
    emotesLoaded = true;
    try {
      const r = await fetch(wrappedApi() + '?action=radio_chat_emotes');
      const d = await r.json();
      const list = (d && d.emotes) || (d && d.emojis) || [];
      if (!Array.isArray(list)) return;
      list.forEach((em) => {
        if (!em || !em.id) return;
        emojiById.set(String(em.id), em);
        if (em.name) emojiByName.set(String(em.name).toLowerCase(), em);
      });
      setRandomEmojiButtonIcon();
      fillEmojiPopover();
    } catch (e) {
      emotesLoaded = false;
    }
  }

  function setRandomEmojiButtonIcon() {
    const emojiBtn = document.getElementById('match-chat-emoji-btn');
    const list = Array.from(emojiById.values());
    if (!emojiBtn || list.length === 0) return;
    const pick = list[Math.floor(Math.random() * list.length)];
    if (!pick || !pick.url) return;
    emojiBtn.innerHTML = '';
    const img = document.createElement('img');
    img.src = pick.url;
    img.alt = 'Emoji';
    img.loading = 'lazy';
    emojiBtn.appendChild(img);
  }

  function fillEmojiPopover() {
    const pop = document.getElementById('match-chat-emoji-popover');
    if (!pop) return;
    pop.innerHTML = '';
    const list = Array.from(emojiById.values()).slice(0, 96);
    list.forEach((em) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.title = ':' + (em.name || '') + ':';
      const img = document.createElement('img');
      img.src = em.url;
      img.alt = em.name || '';
      img.loading = 'lazy';
      b.appendChild(img);
      b.addEventListener('click', () => {
        insertEmoteToken(em);
        pop.hidden = true;
      });
      pop.appendChild(b);
    });
  }

  function insertEmoteToken(em) {
    const ta = document.getElementById('match-chat-input');
    if (!ta || !em) return;
    const token = em.animated
      ? '<a:' + em.name + ':' + em.id + '>'
      : '<:' + em.name + ':' + em.id + '>';
    const start = ta.selectionStart || ta.value.length;
    const end = ta.selectionEnd || ta.value.length;
    ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
    ta.focus();
    const pos = start + token.length;
    ta.setSelectionRange(pos, pos);
  }

  async function sendMessage() {
    const ta = document.getElementById('match-chat-input');
    const token = authToken();
    if (!ta || !token) return;
    const text = String(ta.value || '').trim();
    if (!text) return;
    setStatus('');
    const profile = myProfile();
    const body = {
      text,
      channel: activeRoom,
      role: G().isSpectator ? 'spectator' : 'player',
      session_token: token,
      token,
      username: profile.username,
      avatar_hash: profile.avatar_hash,
    };
    try {
      const r = await fetch(wrappedApi() + '?action=tcg_match_chat_send', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: 'Bearer ' + token,
          'X-Auth-Token': token,
        },
        body: JSON.stringify(body),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d.success) {
        setStatus(String(d.error || tt('chat.sendFailed', 'Could not send.')));
        return;
      }
      ta.value = '';
      if (d.message) appendLine(d.message);
    } catch (e) {
      setStatus(tt('chat.sendFailed', 'Could not send.'));
    }
  }

  function reportMessage(line) {
    const target = line?.dataset?.userId;
    const snippet = line?.dataset?.rawText || '';
    if (!target || typeof global.accountPost !== 'function') return;
    const reasons = [
      { v: 'chat_spam', l: tt('chat.reportSpam', 'Spam') },
      { v: 'chat_rules', l: tt('chat.reportRules', 'Breaking rules') },
      { v: 'chat_harassment', l: tt('chat.reportHarassment', 'Harassment') },
      { v: 'chat_other', l: tt('chat.reportOther', 'Other') },
    ];
    const pick = window.prompt(
      tt('chat.reportAsk', 'Report reason:\n1 Spam\n2 Breaking rules\n3 Harassment\n4 Other\nEnter 1-4:'),
      '1'
    );
    if (pick == null) return;
    const n = parseInt(String(pick).trim(), 10);
    const reason = reasons[(n >= 1 && n <= 4 ? n : 1) - 1];
    global
      .accountPost('social_report', {
        user_id: target,
        field: reason.v,
        reason: reason.v,
        snippet: String(snippet).slice(0, 500),
      })
      .then(() => {
        if (typeof global.toast === 'function') {
          global.toast(tt('chat.reported', 'Report sent.'), 2200);
        }
      })
      .catch((e) => {
        setStatus(e?.message || tt('chat.reportFailed', 'Report failed.'));
      });
  }

  function updateAutocomplete() {
    const ta = document.getElementById('match-chat-input');
    const box = document.getElementById('match-chat-emoji-autocomplete');
    if (!ta || !box) return;
    const val = ta.value;
    const pos = ta.selectionStart || 0;
    const before = val.slice(0, pos);
    const m = before.match(/:([a-zA-Z0-9_]{1,32})$/);
    if (!m) {
      box.hidden = true;
      autocompleteItems = [];
      return;
    }
    const q = m[1].toLowerCase();
    autocompleteItems = Array.from(emojiByName.values())
      .filter((em) => String(em.name || '').toLowerCase().includes(q))
      .slice(0, 8);
    if (!autocompleteItems.length) {
      box.hidden = true;
      return;
    }
    autocompleteIndex = 0;
    box.innerHTML = '';
    autocompleteItems.forEach((em, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      if (i === 0) b.classList.add('is-active');
      b.innerHTML =
        (em.url
          ? '<img src="' + escapeHtml(em.url) + '" alt="" />'
          : '') +
        ':' +
        escapeHtml(em.name) +
        ':';
      b.addEventListener('click', () => applyAutocomplete(i));
      box.appendChild(b);
    });
    box.hidden = false;
  }

  function applyAutocomplete(idx) {
    const ta = document.getElementById('match-chat-input');
    const box = document.getElementById('match-chat-emoji-autocomplete');
    const em = autocompleteItems[idx];
    if (!ta || !em) return;
    const pos = ta.selectionStart || 0;
    const before = ta.value.slice(0, pos);
    const after = ta.value.slice(pos);
    const replaced = before.replace(/:([a-zA-Z0-9_]{1,32})$/, '');
    const token = em.animated
      ? '<a:' + em.name + ':' + em.id + '>'
      : '<:' + em.name + ':' + em.id + '>';
    ta.value = replaced + token + after;
    const np = replaced.length + token.length;
    ta.setSelectionRange(np, np);
    if (box) box.hidden = true;
    autocompleteItems = [];
  }

  function bindUi() {
    if (uiBound) return;
    uiBound = true;

    document.getElementById('btn-match-tab-log')?.addEventListener('click', () => setTab('log'));
    document.getElementById('btn-match-tab-chat')?.addEventListener('click', () => setTab('chat'));

    document.getElementById('match-chat-rooms')?.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-chat-room]');
      if (!btn) return;
      setRoom(btn.getAttribute('data-chat-room'));
    });

    document.getElementById('match-chat-send-btn')?.addEventListener('click', () => {
      void sendMessage();
    });

    const ta = document.getElementById('match-chat-input');
    ta?.addEventListener('keydown', (e) => {
      const box = document.getElementById('match-chat-emoji-autocomplete');
      if (box && !box.hidden && autocompleteItems.length) {
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          autocompleteIndex = (autocompleteIndex + 1) % autocompleteItems.length;
          box.querySelectorAll('button').forEach((b, i) => b.classList.toggle('is-active', i === autocompleteIndex));
          return;
        }
        if (e.key === 'ArrowUp') {
          e.preventDefault();
          autocompleteIndex = (autocompleteIndex - 1 + autocompleteItems.length) % autocompleteItems.length;
          box.querySelectorAll('button').forEach((b, i) => b.classList.toggle('is-active', i === autocompleteIndex));
          return;
        }
        if (e.key === 'Enter' || e.key === 'Tab') {
          e.preventDefault();
          applyAutocomplete(autocompleteIndex);
          return;
        }
        if (e.key === 'Escape') {
          box.hidden = true;
          return;
        }
      }
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        void sendMessage();
      }
    });
    ta?.addEventListener('input', updateAutocomplete);

    document.getElementById('match-chat-emoji-btn')?.addEventListener('click', () => {
      const pop = document.getElementById('match-chat-emoji-popover');
      if (!pop) return;
      pop.hidden = !pop.hidden;
      if (!pop.hidden) loadEmotesOnce();
    });

    document.getElementById('match-chat-messages')?.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-report]');
      if (!btn) return;
      const line = btn.closest('.match-chat-line');
      if (line) reportMessage(line);
    });

    document.addEventListener('click', (e) => {
      const pop = document.getElementById('match-chat-emoji-popover');
      const wrap = document.querySelector('.match-chat-emoji-wrap');
      if (pop && wrap && !wrap.contains(e.target)) pop.hidden = true;
    });

    const chk = document.getElementById('chk-match-chat');
    if (chk) {
      chk.checked = matchChatUserEnabled();
      chk.addEventListener('change', () => {
        setMatchChatUserEnabled(!!chk.checked);
        if (!chk.checked) {
          activeTab = 'log';
          disconnectStream();
        }
        syncMatchChat();
      });
    }
  }

  function syncMatchChat() {
    bindUi();
    syncTabUi();
    if (matchChatEligible() && activeTab === 'chat') ensureStream();
    else if (!matchChatEligible()) disconnectStream();
  }

  function hookLifecycle() {
    const wrap = (name) => {
      const orig = global[name];
      if (typeof orig !== 'function' || orig._tcgMatchChatWrapped) return;
      const wrapped = function () {
        const ret = orig.apply(this, arguments);
        try {
          syncMatchChat();
        } catch (e) { /* ignore */ }
        return ret;
      };
      wrapped._tcgMatchChatWrapped = true;
      global[name] = wrapped;
    };
    wrap('showScr');
    wrap('stopPoll');
    wrap('startPoll');
  }

  function init() {
    bindUi();
    hookLifecycle();
    syncMatchChat();
    setInterval(() => {
      if (matchChatEligible() && activeTab === 'chat' && !eventSource) ensureStream();
      else if (!matchChatEligible() && eventSource) disconnectStream();
    }, 4000);
  }

  global.tcgMatchChatSync = syncMatchChat;
  global.tcgMatchChatDisconnect = disconnectStream;
  global.matchChatUserEnabled = matchChatUserEnabled;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(typeof window !== 'undefined' ? window : globalThis);
