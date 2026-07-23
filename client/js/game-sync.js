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
    G._syncPullBlockedSpins = 0;
    clearInterval(G.presenceTimer);
    G.presenceTimer = null;
  };

  /** Match doPollLegacy gates — avoid fetching mid-animation (queues states and stacks anims). */
  global.pollPresentationBlocked = function pollPresentationBlocked() {
    // Soft-heal only when the director is idle — never clear flags under an active run.
    const directorActive = typeof LiveRoundDirector !== 'undefined' && LiveRoundDirector.active;
    if (!directorActive
        && G._liveRoundPlaybackActive && !G.animating && !G._perfSpectacleActive && !G._liveSpectacleGateRunning) {
      TCG_DEBUG.warn('poll', 'clear stale liveRoundPlaybackActive');
      G._liveRoundPlaybackActive = false;
      if (G._livePollHold && typeof releaseLivePolls === 'function') releaseLivePolls();
    }
    // Stuck Performance chrome after the round already reached Main / judge pick softlocks sync.
    const ph = G.gameState?.phase;
    const prType = G.gameState?.pending_prompt?.type;
    const mainStable = ph === 'main_first' || ph === 'main_second'
      || ph === 'active_first' || ph === 'active_second';
    const metaType = G.gameState?.pending_prompt_meta?.type || null;
    const judgePickReady = ph === 'live_judge'
      && (prType === 'pick_judge_success_live' || prType === 'replace_success_with_wr_live' || prType === 'sbp6_live_wr_deck_position'
        || metaType === 'pick_judge_success_live' || metaType === 'replace_success_with_wr_live' || metaType === 'sbp6_live_wr_deck_position')
      && !!G._liveRoundPostSpectacleReady;
    // Spectators never receive full pending_prompt — live_judge must not softlock polls.
    // Do not special-case spectators beyond missing prompts: a broader live_judge clear
    // used to abort Performance mid-show when animating briefly dropped.
    const judgeWaitNoLocalPrompt = ph === 'live_judge' && !prType;
    if (!directorActive
        && G._perfSpectacleActive && !G.animating && !G._liveSpectacleGateRunning
        && !G._liveRoundPlaybackActive
        && (mainStable || judgePickReady || judgeWaitNoLocalPrompt)) {
      TCG_DEBUG.warn('poll', 'clear stuck perfSpectacleActive', { phase: ph, prType, spectator: !!G.isSpectator });
      if (typeof perfCloseSpectacle === 'function') perfCloseSpectacle();
      else G._perfSpectacleActive = false;
      if (G._livePollHold && typeof releaseLivePolls === 'function') releaseLivePolls();
    }
    // Spectators stuck mid Win/Loss with playback held but no animation — release
    // only after the show finished or never started (postSpectacleReady / no defer).
    if (!directorActive
        && !!G.isSpectator
        && ph === 'live_judge'
        && G._liveRoundPlaybackActive
        && !G.animating
        && !G._perfSpectacleActive
        && !G._liveSpectacleGateRunning
        && (G._liveRoundPostSpectacleReady || !G._deferredLiveState)) {
      TCG_DEBUG.warn('poll', 'clear stale spectator liveRoundPlaybackActive on live_judge');
      G._liveRoundPlaybackActive = false;
      G._liveRoundPostSpectacleReady = true;
      if (G._livePollHold && typeof releaseLivePolls === 'function') releaseLivePolls();
    }
    return !!(G.animating || G._perfSpectacleActive || G._livePollHold
      || directorActive
      || G._replaySeekInFlight || G._replayForwardApply);
  };

  global.scheduleDeferredSyncPull = function scheduleDeferredSyncPull(delayMs = 400) {
    clearTimeout(G._syncPullTimer);
    if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.syncEnabled) return;
    G._syncPullTimer = setTimeout(async () => {
      G._syncPullTimer = null;
      if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
      if (pollPresentationBlocked()) {
        // Re-arm once presentation frees — avoid a tight timer spin while blocked.
        const spins = (G._syncPullBlockedSpins || 0) + 1;
        G._syncPullBlockedSpins = spins;
        scheduleDeferredSyncPull(Math.min(2000, 400 + spins * 100));
        return;
      }
      G._syncPullBlockedSpins = 0;
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

  /** Drop poll responses from a prior room/session (e.g. tutorial boot after CPU match). */
  function pollResponseStillCurrent(epoch, roomId) {
    return !!(G.polling && epoch === G._gameSessionEpoch && roomId && roomId === G.roomId);
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
      await apiPost('ping', { room_id: G.roomId, token: G.token }, { silent: true });
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
      if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.roomId) return;
      if (!G.syncTicket) {
        try {
          const r = await apiPost('sync_ticket', { room_id: G.roomId, token: G.token }, { silent: true });
          captureSyncMeta(r);
        } catch (e) { /* retry below */ }
      }
      if (G.syncEnabled && G.syncTicket) openSyncStream();
      else if (G.polling) scheduleSyncReconnect(Math.min(12000, ms * 2));
    }, ms);
  };

  function onSyncStateEvent(data) {
    if (!G.polling || !G.roomId) return;
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
        const r = await apiPost('sync_ticket', { room_id: G.roomId, token: G.token }, { silent: true });
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
    const pollEpoch = G._gameSessionEpoch;
    const pollRoomId = G.roomId;
    let pollError = null;
    try {
      TCG_DEBUG.log('poll', 'fetch', { seq: G.lastSeq, room: pollRoomId });
      const r = await fetch(`${API}?action=get_state&room_id=${encodeURIComponent(pollRoomId)}&token=${G.token}&seq=${G.lastSeq}`);
      const d = await parseGameApiResponse(r);
      if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return;
      G._pollRateLimitBackoff = 0;
      onState(d);
    } catch (e) {
      if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return;
      if (e && e.httpStatus >= 400) {
        if (handleSpectatorPollError(e.message)) return;
        reportApiError(e, { source: 'poll' });
        pollError = e.message;
      } else {
        TCG_DEBUG.warn('poll', 'fetch failed', e);
        reportApiError(createApiError(
          (global.LLTCG_I18N && typeof global.LLTCG_I18N.t === 'function')
            ? global.LLTCG_I18N.t('apiError.connectionFailed')
            : 'Could not reach the server. Try refreshing the page.',
          503
        ), { source: 'poll' });
        pollError = e && e.message ? e.message : 'fetch failed';
      }
    }
    if (G.polling) G.pollTimer = setTimeout(doPollLegacy, nextPollDelayMs(pollError));
  };

  /** Fetch state during live presentation without queueing behind G.animating. */
  global.pullSkillResolutionState = async function pullSkillResolutionState(opts = {}) {
    if (!G.roomId || !G.token) return G.gameState || null;
    if (G.isTutorial && !G.tutorialLive) return G.gameState || null;
    const pollEpoch = G._gameSessionEpoch;
    const pollRoomId = G.roomId;
    TCG_DEBUG.log('poll', 'pullSkillResolutionState', { seq: G.lastSeq, room: pollRoomId });
    try {
      const r = await fetch(`${API}?action=get_state&room_id=${encodeURIComponent(pollRoomId)}&token=${encodeURIComponent(G.token)}&seq=${G.lastSeq}&poll=0`);
      const d = await parseGameApiResponse(r);
      if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return G.gameState || null;
      if ((d.seq ?? 0) <= (G.lastSeq ?? 0)) return G.gameState || null;
      // Finished must go through onState/applyFinishedState — advancing lastSeq here
      // alone skips the win overlay and leaves later finished polls as "stale".
      if (d.status === 'finished') {
        if (typeof global.onState === 'function') {
          global.onState(d);
        } else if (typeof global.applyFinishedState === 'function') {
          void global.applyFinishedState(d, G.gameState);
        }
        return d;
      }
      G.lastSeq = d.seq;
      G.playerId = G.isSpectator
        ? ((G.spectatorViewAs === 'p1' || G.spectatorViewAs === 'p2') ? G.spectatorViewAs : (d.view_as || 'p1'))
        : (d.my_id || G.playerId);
      G.gameState = d;
      // Mid-spectacle Kurage / yell-retry: keep deferred in lockstep so presentLiveRound
      // does not resurrect the gate-entry pending_prompt after resolve.
      if (G._perfSpectacleActive || G._liveRoundPlaybackActive || G._livePollHold) {
        G._deferredLiveState = d;
      }
      if (typeof global.renderGame === 'function') {
        const skipPrompt = typeof global.shouldDeferPromptForLivePresentation === 'function'
          && global.shouldDeferPromptForLivePresentation(d, G.playerId);
        global.renderGame(d, { skipLog: true, skipPrompt });
      }
      if (typeof global.ensurePendingPromptSurfaced === 'function') {
        global.ensurePendingPromptSurfaced(d, G.playerId);
      }
      if (d.pending_prompt) {
        if (typeof global.syncPromptSubmitState === 'function') global.syncPromptSubmitState(d);
      } else if (typeof global.clearDeferredPromptState === 'function') {
        global.clearDeferredPromptState();
      }
      return d;
    } catch (e) {
      if (!opts.silent) {
        TCG_DEBUG.warn('poll', 'pullSkillResolutionState failed', e);
      }
      return G.gameState || null;
    }
  };

  global.pullLatestState = async function pullLatestState(force, opts = {}) {
    if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.roomId || !G.token) return;
    if (!force && pollPresentationBlocked()) {
      if (G.syncEnabled && G.syncTicket) scheduleDeferredSyncPull(400);
      else resumePollingTick(400);
      return;
    }
    // Coalesce concurrent poll=0 fetches — overlapping callers used to stampede get_state.
    if (G._pullLatestInFlight) {
      await G._pullLatestInFlight;
      if (!force) return;
      // force: after the in-flight result, fall through for one more pull if still current.
      if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.roomId || !G.token) return;
    }
    const pollEpoch = G._gameSessionEpoch;
    const pollRoomId = G.roomId;
    TCG_DEBUG.log('poll', 'pullLatestState', { seq: G.lastSeq, force: !!force, room: pollRoomId });
    const run = (async () => {
      try {
        const r = await fetch(`${API}?action=get_state&room_id=${encodeURIComponent(pollRoomId)}&token=${encodeURIComponent(G.token)}&seq=${G.lastSeq}&poll=0`);
        const d = await parseGameApiResponse(r);
        if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return;
        if (force && d.status === 'finished') {
          G._pendingStateQueue = (G._pendingStateQueue || []).filter(st => (st.seq ?? 0) > (d.seq ?? 0));
        }
        if (force && G.gameState && (d.seq ?? 0) <= (G.lastSeq ?? 0)) {
          TCG_DEBUG.logOnce('poll', `force-stale:${d.seq}`, 'skip force pull stale', { incoming: d.seq, last: G.lastSeq });
          if (typeof tryFlushSpectacleRecovery === 'function') tryFlushSpectacleRecovery();
          return;
        }
        onState(d);
      } catch (e) {
        if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return;
        if (!opts.silent) {
          if (e && e.httpStatus >= 400) {
            if (!handleSpectatorPollError(e.message)) reportApiError(e, { source: 'pullLatestState' });
          } else {
            TCG_DEBUG.warn('poll', 'pullLatestState failed', e);
            reportApiError(createApiError(
              (global.LLTCG_I18N && typeof global.LLTCG_I18N.t === 'function')
                ? global.LLTCG_I18N.t('apiError.connectionFailed')
                : 'Could not reach the server. Try refreshing the page.',
              503
            ), { source: 'pullLatestState' });
          }
        } else {
          TCG_DEBUG.warn('poll', 'pullLatestState failed (silent)', e);
        }
      }
    })();
    G._pullLatestInFlight = run;
    try {
      await run;
    } finally {
      if (G._pullLatestInFlight === run) G._pullLatestInFlight = null;
    }
  };

})(window);
