/**
 * TCG poll loop, SSE sync stream, and pullLatestState transport.
 */
(function (global) {
  'use strict';

  global.TCG_PRESENCE_PING_MS = 30000;
  global.TCG_SYNC_FALLBACK_POLL_MS = 3000;
  global.TCG_SYNC_MAX_FAILS = 6;

  global.stopSyncStream = function stopSyncStream() {
    if (G.syncEventSource) {
      G.syncEventSource.close();
      G.syncEventSource = null;
    }
    clearTimeout(G.syncFallbackTimer);
    G.syncFallbackTimer = null;
    clearTimeout(G._syncReconnectTimer);
    G._syncReconnectTimer = null;
    clearTimeout(G._syncPullTimer);
    G._syncPullTimer = null;
    clearInterval(G.presenceTimer);
    G.presenceTimer = null;
  };

  /** Match doPollLegacy gates — avoid fetching mid-animation (queues states and stacks anims). */
  global.pollPresentationBlocked = function pollPresentationBlocked() {
    return !!(G.animating || G._perfSpectacleActive || G._livePollHold);
  };

  global.scheduleDeferredSyncPull = function scheduleDeferredSyncPull(delayMs = 400) {
    clearTimeout(G._syncPullTimer);
    if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.syncEnabled) return;
    G._syncPullTimer = setTimeout(async () => {
      G._syncPullTimer = null;
      if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
      if (pollPresentationBlocked()) {
        scheduleDeferredSyncPull(400);
        return;
      }
      await pullLatestState();
    }, delayMs);
  };

  global.resumePollingTick = function resumePollingTick(delayMs = 120) {
    if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
    clearTimeout(G.pollTimer);
    if (G.syncEnabled && G.syncTicket) scheduleDeferredSyncPull(Math.max(delayMs, 150));
    else G.pollTimer = setTimeout(doPollLegacy, delayMs);
  };

  function pollDelayAfterError(errorMsg) {
    if (typeof errorMsg === 'string' && /rate limit/i.test(errorMsg)) {
      G._pollRateLimitBackoff = Math.min((G._pollRateLimitBackoff || 0) + 1, 6);
      return Math.min(8000, 800 * (2 ** G._pollRateLimitBackoff));
    }
    G._pollRateLimitBackoff = 0;
    return null;
  }

  function nextPollDelayMs(errorMsg) {
    const backoff = pollDelayAfterError(errorMsg);
    if (backoff != null) return backoff;
    return G.isCPU ? 280 : 600;
  }

  global.stopPoll = function stopPoll() {
    G.polling = false;
    clearTimeout(G.pollTimer);
    clearTimeout(G.watchdogTimer);
    clearPvPWatchdog();
    stopSyncStream();
  };

  async function tcgPresencePing() {
    if (!G.polling || !G.roomId || !G.token || (G.isTutorial && !G.tutorialLive)) return;
    try {
      await apiPost('ping', { room_id: G.roomId, token: G.token });
    } catch (e) { /* best effort */ }
  }

  global.startSyncFallbackPoll = function startSyncFallbackPoll() {
    clearTimeout(G.syncFallbackTimer);
    if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
    G.syncFallbackTimer = setTimeout(async () => {
      G.syncFallbackTimer = null;
      if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
      if (G.animating || G._perfSpectacleActive || G._livePollHold) {
        startSyncFallbackPoll();
        return;
      }
      await pullLatestState();
      if (G.polling && G.syncEnabled === false) startSyncFallbackPoll();
    }, TCG_SYNC_FALLBACK_POLL_MS);
  };

  global.scheduleSyncReconnect = function scheduleSyncReconnect(ms) {
    clearTimeout(G._syncReconnectTimer);
    G._syncReconnectTimer = setTimeout(async () => {
      G._syncReconnectTimer = null;
      if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
      if (!G.syncTicket) {
        try {
          const r = await apiPost('sync_ticket', { room_id: G.roomId, token: G.token });
          captureSyncMeta(r);
        } catch (e) { /* retry below */ }
      }
      if (G.syncEnabled && G.syncTicket) openSyncStream();
      else if (G.polling) scheduleSyncReconnect(Math.min(12000, ms * 2));
    }, ms);
  };

  function onSyncStateEvent(data) {
    const seq = parseInt(data?.seq, 10);
    if (!Number.isFinite(seq) || seq <= (G.lastSeq ?? 0)) return;
    TCG_DEBUG.log('sync', 'state event', { seq, last: G.lastSeq });
    if (pollPresentationBlocked()) {
      scheduleDeferredSyncPull(400);
      return;
    }
    void pullLatestState();
  }

  global.openSyncStream = function openSyncStream() {
    stopSyncStream();
    if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.roomId || !G.syncTicket) return;
    const url = `${WRAPPED_API}?action=tcg_sync_stream&room_id=${encodeURIComponent(G.roomId)}`
      + `&ticket=${encodeURIComponent(G.syncTicket)}&last_seq=${encodeURIComponent(String(G.lastSeq ?? 0))}`;
    TCG_DEBUG.log('sync', 'connect', { room: G.roomId, seq: G.lastSeq });
    const es = new EventSource(url);
    G.syncEventSource = es;
    es.addEventListener('ready', () => {
      G._syncFailCount = 0;
      clearTimeout(G.syncFallbackTimer);
      G.syncFallbackTimer = null;
    });
    es.addEventListener('state', (ev) => {
      try { onSyncStateEvent(JSON.parse(ev.data)); } catch (e) { /* ignore */ }
    });
    es.addEventListener('rotate', () => {
      es.close();
      G.syncEventSource = null;
      if (G.polling) scheduleSyncReconnect(280);
    });
    es.onerror = () => {
      es.close();
      G.syncEventSource = null;
      G._syncFailCount = (G._syncFailCount || 0) + 1;
      if (G._syncFailCount >= TCG_SYNC_MAX_FAILS) {
        TCG_DEBUG.warn('sync', 'using poll=0 fallback');
        startSyncFallbackPoll();
      }
      if (G.polling) scheduleSyncReconnect(Math.min(8000, 400 * Math.pow(2, G._syncFailCount)));
    };
    clearInterval(G.presenceTimer);
    void tcgPresencePing();
    G.presenceTimer = setInterval(() => void tcgPresencePing(), TCG_PRESENCE_PING_MS);
  };

  global.beginGameSync = async function beginGameSync() {
    if (!G.syncTicket) {
      try {
        const r = await apiPost('sync_ticket', { room_id: G.roomId, token: G.token });
        captureSyncMeta(r);
      } catch (e) {
        TCG_DEBUG.warn('sync', 'sync_ticket failed', e);
      }
    }
    // CPU solo: no opponent to push — legacy poll paces updates and waits for animations.
    if (G.isCPU && !G.isSpectator) {
      G.syncEnabled = false;
      G.syncTicket = null;
      stopSyncStream();
      await pullLatestState();
      doPollLegacy();
      return;
    }
    if (G.syncEnabled && G.syncTicket) {
      openSyncStream();
      // Bootstrap: SSE only pushes seq bumps; missed pre-subscribe notifies need one fetch.
      await pullLatestState();
      return;
    }
    G.syncEnabled = false;
    ensurePresencePingTimer();
    doPollLegacy();
    return;
  };

  global.ensurePresencePingTimer = function ensurePresencePingTimer() {
    if (G.presenceTimer || !G.polling || (G.isTutorial && !G.tutorialLive) || !G.roomId || !G.token) return;
    void tcgPresencePing();
    G.presenceTimer = setInterval(() => void tcgPresencePing(), TCG_PRESENCE_PING_MS);
  };

  global.startPoll = function startPoll() {
    clearTimeout(G.pollTimer);
    clearTimeout(G.watchdogTimer);
    G.polling = true;
    G._syncFailCount = 0;
    if (G.isSpectator) saveSpectatorSession();
    else saveActiveGameSession();
    if ((G.isTutorial && !G.tutorialLive)) return;
    void beginGameSync();
  };

  global.doPollLegacy = async function doPollLegacy() {
    if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
    if (G.animating || G._perfSpectacleActive) {
      TCG_DEBUG.logOnce('poll', `blocked:${G.animating}:${G._perfSpectacleActive}`, 'blocked (animating/spectacle)', { animating: G.animating, spectacle: G._perfSpectacleActive });
      if (G.polling) G.pollTimer = setTimeout(doPollLegacy, 400);
      return;
    }
    ensurePollHoldReleased(G.gameState);
    const blockPoll = G._livePollHold;
    if (blockPoll) {
      TCG_DEBUG.logOnce('poll', 'livePollHold', 'blocked (livePollHold)', TCG_DEBUG.snap(G.gameState));
      if (G.polling) G.pollTimer = setTimeout(doPollLegacy, 400);
      return;
    }
    let pollError = null;
    try {
      TCG_DEBUG.log('poll', 'fetch', { seq: G.lastSeq, room: G.roomId });
      const r = await fetch(`${API}?action=get_state&room_id=${encodeURIComponent(G.roomId)}&token=${G.token}&seq=${G.lastSeq}`);
      const d = await r.json();
      if (d.error) {
        if (handleSpectatorPollError(d.error)) return;
        pollError = d.error;
        TCG_DEBUG.warn('poll', 'error', d.error);
      } else {
        G._pollRateLimitBackoff = 0;
        onState(d);
      }
    } catch (e) { TCG_DEBUG.warn('poll', 'fetch failed', e); }
    if (G.polling) G.pollTimer = setTimeout(doPollLegacy, nextPollDelayMs(pollError));
  };

  global.pullLatestState = async function pullLatestState(force) {
    if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.roomId || !G.token) return;
    if (!force && pollPresentationBlocked()) {
      if (G.syncEnabled && G.syncTicket) scheduleDeferredSyncPull(400);
      else resumePollingTick(400);
      return;
    }
    TCG_DEBUG.log('poll', 'pullLatestState', { seq: G.lastSeq, force: !!force });
    try {
      const r = await fetch(`${API}?action=get_state&room_id=${encodeURIComponent(G.roomId)}&token=${encodeURIComponent(G.token)}&seq=${G.lastSeq}&poll=0`);
      const d = await r.json();
      if (!d.error) {
        if (force && d.status === 'finished') {
          G._pendingStateQueue = (G._pendingStateQueue || []).filter(st => (st.seq ?? 0) > (d.seq ?? 0));
        }
        onState(d);
      } else if (!handleSpectatorPollError(d.error)) TCG_DEBUG.warn('poll', 'pullLatestState error', d.error);
    } catch (e) { TCG_DEBUG.warn('poll', 'pullLatestState failed', e); }
  };

})(window);
