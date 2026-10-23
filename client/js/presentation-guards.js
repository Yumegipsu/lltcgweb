/**
 * Pure presentation-heal guards (browser + Node).
 * Never unstick Main / abort spectacle while Live Win/Loss (heart check / judge) is live.
 * Match-end (status=finished) and promptless post-cursor live_judge are clearable.
 */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  if (root) root.LLTCG_PRESENTATION_GUARDS = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  const LIVE_WIN_LOSS_PHASES = {
    live_start_effects: true,
    live_performance_first: true,
    live_performance_second: true,
    live_judge: true,
    live_success_effects: true,
  };

  const SETTLED_PLAY_PHASES = {
    main_first: true,
    main_second: true,
    active_first: true,
    active_second: true,
    live_set: true,
  };

  const MAIN_STABLE_PHASES = {
    main_first: true,
    main_second: true,
    active_first: true,
    active_second: true,
  };

  /** Default ms before Main "no flights" heal may clear healthy baton / log-sync. */
  const MAIN_UNSTICK_HYSTERESIS_MS = 1800;
  /** Faster heal when flights finished but hand still masked by _animHideIids (baton/play). */
  const MAIN_HIDE_IID_UNSTICK_MS = 700;

  function liveShowInFlight(state) {
    const stage = state?.live_show?.stage;
    return !!(stage && stage !== 'done');
  }

  function isLiveWinLossPipelinePhase(ph) {
    return !!LIVE_WIN_LOSS_PHASES[ph];
  }

  function isSettledPlayPhase(ph) {
    return !!SETTLED_PLAY_PHASES[ph];
  }

  function isMainStablePhase(ph) {
    return !!MAIN_STABLE_PHASES[ph];
  }

  function judgePickPromptType(state) {
    return state?.pending_prompt?.type
      || state?.pending_prompt_meta?.type
      || null;
  }

  function isJudgePickPrompt(pr) {
    return pr === 'pick_judge_success_live'
      || pr === 'replace_success_with_wr_live'
      || pr === 'sbp6_live_wr_deck_position';
  }

  /**
   * Match-ending 3rd Success leaves phase=live_judge with live_show unset.
   * Only treat as clearable when there is truly no pending skill/pick (e.g. not
   * START:DASH Live Success surveil_arrange sitting after the show cursor).
   */
  function isPromptlessPostCursorLiveJudge(state) {
    if (!state || state.phase !== 'live_judge') return false;
    if (liveShowInFlight(state)) return false;
    if (state.pending_prompt || state.pending_prompt_meta) return false;
    return true;
  }

  /** Aggressive poll/chrome unblock — finished matches only (not mid Success skills). */
  function mayUnblockPollsForFinishedMatch(state) {
    return !!(state && state.status === 'finished');
  }

  function liveWinLossPresentationActive(state, flags) {
    flags = flags || {};
    // Finished / post-cursor match-end is not sacred Win/Loss chrome.
    if (mayUnblockPollsForFinishedMatch(state)) return false;
    if (flags.heartCheckHold || flags.liveShowRunner) return true;
    if (liveShowInFlight(state)) return true;
    if (isLiveWinLossPipelinePhase(state?.phase)) return true;
    const stage = state?.live_show?.stage;
    if (stage === 'performance' || stage === 'outcomes' || stage === 'judge') return true;
    return false;
  }

  /**
   * Force-apply End Main / play catch-up behind leftover On Enter holds.
   * Must stay false for any Live Win/Loss / live_show cursor — aborting the
   * director there parks both players on "Checking hearts…" / Live Win/Loss Check.
   *
   * Turn advances (phase / active player) may pierce leftover Checking-hearts /
   * empty-LIVE chrome. Main board catch-up (hand/stage) must NOT — that aborted
   * healthy skill animations and reopened answered prompts.
   */
  function mayForceApplyHeldSnapshot(prev, next, flags) {
    flags = flags || {};
    if (!prev || !next) return false;
    if ((next.seq ?? 0) <= (prev.seq ?? 0)) return false;
    // Hard refuse: live_show cursor or runner still owns Win/Loss presentation.
    if (liveShowInFlight(prev) || liveShowInFlight(next)) return false;
    if (flags.liveShowRunner) return false;
    if (isLiveWinLossPipelinePhase(next.phase) && next.status !== 'finished') return false;

    const turnAdvance = isTurnAdvanceSnapshot(prev, next);
    const boardCatchup = isMainBoardCatchupSnapshot(prev, next);
    if (!turnAdvance && !boardCatchup) return false;

    if (turnAdvance) {
      // Leftover chrome on settled play: allow End Main. Still refuse when leaving
      // a real Win/Loss pipeline phase (not yet on settled Main / live_set).
      if ((flags.perfSpectacle || flags.spectacleGate || flags.heartCheckHold)
          && isLiveWinLossPipelinePhase(prev.phase)
          && !isSettledPlayPhase(next.phase)
          && next.status !== 'finished') {
        return false;
      }
      return true;
    }

    // Main board catch-up: only pierce plain On Enter / log-sync animating —
    // never Live presentation leftovers (replays animations + reopens skills).
    if (flags.perfSpectacle || flags.spectacleGate || flags.heartCheckHold
        || flags.liveRoundPlayback || flags.directorActive) {
      return false;
    }
    return true;
  }

  function isTurnAdvanceSnapshot(prev, next) {
    if (!prev || !next) return false;
    if ((next.seq ?? 0) <= (prev.seq ?? 0)) return false;
    if (liveShowInFlight(next)) return false;
    if (isLiveWinLossPipelinePhase(next.phase) && next.status !== 'finished') return false;
    if (prev.phase !== next.phase) return isSettledPlayPhase(prev.phase);
    if ((prev.phase === 'main_first' || prev.phase === 'main_second')
        && prev.active_player !== next.active_player) {
      return true;
    }
    if (prev.phase === 'live_set' && next.phase === 'live_set') {
      return prev.active_player !== next.active_player
        || (prev.live_set_player || prev.active_player) !== (next.live_set_player || next.active_player)
        || JSON.stringify(prev.live_ready || {}) !== JSON.stringify(next.live_ready || {});
    }
    return false;
  }

  function stageOccupancyKey(state, pid) {
    const stage = state?.players?.[pid]?.stage || {};
    return ['left', 'center', 'right'].map((slot) => stage[slot]?.instance_id || '').join(',');
  }

  function isMainBoardCatchupSnapshot(prev, next) {
    if (!prev || !next) return false;
    if ((next.seq ?? 0) <= (prev.seq ?? 0)) return false;
    if (liveShowInFlight(next) || liveShowInFlight(prev)) return false;
    if (prev.phase !== next.phase) return false;
    if (!isMainStablePhase(prev.phase)) return false;
    const pid = next.active_player || prev.active_player;
    if (!pid) return false;
    if (stageOccupancyKey(prev, pid) !== stageOccupancyKey(next, pid)) return true;
    const ph = prev.players?.[pid]?.hand?.length ?? -1;
    const nh = next.players?.[pid]?.hand?.length ?? -1;
    if (ph !== nh) return true;
    const pe = (prev.players?.[pid]?.energy_zone || []).length;
    const ne = (next.players?.[pid]?.energy_zone || []).length;
    return pe !== ne;
  }

  /**
   * Poll heal that clears G.animating on Main. False during Win/Loss / live_show.
   * Requires hysteresisMs so healthy baton / log-sync gaps between flight clones are not aborted.
   * Baton / play hide-iids with no flights use a shorter latch so the hand does not
   * stay stuck on the pre-play fan while the stage already shows the new member.
   */
  function mayUnstickStuckMainPresentation(state, flags, flightCount) {
    flags = flags || {};
    if (liveWinLossPresentationActive(state, flags)) return false;
    if (flags.perfSpectacle || flags.spectacleGate || flags.liveShowRunner) return false;
    if (!isMainStablePhase(state?.phase)) return false;
    if ((flightCount || 0) > 0) return false;
    const busy = !!(flags.animating || flags.liveRoundPlayback || flags.logSync || flags.directorActive);
    if (!busy) return false;
    const hysteresis = Number.isFinite(flags.hysteresisMs)
      ? flags.hysteresisMs
      : (flags.hideIidsStuck ? MAIN_HIDE_IID_UNSTICK_MS : MAIN_UNSTICK_HYSTERESIS_MS);
    const zeroFlightMs = Number(flags.zeroFlightMs || 0);
    if (zeroFlightMs < hysteresis) return false;
    return true;
  }

  /**
   * Closing leftover Performance chrome. Allow match-end finished and truly
   * promptless post-cursor live_judge. Never while live_show is in flight or
   * any pending skill (Live Success surveil, Success pick, etc.) is open.
   */
  function mayClearStuckPerfSpectacle(state, flags) {
    flags = flags || {};
    if (!flags.perfSpectacle) return false;
    if (flags.animating || flags.spectacleGate || flags.liveRoundPlayback || flags.directorActive) {
      return false;
    }
    if (liveShowInFlight(state)) return false;
    if (state?.pending_prompt || state?.pending_prompt_meta) {
      const pr = judgePickPromptType(state);
      if (isJudgePickPrompt(pr) && flags.postSpectacleReady) return true;
      return false;
    }
    if (flags.heartCheckHold && state?.status !== 'finished' && !isPromptlessPostCursorLiveJudge(state)) {
      // Match-ending Success can clear live_show while Checking hearts is still up
      // and phase has not yet flipped to finished — still allow chrome clear so polls
      // can deliver status=finished (see spectacleBlocksPoll companion in game-sync).
      if (!liveShowInFlight(state) && !state?.pending_prompt && !state?.pending_prompt_meta) {
        return true;
      }
      return false;
    }
    if (mayUnblockPollsForFinishedMatch(state)) return true;
    if (isPromptlessPostCursorLiveJudge(state)) return true;
    const ph = state?.phase;
    if (isLiveWinLossPipelinePhase(ph) && ph !== 'live_judge') return false;
    if (ph === 'live_judge') return false;
    return isMainStablePhase(ph);
  }

  /** Dead live_show runner while the cursor still needs acks (Win/Loss freeze). */
  function shouldResumeLiveShowRunner(state, flags) {
    flags = flags || {};
    if (!state || flags.liveShowRunner || flags.isSpectator) return false;
    if (state.pending_prompt) return false;
    if (state.status === 'finished') return false;
    const stage = state.live_show?.stage;
    return !!(stage && stage !== 'done');
  }

  /**
   * Soft tab catch-up only while Live Start wait owns presentLiveRound.
   * Bare livePollHold / liveRoundPlayback on Main must hard-catch-up (Aug 18).
   */
  function shouldSoftTabCatchUpPreserveLivePipeline(state, flags) {
    flags = flags || {};
    if (flags.awaitingLiveStart) return true;
    const stage = state?.live_show?.stage;
    if (stage === 'reveal' || stage === 'live_start') return true;
    if (state?.phase === 'live_start_effects') return true;
    return false;
  }

  /**
   * Whether tab catch-up may seal yell/performance playback for this live_show stage.
   * Must NOT seal during reveal/live_start — that skipped the whole Live round.
   */
  function shouldForceSkipLiveYellsOnTabRestore(stage, hiddenMs, board) {
    const ms = Number(hiddenMs) || 0;
    const st = stage || board?.live_show?.stage || null;
    if (st === 'outcomes' || st === 'judge' || st === 'done') return true;
    if (st === 'performance') {
      if (board && board._perf_hearts_resolved
          && typeof board._perf_hearts_resolved === 'object'
          && Object.keys(board._perf_hearts_resolved).length > 0) {
        return true;
      }
      if (board && board.phase === 'live_success_effects') return true;
      if (board?.pending_prompt?.type === 'pick_judge_success_live') return true;
      // Very long hide — user likely missed the climb; avoid a long catch-up replay.
      if (ms >= 30000) return true;
      return false;
    }
    if (st === 'reveal' || st === 'live_start') {
      // Still owe reveal / Live Start — never seal on a normal alt-tab.
      return ms >= 120000;
    }
    // No live_show cursor but server already on settled Main with a done round.
    if (!st && board && isMainStablePhase(board.phase) && ms >= 2800) return true;
    return false;
  }

  /** Whether tab catch-up may call markLiveShowPerformancePresented for this board. */
  function shouldSealLivePerformanceOnTabRestore(stage, hiddenMs, board) {
    const st = stage || board?.live_show?.stage || null;
    if (st === 'outcomes' || st === 'judge' || st === 'done') return true;
    if (st === 'performance') {
      return shouldForceSkipLiveYellsOnTabRestore(st, hiddenMs, board);
    }
    return false;
  }

  /** Poll-hold watchdog: soft-release only on idle settled Main (never abort director). */
  function livePollHoldWatchdogMayRelease(state, flags) {
    flags = flags || {};
    const ph = state?.phase;
    const stage = state?.live_show?.stage;
    const onSettledMain = isMainStablePhase(ph);
    const liveBusy = !!(stage && stage !== 'done')
      || ph === 'live_start_effects' || ph === 'live_performance_first'
      || ph === 'live_performance_second' || ph === 'live_judge'
      || ph === 'live_success_effects' || ph === 'live_set'
      || flags.liveRoundPlayback || flags.spectacleGate
      || flags.liveShowRunner || flags.perfSpectacle
      || flags.directorActive;
    return onSettledMain && !liveBusy;
  }

  /** Soft catch-up should escalate to hard when the fetched board left Live Start. */
  function shouldEscalateSoftTabCatchUpToHard(fetched, opts) {
    opts = opts || {};
    if (!fetched) return false;
    if (fetched.status === 'finished') return true;
    if ((opts.hiddenMs || 0) >= 2800 && (opts.seqGap || 0) > 0) return true;
    const stage = fetched.live_show?.stage;
    if (stage && stage !== 'done' && stage !== 'reveal' && stage !== 'live_start') {
      return true;
    }
    if (isMainStablePhase(fetched.phase) || fetched.phase === 'live_set') return true;
    if (fetched.phase === 'live_performance_first' || fetched.phase === 'live_performance_second'
        || fetched.phase === 'live_judge' || fetched.phase === 'live_success_effects') {
      return true;
    }
    return false;
  }

  /** A finished match whose win overlay never opened (hung final-round presentation / queued apply). */
  const FINISH_STALL_MS = 30000;
  /** Presentation chrome busy with no state / cursor / spectacle-phase progress at all. */
  const PRESENTATION_STALL_MS = 100000;
  /** At most one automatic recovery reload per window. */
  const STALL_RELOAD_COOLDOWN_MS = 300000;

  /** 'force_win' once a finished match has waited FINISH_STALL_MS without the win overlay. */
  function finishedStallAction(ctx) {
    ctx = ctx || {};
    if (!ctx.finishedSeenAt || ctx.winOverlayOpen || ctx.forced) return null;
    if ((ctx.now - ctx.finishedSeenAt) < (ctx.finishStallMs || FINISH_STALL_MS)) return null;
    return 'force_win';
  }

  /**
   * 'reload' when presentation flags have been busy with no progress for PRESENTATION_STALL_MS.
   * Never while the tab is hidden, a skill prompt is open (it has its own timer), or the match is
   * already over / not a live player session.
   */
  function presentationStallAction(ctx) {
    ctx = ctx || {};
    if (!ctx.busy || ctx.hidden || ctx.hasPrompt || ctx.finished || ctx.notPlayerSession) return null;
    if (!ctx.busySince || (ctx.now - ctx.busySince) < (ctx.stallMs || PRESENTATION_STALL_MS)) return null;
    if (ctx.lastReloadAt && (ctx.now - ctx.lastReloadAt) < STALL_RELOAD_COOLDOWN_MS) return null;
    return 'reload';
  }

  return {
    FINISH_STALL_MS,
    PRESENTATION_STALL_MS,
    finishedStallAction,
    presentationStallAction,
    MAIN_UNSTICK_HYSTERESIS_MS,
    MAIN_HIDE_IID_UNSTICK_MS,
    liveShowInFlight,
    isLiveWinLossPipelinePhase,
    isSettledPlayPhase,
    isMainStablePhase,
    isPromptlessPostCursorLiveJudge,
    mayUnblockPollsForFinishedMatch,
    liveWinLossPresentationActive,
    isTurnAdvanceSnapshot,
    isMainBoardCatchupSnapshot,
    mayForceApplyHeldSnapshot,
    mayUnstickStuckMainPresentation,
    mayClearStuckPerfSpectacle,
    shouldResumeLiveShowRunner,
    shouldSoftTabCatchUpPreserveLivePipeline,
    shouldForceSkipLiveYellsOnTabRestore,
    shouldSealLivePerformanceOnTabRestore,
    livePollHoldWatchdogMayRelease,
    shouldEscalateSoftTabCatchUpToHard,
  };
});
