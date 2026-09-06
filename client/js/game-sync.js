/**
 * TCG poll loop, SSE sync stream, and pullLatestState transport.
 */
(function (global) {
  'use strict';

  global.TCG_PRESENCE_PING_MS = 30000;
  /** Base interval for poll=0 fallback when SSE is down (grows with backoff). */
  global.TCG_SYNC_FALLBACK_POLL_MS = 5000;
  global.TCG_SYNC_FALLBACK_POLL_MAX_MS = 20000;
  global.TCG_SYNC_MAX_FAILS = 6;
  /** Prefer Apache-proxied VPS stream; Hostinger PHP proxy is fallback only. */
  global.TCG_SYNC_USE_PHP_PROXY = false;
  /** Debounce SSE→get_state so a burst of seq notifies becomes one fetch. */
  global.TCG_SYNC_SSE_DEBOUNCE_MS = 220;
  /** Spectators can coalesce more aggressively — they never own the action path. */
  global.TCG_SYNC_SSE_DEBOUNCE_SPECTATE_MS = 750;
  /** After an in-flight get_state, wait this long before one follow-up pull. */
  global.TCG_SYNC_COALESCE_FOLLOW_MS = 450;
  /** Safety get_state while SSE looks healthy (PvP / CPU). */
  global.TCG_SYNC_SAFETY_POLL_MS = 5500;
  global.TCG_SYNC_SAFETY_POLL_CPU_MS = 5000;
  /** Spectators: slower safety net so N watchers do not match player poll cadence. */
  global.TCG_SYNC_SAFETY_POLL_SPECTATE_MS = 16000;
  /** Fallback poll when SSE is down — spectators use a longer base interval. */
  global.TCG_SYNC_FALLBACK_POLL_SPECTATE_MS = 12000;

  global._tcgSyncStats = global._tcgSyncStats || {
    streamDirect: 0,
    streamProxy: 0,
    streamFails: 0,
    getState: 0,
    disconnectHints: 0,
  };

  function isReplayViewingSync() {
    return typeof global.isReplayViewing === 'function' && global.isReplayViewing();
  }

  global.tcgSyncStatsSnapshot = function tcgSyncStatsSnapshot() {
    return { ...(global._tcgSyncStats || {}) };
  };

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
    if (typeof stopSyncSafetyPoll === 'function') stopSyncSafetyPoll();
    clearInterval(G.presenceTimer);
    G.presenceTimer = null;
  };

  /** Match doPollLegacy gates — avoid fetching mid-animation (queues states and stacks anims). */
  global.pollPresentationBlocked = function pollPresentationBlocked() {
    // Soft-heal only when the director is idle — never clear flags under an active run.
    const directorActive = typeof LiveRoundDirector !== 'undefined' && LiveRoundDirector.active;
    const serverLiveShowInFlight = !!G.gameState?.live_show?.stage
      && G.gameState.live_show.stage !== 'done';
    const guards = global.LLTCG_PRESENTATION_GUARDS;
    const finishedUnblock = !!(guards?.mayUnblockPollsForFinishedMatch?.(G.gameState));

    // Match-ending 3rd Success: status=finished but phase may stay live_judge with
    // live_show unset — clear leftover Checking hearts / spectacle so showWin can run.
    if (finishedUnblock) {
      if (G._perfSpectacleActive || G._perfHeartCheckHold) {
        TCG_DEBUG.warn('poll', 'clear leftover chrome for finished match');
        if (typeof perfCloseSpectacle === 'function') perfCloseSpectacle();
        else G._perfSpectacleActive = false;
        if (typeof perfClearHeartCheckHold === 'function') perfClearHeartCheckHold();
      }
      G.animating = false;
      G._liveRoundPlaybackActive = false;
      G._liveSpectacleGateRunning = false;
      G._liveShowRunnerActive = false;
      if (typeof releaseLivePolls === 'function') releaseLivePolls({ forceResume: true });
      else G._livePollHold = false;
      return !!(G._replaySeekInFlight || G._replayForwardApply);
    }

    if (G._liveShowRunnerActive) return true;
    if (!directorActive
        && !serverLiveShowInFlight
        && G._liveRoundPlaybackActive && !G.animating && !G._perfSpectacleActive && !G._liveSpectacleGateRunning) {
      TCG_DEBUG.warn('poll', 'clear stale liveRoundPlaybackActive');
      G._liveRoundPlaybackActive = false;
      if (typeof releaseLivePolls === 'function') releaseLivePolls({ forceResume: true });
    }
    const flags = {
      animating: !!G.animating,
      perfSpectacle: !!G._perfSpectacleActive,
      heartCheckHold: !!G._perfHeartCheckHold,
      spectacleGate: !!G._liveSpectacleGateRunning,
      liveRoundPlayback: !!G._liveRoundPlaybackActive,
      liveShowRunner: !!G._liveShowRunnerActive,
      logSync: !!G._logSyncInFlight,
      directorActive,
      postSpectacleReady: !!G._liveRoundPostSpectacleReady,
      isSpectator: !!G.isSpectator,
    };
    // Stuck Performance chrome after the round already reached Main / judge pick softlocks sync.
    const ph = G.gameState?.phase;
    const prType = G.gameState?.pending_prompt?.type;
    const mainStable = ph === 'main_first' || ph === 'main_second'
      || ph === 'active_first' || ph === 'active_second';
    const spectatorHeartCheckStuck = !!G.isSpectator && !!G._perfHeartCheckHold
      && (mainStable || !serverLiveShowInFlight);
    if (spectatorHeartCheckStuck
        || (guards ? guards.mayClearStuckPerfSpectacle(G.gameState, flags) : false)) {
      TCG_DEBUG.warn('poll', 'clear stuck perfSpectacleActive', { phase: ph, prType, spectator: !!G.isSpectator });
      if (typeof perfCloseSpectacle === 'function') perfCloseSpectacle();
      else G._perfSpectacleActive = false;
      if (typeof perfClearHeartCheckHold === 'function') perfClearHeartCheckHold();
      if (typeof releaseLivePolls === 'function') releaseLivePolls({ forceResume: true });
    }
    // Spectators stuck mid Win/Loss with playback held but no animation — release
    // only after the show finished or never started (postSpectacleReady / no defer).
    if (!directorActive
        && !serverLiveShowInFlight
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
      if (typeof releaseLivePolls === 'function') releaseLivePolls({ forceResume: true });
    }
    // Spectators cannot ack a beat: if the server's live_show cursor stops moving
    // (stalled room, slow players), drop the chrome instead of freezing the view
    // on the Yell columns with no hearts and no judge.
    if (!!G.isSpectator && G._perfSpectacleActive && !G._liveShowRunnerActive
        && !G.animating && !directorActive && !G._liveSpectacleGateRunning
        && !G._liveRoundPlaybackActive) {
      const show = G.gameState?.live_show || null;
      const beat = show ? `${show.turn}:${show.stage_seq}:${show.stage}` : 'none';
      if (G._spectatorBeatKey !== beat) {
        G._spectatorBeatKey = beat;
        G._spectatorBeatSince = Date.now();
      } else if (Date.now() - (G._spectatorBeatSince || Date.now()) > 20000) {
        TCG_DEBUG.warn('poll', 'spectator live_show beat stalled — close spectacle', { beat });
        if (typeof perfCloseSpectacle === 'function') perfCloseSpectacle();
        if (typeof perfClearHeartCheckHold === 'function') perfClearHeartCheckHold();
        if (typeof releaseLivePolls === 'function') releaseLivePolls({ forceResume: true });
        G._spectatorBeatSince = Date.now();
      }
    }
    // Soft-heal a director lock left behind after empty LIVE / aborted presentLiveRound.
    if (typeof LiveRoundDirector !== 'undefined' && LiveRoundDirector.active
        && !G._liveRoundPlaybackActive && !G._perfSpectacleActive && !G._liveSpectacleGateRunning
        && !G._liveShowRunnerActive && !G.animating && !G._perfHeartCheckHold
        && !serverLiveShowInFlight
        && !(guards && guards.isLiveWinLossPipelinePhase(ph))) {
      TCG_DEBUG.warn('poll', 'clear stuck LiveRoundDirector.active');
      LiveRoundDirector.end('poll-soft-heal');
    }
    // End Main can succeed on the server while G.animating stays true (stuck On Enter
    // flight). Require hysteresis so healthy baton / log-sync gaps are not aborted.
    const flightCount = typeof document !== 'undefined'
      ? document.querySelectorAll('#card-flight-layer .card-flight').length
      : 0;
    const mainBusy = !!(G.animating || G._liveRoundPlaybackActive || G._logSyncInFlight || directorActive);
    if (mainStable && flightCount === 0 && mainBusy) {
      if (!G._mainZeroFlightSince) G._mainZeroFlightSince = Date.now();
    } else {
      G._mainZeroFlightSince = 0;
    }
    flags.zeroFlightMs = G._mainZeroFlightSince ? (Date.now() - G._mainZeroFlightSince) : 0;
    if (G._logSyncStartedAt && G._logSyncInFlight) {
      flags.zeroFlightMs = Math.max(flags.zeroFlightMs, Date.now() - G._logSyncStartedAt);
    }
    flags.hideIidsStuck = !!(G._animHideIids && G._animHideIids.size);
    if (guards && guards.mayUnstickStuckMainPresentation(G.gameState, flags, flightCount)) {
      TCG_DEBUG.warn('poll', 'clear stuck Main presentation (no flights)', {
        phase: ph,
        animating: !!G.animating,
        playback: !!G._liveRoundPlaybackActive,
        zeroFlightMs: flags.zeroFlightMs,
      });
      if (typeof LiveRoundDirector !== 'undefined' && LiveRoundDirector.active) {
        LiveRoundDirector.abort('poll: stuck Main');
      }
      G.animating = false;
      G._liveRoundPlaybackActive = false;
      if (typeof dropStaleLiveRoundPlaybackBoards === 'function') {
        dropStaleLiveRoundPlaybackBoards('poll: stuck Main');
      }
      G._animHideIids = null;
      G._logSyncInFlight = false;
      G._logSyncStartedAt = 0;
      G._mainZeroFlightSince = 0;
      if (typeof clearHandArrivingFlags === 'function') clearHandArrivingFlags();
      if (typeof releaseLivePolls === 'function') releaseLivePolls({ forceResume: true });
      if (G.gameState && typeof renderGame === 'function') {
        renderGame(G.gameState, { skipLog: true });
      }
    }
    if (guards && guards.shouldResumeLiveShowRunner(G.gameState, flags)
        && typeof presentServerLiveShowStage === 'function') {
      const now = Date.now();
      if (!G._liveShowResumeAt || now - G._liveShowResumeAt > 900) {
        G._liveShowResumeAt = now;
        TCG_DEBUG.warn('poll', 'resume live_show runner (Win/Loss ack)', {
          stage: G.gameState?.live_show?.stage,
          phase: ph,
        });
        const myId = G.playerId || G.gameState?.my_id || 'p1';
        void presentServerLiveShowStage(null, G.gameState, myId);
      }
    }
    // live_show cursor: allow polls while spectacle chrome is up so stage advances
    // arrive. The runner itself holds polls via _liveShowRunnerActive / _livePollHold.
    //
    // Do NOT block when Checking hearts / Win-Loss chrome is up but live_show is
    // already cleared — match-ending 3rd Success does that, and blocking polls
    // there softlocks on Live Win/Loss Check until refresh (never sees finished).
    if (typeof ensureBannerPumpNotStuck === 'function') ensureBannerPumpNotStuck('poll-gate');
    const winLossChrome = !!G._perfHeartCheckHold
      || !!(guards && guards.isLiveWinLossPipelinePhase?.(ph));
    const spectacleBlocksPoll = !!G._perfSpectacleActive && !serverLiveShowInFlight
      && !winLossChrome;
    const directorActiveNow = typeof LiveRoundDirector !== 'undefined' && LiveRoundDirector.active;
    return !!(G.animating || spectacleBlocksPoll || G._livePollHold
      || directorActiveNow
      || G._replaySeekInFlight || G._replayForwardApply);
  };

  global.scheduleDeferredSyncPull = function scheduleDeferredSyncPull(delayMs = 500) {
    clearTimeout(G._syncPullTimer);
    if (isReplayViewingSync()) return;
    if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.syncEnabled) return;
    // Action-ack owns the next apply — coalesce SSE pulls until the epoch ends.
    if (G._actionApplyEpoch) {
      G._actionApplyEpochNeedsFollowUp = true;
      return;
    }
    G._syncPullTimer = setTimeout(async () => {
      G._syncPullTimer = null;
      if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
      if (G._actionApplyEpoch) {
        G._actionApplyEpochNeedsFollowUp = true;
        return;
      }
      if (pollPresentationBlocked()) {
        // Re-arm once presentation frees — avoid a tight timer spin while blocked.
        const spins = (G._syncPullBlockedSpins || 0) + 1;
        G._syncPullBlockedSpins = spins;
        scheduleDeferredSyncPull(Math.min(2500, 500 + spins * 150));
        return;
      }
      G._syncPullBlockedSpins = 0;
      await pullLatestState();
    }, delayMs);
  };

  /**
   * Serialize sendAct force-pull / prompt resolution against SSE get_state so the
   * same prompt transition is not applied twice with different deferred/submit latches.
   */
  global.beginActionApplyEpoch = function beginActionApplyEpoch(reason) {
    G._actionApplyEpoch = reason || 'action';
    G._actionApplyEpochNeedsFollowUp = false;
    clearTimeout(G._syncPullTimer);
    G._syncPullTimer = null;
  };

  global.endActionApplyEpoch = function endActionApplyEpoch() {
    const needsFollow = !!G._actionApplyEpochNeedsFollowUp;
    G._actionApplyEpoch = null;
    G._actionApplyEpochNeedsFollowUp = false;
    if (needsFollow && G.polling && G.syncEnabled && G.syncTicket) {
      scheduleDeferredSyncPull(syncSseDebounceMs());
    }
  };

  global.resumePollingTick = function resumePollingTick(delayMs = 200) {
    if (isReplayViewingSync()) return;
    if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
    clearTimeout(G.pollTimer);
    if (G.syncEnabled && G.syncTicket) {
      scheduleDeferredSyncPull(Math.max(delayMs, syncSseDebounceMs()));
    } else {
      G.pollTimer = setTimeout(doPollLegacy, delayMs);
    }
  };

  function pollDelayAfterError(errorMsg) {
    if (typeof errorMsg === 'string' && /rate limit/i.test(errorMsg)) {
      G._pollRateLimitBackoff = Math.min((G._pollRateLimitBackoff || 0) + 1, 6);
      return Math.min(8000, 800 * (2 ** G._pollRateLimitBackoff));
    }
    G._pollRateLimitBackoff = 0;
    return null;
  }

  /** After a player action, keep CPU polling snappy until the server/AI advances. */
  global.markPollFastBurst = function markPollFastBurst(ms = 2500) {
    G._pollFastUntil = Date.now() + Math.max(0, ms | 0);
  };

  /**
   * CPU solo: slow polls while the local human clearly owns input; stay fast when
   * waiting on the server/CPU so AI replies remain snappy.
   */
  function cpuHumanOwnsInput() {
    if (!G.isCPU || G.isSpectator) return false;
    if (G._pollFastUntil && Date.now() < G._pollFastUntil) return false;
    const s = G.gameState;
    if (!s || s.status === 'finished') return true;
    const myId = G.playerId;
    if (!myId) return false;
    const pr = s.pending_prompt;
    if (pr) return pr.responder === myId;
    const ph = s.phase || '';
    if (ph === 'mulligan') {
      const done = s.mulligan_done || s.mulligan || {};
      return !done[myId];
    }
    if (ph === 'coin_flip' || ph === 'choose_first') {
      const ack = s.coin_ack || s.first_player_ack || {};
      if (Object.prototype.hasOwnProperty.call(ack, myId)) return !ack[myId];
      return true;
    }
    if ((ph === 'main_first' || ph === 'main_second'
        || ph === 'active_first' || ph === 'active_second')
        && s.active_player === myId) {
      return true;
    }
    if (ph === 'live_set') {
      const livePid = s.live_set_player || s.active_player;
      return livePid === myId && !s.live_ready?.[myId];
    }
    if (ph === 'live_judge') {
      // Judge prompts use pending_prompt; without one, prefer fast polls.
      return false;
    }
    return false;
  }

  function nextPollDelayMs(errorMsg) {
    const backoff = pollDelayAfterError(errorMsg);
    if (backoff != null) return backoff;
    if (isReplayViewingSync()) return 30000;
    if (!G.isCPU) return 600;
    return cpuHumanOwnsInput() ? 1100 : 280;
  }

  function getStateUrl(extraQs, sinceSeqOverride) {
    const since = (sinceSeqOverride != null && Number.isFinite(Number(sinceSeqOverride)))
      ? Number(sinceSeqOverride)
      : (G.lastSeq ?? 0);
    let url = `${gameApiBase()}?action=get_state&room_id=${encodeURIComponent(G.roomId)}`
      + `&token=${encodeURIComponent(G.token)}&seq=${encodeURIComponent(String(since))}`
      + `&since_seq=${encodeURIComponent(String(since))}&poll=0`;
    if (extraQs) url += extraQs;
    return url;
  }

  /** True when server says seq is unchanged (skip onState / full apply). */
  function isUnchangedStatePayload(d) {
    return !!(d && d.unchanged === true && !d.error);
  }

  function isDelayedTournamentSpectatePayload(d) {
    return !!(G.isSpectator && d && (d.spectate_stream_delayed || d.spectate_stream_waiting));
  }

  function noteUnchangedSeq(d) {
    if (!Number.isFinite(d?.seq)) return;
    if (isDelayedTournamentSpectatePayload(d) || (G.isSpectator && G.gameState?.spectate_stream_delayed)) {
      G.lastSeq = d.seq;
    } else {
      G.lastSeq = Math.max(G.lastSeq ?? 0, d.seq);
    }
  }

  function syncFallbackDelayMs() {
    const fails = G._syncFailCount || 0;
    const base = G.isSpectator
      ? (global.TCG_SYNC_FALLBACK_POLL_SPECTATE_MS || global.TCG_SYNC_FALLBACK_POLL_MS || 12000)
      : (global.TCG_SYNC_FALLBACK_POLL_MS || 5000);
    const max = global.TCG_SYNC_FALLBACK_POLL_MAX_MS || 20000;
    return Math.min(max, base * Math.max(1, Math.pow(1.5, Math.min(fails, 6))));
  }

  function syncSseDebounceMs() {
    return G.isSpectator
      ? (global.TCG_SYNC_SSE_DEBOUNCE_SPECTATE_MS || 750)
      : (global.TCG_SYNC_SSE_DEBOUNCE_MS || 220);
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
    // Do not clear G.apiOrigin here — mid-match stop/start must keep the room's
    // origin lock. endGameSession / resetLobby / tcgClearApiOriginLock clear it.
  };

  function gameApiBase() {
    return (typeof global.tcgGameApiUrl === 'function' ? global.tcgGameApiUrl() : (global.API || './api.php'));
  }

  async function tcgPresencePing() {
    if (isReplayViewingSync()) return;
    if (!G.polling || !G.roomId || !G.token || (G.isTutorial && !G.tutorialLive)) return;
    try {
      await apiPost('ping', { room_id: G.roomId, token: G.token }, { silent: true });
    } catch (e) { /* best effort */ }
  }

  global.startSyncFallbackPoll = function startSyncFallbackPoll() {
    clearTimeout(G.syncFallbackTimer);
    if (isReplayViewingSync()) return;
    if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
    G.syncFallbackTimer = setTimeout(async () => {
      G.syncFallbackTimer = null;
      if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
      // Use pollPresentationBlocked so live_show cursor sync is allowed while chrome is up.
      if (pollPresentationBlocked()) {
        startSyncFallbackPoll();
        return;
      }
      await pullLatestState();
      if (G.polling && G.syncEnabled === false) startSyncFallbackPoll();
      else if (G.polling && (G._syncFailCount || 0) >= TCG_SYNC_MAX_FAILS) startSyncFallbackPoll();
    }, syncFallbackDelayMs());
  };

  /**
   * Slow get_state while SSE is connected. Docker→wrapped notify can miss events;
   * without this, turns/hearts stall until a manual refresh.
   */
  global.startSyncSafetyPoll = function startSyncSafetyPoll() {
    clearTimeout(G.syncSafetyTimer);
    if (isReplayViewingSync()) return;
    if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
    const delay = G.isSpectator
      ? (global.TCG_SYNC_SAFETY_POLL_SPECTATE_MS || 16000)
      : (G.isCPU
        ? (global.TCG_SYNC_SAFETY_POLL_CPU_MS || 5000)
        : (global.TCG_SYNC_SAFETY_POLL_MS || 5500));
    G.syncSafetyTimer = setTimeout(async () => {
      G.syncSafetyTimer = null;
      if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
      if (!pollPresentationBlocked()) {
        try { await pullLatestState(false, { silent: true }); } catch (e) { /* ignore */ }
      }
      if (G.polling && G.syncEnabled && G.syncTicket) startSyncSafetyPoll();
    }, delay);
  };

  global.stopSyncSafetyPoll = function stopSyncSafetyPoll() {
    clearTimeout(G.syncSafetyTimer);
    G.syncSafetyTimer = null;
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
    // Debounce: several notifies in one action burst → one get_state.
    const delay = pollPresentationBlocked()
      ? Math.max(500, syncSseDebounceMs())
      : syncSseDebounceMs();
    scheduleDeferredSyncPull(delay);
  }

  function syncStreamUrl(mode) {
    const qs = `room_id=${encodeURIComponent(G.roomId)}`
      + `&ticket=${encodeURIComponent(G.syncTicket)}&last_seq=${encodeURIComponent(String(G.lastSeq ?? 0))}`;
    if (mode === 'php') {
      return `${global.WRAPPED_API}?action=tcg_sync_stream&${qs}`;
    }
    if (mode === 'apache') {
      const base = (global.TCG_SYNC_STREAM_FALLBACK_URL || './sync-stream').replace(/\?.*$/, '');
      return `${base}?${qs}`;
    }
    const base = (global.TCG_SYNC_STREAM_URL || 'https://stream.loveliveradio.ca/tcg/sync/stream').replace(/\?.*$/, '');
    return `${base}?${qs}`;
  }

  global.openSyncStream = function openSyncStream() {
    stopSyncStream();
    if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.roomId || !G.syncTicket) return;
    const mode = global.TCG_SYNC_STREAM_MODE || 'vps';
    const url = syncStreamUrl(mode);
    TCG_DEBUG.log('sync', 'connect', { room: G.roomId, seq: G.lastSeq, via: mode });
    if (mode === 'vps') global._tcgSyncStats.streamDirect++;
    else global._tcgSyncStats.streamProxy++;
    const es = new EventSource(url);
    G.syncEventSource = es;
    es.addEventListener('ready', () => {
      G._syncFailCount = 0;
      clearTimeout(G.syncFallbackTimer);
      G.syncFallbackTimer = null;
      // Safety get_state even while SSE looks healthy (missed Redis notify).
      startSyncSafetyPoll();
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
      global._tcgSyncStats.streamFails++;
      // Escalate: VPS nginx → Hostinger Apache sync-stream → PHP proxy → poll=0
      if (mode === 'vps' && (G._syncFailCount || 0) === 2) {
        TCG_DEBUG.warn('sync', 'VPS stream failed; trying Apache sync-stream');
        global.TCG_SYNC_STREAM_MODE = 'apache';
        if (G.polling) scheduleSyncReconnect(400);
        return;
      }
      if (mode !== 'php' && (G._syncFailCount || 0) === 4) {
        TCG_DEBUG.warn('sync', 'trying PHP proxy');
        global.TCG_SYNC_STREAM_MODE = 'php';
        global.TCG_SYNC_USE_PHP_PROXY = true;
        if (G.polling) scheduleSyncReconnect(400);
        return;
      }
      if (G._syncFailCount >= TCG_SYNC_MAX_FAILS) {
        TCG_DEBUG.warn('sync', 'using poll=0 fallback');
        G.syncEnabled = false;
        startSyncFallbackPoll();
      }
      if (G.polling) scheduleSyncReconnect(Math.min(8000, 400 * Math.pow(2, G._syncFailCount)));
    };
    clearInterval(G.presenceTimer);
    void tcgPresencePing();
    G.presenceTimer = setInterval(() => void tcgPresencePing(), TCG_PRESENCE_PING_MS);
  };

  global.beginGameSync = async function beginGameSync() {
    global.TCG_SYNC_USE_PHP_PROXY = false;
    global.TCG_SYNC_STREAM_MODE = 'vps';
    if (!G.roomId || !G.token) {
      TCG_DEBUG.warn('sync', 'beginGameSync skipped — missing room/token');
      return;
    }
    if (isReplayViewingSync()) {
      TCG_DEBUG.log('sync', 'beginGameSync skipped — replay viewing (no poll loop)');
      G.polling = false;
      G.syncEnabled = false;
      G.syncTicket = null;
      stopSyncStream();
      return;
    }
    // Hostinger-only drain rooms (legacy ranked files): short poll. VPS Redis rooms use SSE.
    if (G.apiOrigin === 'hostinger') {
      G.syncEnabled = false;
      G.syncTicket = null;
      stopSyncStream();
      ensurePresencePingTimer();
      await pullLatestState(false, { silent: true }).catch(() => {});
      doPollLegacy();
      return;
    }
    if (!G.syncTicket) {
      try {
        const r = await apiPost('sync_ticket', { room_id: G.roomId, token: G.token }, { silent: true });
        captureSyncMeta(r);
      } catch (e) {
        TCG_DEBUG.warn('sync', 'sync_ticket failed', e);
      }
    }
    // CPU solo: no opponent to push — short poll=0 (never hold Hostinger long-poll workers).
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
      startSyncSafetyPoll();
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
    if (isReplayViewingSync()) {
      // Replay rooms are local snapshots. Seeks use replay_goto; a CPU-style
      // poll=0 loop (~280ms) only hammers Hostinger get_state for unchanged seq.
      G.polling = false;
      G.syncEnabled = false;
      G.syncTicket = null;
      stopSyncStream();
      if (G.isSpectator) saveSpectatorSession();
      else saveActiveGameSession();
      TCG_DEBUG.log('sync', 'startPoll skipped — replay viewing');
      return;
    }
    G.polling = true;
    G._syncFailCount = 0;
    if (G.isSpectator) saveSpectatorSession();
    else saveActiveGameSession();
    if ((G.isTutorial && !G.tutorialLive)) return;
    void beginGameSync();
  };

  /**
   * Short poll=0 loop — never use Hostinger long-poll (holds PHP up to 25s).
   * Used for CPU solo and when SSE sync is unavailable.
   */
  global.doPollLegacy = async function doPollLegacy() {
    if (isReplayViewingSync()) return;
    if (!G.polling || (G.isTutorial && !G.tutorialLive)) return;
    // Must use pollPresentationBlocked — raw _perfSpectacleActive blocked CPU/spectator
    // from seeing live_show stage advances (Win/Loss freeze).
    if (pollPresentationBlocked()) {
      TCG_DEBUG.logOnce(
        'poll',
        `blocked:${G.animating}:${G._perfSpectacleActive}:${!!G.gameState?.live_show?.stage}`,
        'blocked (presentation)',
        {
          animating: G.animating,
          spectacle: G._perfSpectacleActive,
          liveShow: G.gameState?.live_show?.stage || null,
          runner: !!G._liveShowRunnerActive,
          pollHold: !!G._livePollHold,
        }
      );
      if (G.polling) G.pollTimer = setTimeout(doPollLegacy, 400);
      return;
    }
    ensurePollHoldReleased(G.gameState);
    const pollEpoch = G._gameSessionEpoch;
    const pollRoomId = G.roomId;
    let pollError = null;
    try {
      TCG_DEBUG.log('poll', 'fetch', { seq: G.lastSeq, room: pollRoomId, mode: 'poll0' });
      global._tcgSyncStats.getState++;
      const r = await fetch(getStateUrl());
      const d = await parseGameApiResponse(r);
      if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return;
      G._pollRateLimitBackoff = 0;
      if (isUnchangedStatePayload(d)) {
        noteUnchangedSeq(d);
      } else {
        if (G._pollFastUntil && (d.seq ?? 0) > (G.lastSeq ?? 0)) G._pollFastUntil = 0;
        onState(d);
      }
    } catch (e) {
      if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return;
      if (e && /room not found/i.test(String(e.message || ''))) {
        // Replay rooms stay on Hostinger; a VPS miss is expected under match-primary.
        if (typeof global.isReplayViewing === 'function' && global.isReplayViewing()) {
          TCG_DEBUG.warn('poll', 'replay room miss on poll origin — keep viewing');
          pollError = e.message;
        } else if (typeof global.abandonDeadMatchSession === 'function') {
          // Ranked rooms live on Hostinger — a VPS miss must recover, not resign.
          void global.abandonDeadMatchSession({ silent: false, forceResign: false });
          return;
        }
      }
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
      global._tcgSyncStats.getState++;
      const r = await fetch(getStateUrl());
      const d = await parseGameApiResponse(r);
      if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return G.gameState || null;
      if (isUnchangedStatePayload(d)) {
        noteUnchangedSeq(d);
        return G.gameState || null;
      }
      if ((d.seq ?? 0) <= (G.lastSeq ?? 0) && !isDelayedTournamentSpectatePayload(d)) return G.gameState || null;
      // Finished must go through onState/applyFinishedState — advancing lastSeq here
      // alone skips the win overlay and leaves later finished polls as "stale".
      if (d.status === 'finished') {
        if (typeof global.onState === 'function') {
          const ret = global.onState(d);
          if (ret && typeof ret.then === 'function') await ret;
        } else if (typeof global.applyFinishedState === 'function') {
          await global.applyFinishedState(d, G.gameState);
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
      // CPU prompt resolve often bypasses applyStateUpdate — resume live_show acks.
      if (!d.pending_prompt
          && d.live_show?.stage
          && d.live_show.stage !== 'done'
          && !G._liveShowRunnerActive
          && typeof presentServerLiveShowStage === 'function') {
        const myId = G.playerId || d.my_id || 'p1';
        void presentServerLiveShowStage(null, d, myId);
      }
      return d;
    } catch (e) {
      if (!opts.silent) {
        TCG_DEBUG.warn('poll', 'pullSkillResolutionState failed', e);
      }
      return G.gameState || null;
    }
  };

  /**
   * Run onState; optionally wait until the painted board reaches the snapshot seq
   * (own-action catch-up) without blocking End LIVE on a full empty-round spectacle.
   */
  async function dispatchOnState(d, opts = {}) {
    let ret = null;
    try {
      ret = typeof onState === 'function' ? onState(d) : null;
    } catch (e) {
      TCG_DEBUG.warn('poll', 'onState failed', e);
      return;
    }
    if (!ret || typeof ret.then !== 'function') return;
    const waitBoard = !!opts.waitBoard;
    const target = Number(d?.seq) || 0;
    const failSoft = (e) => TCG_DEBUG.warn('poll', 'onState async failed', e);
    if (!waitBoard || target <= 0) {
      void Promise.resolve(ret).catch(failSoft);
      return;
    }
    void Promise.resolve(ret).catch(failSoft);
    const deadline = Date.now() + 2800;
    while (Date.now() < deadline) {
      if ((G.gameState?.seq ?? 0) >= target) return;
      await new Promise((r) => setTimeout(r, 40));
    }
  }

  global.pullLatestState = async function pullLatestState(force, opts = {}) {
    if (isReplayViewingSync() && !opts.allowReplayPull) return;
    if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.roomId || !G.token) return;
    // Non-owning SSE/safety polls wait for sendAct's apply epoch.
    if (!force && !opts.actionEpoch && G._actionApplyEpoch) {
      G._actionApplyEpochNeedsFollowUp = true;
      return;
    }
    if (!force && pollPresentationBlocked()) {
      if (G.syncEnabled && G.syncTicket) scheduleDeferredSyncPull(500);
      else resumePollingTick(500);
      return;
    }
    // Coalesce concurrent poll=0 fetches — overlapping callers used to stampede get_state.
    // Mark one follow-up instead of every waiter scheduling its own ~80ms pull.
    if (G._pullLatestInFlight) {
      G._pullLatestNeedsFollowUp = true;
      await G._pullLatestInFlight;
      if (!G.polling || (G.isTutorial && !G.tutorialLive) || !G.roomId || !G.token) return;
      if (!force) return;
      // force: fall through for one more immediate pull after the in-flight finishes
    }
    const pollEpoch = G._gameSessionEpoch;
    const pollRoomId = G.roomId;
    TCG_DEBUG.log('poll', 'pullLatestState', { seq: G.lastSeq, force: !!force, room: pollRoomId });
    const run = (async () => {
      try {
        global._tcgSyncStats.getState++;
        // If presentation advanced lastSeq without committing gameState, a normal
        // since_seq=lastSeq fetch returns unchanged and the board stays stale forever.
        const boardSeq = G.gameState?.seq ?? 0;
        const last = G.lastSeq ?? 0;
        // Force catch-up: query from the painted board seq (not lastSeq), so an
        // early lastSeq bump cannot make the server answer "unchanged".
        let sinceForFetch = last;
        if (force) {
          sinceForFetch = Math.min(boardSeq, last);
          if (boardSeq < last) {
            TCG_DEBUG.warn('poll', 'force pull: rewind since_seq to board', { boardSeq, last });
            G.lastSeq = boardSeq;
          }
        }
        const forceQs = force ? '&force=1' : '';
        const r = await fetch(getStateUrl(forceQs, sinceForFetch));
        const d = await parseGameApiResponse(r);
        if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return;
        if (isUnchangedStatePayload(d)) {
          // force=1 should not short-circuit on the server; if it still does and
          // the painted board is behind the ack seq, hard-rewind and retry once.
          const reported = Number(d.seq);
          if (force && Number.isFinite(reported) && boardSeq < reported) {
            TCG_DEBUG.warn('poll', 'force pull unchanged while board behind — hard rewind', {
              boardSeq, last: G.lastSeq, reported,
            });
            G.lastSeq = boardSeq;
            const r2 = await fetch(getStateUrl('&force=1', Math.max(0, boardSeq - 1)));
            const d2 = await parseGameApiResponse(r2);
            if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return;
            if (!isUnchangedStatePayload(d2)) {
              if (force && d2.status === 'finished') {
                G._pendingStateQueue = (G._pendingStateQueue || []).filter(st => (st.seq ?? 0) > (d2.seq ?? 0));
              }
              if (G._pollFastUntil && (d2.seq ?? 0) > (G.lastSeq ?? 0)) G._pollFastUntil = 0;
              await dispatchOnState(d2, { waitBoard: !!(force && opts.actionEpoch) });
              return;
            }
          }
          noteUnchangedSeq(d);
          if (typeof tryFlushSpectacleRecovery === 'function') tryFlushSpectacleRecovery();
          return;
        }
        if (force && d.status === 'finished') {
          G._pendingStateQueue = (G._pendingStateQueue || []).filter(st => (st.seq ?? 0) > (d.seq ?? 0));
        }
        if (force && G.gameState && (d.seq ?? 0) <= (G.lastSeq ?? 0)
            && !isDelayedTournamentSpectatePayload(d)) {
          // Hung presentation can advance lastSeq before committing gameState.
          // Still apply when the board is behind the fetched snapshot.
          if ((d.seq ?? 0) <= (G.gameState.seq ?? 0)) {
            // Seq matches but own-action catch-up may still need a re-paint
            // (gameState was assigned without renderGame).
            if (opts.actionEpoch && typeof renderGame === 'function') {
              TCG_DEBUG.warn('poll', 'force pull: re-paint board at matched seq', {
                seq: d.seq, phase: d.phase,
              });
              G.gameState = d;
              G.lastSeq = Math.max(G.lastSeq ?? 0, d.seq ?? 0);
              renderGame(d, { skipLog: true, skipPrompt: true });
              if (G.playerId && typeof updatePhaseActionButton === 'function') {
                updatePhaseActionButton(d, G.playerId);
              }
              if (G.playerId && typeof updateLiveSetButton === 'function') {
                updateLiveSetButton(d, G.playerId);
              }
              return;
            }
            TCG_DEBUG.logOnce('poll', `force-stale:${d.seq}`, 'skip force pull stale', { incoming: d.seq, last: G.lastSeq });
            if (typeof tryFlushSpectacleRecovery === 'function') tryFlushSpectacleRecovery();
            return;
          }
          TCG_DEBUG.warn('poll', 'force pull: board behind lastSeq — apply anyway', {
            boardSeq: G.gameState.seq,
            lastSeq: G.lastSeq,
            incoming: d.seq,
          });
          if (typeof releaseStuckPresentation === 'function') releaseStuckPresentation();
        }
        if (G._pollFastUntil && (d.seq ?? 0) > (G.lastSeq ?? 0)) G._pollFastUntil = 0;
        if (d.end_reason === 'disconnect') global._tcgSyncStats.disconnectHints++;
        await dispatchOnState(d, { waitBoard: !!(force && opts.actionEpoch) });
      } catch (e) {
        if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return;
        if (!opts.silent) {
          const replayViewing = typeof global.isReplayViewing === 'function' && global.isReplayViewing();
          if (replayViewing && e && /room not found/i.test(String(e.message || ''))) {
            TCG_DEBUG.warn('poll', 'replay pull miss — keep viewing', e);
          } else if (e && e.httpStatus >= 400) {
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
      if (G._pullLatestNeedsFollowUp) {
        G._pullLatestNeedsFollowUp = false;
        if (G.polling && G.syncEnabled && G.syncTicket) {
          const followMs = pollPresentationBlocked()
            ? 500
            : (global.TCG_SYNC_COALESCE_FOLLOW_MS || 450);
          scheduleDeferredSyncPull(followMs);
        } else if (G.polling) {
          resumePollingTick(global.TCG_SYNC_COALESCE_FOLLOW_MS || 450);
        }
      }
    }
  };

  /**
   * Background tabs freeze rAF/setTimeout (Performance spectacle freezes).
   * On return, abort the stuck show and snap to the latest server snapshot instead of
   * resuming mid-animation minutes behind the live match.
   * Works for spectators and active players.
   */
  function paintMatchHudAfterTabCatchUp(d, isSpec) {
    if (!d) return;
    // Stale Main Phase "PICK A SLOT" / baton chrome must not survive a Live advance.
    try {
      if (typeof clearPlaySelection === 'function') clearPlaySelection();
    } catch (_) { /* ignore */ }
    G.selCard = null;
    G.drag = null;
    G.liveSel = Array.isArray(G.liveSel) ? [] : G.liveSel;
    if (typeof showScr === 'function') showScr('game');
    if (typeof renderGame === 'function') renderGame(d, { skipLog: true });
    if (typeof catchUpGameLog === 'function') catchUpGameLog(d, null);
    if (!isSpec && d.pending_prompt?.responder === G.playerId
        && typeof ensurePendingPromptSurfaced === 'function') {
      if (typeof clearLiveSuccessHandDeferral === 'function') clearLiveSuccessHandDeferral(d);
      ensurePendingPromptSurfaced(d, G.playerId);
    }
    if (typeof updateOpponentSkillWaitBanner === 'function' && G.playerId) {
      updateOpponentSkillWaitBanner(d, G.playerId);
    }
    if (typeof updatePhaseActionButton === 'function' && G.playerId) {
      updatePhaseActionButton(d, G.playerId);
    }
    if (typeof updateLiveSetButton === 'function' && G.playerId) {
      updateLiveSetButton(d, G.playerId);
    }
  }

  function tabCatchUpShouldPreserveLivePipeline() {
    const guards = global.LLTCG_PRESENTATION_GUARDS;
    const flags = {
      awaitingLiveStart: !!G._awaitingLiveStartPrompts,
    };
    if (typeof guards?.shouldSoftTabCatchUpPreserveLivePipeline === 'function') {
      return !!guards.shouldSoftTabCatchUpPreserveLivePipeline(G.gameState, flags);
    }
    const s = G.gameState;
    const stage = s?.live_show?.stage;
    return !!(flags.awaitingLiveStart
      || s?.phase === 'live_start_effects'
      || stage === 'reveal'
      || stage === 'live_start');
  }

  /** Soft resync: advance observed board without aborting or full re-paint thrash.
   *  Returns true/false when done, or 'escalate' to fall through to hard catch-up. */
  async function softCatchUpPreserveLivePipeline(isSpec, pollEpoch, pollRoomId, hiddenMs) {
    TCG_DEBUG.warn('poll', 'tab catch-up: soft preserve Live pipeline', {
      hiddenMs,
      phase: G.gameState?.phase || null,
      stage: G.gameState?.live_show?.stage || null,
      awaitingLiveStart: !!G._awaitingLiveStartPrompts,
    });
    global._tcgSyncStats.getState++;
    const boardSeq = G.gameState?.seq ?? 0;
    const last = G.lastSeq ?? 0;
    if (boardSeq < last || hiddenMs >= 400) G.lastSeq = boardSeq;
    const r = await fetch(getStateUrl('&force=1'));
    let d = await parseGameApiResponse(r);
    if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return false;
    if (isSpec && !G.isSpectator) return false;

    if (isUnchangedStatePayload(d)) {
      if (G.playerId && typeof updateOpponentSkillWaitBanner === 'function' && G.gameState) {
        updateOpponentSkillWaitBanner(G.gameState, G.playerId);
      }
      resumePollingTick(200);
      return true;
    }
    if (isSpec && typeof alignSpectatorStageBoard === 'function') {
      d = alignSpectatorStageBoard(d) || d;
    }

    const guards = global.LLTCG_PRESENTATION_GUARDS;
    const seqGap = (d.seq ?? 0) - boardSeq;
    if (typeof guards?.shouldEscalateSoftTabCatchUpToHard === 'function'
        && guards.shouldEscalateSoftTabCatchUpToHard(d, { hiddenMs, seqGap })) {
      TCG_DEBUG.warn('poll', 'tab catch-up: escalate soft → hard', {
        phase: d.phase,
        stage: d.live_show?.stage || null,
        status: d.status,
        hiddenMs,
        seqGap,
      });
      G.gameState = d;
      G.lastSeq = d.seq ?? G.lastSeq ?? 0;
      G._pendingStateQueue = (G._pendingStateQueue || []).filter(st => (st.seq ?? 0) > (d.seq ?? 0));
      if (isSpec) {
        G.playerId = (G.spectatorViewAs === 'p1' || G.spectatorViewAs === 'p2')
          ? G.spectatorViewAs
          : (d.view_as || G.playerId || 'p1');
      } else {
        G.playerId = d.my_id || G.playerId;
      }
      return 'escalate';
    }

    // Keep playback flags — only advance the observed board for the wait loop.
    G.gameState = d;
    G.lastSeq = d.seq ?? G.lastSeq ?? 0;
    G._pendingStateQueue = (G._pendingStateQueue || []).filter(st => (st.seq ?? 0) > (d.seq ?? 0));
    if (isSpec) {
      G.playerId = (G.spectatorViewAs === 'p1' || G.spectatorViewAs === 'p2')
        ? G.spectatorViewAs
        : (d.view_as || G.playerId || 'p1');
    } else {
      G.playerId = d.my_id || G.playerId;
    }
    // Light HUD only — full paintMatchHud thrash re-entered renderGame under
    // presentLiveRound and caused Chrome lag / razor hand fans.
    if (typeof updateOpponentSkillWaitBanner === 'function' && G.playerId) {
      updateOpponentSkillWaitBanner(d, G.playerId);
    }
    if (!isSpec && d.pending_prompt?.responder === G.playerId
        && typeof ensurePendingPromptSurfaced === 'function') {
      if (typeof clearLiveSuccessHandDeferral === 'function') clearLiveSuccessHandDeferral(d);
      ensurePendingPromptSurfaced(d, G.playerId);
    }
    if (d.status === 'finished') {
      if (typeof stopPoll === 'function') stopPoll();
      if (isSpec && typeof clearSpectatorSession === 'function') clearSpectatorSession();
      if (typeof showWin === 'function') showWin(d);
      return true;
    }
    if (isSpec && typeof saveSpectatorSession === 'function') saveSpectatorSession();
    else if (!isSpec && typeof saveActiveGameSession === 'function') saveActiveGameSession();
    resumePollingTick(150);
    return true;
  }

  global.catchUpMatchAfterTabVisible = async function catchUpMatchAfterTabVisible(opts = {}) {
    if (!G.polling || !G.roomId || !G.token || G.isTutorial) return false;
    if (G._tabCatchUpBusy) return false;
    G._tabCatchUpBusy = true;
    const isSpec = !!G.isSpectator;
    const pollEpoch = G._gameSessionEpoch;
    const pollRoomId = G.roomId;
    try {
      const liveStage = G.gameState?.live_show?.stage || null;
      const midSpectacleChrome = !!(G._perfSpectacleActive || document.body.classList.contains('perf-spectacle-active'))
        && (liveStage === 'performance' || liveStage === 'outcomes' || liveStage === 'judge');
      const hiddenMs = opts.hiddenMs || 0;
      const preserveLivePipeline = !midSpectacleChrome && tabCatchUpShouldPreserveLivePipeline();
      TCG_DEBUG.warn('poll', 'tab catch-up', {
        spectator: isSpec,
        hiddenMs,
        wasBusy: !!opts.wasBusy,
        seq: G.lastSeq,
        spectacle: !!G._perfSpectacleActive,
        midSpectacleChrome,
        preserveLivePipeline,
        liveStage,
        phase: G.gameState?.phase || null,
      });

      if (preserveLivePipeline) {
        const soft = await softCatchUpPreserveLivePipeline(isSpec, pollEpoch, pollRoomId, hiddenMs);
        if (soft !== 'escalate') return soft;
        // Soft saw a board that left Live Start — fall through to hard abort + paint.
        TCG_DEBUG.warn('poll', 'tab catch-up: hard path after soft escalate');
      }

      // Mid Performance chrome: never tear down the stage (same rule as
      // releaseStuckPresentation). Closing body.perf-spectacle-active left yell/
      // heart flights over the visible playmat after alt-tab.
      if (midSpectacleChrome) {
        // Brief focus blips (notifications, Discord overlay, accidental click-away)
        // used to kill the yell climb and seal "performance presented" → Checking
        // hearts with no yell flies. Keep the in-flight runner for short hides.
        if (hiddenMs < 700
            && (G._liveShowRunnerActive || G._perfSpectacleActive)
            && !G._perfSpectacleAborted) {
          TCG_DEBUG.warn('poll', 'tab catch-up: brief flicker, keep Performance climb', {
            hiddenMs,
            phase: G._perfSpectaclePhase || null,
          });
          if (typeof perfOpenSpectacle === 'function') perfOpenSpectacle();
          resumePollingTick(150);
          return true;
        }

        if (typeof bumpLiveShowRunnerEpoch === 'function') bumpLiveShowRunnerEpoch();
        else {
          G._liveShowRunnerEpoch = (G._liveShowRunnerEpoch || 0) + 1;
          G._perfHeartFlyEpoch = (G._perfHeartFlyEpoch || 0) + 1;
        }
        if (G._perfSpectacleAbort) {
          G._perfSpectacleAbort();
          G._perfSpectacleAborted = true;
        }
        G._liveShowRunnerActive = false;
        G.animating = false;
        if (typeof releaseLivePolls === 'function') releaseLivePolls({ forceResume: true });
        else G._livePollHold = false;
        G._liveRoundPlaybackActive = false;
        G._liveSpectacleGateRunning = false;
        G._presentationAborted = false;
        G._pendingStateQueue = [];
        document.querySelectorAll('.perf-yell-flying, .perf-heart-fly, .perf-score-fly')
          .forEach((n) => n.remove());
        if (typeof LiveRoundDirector !== 'undefined' && LiveRoundDirector.active) {
          LiveRoundDirector.abort(isSpec ? 'spectator-tab-visible' : 'tab-visible');
        }

        global._tcgSyncStats.getState++;
        const boardSeq = G.gameState?.seq ?? 0;
        const last = G.lastSeq ?? 0;
        // Always rewind so force=1 cannot short-circuit as unchanged while HUD is stale.
        G.lastSeq = Math.min(boardSeq, last);
        const r = await fetch(getStateUrl('&force=1'));
        let d = await parseGameApiResponse(r);
        if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return false;
        if (isSpec && !G.isSpectator) return false;

        if (isUnchangedStatePayload(d)) {
          d = G.gameState;
        } else {
          if (isSpec && typeof alignSpectatorStageBoard === 'function') {
            d = alignSpectatorStageBoard(d) || d;
          }
          G.gameState = d;
          G.lastSeq = d.seq ?? G.lastSeq ?? 0;
          G._prevLogLen = (d.log || []).length;
          if (isSpec) {
            G.playerId = (G.spectatorViewAs === 'p1' || G.spectatorViewAs === 'p2')
              ? G.spectatorViewAs
              : (d.view_as || G.playerId || 'p1');
          } else {
            G.playerId = d.my_id || G.playerId;
          }
        }

        // Only seal yell climbs once past Performance (or hearts already resolved /
        // very long hide). Mid-performance + brief tab-away must still replay yells.
        const stageNow = d?.live_show?.stage;
        const guards = global.LLTCG_PRESENTATION_GUARDS;
        const forceSkipYells = (guards && typeof guards.shouldForceSkipLiveYellsOnTabRestore === 'function')
          ? guards.shouldForceSkipLiveYellsOnTabRestore(stageNow, hiddenMs, d)
          : (stageNow === 'outcomes'
            || stageNow === 'judge'
            || stageNow === 'done'
            || (stageNow === 'performance' && hiddenMs >= 30000));
        if (forceSkipYells
            && d?.live_show?.turn != null
            && typeof markLiveShowPerformancePresented === 'function'
            && guards?.shouldSealLivePerformanceOnTabRestore?.(stageNow, hiddenMs, d)) {
          markLiveShowPerformancePresented(d.live_show.turn);
        }

        // Keep _perfSpectacleAborted true until restore clears it — otherwise
        // in-flight heart flies land on the restored panel (duplicate counts).
        if (typeof restoreLiveShowSpectacleAfterTabVisible === 'function' && d) {
          await restoreLiveShowSpectacleAfterTabVisible(d, G.playerId, {
            forceSkipYells,
            hiddenMs,
          });
        } else {
          G._perfSpectacleAborted = false;
          if (typeof perfOpenSpectacle === 'function') {
            perfOpenSpectacle();
          }
        }

        paintMatchHudAfterTabCatchUp(d, isSpec);

        if (d?.status === 'finished') {
          if (typeof stopPoll === 'function') stopPoll();
          if (isSpec && typeof clearSpectatorSession === 'function') clearSpectatorSession();
          if (typeof showWin === 'function') showWin(d);
          if (isSpec && typeof maybeFollowTournamentBo3NextSpectate === 'function') {
            void maybeFollowTournamentBo3NextSpectate(d);
          }
          return true;
        }

        if (isSpec && typeof saveSpectatorSession === 'function') saveSpectatorSession();
        else if (!isSpec && typeof saveActiveGameSession === 'function') saveActiveGameSession();
        resumePollingTick(150);
        return true;
      }

      if (typeof abortGameplayPresentation === 'function') {
        abortGameplayPresentation({ skipAbortFlag: true });
      }
      G._presentationAborted = false;
      G._pendingStateQueue = [];
      G._liveShowRunnerActive = false;
      if (typeof LiveRoundDirector !== 'undefined' && LiveRoundDirector.active) {
        LiveRoundDirector.abort(isSpec ? 'spectator-tab-visible' : 'tab-visible');
      }

      global._tcgSyncStats.getState++;
      // Hung presentation can advance lastSeq before committing gameState.
      // Always rewind to the painted board so force=1 cannot return unchanged
      // while phase / active_player HUD is still on the previous turn.
      const boardSeq = G.gameState?.seq ?? 0;
      const last = G.lastSeq ?? 0;
      if (boardSeq < last || hiddenMs >= 400) {
        TCG_DEBUG.warn('poll', 'tab catch-up: rewind since_seq to board', { boardSeq, last, hiddenMs });
        G.lastSeq = boardSeq;
      }
      // Long background freezes: request a full snapshot even if seq matches.
      if (hiddenMs >= 2500) G.lastSeq = 0;
      const r = await fetch(getStateUrl('&force=1'));
      let d = await parseGameApiResponse(r);
      if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return false;
      if (isSpec && !G.isSpectator) return false;

      // Same seq: presentation already aborted above — keep the local board but
      // still re-paint HUD (stale Main Phase banner / PICK A SLOT after Live).
      if (isUnchangedStatePayload(d)) {
        if (G.gameState) {
          paintMatchHudAfterTabCatchUp(G.gameState, isSpec);
          resumePollingTick(200);
          return true;
        }
        G.lastSeq = 0;
        const r2 = await fetch(getStateUrl('&force=1'));
        d = await parseGameApiResponse(r2);
        if (!pollResponseStillCurrent(pollEpoch, pollRoomId)) return false;
        if (isUnchangedStatePayload(d)) {
          resumePollingTick(200);
          return false;
        }
      }

      // Soft reconnect: seal historical spectacles so apply does not replay the gap.
      if (typeof prepareClientReconnectState === 'function') {
        prepareClientReconnectState(d);
      } else {
        G.animating = false;
        G._perfSpectacleActive = false;
        if (typeof releaseLivePolls === 'function') releaseLivePolls({ forceResume: true });
        else G._livePollHold = false;
        G._liveRoundPlaybackActive = false;
        G._liveSpectacleGateRunning = false;
      }

      const hardHiddenMs = hiddenMs;
      const hardStage = d.live_show?.stage;
      // Long freeze past Performance / hearts resolved: skip yell replay.
      // Short hides still mid-performance should re-climb.
      const hardGuards = global.LLTCG_PRESENTATION_GUARDS;
      const hardForceSkipYells = (hardGuards && typeof hardGuards.shouldForceSkipLiveYellsOnTabRestore === 'function')
        ? hardGuards.shouldForceSkipLiveYellsOnTabRestore(hardStage, hardHiddenMs, d)
        : (hardStage === 'outcomes'
          || hardStage === 'judge'
          || hardStage === 'done'
          || (hardStage === 'performance' && hardHiddenMs >= 30000));
      if (hardForceSkipYells
          && d.live_show?.turn != null
          && typeof markLiveShowPerformancePresented === 'function'
          && hardGuards?.shouldSealLivePerformanceOnTabRestore?.(hardStage, hardHiddenMs, d)) {
        markLiveShowPerformancePresented(d.live_show.turn);
      }

      if (isSpec && typeof alignSpectatorStageBoard === 'function') {
        d = alignSpectatorStageBoard(d) || d;
      }

      G.gameState = d;
      G.lastSeq = d.seq ?? G.lastSeq ?? 0;
      G._prevLogLen = (d.log || []).length;
      if (isSpec) {
        G.playerId = (G.spectatorViewAs === 'p1' || G.spectatorViewAs === 'p2')
          ? G.spectatorViewAs
          : (d.view_as || G.playerId || 'p1');
      } else {
        G.playerId = d.my_id || G.playerId;
      }

      // Hard catch-up may have closed chrome while the server is still mid
      // Performance — restore stage overlay so remaining beats are not over the mat.
      if (typeof liveShowSpectacleChromeStage === 'function'
          && liveShowSpectacleChromeStage(d.live_show?.stage)
          && typeof restoreLiveShowSpectacleAfterTabVisible === 'function') {
        if (typeof bumpLiveShowRunnerEpoch === 'function') bumpLiveShowRunnerEpoch();
        else G._perfHeartFlyEpoch = (G._perfHeartFlyEpoch || 0) + 1;
        G._perfSpectacleAborted = true;
        await restoreLiveShowSpectacleAfterTabVisible(d, G.playerId, {
          forceSkipYells: hardForceSkipYells,
          hiddenMs: hardHiddenMs,
        });
      }

      paintMatchHudAfterTabCatchUp(d, isSpec);

      if (d.status === 'finished') {
        // Skip final-LIVE spectacle replay after a tab freeze — jump straight to results.
        if (typeof stopPoll === 'function') stopPoll();
        if (isSpec && typeof clearSpectatorSession === 'function') clearSpectatorSession();
        if (typeof showWin === 'function') showWin(d);
        if (isSpec && typeof maybeFollowTournamentBo3NextSpectate === 'function') {
          void maybeFollowTournamentBo3NextSpectate(d);
        }
        return true;
      }

      if (isSpec && typeof saveSpectatorSession === 'function') saveSpectatorSession();
      else if (!isSpec && typeof saveActiveGameSession === 'function') saveActiveGameSession();
      resumePollingTick(150);
      return true;
    } catch (e) {
      TCG_DEBUG.warn('poll', 'tab catch-up failed', e);
      if (isSpec && e && e.httpStatus >= 400
          && typeof handleSpectatorPollError === 'function'
          && handleSpectatorPollError(e.message)) {
        return false;
      }
      try {
        G._presentationAborted = false;
        await pullLatestState(true, { silent: true });
      } catch (_) { /* ignore */ }
      return false;
    } finally {
      G._tabCatchUpBusy = false;
    }
  };

  /** @deprecated Use catchUpMatchAfterTabVisible */
  global.catchUpSpectatorAfterTabVisible = function catchUpSpectatorAfterTabVisible(opts) {
    return global.catchUpMatchAfterTabVisible(opts);
  };

})(window);
