/**
 * TCG state apply pipeline — onState gate, applyStateUpdate, pending queue.
 */
(function (global) {
  'use strict';

  function isReplayViewingState(s) {
    return !!(s && ((s.mode || '') === 'replay_view' || s.replay))
      || (typeof global.isReplayViewing === 'function' && global.isReplayViewing());
  }

  function replayStepDelta(prev, next) {
    const prevStep = prev?.replay?.step;
    const nextStep = next?.replay?.step ?? G.replayStep;
    if (typeof prevStep === 'number' && typeof nextStep === 'number') return nextStep - prevStep;
    return null;
  }

  function primeReplaySnapshotPresentationContext(s) {
    if (typeof clearPerfSpectacleDoneStorage === 'function') clearPerfSpectacleDoneStorage();
    if (typeof primePerfSpectacleDoneKeysFromLog === 'function') primePerfSpectacleDoneKeysFromLog(s);
    if (typeof primeReplayEmptyLivePresentedFromLog === 'function') {
      primeReplayEmptyLivePresentedFromLog(s);
    }
    G._announceBaseline = (s?.log || []).length;
    G._prevLogLen = (s?.log || []).length;
    G._lastPhase = s?.phase ?? null;
    G._deferPerfSpectaclePrev = null;
    G._livePostRevealBoard = null;
    G._liveStorageOutcomePending = false;
    G._liveRoundPlaybackActive = false;
    G._liveRoundPostSpectacleReady = false;
    G._liveSpectacleGateRunning = false;
    G._postSpectacleSplashPause = false;
    if (typeof resyncGameLogFromState === 'function') resyncGameLogFromState(s);
  }

  function applyReplaySnapshot(s) {
    G._lastAppliedAt = Date.now();
    G._pendingStateQueue = [];
    G._promptSubmitKey = null;
    G.lastSeq = s?.seq ?? G.lastSeq;
    G.playerId = G.isSpectator ? (s.view_as || 'p1') : (s.my_id || G.playerId);
    if (typeof applyReplayStateFromPoll === 'function') applyReplayStateFromPoll(s);
    if (document.querySelector('.screen.active')?.id !== 'screen-game') showScr('game');
    if (typeof dismissAllGameplayOverlays === 'function') dismissAllGameplayOverlays();
    primeReplaySnapshotPresentationContext(s);
    G.gameState = s;
    renderGame(s, { skipPrompt: true });
    G._presentationAborted = false;
    G._replayLastAppliedStep = s?.replay?.step ?? G.replayStep;
    if (typeof syncReplayPromptReadOnlyUi === 'function') syncReplayPromptReadOnlyUi(true);
    if (typeof syncReplayControlBar === 'function') syncReplayControlBar();
  }

  async function applyReplayStateUpdate(prev, s) {
    if (typeof abortGameplayPresentation === 'function') abortGameplayPresentation();
    const delta = replayStepDelta(prev, s);
    const scrub = !prev || G._replaySeekWasScrub || delta == null || delta !== 1;
    if (scrub) {
      applyReplaySnapshot(s);
      G._replaySeekWasScrub = false;
      return;
    }
    G._replayForwardApply = true;
    G._presentationAborted = false;
    try {
      if (typeof holdLivePolls === 'function') holdLivePolls();
      await global.applyStateUpdate(s);
    } finally {
      G._replayForwardApply = false;
      G._replayLastAppliedStep = s?.replay?.step ?? G.replayStep;
      G._replaySeekWasScrub = false;
      if (typeof releaseLivePolls === 'function') releaseLivePolls();
      if (typeof syncReplayPromptReadOnlyUi === 'function') syncReplayPromptReadOnlyUi(true);
      if (typeof syncReplayControlBar === 'function') syncReplayControlBar();
    }
  }

  global.onState = function onState(s) {
    if (G.isTutorial && !G.tutorialLive) return;
    if (isReplayViewingState(s)) {
      if (typeof G._replaySeekAppliedStep === 'number' && s.replay) {
        const pollStep = s.replay.step ?? 0;
        if (pollStep < G._replaySeekAppliedStep) {
          TCG_DEBUG.logOnce('state', `replay-stale:${pollStep}`, 'skip stale replay poll', {
            pollStep,
            applied: G._replaySeekAppliedStep,
          });
          return;
        }
      }
      if (G._replaySeekInFlight && s.replay) {
        const pollStep = s.replay.step ?? 0;
        const target = G._replaySeekTarget ?? G.replayStep;
        if (pollStep < target) {
          TCG_DEBUG.logOnce('state', `replay-inflight:${pollStep}`, 'skip replay poll during seek', {
            pollStep,
            target,
          });
          return;
        }
      }
      TCG_DEBUG.log('state', 'apply replay snapshot', TCG_DEBUG.snap(s));
      applyStateUpdate(s);
      return;
    }
    if(s.seq<=G.lastSeq && G.gameState) {
      TCG_DEBUG.logOnce('state', `stale:${s.seq}`, 'skip stale', { incoming: s.seq, last: G.lastSeq });
      if (typeof tryFlushSpectacleRecovery === 'function') tryFlushSpectacleRecovery();
      return;
    }
    if (s.status === 'finished') {
      clearPvPWatchdog();
      if (G.rematchWaiting && s.seq <= G.lastSeq && G.gameState?.status === 'finished') {
        if (s.seq > G.lastSeq) {
          G.lastSeq = s.seq;
          G.gameState = s;
          if (typeof global.syncWinRematchUi === 'function') global.syncWinRematchUi(s);
        }
        return;
      }
      TCG_DEBUG.log('state', 'apply finished (immediate)', TCG_DEBUG.snap(s));
      void applyStateUpdate(s);
      return;
    }
    if (G.rematchWaiting && s.status !== 'finished') {
      G.rematchWaiting = false;
      G.rematchRequested = false;
      global.el?.('overlay-win')?.classList.remove('open');
    }
    if (shouldHoldStateForLocalPrompt(s)) {
      TCG_DEBUG.log('state', 'queue (local prompt open)', { seq: s.seq, phase: s.phase, q: (G._pendingStateQueue?.length || 0) + 1 });
      enqueuePendingState(s);
      return;
    }
    if (G.animating) {
      TCG_DEBUG.log('state', 'queue (animating)', { seq: s.seq, phase: s.phase, q: (G._pendingStateQueue?.length || 0) + 1 });
      enqueuePendingState(s);
      return;
    }
    TCG_DEBUG.log('state', 'apply', TCG_DEBUG.snap(s));
    applyStateUpdate(s);
  };

  global.applyFinishedState = async function applyFinishedState(s, prev) {
    if (G.isSpectator) {
      G.lastSeq = s.seq;
      G.gameState = s;
      renderGame(s, { skipLog: true });
      await leaveSpectatorMode({ toastMsg: 'Match ended.' });
      return;
    }
    G.lastSeq = s.seq;
    G.playerId = s.my_id || G.playerId;
    const cur = document.querySelector('.screen.active')?.id;
    if (cur !== 'screen-game') showScr('game');

    const prevLogLen = prev?.log?.length || 0;
    const newEntries = (s.log || []).slice(prevLogLen);
    const resigned = !!gameResignedBy(s);
    let playedFinalLiveRound = false;
    if (!resigned) {
      playedFinalLiveRound = await maybePlayFinalLiveRoundPresentation(prev, s, newEntries);
    }

    abortGameplayPresentation();
    const rematchSettings = typeof global.captureRematchSettings === 'function'
      ? global.captureRematchSettings(s) : null;
    const rematchEligible = typeof global.isFriendPvpRematchEligible === 'function'
      && global.isFriendPvpRematchEligible(rematchSettings);
    if (rematchEligible) {
      G.rematchWaiting = true;
      resumePollingTick(400);
    } else {
      stopPoll();
    }

    if (shouldPlaySuccessLiveTriumph(prev, s)) {
      G.animating = true;
      try {
        G.gameState = s;
        renderGame(s, { skipLog: true });
        await playSuccessLiveTriumphCelebration(s, G.playerId);
      } finally {
        G.animating = false;
      }
    } else if (!playedFinalLiveRound) {
      G.gameState = s;
      renderGame(s, { skipLog: true });
    }

    catchUpGameLog(s, prev);
    if (prev && !resigned) flushPostLiveLogBanners(prev, s, G.playerId);
    showWin(s);
    if (G.rematchWaiting && typeof global.syncWinRematchUi === 'function') {
      global.syncWinRematchUi(s);
    }
  };

  /** Apply one server state snapshot: spectacle gate, log anims, or direct render. */
  global.applyStateUpdate = async function applyStateUpdate(s) {
    if (G.isTutorial && !G.tutorialLive) return;
    if (isReplayViewingState(s) && !G._replayForwardApply) {
      await applyReplayStateUpdate(G.gameState, s);
      return;
    }
    const replayForward = !!G._replayForwardApply;
    G._lastAppliedAt = Date.now();
    if (!replayForward) syncPromptSubmitState(s);
    if (!s?.pending_prompt) clearDeferredPromptState();
    clearStaleOpponentSkillWaitIfResolved(s, G.playerId);
    const prev = G.gameState;
    restorePerfSpectacleDoneKey();
    markSpectacleDoneFromState(s, prev);
    if (prev && !isLiveSetPhase(prev.phase) && isLiveSetPhase(s.phase)) {
      G._perfYellRevealCache = null;
      G._deferPerfSpectaclePrev = null;
      G._liveSetStorageBaseline = null;
      G._livePostRevealBoard = null;
      G._perfSplashShownForTurn = null;
    }
    syncDeferredHandDrawMask(prev, s, G.playerId);
    syncLiveSuccessPresentationDefer(prev, s);
    if (isLiveSetPhase(s.phase)) refreshLiveSetStorageBaseline(s);
    const oppId = G.playerId === 'p1' ? 'p2' : 'p1';
    const truncated = prev && logWasTruncated(prev, s);
    if (truncated) resyncGameLogFromState(s);
    const prevLogLen = truncated ? 0 : (prev?.log?.length || 0);
    const newEntries = (s.log || []).slice(prevLogLen);
    const hasAnimSteps = newEntries.some(e => e.anim?.length);
    ensurePerfSpectacleNotStaleDone(prev, s);
    maybeToastWrFizzleFromLog(newEntries);

    G.lastSeq = s.seq;
    G.playerId = G.isSpectator ? (s.view_as || 'p1') : (s.my_id || G.playerId);
    maybeResetBatonTouchToggle(prev, s);
    applyReplayStateFromPoll(s);
    stashPerfYellRevealCache(s);
    if (s.status === 'waiting') {
      G.gameState = s;
      updateWaitingTimerInfo(s.phase_timer_cfg);
      showScr('waiting');
      return;
    }
    if (s.status === 'finished') {
      await applyFinishedState(s, prev);
      return;
    }
    const cur = document.querySelector('.screen.active')?.id;
    if (cur !== 'screen-game') showScr('game');

    if (G._announceBaseline == null && isActiveGameplay(s)) {
      G._announceBaseline = prev?.log?.length ?? (s.log || []).length;
      if (!prev) G._lastPhase = s.phase;
    }

    const livePrev = (typeof effectiveLiveRoundPrev === 'function'
      ? effectiveLiveRoundPrev(prev, s)
      : null) ?? effectiveEmptyLiveRoundPrev(prev, s);
    const pendingSpectacleTurn = detectPendingLiveSpectacleTurn(livePrev, s)
      ?? detectPendingLiveSpectacleTurn(prev, s);
    const spectacleGateActive = pendingSpectacleTurn != null && !liveSpectacleDoneForTurn(pendingSpectacleTurn);

    if (spectacleGateActive && (G.gameState?.seq ?? 0) < (s.seq ?? 0)
        && typeof abortStuckLiveRoundPlayback === 'function') {
      abortStuckLiveRoundPlayback('behind server during spectacle');
      G.gameState = s;
    }

    if (await runLiveSpectacleGate(livePrev, s, newEntries, G.playerId)) {
      const live = G.gameState || s;
      if (!replayForward
          && live.pending_prompt?.responder === G.playerId
          && live.phase === 'live_judge'
          && live.pending_prompt?.type === 'pick_judge_success_live') {
        ensurePendingPromptSurfaced(live, G.playerId);
      }
      if (!replayForward && G.isCPU && !G.animating && !(G.tutorialLive && G.tutorialHoldCpu)) {
        doCPU(live);
        armWatchdog(live);
      }
      return;
    }

    if (spectacleGateActive && !liveSpectacleDoneForTurn(pendingSpectacleTurn)) {
      if (!replayForward && !G.animating && !G._liveSpectacleGateRunning && !G._liveRoundPlaybackActive
          && (G.gameState?.seq ?? 0) < (s.seq ?? 0)) {
        G.gameState = s;
        renderGame(s, {
          skipLog: true,
          skipPrompt: typeof shouldDeferPromptForLivePresentation === 'function'
            && shouldDeferPromptForLivePresentation(s, G.playerId),
        });
      }
      const live = G.gameState || s;
      if (!replayForward && live.pending_prompt?.responder === G.playerId) {
        ensurePendingPromptSurfaced(live, G.playerId);
      }
      if (!replayForward && G.isCPU && !G.animating && !(G.tutorialLive && G.tutorialHoldCpu)) {
        doCPU(live);
        armWatchdog(live);
      }
      return;
    }

      if (prev && newEntries.length && hasAnimSteps) {
      TCG_DEBUG.log('apply', 'playLogSyncedSequence', { entries: newEntries.length, anims: newEntries.filter(e => e.anim?.length).length, ...TCG_DEBUG.trans(prev, s) });
      G.animating = true;
      try {
        await playLogSyncedSequence(prev, s, newEntries, G.playerId);
        if (!G.gameState || (G.gameState.seq ?? 0) < (s.seq ?? 0)) {
          G.gameState = s;
        }
      } finally {
        G.animating = false;
        flushPendingState();
      }
    } else {
      G._prevLogLen = prevLogLen;
      G._prevRects = prev ? collectCardRects() : {};
      G._handSlotsBefore = prev ? collectHandSlotRects() : null;
      let moves = prev ? diffCardMoves(prev, s) : [];
      if (G._deferredHandDrawIids?.size) {
        moves = filterDeferredHandDrawMoves(moves, G._deferredHandDrawIids);
      }
      if (prev && liveStorageOutcomePlaybackPending(prev, s)) {
        moves = filterLiveStorageDeferredMoves(prev, moves, s);
      }
      if (emptyLiveRoundPresentationPending(prev, s)) {
        moves = filterEmptyLivePendingWrMoves(prev, moves, s);
      }
      const openingDeal = isOpeningHandDealTransition(prev, s);
      const setupMulliganOnly = prev?.phase === 'setup' && s.phase === 'setup';
      if (setupMulliganOnly) moves = [];
      if (openingDeal) {
        const openingIds = openingHandDealIids(s);
        moves = moves.filter(m => !openingIds.has(m.iid));
      }
      G._animHideIids = prev && moves.length ? animHideIidsForMoves(prev, moves) : null;
      G._liveRevealFlips = prev ? collectLiveRevealFlips(prev, s) : new Set();
      rememberPerfSpectacleBaseline(prev, s);
      const livePlan = liveRoundPresentationPlan(livePrev, s);
      const emptySkip = !liveSetPlacementInProgress(s)
        && (livePlan.wantsEmptyRound || shouldPresentEmptyLiveRound(livePrev, s));
      if (!spectacleGateActive && pendingSpectacleTurn == null
          && (livePlan.needsLiveReveal || livePlan.wantsSpectacle || emptySkip)) {
        TCG_DEBUG.log('apply', 'presentLiveRound', { ...livePlan, emptySkip, solo: isSoloPlayerEmptyLiveRound(livePrev, s) }, TCG_DEBUG.trans(livePrev, s));
        G.animating = true;
        try {
          await presentLiveRound(livePrev, s, G.playerId, {
            newEntries,
            forceEmptyRound: emptySkip && !livePlan.wantsEmptyRound,
          });
          if (!replayForward) ensurePendingPromptSurfaced(s, G.playerId);
        } finally {
          G._animHideIids = null;
          clearHandArrivingFlags();
          G.animating = false;
          if (!replayForward && s.pending_prompt?.responder === G.playerId) {
            ensurePendingPromptSurfaced(s, G.playerId);
          }
          releaseLivePollsAndFlush();
        }
      } else {
          const emptyPending = emptyLiveRoundPresentationPending(prev, s);
          TCG_DEBUG.log('apply', 'direct render', { moves: moves.length, newLog: newEntries.length, emptyPending, ...TCG_DEBUG.trans(prev, s) });
          let animPrev = prev;
          let emptyRoundHandled = false;
          if (emptyPending && isLeavingLiveSetPhase(prev, s)) {
            G.animating = true;
            try {
              await presentLiveRound(prev, s, G.playerId, { newEntries, forceEmptyRound: true });
              if (!replayForward) ensurePendingPromptSurfaced(s, G.playerId);
              emptyRoundHandled = true;
            } finally {
              G._animHideIids = null;
              clearHandArrivingFlags();
              G.animating = false;
              releaseLivePollsAndFlush();
            }
          } else if (shouldAnimateEmptyLiveStorageWr(animPrev, s) && shouldPresentEmptyLiveRound(prev, s)) {
            G.animating = true;
            try {
              const wrFrom = buildEmptyLiveWrPlayback(animPrev, s) || animPrev;
              if (wrFrom && liveStorageHasCards(wrFrom)) {
                G.gameState = wrFrom;
                renderGame(wrFrom, { skipLog: true });
                await runLiveStorageRevealSequence(wrFrom, s, G.playerId, {
                  deferWrDiscards: true,
                  skipIntroBanner: true,
                });
              }
              await queueEmptyLiveRoundBanner();
              await waitForBannersIdle();
              const revealBoard = G._livePostRevealBoard || wrFrom;
              if (revealBoard && collectLiveBluffDiscards(revealBoard, s).length) {
                await playLiveStorageWrDiscards(revealBoard, s, G.playerId, { initialDelayMs: LIVE_BLUFF_WR_DELAY_MS });
                animPrev = G.gameState;
              }
              G._livePostRevealBoard = null;
              moves = diffCardMoves(animPrev, s);
              if (G._deferredHandDrawIids?.size) {
                moves = filterDeferredHandDrawMoves(moves, G._deferredHandDrawIids);
              }
              if (prev && liveStorageOutcomePlaybackPending(prev, s)) {
                moves = filterLiveStorageDeferredMoves(prev, moves, s);
              }
              moves = filterEmptyLivePendingWrMoves(prev, moves, s);
              G._prevRects = collectCardRects();
              G._handSlotsBefore = collectHandSlotRects();
              G._animHideIids = animPrev && moves.length ? animHideIidsForMoves(animPrev, moves) : null;
              flushPostLiveLogBanners(animPrev, s, G.playerId, { emptySkip: true });
              markEmptyLiveRoundPresented(prev, s);
              clearEmptyLiveRoundPerfState();
              await playEmptySkipTurnPrepSequence(prev, s, newEntries, G.playerId);
              emptyRoundHandled = true;
              nudgeCpuAfterStatePresentation(s);
            } finally {
              G.animating = false;
            }
          } else if (G._livePostRevealBoard) {
            G.animating = true;
            try {
              if (await maybeAnimatePendingLiveStorageWr(s, G.playerId)) {
                animPrev = G.gameState;
                moves = diffCardMoves(animPrev, s);
                if (G._deferredHandDrawIids?.size) {
                  moves = filterDeferredHandDrawMoves(moves, G._deferredHandDrawIids);
                }
                if (prev && liveStorageOutcomePlaybackPending(prev, s)) {
                  moves = filterLiveStorageDeferredMoves(prev, moves, s);
                }
                G._prevRects = collectCardRects();
                G._handSlotsBefore = collectHandSlotRects();
                G._animHideIids = animPrev && moves.length ? animHideIidsForMoves(animPrev, moves) : null;
              }
            } finally {
              G.animating = false;
            }
          }
          if (!emptyRoundHandled) {
          applyTurnPrepEntriesToState(s, s, newEntries);
          if (!liveSpectaclePendingForTransition(prev, s)) {
            queueStateAnnouncements(prev, s, G.playerId, {
              emptyLiveSkip: isEmptyLiveSkipTransition(prev, s),
              replayForward,
            });
          }
          const hideHandsOnMat = handsHiddenOnMat(s);
          const deferHand = hideHandsOnMat || openingDeal || handLayoutDeferForPlayer(moves, G.playerId);
          const deferOppHand = hideHandsOnMat || openingDeal || shouldDeferOpponentHandLayout(moves, s, G.playerId);
          captureHandShiftBaselines(moves, G.playerId);
          captureFlightArtClones(moves, G.playerId, animPrev);
          prepareWrPileAnimPending(animPrev, s, moves);
          G.gameState = s;
          renderGame(s, { skipHand: deferHand, skipOppHand: deferOppHand });
          const silentWrAdds = animPrev ? wrCardsAddedWithoutAnimMoves(animPrev, s, moves) : [];
          if (silentWrAdds.length) {
            void refreshWaitingRoomPiles(s, G.playerId, {
              releaseIids: silentWrAdds.map(x => x.iid),
            });
          }
          if (moves.length && (deferHand || deferOppHand) && !openingDeal) {
            primeDeferredHandLayoutsAfterRender(s, G.playerId, moves);
          }
          const handSlotsAfter = (deferHand || deferOppHand)
            ? projectHandSlotRects(s, G.playerId)
            : collectHandSlotRects();
          if (openingDeal) {
            G.animating = true;
            try {
              await playOpeningHandDeal(prev, s, G.playerId);
              if (moves.length) {
                await playCardMoveAnimations(prev, s, G._prevRects, G.playerId, G._handSlotsBefore, handSlotsAfter, moves);
              }
            } finally {
              clearWrPileAnimPending();
              clearHandDepartRemovals();
              clearHandShiftBaselines();
              G._animHideIids = null;
              clearHandArrivingFlags();
              G.animating = false;
              flushPendingState();
            }
          } else if (animPrev && moves.length) {
            G.animating = true;
            if (!(deferHand || deferOppHand)) markHandDepartRemovals(moves);
            try {
              const liveSetPlacements = isLiveSetPhase(s.phase)
                && moves.length > 0
                && moves.every(m => m.from?.zone === 'hand' && m.to?.zone === 'live');
              if (liveSetPlacements) {
                await playHandToLiveStoragePlacements(animPrev, s, G.playerId, moves);
              } else {
                await playCardMoveAnimations(animPrev, s, G._prevRects, G.playerId, G._handSlotsBefore, handSlotsAfter, moves);
              }
            } finally {
              clearWrPileAnimPending();
              if (wrCardsAddedWithoutAnimMoves(animPrev, s, moves).length) {
                void refreshWaitingRoomPiles(s, G.playerId, { clearPending: true });
              }
              finalizeDeferredHandLayouts(s, G.playerId, { deferMine: deferHand, deferOpp: deferOppHand });
              clearHandDepartRemovals();
              clearHandShiftBaselines();
              G._animHideIids = null;
              clearHandArrivingFlags();
              G.animating = false;
              flushPendingState();
            }
          } else {
            G._animHideIids = null;
            if (prev && wrCardsAddedWithoutAnimMoves(prev, s, moves).length) {
              void refreshWaitingRoomPiles(s, G.playerId, { clearPending: true });
            }
            flushPendingState();
          }
          }
      }
    }

    if (G._liveSetLockPid && (s.live_ready?.[G._liveSetLockPid] || s.phase !== 'live_set')) {
      G._liveSetLockPid = null;
    }
    if (liveSetPlacementInProgress(s)
        && (G._liveRoundPlaybackActive || G._perfSpectacleActive || G._liveSpectacleGateRunning || G._livePollHold)) {
      TCG_DEBUG.warn('live', 'abort stuck presentation during live_set placement');
      abortGameplayPresentation({ skipAbortFlag: true });
    }
    if (G.isTutorial && !G.tutorialLive) return;
    tcgDebugOnStateApplied(prev, s, newEntries);
    ensurePollHoldReleased(G.gameState || s);
    if (!replayForward && !G.animating && !G._perfSpectacleActive && !G._liveSpectacleGateRunning) {
      if (shouldRecoverMissedLiveSpectacle(prev, s)) {
        await runLiveSpectacleGate(prev, s, newEntries, G.playerId);
      }
      G._spectacleRecoveryPending = null;
    } else if (!replayForward && shouldRecoverMissedLiveSpectacle(prev, s)) {
      G._spectacleRecoveryPending = { prev, s, newEntries, myId: G.playerId };
    }
    clearStalePerfDeferState(prev, s);
    if (!G.animating && !G._liveRoundPlaybackActive && !liveSetPlacementInProgress(s)
        && shouldPresentEmptyLiveRound(prev, s)) {
      G.animating = true;
      try {
        await presentLiveRound(prev, s, G.playerId, { newEntries, forceEmptyRound: true });
      } finally {
        G.animating = false;
        releaseLivePollsAndFlush();
      }
    }
    flushPendingState();
    if (!replayForward && !G.animating && !G._perfSpectacleActive && s.pending_prompt?.responder === G.playerId
        && (s.phase === 'live_success_effects'
            || (s.phase === 'live_judge' && s.pending_prompt?.type === 'pick_judge_success_live'))) {
      ensurePendingPromptSurfaced(s, G.playerId);
    }
    clearStaleCpuPromptBusyIfResolved(G.gameState || s);
    if (G.playerId) updateOpponentSkillWaitBanner(G.gameState || s, G.playerId);
    if (!replayForward && G.isCPU && !G.animating && !(G.tutorialLive && G.tutorialHoldCpu)) {
      doCPU(G.gameState || s);
      armWatchdog(G.gameState || s);
    } else if (!replayForward && G.isCPU && (G.gameState || s)?.pending_prompt?.responder === 'p2'
        && !(G.tutorialLive && G.tutorialHoldCpu)) {
      scheduleCpuResolvePrompt(G.gameState || s, (G.gameState || s).players?.p2);
      armCpuPromptHangWatch(G.gameState || s);
    } else if (!replayForward && !G.isCPU && !G.isSpectator) {
      armPvPWatchdog(G.gameState || s);
    }
    if (G.tutorialLive && typeof global.TutorialInteractive?.onStateApplied === 'function') {
      global.TutorialInteractive.onStateApplied(G.gameState || s, prev);
    }
  };

  global.enqueuePendingState = function enqueuePendingState(s) {
    if (!s || s.seq <= G.lastSeq) return;
    const q = G._pendingStateQueue || (G._pendingStateQueue = []);
    q.push(s);
    q.sort((a, b) => a.seq - b.seq);
  };

  global.holdLivePolls = function holdLivePolls() {
    if (!G._livePollHold) TCG_DEBUG.log('poll', 'holdLivePolls');
    G._livePollHold = true;
  };

  global.releaseLivePolls = function releaseLivePolls() {
    if (!G._livePollHold) return;
    TCG_DEBUG.log('poll', 'releaseLivePolls');
    G._livePollHold = false;
    if (G.polling && !G._perfSpectacleActive && !G.animating) {
      resumePollingTick(120);
    }
  };

  global.releaseLivePollsAndFlush = function releaseLivePollsAndFlush() {
    releaseLivePolls();
    flushPendingState();
    tryFlushSpectacleRecovery();
  };

  global.flushPendingState = function flushPendingState() {
    if (G.animating || isPresentationSuperseded()) return;
    if (typeof global.isReplayViewing === 'function' && global.isReplayViewing() && !G._replayForwardApply) return;
    const q = G._pendingStateQueue;
    if (!q?.length) {
      if (G.syncEnabled && G.syncTicket) scheduleDeferredSyncPull(120);
      return;
    }
    const next = q.shift();
    TCG_DEBUG.log('state', 'flush pending', { seq: next?.seq, remaining: q.length });
    if (next && next.seq > G.lastSeq) {
      const cur = G.gameState;
      if (cur && typeof liveSpectaclePendingForTransition === 'function'
          && liveSpectaclePendingForTransition(cur, next)) {
        TCG_DEBUG.log('state', 'flush pending: spectacle still owed — apply queued state', { seq: next.seq });
      }
      applyStateUpdate(next);
    }
    tryFlushSpectacleRecovery();
  };

  const _origApplyStateUpdate = global.applyStateUpdate;
  if (typeof _origApplyStateUpdate === 'function' && !_origApplyStateUpdate.__stampsHooked) {
    const wrapped = async function applyStateUpdateWithStamps(s) {
      await _origApplyStateUpdate(s);
      global.TCG_STAMPS?.syncGameUi?.(s);
      global.TCG_STAMPS?.onState?.(s);
    };
    wrapped.__stampsHooked = true;
    global.applyStateUpdate = wrapped;
  }

})(window);
