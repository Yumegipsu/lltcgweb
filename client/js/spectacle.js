/**
 * LIVE Performance spectacle — gating, presentLiveRound, yell stack, judge UI.
 * Loaded after the main index.html script so shared helpers (el, enrichCard, etc.) exist.
 * CSS for #perf-spectacle remains in index.html.
 */
'use strict';

function liveStorageHasCards(s) {
  if (!s?.players) return false;
  return (s.players.p1?.live_zone?.length || 0) + (s.players.p2?.live_zone?.length || 0) > 0;
}

function liveZoneLiveCards(s) {
  if (!s?.players) return [];
  const cards = [];
  for (const pid of ['p1', 'p2']) {
    for (const c of s.players[pid]?.live_zone || []) {
      if (isLiveTypeCard(c)) cards.push(c);
    }
  }
  return cards;
}

function liveRoundHasLiveCards(s) {
  return liveZoneLiveCards(s).length > 0;
}

/** Debug card tests seed Live storage during Main for [Always] QA — not a live round yet. */
function debugLiveZonePreseedActive(s) {
  if (!s?.debug_card_test?.live_zone_preseed) return false;
  if (isLiveSetPhase(s.phase) || isLiveSpectaclePipelinePhase(s.phase) || s.phase === 'live_success_effects') {
    return false;
  }
  return isMainOrActivePhase(s.phase);
}

function liveRoundHasLiveCardsForRound(s) {
  if (!s) return false;
  if (debugLiveZonePreseedActive(s)) return false;
  return liveRoundHasLiveCards(s);
}

/** Live cards on board or in the live_set placement baseline (optimistic / batched polls). */
function liveRoundBoardHasLiveCards(s) {
  if (liveRoundHasLiveCardsForRound(s)) return true;
  const baseline = G._liveSetStorageBaseline;
  return !!(baseline && liveRoundHasLiveCardsForRound(baseline));
}

function isLiveSetPhase(ph) {
  return ph === 'live_set' || ph === 'live_set_first' || ph === 'live_set_second';
}

function isLeavingLiveSetPhase(prev, next) {
  return isLiveSetPhase(prev?.phase) && !isLiveSetPhase(next?.phase);
}

/** First poll entering live_set — placement is open but the LIVE Phase splash should still show. */
function isEnteringLiveSetPhase(prev, next) {
  return !!prev && !!next && prev.phase !== next.phase && isLiveSetPhase(next.phase);
}

/** Block phase-transition banners during live_set placement, except the entry splash. */
function phaseBannerBlockedByLivePlacement(prev, s) {
  return liveSetPlacementInProgress(s) && !isEnteringLiveSetPhase(prev, s);
}

/** LIVE placement (entering or staying in live_set) — not performance/judge yet. */
function isLiveSetPlacementOnly(prev, next) {
  return isLiveSetPhase(next?.phase) && !isLeavingLiveSetPhase(prev, next);
}

/** True while live_set is open and at least one player has not locked in yet. */
function liveSetPlacementInProgress(s) {
  if (s?.phase !== 'live_set') return false;
  const ready = s.live_ready;
  if (!ready || typeof ready !== 'object') return false;
  return ready.p1 !== true || ready.p2 !== true;
}

/** True when this LIVE round had actual Live cards played (not empty/member-only skip). */
function liveRoundHadLivesPlayed(prev, next, showTurn = null) {
  if (!prev || !next || G.isTutorial) return false;
  if (isEmptyLiveSkipTransition(prev, next)) return false;
  if (shouldIgnoreStaleLivePerfSignals(prev, next)) return false;
  const turn = showTurn ?? inferLiveShowTurn(prev, next);
  if (newLogHasLivePerformance(prev, next)) return true;
  // Strict on Main: do not treat prior-turn log as this transition's Lives.
  const strict = isMainOrActivePhase(next?.phase) && !isLeavingLiveSetPhase(prev, next)
    && prev?.phase !== 'live_start_effects';
  if (logHasLivePerformanceForShowTurn(prev, next, turn, { strict })) return true;
  if (isLeavingLiveSetPhase(prev, next)
      || prev?.phase === 'live_start_effects'
      || next?.phase === 'live_start_effects') {
    if (liveRoundBoardHasLiveCards(prev) || liveRoundBoardHasLiveCards(next)) return true;
    const deferred = G._deferPerfSpectaclePrev;
    if (deferred && liveRoundHasLiveCardsForRound(deferred)) return true;
  }
  return false;
}

/** Client still owes full Performance spectacle playback for this transition. */
/** Lives were played this round and client playback has not finished yet. */
function liveSpectacleStillPending(prev, next, showTurn = null) {
  if (!prev || !next || G.isTutorial) return false;
  const turn = showTurn ?? inferLiveShowTurn(prev, next);
  if (turn == null) return false;
  if (emptyLiveRoundAlreadyPresented(turn)) return false;
  return liveRoundHadLivesPlayed(prev, next, turn) && !liveSpectacleDoneForTurn(turn);
}

function liveSpectacleOwed(prev, next, showTurn = null) {
  if (!prev || !next || G.isTutorial) return false;
  const turn = showTurn ?? inferLiveShowTurn(prev, next);
  if (turn == null) return false;
  if (!liveRoundHadLivesPlayed(prev, next, turn)) return false;
  if (liveSpectacleDoneForTurn(turn)) return false;
  if (emptyLiveRoundAlreadyPresented(turn)) return false;
  if (isLiveSetPlacementOnly(prev, next) || liveSetPlacementInProgress(next)) return false;
  if (!liveRoundJudgeReady(next)) return false;
  if (pendingPromptBlocksPerfSpectacle(next)) return false;
  return true;
}

/**
 * Like liveSpectacleOwed, but ignores the main→main stale-signal gate.
 * Used to re-arm recovery after a missed show once the server is already on Main.
 * Must NOT re-arm from full-log "performed Live!" alone on ordinary Main→Main polls
 * (that caused stale Performance splash + ghost flips when playing a Member later).
 */
function liveSpectacleStillOwedOnBoard(prev, next, showTurn = null) {
  if (!prev || !next || G.isTutorial) return false;
  const turn = showTurn ?? inferLiveShowTurn(prev, next);
  if (turn == null) return false;
  if (liveSpectacleDoneForTurn(turn)) return false;
  if (emptyLiveRoundAlreadyPresented(turn)) return false;
  if (isLiveSetPlacementOnly(prev, next) || liveSetPlacementInProgress(next)) return false;

  const staleMain = shouldIgnoreStaleLivePerfSignals(prev, next);
  const deferredHasLives = !!(G._deferPerfSpectaclePrev
    && liveRoundHasLiveCardsForRound(G._deferPerfSpectaclePrev));
  const heldHasStorage = !!(G._livePostRevealBoard && liveStorageHasCards(G._livePostRevealBoard));
  const boardHasLives = liveRoundBoardHasLiveCards(prev) || liveRoundBoardHasLiveCards(next);
  const faceDownOpp = !!(next?.players && liveStorageHadFaceDownOppBluff(next, G.playerId));

  if (staleMain) {
    // Ordinary Main→Main (baton / play Member): only recover if local playback residue
    // still holds the missed show — never from historical log lines alone.
    if (!deferredHasLives && !heldHasStorage && !boardHasLives && !faceDownOpp) {
      return false;
    }
  } else if (!logHasLivePerformanceForTurn(next, turn)
      && !boardHasLives
      && !deferredHasLives) {
    return false;
  }

  if (!liveRoundJudgeReady(next)) return false;
  if (pendingPromptBlocksPerfSpectacle(next)) return false;
  return true;
}

/** Block main-phase log/phase banners until spectacle completes or round had no Lives. */
function liveSpectaclePendingForTransition(prev, next) {
  if (!prev || !next || G.isTutorial) return false;
  if (detectPendingLiveSpectacleTurn(prev, next) != null) return true;
  const turn = inferLiveShowTurn(prev, next);
  if (turn == null) return false;
  return liveRoundHadLivesPlayed(prev, next, turn)
    && !liveSpectacleDoneForTurn(turn)
    && !emptyLiveRoundAlreadyPresented(turn);
}

/** Either side set a Live card this round — spectacle is mandatory once performance is owed. */
function liveRoundRequiresSpectacle(prev, next, showTurn = null) {
  if (!prev || !next || G.isTutorial) return false;
  const turn = showTurn ?? inferLiveShowTurn(prev, next);
  if (!liveRoundHadLivesPlayed(prev, next, turn)) return false;
  if (liveSpectacleDoneForTurn(turn)) return false;
  if (isLiveSetPlacementOnly(prev, next)) return false;
  if (pendingPromptBlocksPerfSpectacle(next)) return false;
  // Must be in/through performance (or this poll just logged it) — not merely leaving live_set.
  const ph = next.phase;
  const performanceOwed = liveRoundJudgeReady(next)
    || isLivePerformancePhase(ph)
    || ph === 'live_judge'
    || newLogHasLivePerformance(prev, next);
  if (!performanceOwed) return false;
  if (liveRoundBoardHasLiveCards(prev) || liveRoundBoardHasLiveCards(next)) return true;
  if (newLogHasLivePerformance(prev, next)
      || logHasLivePerformanceForShowTurn(prev, next, turn, { strict: true })) return true;
  if (resolvedPerfSignalsForTransition(prev, next)) return true;
  return false;
}

/*
 * LIVE_ROUND_ORDER — client playback contract when the server batches live_set..main in one poll.
 * 1 LIVE placement (live_set UI, no spectacle)
 * 2 Opponent reveal (runLiveStorageRevealSequence / playLivePhaseTransition)
 * 3 Live Start prompts (live_start_effects; block spectacle until cleared)
 * 3A Performance spectacle (queuePerformancePhaseBanner + runPerformanceSpectacle)
 * 4-5 Yells, outcomes, judge (inside spectacle)
 * 6 Normal mat view (hold live storage; defer turn commit until step 9)
 * 7-8 Post-live prompts (live_success_effects, pick_judge_success_live)
 * 9 Storage to WR / Success (playDeferredLiveStorageOutcomes)
 * 3B No Lives: empty splash, then step 9 for member bluffs only
 * After: Main Phase / turn banners (flushPostLiveLogBanners)
 * Guard: G._liveRoundPlaybackActive blocks main_* and turn-begin banners until step 9 completes.
 */

function isLiveRoundPlaybackActive() {
  return !!G._liveRoundPlaybackActive;
}

function livePlaybackBlocksMainPhaseUi(s, prev) {
  if (isLiveRoundPlaybackActive()) return true;
  if (G._liveStorageOutcomePending) return true;
  if (!prev || !s) return false;
  const ph = s.phase;
  if (ph !== 'main_first' && ph !== 'main_second' && ph !== 'active_first' && ph !== 'active_second') {
    return false;
  }
  if (liveSpectaclePendingForTransition(prev, s)) return true;
  return false;
}

function nudgeCpuAfterStatePresentation(s) {
  if (!G.isCPU || G.animating || G._perfSpectacleActive || G._liveSpectacleGateRunning) return;
  if (G.tutorialLive && G.tutorialHoldCpu) return;
  ensurePollHoldReleased(s || G.gameState);
  const live = s || G.gameState;
  if (!live) return;
  doCPU(live);
  armWatchdog(live);
}

/**
 * True when the CURRENT performance round already logged a performance result.
 * Round-scoped (anchored at the latest Performance Phase / Live Start Effects
 * marker) so an earlier turn's "performed Live!" never suppresses this round's
 * pre-performance Live Start prompt wait.
 */
function currentRoundLogHasPerformance(next) {
  const log = next?.log || [];
  if (!log.length) return false;
  const start = currentPerformanceRoundLogStart(next);
  for (let i = start; i < log.length; i++) {
    if (/ performed Live! Blades: /.test(log[i]?.msg || '')) return true;
  }
  return false;
}

function liveStartPromptNeedsWait(next, myId) {
  if (!next?.pending_prompt) return false;
  const pr = next.pending_prompt;
  if (PERF_SPECTACLE_DEFERRED_PROMPTS.has(pr.type)) return false;
  if (next.phase === 'live_success_effects') return false;
  // Only bail once THIS round has actually performed — pre-performance Live Start
  // skills (e.g. optional [Live Start] choices) must resolve before the spectacle.
  if (currentRoundLogHasPerformance(next)) return false;
  if (next.phase === 'live_start_effects') return true;
  const from = next.log?.length ? Math.max(0, next.log.length - 24) : 0;
  const slice = (next.log || []).slice(from);
  if (!slice.some(e => e.msg === '=== Live Start Effects ===')) return false;
  return pr.responder === myId || pr.responder === (myId === 'p1' ? 'p2' : 'p1');
}

function shouldPullSkillResolutionDirect() {
  return !!(G._liveRoundPlaybackActive || G._livePollHold || G._liveSpectacleGateRunning || G._perfSpectacleActive);
}

async function pullPromptResolutionState() {
  if (shouldPullSkillResolutionDirect() && typeof pullSkillResolutionState === 'function') {
    return pullSkillResolutionState();
  }
  await pullLatestState(true);
  return G.gameState;
}

async function waitForPipelinePromptResolution(myId, opts = {}) {
  const maxMs = opts.maxMs ?? 120000;
  const start = performance.now();
  const oppId = myId === 'p1' ? 'p2' : 'p1';
  const isResolved = opts.isResolved || ((s) => !s?.pending_prompt);
  let stalePulls = 0;
  while (performance.now() - start < maxMs) {
    if (isPresentationSuperseded()) return;
    const cur = G.gameState || opts.targetState;
    if (!cur) return;
    if (cur.status === 'finished') return;
    if (isResolved(cur)) return;
    const pr = cur.pending_prompt;
    if (pr?.type === 'pick_judge_success_live' && pr?.responder === myId) {
      ensurePendingPromptSurfaced(cur, myId);
    }
    if (G.isCPU && pr?.responder === 'p2') {
      doCPU(cur);
      await sleep(200);
      continue;
    }
    const seqBefore = G.lastSeq ?? 0;
    if (pr?.responder === myId && !isMyBlockingPromptOpen(cur)) {
      if (isLiveSuccessDiscardPrompt(cur)) clearLiveSuccessHandDeferral(cur);
      if (isLiveSuccessDiscardPrompt(cur) || pr.type === 'pick_judge_success_live') {
        ensurePendingPromptSurfaced(cur, myId);
      }
      if (!isMyBlockingPromptOpen(cur)) await pullPromptResolutionState();
    } else if (pr?.responder === oppId && !G.isCPU) {
      await pullPromptResolutionState();
      if ((G.lastSeq ?? 0) > seqBefore || G.gameState?.status === 'finished') {
        stalePulls = 0;
        continue;
      }
      if (G._pendingStateQueue?.length) flushPendingState();
    } else {
      // Prompt open / waiting on self UI — do not hammer get_state.
      await sleep(300);
      continue;
    }
    if ((G.lastSeq ?? 0) <= seqBefore) {
      stalePulls += 1;
      // Back off when the server seq is not advancing (stuck prompt / same poll=0 body).
      await sleep(Math.min(2500, 250 + stalePulls * 200));
    } else {
      stalePulls = 0;
      await sleep(250);
    }
  }
}

async function awaitLiveStartPromptsIfNeeded(prev, next, myId) {
  if (!liveStartPromptNeedsWait(next, myId)) return;
  // Round-scoped: only skip the wait if THIS round already performed (whole
  // pipeline resolved in one poll), not because an earlier round performed.
  if (currentRoundLogHasPerformance(next)) return;
  TCG_DEBUG.log('live', 'awaitLiveStartPromptsIfNeeded');
  G._awaitingLiveStartPrompts = true;
  try {
    G.gameState = next;
    renderGame(next, { skipLog: true, skipPrompt: false });
    ensurePendingPromptSurfaced(next, myId);
    await waitForPipelinePromptResolution(myId, {
      targetState: next,
      isResolved: (s) => !s?.pending_prompt || s.phase !== 'live_start_effects',
    });
  } finally {
    G._awaitingLiveStartPrompts = false;
  }
}

/** Batched polls skip live_start_effects — show the Live Start banner before the spectacle. */
async function presentSkippedLiveStartBanners(prev, next, myId) {
  if (!prev || !next) return;
  if (liveStartPromptNeedsWait(next, myId)) return;
  const logFrom = prev.log?.length || 0;
  const slice = (next.log || []).slice(logFrom);
  if (!slice.some(e => e.msg === '=== Live Start Effects ===')) return;
  if (G._liveStartBannerPresentedSeq === (next.seq ?? 0)) return;
  const banner = parseLogToBanner('=== Live Start Effects ===', 'live', next, myId, prev, logFrom);
  if (!banner) return;
  G._liveStartBannerPresentedSeq = next.seq ?? 0;
  queueCenterBanner(banner);
  await waitForBannersIdle();
}

function pickSpectacleStateForPerf(finalNext) {
  const candidates = [finalNext, G._deferredLiveState, G.gameState].filter(Boolean);
  if (!candidates.length) return finalNext;
  const hasPerfFields = (st) => !!(
    st?._live_perf_snapshot || st?.live_perf_success
    || st?._live_round_success_snapshot || st?.live_round_success
    || st?._yell_reveal_snapshot || st?.yell_reveal
  );
  let best = finalNext || candidates[0];
  for (const c of candidates) {
    const cSeq = c.seq ?? 0;
    const bestSeq = best.seq ?? 0;
    if (cSeq > bestSeq) {
      best = c;
      continue;
    }
    if (cSeq === bestSeq && hasPerfFields(c) && !hasPerfFields(best)) best = c;
  }
  return best;
}

async function awaitResolvePostLivePrompts(prev, next, myId, opts = {}) {
  if (!G._liveRoundPostSpectacleReady) return next;
  await playLiveSuccessDeferredEffects(prev, next, myId, opts.newEntries || []);
  clearLiveSuccessHandDeferral(next);
  clearDeferredPromptState();
  const latest = pickLatestStateForPlayback(G.gameState) || pickLatestStateForPlayback(next) || G.gameState || next;
  const pr = latest?.pending_prompt;
  if (!pr) {
    // Do not pass gate-entry `next` — it may still carry a resolved Live Start / Kurage prompt.
    ensurePendingPromptSurfaced(latest, myId);
    return latest;
  }
  const postLive = isPostLiveSkillPrompt(latest);
  if (!postLive) {
    ensurePendingPromptSurfaced(latest, myId);
    return latest;
  }
  TCG_DEBUG.log('live', 'awaitResolvePostLivePrompts', { type: pr.type, phase: latest.phase });
  ensurePendingPromptSurfaced(latest, myId);
  await waitForPipelinePromptResolution(myId, {
    targetState: latest,
    isResolved: (s) => {
      if (!s) return true;
      const ph = s.phase;
      if (ph === 'live_success_effects' || ph === 'live_judge') {
        return !s.pending_prompt;
      }
      if (s.pending_prompt && isPostLiveSkillPrompt(s)) return false;
      return true;
    },
  });
  const settled = pickLatestStateForPlayback(G.gameState) || G.gameState || latest;
  ensurePendingPromptSurfaced(settled, myId);
  return settled;
}

function newLogEntries(prev, next) {
  const from = prev?.log?.length || 0;
  return (next?.log || []).slice(from);
}

function newLogHasLivePerformance(prev, next) {
  return newLogEntries(prev, next).some(e => / performed Live! Blades: /.test(e.msg || ''));
}

function inferLiveShowTurn(prev, next) {
  if (prev && isLiveSetPhase(prev.phase) && prev.turn != null) return prev.turn;
  if (next?.turn != null && prev?.turn != null && next.turn > prev.turn) return prev.turn;
  if (prev?.turn != null) return prev.turn;
  return next?.turn ?? 0;
}

function liveSpectacleDoneForTurn(turn) {
  if (turn == null) return false;
  if (G._perfSpectacleDoneTurns?.has(turn)) return true;
  const doneKey = `${turn}:live_show`;
  if (G._perfSpectacleDoneKey === doneKey) return true;
  try {
    const raw = sessionStorage.getItem(PERF_SPECTACLE_DONE_STORAGE_KEY);
    if (raw) {
      const data = JSON.parse(raw);
      if (data?.roomId === G.roomId) {
        if (Array.isArray(data.keys) && data.keys.includes(doneKey)) return true;
        if (data?.key === doneKey) return true;
      }
    }
  } catch (e) {}
  return false;
}

function liveStorageRevealDoneForTurn(turn) {
  return turn != null && !!G._liveStorageRevealDoneTurns?.has(turn);
}

function markLiveStorageRevealDone(turn) {
  if (turn == null) return;
  if (!G._liveStorageRevealDoneTurns) G._liveStorageRevealDoneTurns = new Set();
  G._liveStorageRevealDoneTurns.add(turn);
}

/** Opponent live-storage flip already scheduled, animating, or face-up on the mat. */
function liveStorageRevealFlipsActive(flipKeys, myId = G.playerId) {
  if ((G._liveStorageRevealAnimCount || 0) > 0) return true;
  if (G._activeCardFlips) return true;
  if (!flipKeys?.size) return false;
  const oppId = myId === 'p1' ? 'p2' : 'p1';
  for (const key of flipKeys) {
    if (G._liveFlipScheduled?.has(key)) return true;
    if (!String(key).startsWith(`${oppId}:`)) continue;
    const iid = String(key).slice(oppId.length + 1);
    for (let i = 0; i < 3; i++) {
      const cardEl = el(`opp-live-${i}`)?.querySelector('.lcard.live-card');
      if (cardEl?.dataset?.iid !== iid) continue;
      if (cardEl.classList.contains('revealed')
          || cardEl.classList.contains('live-storage-flip')) {
        return true;
      }
    }
  }
  return false;
}

/** All opponent flip keys are already face-up in the DOM (animation finished). */
function liveStorageRevealDomComplete(flipKeys, myId = G.playerId) {
  const oppId = myId === 'p1' ? 'p2' : 'p1';
  if (!flipKeys?.size) return true;
  for (const key of flipKeys) {
    if (!String(key).startsWith(`${oppId}:`)) continue;
    const iid = String(key).slice(oppId.length + 1);
    let found = false;
    for (let i = 0; i < 3; i++) {
      const cardEl = el(`opp-live-${i}`)?.querySelector('.lcard.live-card');
      if (cardEl?.dataset?.iid === iid && cardEl.classList.contains('revealed')) {
        found = true;
        break;
      }
    }
    if (!found) return false;
  }
  return true;
}

function candidateLiveShowTurns(prev, next) {
  const turns = new Set();
  const inferred = inferLiveShowTurn(prev, next);
  if (inferred != null) turns.add(inferred);
  if (prev?.turn != null) turns.add(prev.turn);
  if (next?.turn != null) turns.add(next.turn);
  const perfPrev = buildPerfSpectaclePrev(prev, next);
  const dkTurn = parseInt(String(perfSpectacleTurnKey(perfPrev, next)).split(':')[0], 10);
  if (Number.isFinite(dkTurn)) turns.add(dkTurn);
  const undone = scanUndoneLiveSpectacleTurn(next, prev);
  if (undone != null) turns.add(undone);
  return [...turns].filter(t => t >= 1);
}

/** Single show-turn for this transition — avoids treating an earlier round's done-key as current. */
function primaryLiveShowTurn(prev, next) {
  const pending = detectPendingLiveSpectacleTurn(prev, next);
  if (pending != null) return pending;
  return inferLiveShowTurn(prev, next);
}

function spectacleDoneForTransition(prev, next) {
  const turn = primaryLiveShowTurn(prev, next);
  if (turn == null) return false;
  return liveSpectacleDoneForTurn(turn);
}

function parseTurnMarker(msg) {
  if (!msg) return null;
  let m = msg.match(/^--- Turn (\d+) ---$/);
  if (m) return parseInt(m[1], 10);
  m = msg.match(/^=== Turn (\d+) begins ===$/);
  if (m) return parseInt(m[1], 10);
  return null;
}

function turnAtLogIndex(s, idx) {
  let inTurn = 1;
  const log = s?.log || [];
  for (let i = 0; i <= idx && i < log.length; i++) {
    const t = parseTurnMarker(log[i]?.msg);
    if (t != null) inTurn = t;
  }
  return inTurn;
}

function logSliceHasLivePipelineSignals(slice) {
  return (slice || []).some(e => {
    const msg = e?.msg || '';
    return / performed Live! Blades: /.test(msg)
      || / is performing Live with /.test(msg)
      || msg === '=== Performance Phase ==='
      || msg === '=== Live Show ==='
      || msg === '=== Live Win/Loss Check Phase ===';
  });
}

/** Spectacle recovery only during live pipeline transitions — not main-phase baton pass, etc. */
function spectacleRecoveryContext(prev, next) {
  if (!prev || !next || G.isTutorial) return false;
  if (isLiveSetPlacementOnly(prev, next) || liveSetPlacementInProgress(next)) return false;
  const slice = newLogEntries(prev, next);
  if (isLeavingLiveSetPhase(prev, next) && !isEmptyLiveSkipTransition(prev, next)) return true;
  if (logSliceHasLivePipelineSignals(slice) || newLogHasLivePerformance(prev, next)) return true;
  if (isLiveSpectaclePipelinePhase(prev.phase) && isMainOrActivePhase(next.phase)) return true;
  if (isLiveSpectaclePipelinePhase(next.phase) && !isLiveSetPhase(next.phase)) return true;
  // Missed show still owed after Main catch-up (failed gate / abort / batched poll).
  if (isMainOrActivePhase(prev.phase) && isMainOrActivePhase(next.phase)) {
    const showTurn = inferLiveShowTurn(prev, next);
    if (liveSpectacleStillOwedOnBoard(prev, next, showTurn)) return true;
  }
  return false;
}

function scanUndoneLiveSpectacleTurn(s, prev = null) {
  if (!s?.log || G.isTutorial) return null;
  // Stale main→main baton/play polls must not scan — unless recovery context says a show is still owed.
  if (prev && shouldIgnoreStaleLivePerfSignals(prev, s) && !spectacleRecoveryContext(prev, s)) return null;
  // Inline perf signals only — resolvedPerfSignalsForTransition calls this function and must not recurse.
  const perfCarryoverRecovery = prev
    && isMainOrActivePhase(s?.phase)
    && !isLiveSetPlacementOnly(prev, s)
    && resolvedPerfSignalsInline(s)
    && (newLogHasLivePerformance(prev, s)
        || isLeavingLiveSetPhase(prev, s)
        || spectacleRecoveryContext(prev, s));
  if (prev && !spectacleRecoveryContext(prev, s) && !perfCarryoverRecovery) return null;

  if (prev) {
    if (newLogHasLivePerformance(prev, s)) {
      const showTurn = inferLiveShowTurn(prev, s);
      if (showTurn != null && !liveSpectacleDoneForTurn(showTurn)) return showTurn;
    }
    if (isLeavingLiveSetPhase(prev, s) && !isEmptyLiveSkipTransition(prev, s)) {
      const showTurn = inferLiveShowTurn(prev, s);
      if (showTurn != null && !liveSpectacleDoneForTurn(showTurn)
          && logHasLivePerformanceForShowTurn(prev, s, showTurn)) {
        return showTurn;
      }
    }
  }

  const maxTurn = s.turn ?? 99;
  const candidates = new Set();
  if (s.turn != null) { candidates.add(s.turn); candidates.add(s.turn - 1); }
  let inTurn = 1;
  for (const e of s.log) {
    const t = parseTurnMarker(e.msg);
    if (t != null) inTurn = t;
    if (/ performed Live! Blades: /.test(e.msg || '')) {
      candidates.add(inTurn);
      candidates.add(inTurn + 1);
    }
  }
  for (const t of [...candidates].filter(x => x >= 1 && x <= maxTurn && x >= maxTurn - 1).sort((a, b) => b - a)) {
    if (!liveSpectacleDoneForTurn(t) && logHasLivePerformanceForTurn(s, t)) return t;
  }
  return null;
}

function detectPendingLiveSpectacleTurn(prev, next) {
  if (!next || G.isTutorial || G._perfSpectacleActive) return null;
  const ph = next.phase;
  if (ph === 'coin_flip' || ph === 'setup' || ph === 'waiting') return null;
  if (isLiveSetPlacementOnly(prev, next) || liveSetPlacementInProgress(next)) return null;
  if (isEmptyLiveSkipTransition(prev, next)) return null;

  const showTurn = inferLiveShowTurn(prev, next);
  if (showTurn != null && emptyLiveRoundAlreadyPresented(showTurn)) return null;
  const slice = newLogEntries(prev, next);

  // Match done-key turn (state.turn during live_set) — not log --- Turn N --- markers,
  // which lag behind state.turn until after the Live round resolves.
  if (slice.some(e => / performed Live! Blades: /.test(e.msg || ''))) {
    if (!liveSpectacleDoneForTurn(showTurn) && liveRoundHadLivesPlayed(prev, next, showTurn)) {
      return showTurn;
    }
    return null;
  }

  if (isLeavingLiveSetPhase(prev, next) && liveRoundHadLivesPlayed(prev, next, showTurn)
      && !liveSpectacleDoneForTurn(showTurn)) {
    return showTurn;
  }

  // Many Live Start skills resolve across polls; the poll that finally leaves
  // live_start_effects must still arm reveal + Performance (not only leave-live_set).
  if (prev?.phase === 'live_start_effects' && ph !== 'live_start_effects'
      && showTurn != null && !liveSpectacleDoneForTurn(showTurn)) {
    const deferredHasLives = !!(G._deferPerfSpectaclePrev
      && liveRoundHasLiveCardsForRound(G._deferPerfSpectaclePrev));
    if (liveRoundHadLivesPlayed(prev, next, showTurn)
        || liveRoundBoardHasLiveCards(prev)
        || deferredHasLives
        || logHasLivePerformanceForShowTurn(prev, next, showTurn, { strict: true })
        || isLivePerformancePhase(ph)
        || ph === 'live_judge') {
      TCG_DEBUG.log('live', 'pending spectacle turn (leaving live_start_effects)', { showTurn, phase: ph });
      return showTurn;
    }
  }

  // Only scan undone turns when THIS poll carried live-pipeline log signals.
  // Do not re-arm from bare main/active polls (baton / On Enter / play member).
  if (logSliceHasLivePipelineSignals(slice)) {
    const undone = scanUndoneLiveSpectacleTurn(next, prev);
    if (undone != null && liveRoundHadLivesPlayed(prev, next, undone)
        && !liveSpectacleDoneForTurn(undone)
        && (liveSpectacleOwed(prev, next, undone)
            // Live Start skills still open — keep gate armed.
            || next.phase === 'live_start_effects')) {
      return undone;
    }
  }

  return null;
}

/** Clear done-key from the log/state turn mismatch bug so the current round can replay. */
function ensurePerfSpectacleNotStaleDone(prev, next) {
  if (!prev || !next || !newLogHasLivePerformance(prev, next)) return;
  const showTurn = inferLiveShowTurn(prev, next);
  if (showTurn == null || liveSpectacleDoneForTurn(showTurn)) return;
  restorePerfSpectacleDoneKey();
  const doneTurn = parseInt(String(G._perfSpectacleDoneKey || '').split(':')[0], 10);
  if (!Number.isFinite(doneTurn) || doneTurn === showTurn) return;
  TCG_DEBUG.log('live', 'clear stale perf done key', { doneKey: G._perfSpectacleDoneKey, showTurn });
  // Keep reveal-done markers — wiping them re-arms flip CSS on already-revealed boards.
  clearPerfSpectacleDoneKeysOnly();
}

function shouldRecoverMissedLiveSpectacle(prev, next) {
  if (!next || G.isTutorial) return false;
  if (isLiveSetPlacementOnly(prev, next) || liveSetPlacementInProgress(next)) return false;
  if (!spectacleRecoveryContext(prev, next)) return false;
  const showTurn = detectPendingLiveSpectacleTurn(prev, next)
    ?? scanUndoneLiveSpectacleTurn(next, prev)
    ?? inferLiveShowTurn(prev, next);
  if (showTurn == null) return false;
  // Strict on normal transitions; recovery on Main uses the board/log owed check.
  return liveSpectacleOwed(prev, next, showTurn)
    || liveSpectacleStillOwedOnBoard(prev, next, showTurn);
}

function isMainOrActivePhase(ph) {
  return ph === 'main_first' || ph === 'main_second' || ph === 'active_first' || ph === 'active_second';
}

/** Main → main updates (baton, play member) must not treat prior-turn perf snapshots/log as this round. */
function shouldIgnoreStaleLivePerfSignals(prev, next) {
  if (!next || G.isTutorial) return false;
  const slice = newLogEntries(prev, next);
  if (logSliceHasLivePipelineSignals(slice) || newLogHasLivePerformance(prev, next)) {
    return false;
  }
  if (isLeavingLiveSetPhase(prev, next)) return false;
  if (isLiveSpectaclePipelinePhase(next?.phase)) return false;
  if (isLiveSpectaclePipelinePhase(prev?.phase) && isMainOrActivePhase(next?.phase)) {
    return false;
  }
  return isMainOrActivePhase(prev?.phase) && isMainOrActivePhase(next?.phase);
}

function liveStorageFlipPlaybackActive(flipKeys, flipKey) {
  if (flipKeys?.has(flipKey)) return true;
  if (G._liveFlipScheduled?.has(flipKey)) return true;
  if ((G._liveStorageRevealAnimCount || 0) > 0) return true;
  if (G._activeCardFlips) return true;
  if (G._liveRoundPlaybackActive && G._liveRevealFlips?.size) return true;
  if (G._perfSpectacleActive || G._liveSpectacleGateRunning) return true;
  return false;
}

/** Collapse a finished live-storage flip to a static face (or facedown shell). */
function settleLiveStorageFlipCard(cardEl, card) {
  if (!cardEl?.classList.contains('live-storage-flip')) return false;
  card = enrichCard(card);
  const inner = cardEl.querySelector('.live-flip-inner');
  if (card.revealed) {
    if (inner) {
      if (!cardEl.classList.contains('revealed')) cardEl.classList.add('revealed');
      finalizeCardFlip(cardEl);
    } else {
      cardEl.classList.remove('live-storage-flip', 'live-storage-facedown', 'revealed', 'storage-sideways-member');
      cardEl.replaceChildren();
      appendLiveStorageFace(cardEl, card);
      applyCardFoilFx(cardEl, card);
    }
    return true;
  }
  cardEl.classList.remove('live-storage-flip', 'revealed', 'storage-sideways-member');
  cardEl.replaceChildren();
  cardEl.classList.add('live-storage-facedown');
  const shell = document.createElement('div');
  shell.className = 'live-facedown-shell';
  const backFace = document.createElement('div');
  backFace.className = 'live-flip-face live-flip-back';
  appendLiveStorageBack(backFace, card);
  shell.appendChild(backFace);
  cardEl.appendChild(shell);
  return true;
}

function settleStaleLiveStorageFlipCard(cardEl, card, s, flipKeys, flipKey) {
  if (!cardEl?.classList.contains('live-storage-flip')) return false;
  if (liveStorageFlipPlaybackActive(flipKeys, flipKey)) return false;
  if (G._liveStorageOutcomePending && !card?.revealed) return false;
  return settleLiveStorageFlipCard(cardEl, card);
}

/** Remove live-storage flip shells left over after performance / main-phase polls. */
function sweepStaleLiveStorageFlipDom(s, myId = G.playerId) {
  if (!s?.players || G._perfSpectacleActive || G._liveSpectacleGateRunning) return false;
  if (G._liveStorageRevealRunning) return false;
  if (G._liveRoundPlaybackActive && G._liveRevealFlips?.size) return false;
  const flipKeys = G._liveRevealFlips || new Set();
  const oppId = myId === 'p1' ? 'p2' : 'p1';
  let settled = false;
  for (const [prefix, pid] of [['my', myId], ['opp', oppId]]) {
    const zone = s.players[pid]?.live_zone || [];
    for (let i = 0; i < 3; i++) {
      const card = liveZoneCardAtSlot(zone, i);
      const cardEl = el(`${prefix}-live-${i}`)?.querySelector('.lcard.live-card');
      if (!card || !cardEl) continue;
      const flipKey = `${pid}:${card.instance_id}`;
      if (settleStaleLiveStorageFlipCard(cardEl, card, s, flipKeys, flipKey)) settled = true;
    }
    if (settled) layoutLiveSlots(prefix);
  }
  return settled;
}

function clearStaleLiveStorageFlipState(prev, next) {
  if (!next || G._liveRoundPlaybackActive || G._perfSpectacleActive || G._liveSpectacleGateRunning) return;
  if (G._liveStorageRevealRunning) return;
  if (!isMainOrActivePhase(next.phase)) return;
  if (detectPendingLiveSpectacleTurn(prev, next) != null) return;
  // Kill sticky flip keys / ghost boards once Main is stable.
  // Do NOT markLiveStorageRevealDone here — sealing the current turn on Main→Main
  // (before Live Set) made the real pre-Performance flip skip entirely.
  G._liveFlipGen = (G._liveFlipGen || 0) + 1;
  G._liveRevealFlips = new Set();
  G._liveFlipScheduled = new Set();
  G._liveStorageRevealAnimCount = 0;
  G._liveSetStorageBaseline = null;
  if (!G._liveWrDiscardInProgress) {
    G._livePostRevealBoard = null;
    G._liveStorageOutcomePending = false;
  }
  const staleMain = shouldIgnoreStaleLivePerfSignals(prev, next);
  if (staleMain) {
    G._deferPerfSpectaclePrev = null;
    if (!G._livePostRevealBoard) G._liveSetStorageBaseline = null;
  }
  sweepStaleLiveStorageFlipDom(next, G.playerId);
}

/** Main→main polls after a finished Live round must not replay pipeline log banners. */
function shouldSkipStaleLiveLogAnnouncements(prev, next) {
  if (!prev || !next || G.isTutorial) return false;
  if (!isMainOrActivePhase(prev?.phase) || !isMainOrActivePhase(next?.phase)) return false;
  return shouldIgnoreStaleLivePerfSignals(prev, next);
}

function clearStalePerfDeferState(prev, next) {
  if (!next || G._perfSpectacleActive || G._liveSpectacleGateRunning) return;
  if (!isMainOrActivePhase(next.phase)) return;
  if (detectPendingLiveSpectacleTurn(prev, next) != null) return;

  const showTurn = inferLiveShowTurn(prev, next);
  const owed = liveSpectacleStillOwedOnBoard(prev, next, showTurn);
  // Never seal an unplayed show as done, and never drop recovery while still owed.
  if (owed || G._spectacleRecoveryPending) {
    clearStaleLiveStorageFlipState(prev, next);
    if (!G._spectacleRecoveryPending && owed) {
      G._spectacleRecoveryPending = {
        prev,
        s: next,
        newEntries: [],
        myId: G.playerId,
      };
    }
    TCG_DEBUG.log('live', 'stale clear deferred — spectacle still owed', { showTurn });
    return;
  }

  G._deferPerfSpectaclePrev = null;
  G._spectacleRecoveryPending = null;
  clearStaleLiveStorageFlipState(prev, next);
  if (shouldSkipStaleLiveLogAnnouncements(prev, next)) {
    G._announceBaseline = Math.max(G._announceBaseline ?? 0, next.log?.length ?? 0);
  }
  // Drop client-only Live Start / mid-yell prompt residue once Main has no server prompt.
  if (!next.pending_prompt) {
    const def = G._deferredPromptState?.pending_prompt;
    if (def && (def.type === 'spbp5_repeat_mill_blade'
        || def.type === 'auto_yell_mill_extra_yell'
        || def.type === 'auto_yell_no_live_retry'
        || def.type === 'optional_live_start'
        || isLiveSpectaclePipelinePhase(G._deferredPromptState?.phase))) {
      clearDeferredPromptState({ skipBannerRefresh: true });
    }
    if (el('overlay-prompt')?.classList.contains('open')) {
      const livePr = G.gameState?.pending_prompt;
      if (!livePr || livePr.responder !== G.playerId) closeM('overlay-prompt');
    }
  }
}

// --- LIVE round presentation / Performance spectacle ---------------------------
// Client-side replay when server batches live_set -> judge in one poll. Gating via
// runLiveSpectacleGate, liveRoundPresentationPlan, shouldTriggerPerfSpectacle, and
// performanceSpectacleReady; playback in presentLiveRound + playPerformanceSpectacle.

/** True when reveal was marked done but opponent storage is still face-down on the mat or server. */
function shouldResetLiveStorageRevealDone(prev, next, showTurn, myId) {
  if (showTurn == null || !liveStorageRevealDoneForTurn(showTurn)) return false;
  if (!liveSpectacleStillPending(prev, next, showTurn)) return false;
  // Never consult buildLiveRevealPlayback(prev) or face-down playback clones — only held/server boards.
  const held = G._livePostRevealBoard;
  if (held?.players && liveStorageHadFaceDownOppBluff(held, myId)) return true;
  if (next?.players && liveStorageHadFaceDownOppBluff(next, myId)) return true;
  return false;
}

async function runLiveSpectacleGate(prev, s, newEntries, myId) {
  if (isLiveSetPlacementOnly(prev, s) || liveSetPlacementInProgress(s)) return false;
  const gatePrev = effectiveLiveRoundPrev(prev, s);
  const showTurn = detectPendingLiveSpectacleTurn(gatePrev, s)
    ?? detectPendingLiveSpectacleTurn(prev, s);
  if (showTurn == null) return false;
  if (G._liveSpectacleGateRunning) return false;
  let revealDone = liveStorageRevealDoneForTurn(showTurn);
  if (shouldResetLiveStorageRevealDone(gatePrev, s, showTurn, myId)) {
    G._liveStorageRevealDoneTurns?.delete(showTurn);
    revealDone = false;
    TCG_DEBUG.warn('live', 'spectacle gate: reveal-done reset — opponent storage still face-down', { showTurn });
  }
  const spectacleReady = !liveSpectacleDoneForTurn(showTurn)
    && (liveSpectacleOwed(gatePrev, s, showTurn) || shouldTriggerPerfSpectacle(gatePrev, s));
  // Multi Live Start skills: keep presentLiveRound alive so await + post-resolve
  // spectacle/flip can run. Do not bail just because reveal already finished.
  const mustAwaitLiveStart = s.phase === 'live_start_effects'
    || liveStartPromptNeedsWait(s, myId)
    || !!G._awaitingLiveStartPrompts;
  if (revealDone && !spectacleReady && !mustAwaitLiveStart) {
    if (liveSpectacleStillPending(gatePrev, s, showTurn)) {
      G._spectacleRecoveryPending = { prev: gatePrev, s, newEntries, myId };
    }
    TCG_DEBUG.log('live', 'spectacle gate: reveal done, spectacle deferred', { showTurn, phase: s.phase });
    return false;
  }
  G._liveSpectacleGateRunning = true;
  rememberPerfSpectacleBaseline(gatePrev, s);
  const perfPrev = buildPerfSpectaclePrev(gatePrev, s);
  if (perfPrev && showTurn != null) perfPrev.turn = showTurn;
  G.animating = true;
  try {
    holdLivePolls();
    G._postSpectacleSplashPause = false;
    const outcome = await presentLiveRound(perfPrev ?? gatePrev, s, myId, { newEntries, forceSpectacleTurn: showTurn });
    // Prefer post-playback state — gate-entry `s` may still hold a resolved Live Start / Kurage prompt.
    const after = pickLatestStateForPlayback(G.gameState) || pickLatestStateForPlayback(s) || G.gameState || s;
    if (after?.pending_prompt) ensurePendingPromptSurfaced(after, myId);
    if (outcome.spectacle || outcome.empty) return true;
    if (outcome.reveal) markLiveStorageRevealDone(showTurn);
    if (liveSpectacleDoneForTurn(showTurn)) return true;
    if (liveSpectacleStillPending(prev, after, showTurn)) {
      G._spectacleRecoveryPending = { prev, s: after, newEntries, myId };
      TCG_DEBUG.warn('live', 'spectacle gate: still pending after presentLiveRound', { showTurn, outcome });
      return false;
    }
    return true;
  } finally {
    G._liveSpectacleGateRunning = false;
    G.animating = false;
    const after = pickLatestStateForPlayback(G.gameState) || pickLatestStateForPlayback(s) || G.gameState || s;
    if (after?.pending_prompt?.responder === myId) ensurePendingPromptSurfaced(after, myId);
    releaseLivePollsAndFlush();
  }
}

function logHasLivePerformanceForTurn(s, turn) {
  if (!s?.log || turn == null) return false;
  let inTurn = 1;
  for (const e of s.log) {
    const t = parseTurnMarker(e.msg);
    if (t != null) inTurn = t;
    if (inTurn === turn && / performed Live! Blades: /.test(e.msg || '')) return true;
  }
  return false;
}

/**
 * Log turn markers lag state.turn during LIVE — accept showTurn-1 unless { strict: true }.
 * Use strict for gating so an older round's log cannot re-arm spectacle on Main.
 */
function logHasLivePerformanceForShowTurn(prev, next, showTurn, opts = {}) {
  if (showTurn == null || !next?.log) return false;
  if (newLogHasLivePerformance(prev, next) || logTransitionHasLivePerformance(prev, next)) return true;
  if (logHasLivePerformanceForTurn(next, showTurn)) return true;
  if (!opts.strict && showTurn > 1 && logHasLivePerformanceForTurn(next, showTurn - 1)) return true;
  return false;
}

function roundHasLivePerformanceSignals(prev, next) {
  if (isEmptyLiveSkipTransition(prev, next)) return false;
  if (shouldIgnoreStaleLivePerfSignals(prev, next)) return false;
  if (currentRoundHasLivePerformance(prev, next)) return true;
  if (newLogHasLivePerformance(prev, next)) return true;
  const showTurn = inferLiveShowTurn(prev, next);
  if (!liveSpectacleDoneForTurn(showTurn) && logHasLivePerformanceForShowTurn(prev, next, showTurn)) return true;
  return false;
}

// --- Spectacle gating helpers (shouldForce, perf signals, done-key) ------------

function shouldForceLiveSpectacle(prev, next) {
  if (!prev || !next || G.isTutorial || G._perfSpectacleActive) return false;
  if (isLiveSetPlacementOnly(prev, next)) return false;
  if (shouldIgnoreStaleLivePerfSignals(prev, next)) return false;
  if (isEmptyLiveSkipTransition(prev, next)) return false;
  const showTurn = inferLiveShowTurn(prev, next);
  if (liveSpectacleDoneForTurn(showTurn)) return false;
  if (pendingPromptBlocksPerfSpectacle(next)) return false;
  if (!roundHasLivePerformanceSignals(prev, next)) return false;
  if (!perfRoundHasShow(prev, next)) return false;
  if (shouldTriggerPerfSpectacle(prev, next)) return true;
  if (isLeavingLiveSetPhase(prev, next) && performanceSpectacleReady(prev, next)) return true;
  if (newLogHasLivePerformance(prev, next)) return true;
  if (logHasLivePerformanceForShowTurn(prev, next, showTurn)) return true;
  return false;
}

/** Snapshot face-down placements for reveal/WR playback when polls batch updates. */
function stashLiveSetStorageBaseline(s, myId, selectedIds) {
  if (!s?.phase || !isLiveSetPhase(s.phase) || !selectedIds?.length) return;
  const baseline = deepCloneState(s);
  const p = baseline.players?.[myId];
  if (!p) return;
  const live = [...(p.live_zone || [])];
  const hand = [...(p.hand || [])];
  for (const iid of selectedIds) {
    if (live.some(c => c.instance_id === iid)) continue;
    const idx = hand.findIndex(c => c.instance_id === iid);
    if (idx >= 0) {
      live.push({ ...hand[idx], revealed: false, live_slot: live.length });
    }
  }
  if (!live.length) return;
  p.live_zone = live;
  p.hand = hand.filter(c => !selectedIds.includes(c.instance_id));
  G._liveSetStorageBaseline = baseline;
}

/** Keep a full live_set board (both players) for empty-round / batched-poll reveal playback. */
function refreshLiveSetStorageBaseline(s) {
  if (!s?.phase || !isLiveSetPhase(s.phase) || !liveStorageHasCards(s)) return;
  G._liveSetStorageBaseline = G._liveSetStorageBaseline
    ? augmentPerfSpectaclePrev(G._liveSetStorageBaseline, s)
    : deepCloneState(s);
}

/** Optimistic face-down placement for LIVE confirm — updates gameState + baseline for playback. */
function applyOptimisticLiveSetPlacements(s, myId, selectedIds) {
  if (!s?.players?.[myId] || !selectedIds?.length) return;
  const p = s.players[myId];
  const live = [...(p.live_zone || [])];
  let hand = [...(p.hand || [])];
  for (const iid of selectedIds) {
    if (live.some(c => c.instance_id === iid)) continue;
    const idx = hand.findIndex(c => c.instance_id === iid);
    if (idx < 0) continue;
    const card = { ...hand[idx], revealed: false, live_slot: live.length };
    live.push(card);
    hand.splice(idx, 1);
  }
  if (live.length !== (p.live_zone || []).length) {
    p.live_zone = live;
    p.hand = hand;
  }
}

function handToLiveStorageMoves(prev, next, pid = null) {
  return diffCardMoves(prev, next).filter(m => {
    if (m.from?.zone !== 'hand' || m.to?.zone !== 'live') return false;
    if (pid && m.from?.pid !== pid) return false;
    return true;
  });
}

/** Fill live slot indices on hand→live moves from the post-placement board. */
function enrichHandToLiveMoveTargets(next, moves) {
  for (const m of moves) {
    if (m.to?.zone !== 'live' || !m.iid) continue;
    const pid = m.to.pid || m.from?.pid;
    if (!pid || m.to.index != null) continue;
    const zone = next?.players?.[pid]?.live_zone || [];
    const idx = zone.findIndex(c => c.instance_id === m.iid);
    if (idx >= 0) m.to.index = liveZoneSlot(zone[idx], idx);
  }
}

/** Stage invisible live-slot destinations before flight so handoff has no blank gap. */
function primeHandToLiveStorageDestinations(prev, next, myId, moves) {
  if (!moves?.length || !prev?.players || !next?.players) return;
  enrichHandToLiveMoveTargets(next, moves);
  const oppId = myId === 'p1' ? 'p2' : 'p1';
  const zones = {
    [myId]: [...(prev.players[myId]?.live_zone || [])],
    [oppId]: [...(prev.players[oppId]?.live_zone || [])],
  };
  for (const m of moves) {
    const pid = m.from?.pid || m.to?.pid;
    const card = findCardInState(next, m.iid, pid);
    if (!pid || !card || zones[pid].some(c => c.instance_id === m.iid)) continue;
    const slot = m.to?.index != null ? m.to.index : liveZoneFirstEmptySlotClient(zones[pid]);
    if (slot < 0) continue;
    zones[pid].push({ ...card, live_slot: slot });
    if (m.to && m.to.index == null) m.to.index = slot;
  }
  const savedState = G.gameState;
  G.gameState = {
    ...savedState,
    players: {
      ...savedState.players,
      [myId]: { ...savedState.players[myId], live_zone: zones[myId] },
      [oppId]: { ...savedState.players[oppId], live_zone: zones[oppId] },
    },
  };
  renderLiveSlots('my', zones[myId], true, myId);
  renderLiveSlots('opp', zones[oppId], false, oppId);
  G.gameState = savedState;
}

/** Face-down hand → live storage flights (live_set placement, same morph as WR discards). */
async function playHandToLiveStoragePlacements(prev, next, myId, movesOverride = null) {
  const moves = movesOverride || handToLiveStorageMoves(prev, next);
  if (!moves.length) return false;
  const prevRects = collectCardRects();
  const handBefore = collectHandSlotRects();
  captureHandShiftBaselines(moves, myId);
  captureFlightArtClones(moves, myId, prev);
  G._animHideIids = animHideIidsForMoves(prev, moves);
  const deferHand = handLayoutDeferForPlayer(moves, myId);
  const deferOpp = shouldDeferOpponentHandLayout(moves, prev, myId);
  const animPrev = logSyncPlaybackFromPrev(prev, next);
  G.gameState = animPrev;
  renderGame(animPrev, { skipLog: true, skipHand: deferHand, skipOppHand: deferOpp, skipPrompt: true });
  primeHandToLiveStorageDestinations(prev, next, myId, moves);
  layoutLiveSlots('my');
  layoutLiveSlots('opp');
  await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
  const handAfter = (deferHand || deferOpp)
    ? projectHandSlotRects(next, myId)
    : collectHandSlotRects();
  try {
    await Promise.all(moves.map((m, i) => executeCardMoveAnimation(m, {
      myId,
      stateBefore: prev,
      stateAfter: next,
      prevRects,
      handBefore,
      handAfter,
      delayMs: i * LIVE_BLUFF_WR_STAGGER_MS,
      renderState: next,
    })));
  } finally {
    finalizeDeferredHandLayouts(next, myId, { deferMine: deferHand, deferOpp });
    clearHandShiftBaselines();
    G._animHideIids = null;
    clearHandArrivingFlags();
  }
  G.gameState = next;
  return true;
}

function shouldRevealLiveStorageForRound(prev, next, emptyRound = null) {
  if (!prev || !next) return false;
  if (liveSetPlacementInProgress(next)) return false;
  const showTurn = inferLiveShowTurn(prev, next);
  if (liveStorageRevealDoneForTurn(showTurn)) return false;
  const empty = emptyRound ?? isEmptyLiveSkipTransition(prev, next);
  if (empty) {
    // solo-human: own member bluffs were face-up during live_set — skip flip.
    // solo-cpu: opponent bluffs must flip before empty splash / WR.
    if (isSoloPlayerEmptyLiveRound(prev, next)) {
      const scenario = emptyLiveRoundScenario(prev, next);
      if (scenario === 'solo-human') return false;
    }
    const board = buildEmptyLiveWrPlayback(prev, next) || prev;
    return !!(board && liveStorageHasCards(board));
  }
  if (isEmptyLiveSkipTransition(prev, next)) return false;
  if (shouldRunLiveRevealSequence(prev, next)) return true;
  const revealBoard = buildLiveRevealPlayback(prev, next);
  return !!(revealBoard && liveStorageHadFaceDownOppBluff(revealBoard));
}

function isMemberOnlyLiveStorageRound(prev, next) {
  if (!prev || !next) return false;
  if (liveRoundBoardHasLiveCards(prev) || liveRoundBoardHasLiveCards(next)) return false;
  if (liveSetPlacementInProgress(next) || liveSetPlacementInProgress(prev)) {
    if (liveStorageHasCards(prev) || liveStorageHasCards(next)) return false;
    const baseline = G._liveSetStorageBaseline;
    if (baseline && liveStorageHasCards(baseline)) return false;
  }
  if (liveStorageHasCards(prev)) return true;
  const baseline = G._liveSetStorageBaseline;
  if (baseline && liveStorageHasCards(baseline)) return true;
  const bluffBoard = liveStorageBoardForPlayback(prev) || prev;
  return collectLiveBluffDiscards(bluffBoard, next).length > 0;
}

function logTransitionHasEmptyLiveSkip(prev, next) {
  if (!next?.log) return false;
  if (liveSetPlacementInProgress(next)) return false;
  const from = prev?.log?.length || 0;
  const slice = (next.log || []).slice(from);
  if (slice.some(e => e.msg === 'No Lives played this turn.')) return true;
  // Batched poll (log+0): only match empty skip for the current LIVE show turn — not prior turns.
  if (!slice.length) {
    // Stable main-phase polls must not re-trigger empty LIVE presentation from older log lines.
    if (prev && isMainOrActivePhase(prev.phase) && isMainOrActivePhase(next.phase)
        && !isLeavingLiveSetPhase(prev, next)) {
      return false;
    }
    const showTurn = emptyLiveRoundShowTurn(prev, next) ?? inferLiveShowTurn(prev, next);
    const fromLog = emptyLiveRoundTurnFromLog(next);
    if (fromLog != null && showTurn != null && fromLog === showTurn) return true;
    if (showTurn != null && fullLogHasEmptyLiveSkipForTurn(next, showTurn)) return true;
  }
  return false;
}

function emptyLiveRoundShowTurn(prev, next) {
  if (!next?.log) return null;
  const from = prev?.log?.length || 0;
  const slice = (next.log || []).slice(from);
  for (let i = 0; i < slice.length; i++) {
    if (slice[i]?.msg === 'No Lives played this turn.') {
      return turnAtLogIndex(next, from + i);
    }
  }
  // log+0 batched poll: prev may already be main_first turn N+1 — scan full log.
  if (slice.length > 0) return null;
  const fromLog = emptyLiveRoundTurnFromLog(next);
  if (fromLog != null) return fromLog;
  const showTurn = inferLiveShowTurn(prev, next);
  if (showTurn != null && fullLogHasEmptyLiveSkipForTurn(next, showTurn)) return showTurn;
  return null;
}

function emptyLiveRoundAlreadyPresented(turn) {
  if (turn == null) return false;
  return !!G._emptyLiveRoundPresentedTurns?.has(turn);
}

function markEmptyLiveRoundPresented(prev, next, turn = null) {
  const t = turn ?? emptyLiveRoundShowTurn(prev, next) ?? inferLiveShowTurn(prev, next);
  if (!Number.isFinite(t)) return;
  if (!G._emptyLiveRoundPresentedTurns) G._emptyLiveRoundPresentedTurns = new Set();
  G._emptyLiveRoundPresentedTurns.add(t);
  G._liveSetStorageBaseline = null;
  // Empty LIVE skips performance — mark done so later main_second polls do not re-enter spectacle.
  if (!G._perfSpectacleDoneTurns) G._perfSpectacleDoneTurns = new Set();
  G._perfSpectacleDoneTurns.add(t);
  savePerfSpectacleDoneKey(buildPerfSpectaclePrev(prev, next) || prev, next, t);
}

function isEmptyLiveSkipTransition(prev, next) {
  if (!prev || !next) return false;
  if (liveSetPlacementInProgress(next)) return false;
  const showTurn = emptyLiveRoundShowTurn(prev, next);
  const from = prev.log?.length || 0;
  const slice = (next.log || []).slice(from);
  if (slice.some(e => e.msg === 'No Lives played this turn.')) return true;
  if (logTransitionHasLivePerformance(prev, next) || newLogHasLivePerformance(prev, next)) return false;
  if (logSliceHasLivePipelineSignals(slice)) return false;
  if (slice.some(e => (e.msg || '').includes('Remaining Live storage sent to Waiting Room.')
      && (e.anim || []).some(a => a.from === 'live' && a.to === 'waiting_room'))
      && !liveRoundHasLiveCards(prev) && !liveRoundHasLiveCards(next)) {
    return true;
  }
  const memberOnly = isMemberOnlyLiveStorageRound(prev, next);
  const leavingLive = isLeavingLiveSetPhase(prev, next) || leavingEmptyLivePipeline(prev, next);
  // log+0 batched poll: full log has empty skip; zone diff / baseline still owe presentation.
  if (leavingLive && memberOnly && showTurn != null && fullLogHasEmptyLiveSkipForTurn(next, showTurn)) {
    TCG_DEBUG.log('live', 'emptyRound detect', { via: 'log+0+member', showTurn, scenario: emptyLiveRoundScenario(prev, next), ...TCG_DEBUG.trans(prev, next) });
    return true;
  }
  if (!memberOnly) return false;
  if (leavingLive) return true;
  // Batched poll: client missed live_set exit but zone diff shows bluff discards.
  const bluffBoard = liveStorageBoardForPlayback(prev) || prev;
  if (collectLiveBluffDiscards(bluffBoard, next).length > 0) {
    if (!slice.length && fullLogHasEmptyLiveSkip(next)) {
      TCG_DEBUG.log('live', 'emptyRound detect', { via: 'zone+fullLog', scenario: emptyLiveRoundScenario(prev, next), ...TCG_DEBUG.trans(prev, next) });
    }
    return true;
  }
  // Recovery: state already committed (live_zone empty) but log still records empty round.
  if (showTurn != null && !liveRoundBoardHasLiveCards(prev) && !liveRoundBoardHasLiveCards(next)
      && logHasLivePerformanceForTurn(next, showTurn) === false
      && (leavingLive || logTransitionHasEmptyLiveSkip(prev, next))
      && fullLogHasEmptyLiveSkipForTurn(next, showTurn)) {
    return true;
  }
  return false;
}

function shouldPresentEmptyLiveRound(prev, next) {
  if (!prev || !next || G.isTutorial) return false;
  if (liveSetPlacementInProgress(next)) return false;
  const ph = next?.phase;
  if (ph === 'coin_flip' || ph === 'setup' || ph === 'waiting') return false;
  const turn = emptyLiveRoundShowTurn(prev, next) ?? inferLiveShowTurn(prev, next);
  if (emptyLiveRoundAlreadyPresented(turn)) return false;
  // Unrelated main-phase polls (baton, play member, etc.) must not replay empty LIVE rounds.
  if (isMainOrActivePhase(prev?.phase) && isMainOrActivePhase(ph) && !isLeavingLiveSetPhase(prev, next)) {
    const slice = newLogEntries(prev, next);
    if (!slice.some(e => e.msg === 'No Lives played this turn.')
        && !slice.some(e => (e.anim || []).some(a => a.from === 'live' && a.to === 'waiting_room'))) {
      return false;
    }
  }
  if (logTransitionHasEmptyLiveSkip(prev, next)) return true;
  return isEmptyLiveSkipTransition(prev, next);
}

function batchHasEmptyLiveSkipEntries(entries) {
  return (entries || []).some(e => e.msg === 'No Lives played this turn.');
}

function emptyLiveRoundPresentationPending(prev, next) {
  if (!shouldPresentEmptyLiveRound(prev, next)) return false;
  const turn = emptyLiveRoundShowTurn(prev, next) ?? inferLiveShowTurn(prev, next);
  return !emptyLiveRoundAlreadyPresented(turn);
}

function filterEmptyLivePendingWrMoves(prev, moves, next) {
  const suppressLiveWr = isEmptyLiveSkipTransition(prev, next)
    || emptyLiveRoundPresentationPending(prev, next);
  if (!suppressLiveWr) return moves;
  return (moves || []).filter(m => {
    if (m.from?.zone !== 'live' || m.to?.zone !== 'waiting_room') return true;
    const card = enrichCard(m.card);
    return !isLiveTypeCard(card);
  });
}

/** Playback board for member-only empty LIVE — prev may already have cleared live_zone. */
function buildEmptyLiveWrPlayback(prev, next) {
  let stored = liveStorageBoardForPlayback(prev);
  if (stored && prev && liveStorageHasCards(prev)) {
    stored = augmentPerfSpectaclePrev(stored, prev);
  }
  if (stored) return deepCloneState(stored);
  const deferred = G._deferPerfSpectaclePrev;
  if (deferred && liveStorageHasCards(deferred)) return deepCloneState(deferred);
  if (!next?.players) return prev ? deepCloneState(prev) : null;
  const from = prev?.log?.length || 0;
  let anims = (next.log || []).slice(from).flatMap(e => e.anim || [])
    .filter(a => a.from === 'live' && a.to === 'waiting_room' && a.iid && a.pid);
  if (!anims.length) {
    anims = (next.log || []).flatMap(e => e.anim || [])
      .filter(a => a.from === 'live' && a.to === 'waiting_room' && a.iid && a.pid);
  }
  if (!anims.length) return prev ? deepCloneState(prev) : null;
  const synth = deepCloneState(next);
  if (prev) {
    synth.phase = prev.phase;
    synth.turn = prev.turn;
    synth.active_player = prev.active_player;
    synth.log = (prev.log || []).slice();
    revertTurnPrepPlayerZones(synth, prev);
  } else {
    synth.phase = 'live_set';
  }
  for (const spec of anims) {
    const pid = spec.pid;
    const card = findCardInState(next, spec.iid, pid);
    if (!card || isLiveTypeCard(card)) continue;
    const p = synth.players[pid];
    if (!p) continue;
    p.waiting_room = (p.waiting_room || []).filter(c => c.instance_id !== spec.iid);
    const live = (p.live_zone || []).filter(c => c.instance_id !== spec.iid);
    live.push({ ...card, revealed: true });
    p.live_zone = live;
  }
  return liveStorageHasCards(synth) ? synth : (prev ? deepCloneState(prev) : null);
}

function shouldAnimateEmptyLiveStorageWr(prev, next) {
  if (!isEmptyLiveSkipTransition(prev, next)) return false;
  if (collectLiveBluffDiscards(prev, next).length > 0) return true;
  const wrFrom = buildEmptyLiveWrPlayback(prev, next);
  return !!(wrFrom && collectLiveBluffDiscards(wrFrom, next).length);
}

function queueMainPhaseBannerAfterEmptyLiveSkip(next, myId) {
  if (!next || !isMainOrActivePhase(next.phase)) return;
  if (shouldSkipPhaseBanner(next.phase, next)) return;
  if (phaseBannerAlreadyShown(next.phase, next)) return;
  const copy = phaseBannerCopy(next.phase, next, myId);
  if (!copy?.title) return;
  markPhaseBannerShown(next.phase, next);
  queueCenterBanner(centerBannerForPhase(next.phase, copy));
}

async function queueEmptyLiveRoundBanner() {
  queueCenterBanner(splashBanner({
    titleKey: 'splash.noLives',
    subtitleKey: null,
    kind: 'phase',
    duration: 1400,
  }));
  await waitForBannersIdle();
}

function clearEmptyLiveRoundPerfState() {
  G._perfYellRevealCache = null;
  G._deferPerfSpectaclePrev = null;
}

/** Drop LIVE-round playback scratch state so the next round cannot inherit stale boards. */
function clearLiveRoundTransientCaches() {
  G._liveSetStorageBaseline = null;
  G._livePostRevealBoard = null;
  G._liveStorageOutcomePending = false;
  G._perfSpectaclePrevForDraws = null;
  G._yellPerfDeferredDrawIids = null;
  G._perfYellScoreAccum = null;
  G._perfYellDrawPending = null;
  // Abort any banner-/image-delayed storage flips from this round.
  G._liveFlipGen = (G._liveFlipGen || 0) + 1;
  G._liveRevealFlips = new Set();
  G._liveFlipScheduled = new Set();
  G._liveStorageRevealAnimCount = 0;
  G._liveStorageRevealRunning = false;
}

/** True when live-storage flip CSS must not start (stable Main after reveal, etc.). */
function shouldSuppressLiveStorageFlipsNow(s = G.gameState) {
  // Only runLiveStorageRevealSequence may arm/start flip CSS. Sticky flip keys alone
  // must never keep flips alive into Main / Live Start / Success — that caused
  // random re-flips on both players' live storage during later phases.
  if (G._liveStorageRevealRunning) return false;
  // Let an already-started flip CSS transition finish (count bumped after .revealed).
  if ((G._liveStorageRevealAnimCount || 0) > 0) return false;
  return true;
}

/** Turn of the most recent unreplayed empty LIVE skip in the full log (log+0 batched polls). */
function emptyLiveRoundTurnFromLog(s, excludePresented = true) {
  if (!s?.log?.length) return null;
  let inTurn = 1;
  let last = null;
  for (let i = 0; i < s.log.length; i++) {
    const t = parseTurnMarker(s.log[i]?.msg);
    if (t != null) inTurn = t;
    if (s.log[i]?.msg !== 'No Lives played this turn.') continue;
    if (excludePresented && emptyLiveRoundAlreadyPresented(inTurn)) continue;
    last = inTurn;
  }
  return last;
}

/** Classify member-only empty LIVE for debug / routing (solo-human, solo-cpu, both-members). */
function emptyLiveRoundScenario(prev, next) {
  if (!prev || !next) return null;
  if (!isMemberOnlyLiveStorageRound(prev, next)) return null;
  if (!shouldPresentEmptyLiveRound(prev, next) && !isEmptyLiveSkipTransition(prev, next)) return null;
  if (!isSoloPlayerEmptyLiveRound(prev, next)) return 'both-members';
  const board = effectiveEmptyLiveRoundPrev(prev, next) || liveStorageBoardForPlayback(prev) || prev;
  const myId = G.playerId || 'p1';
  const mine = (board?.players?.[myId]?.live_zone?.length || 0) > 0;
  return mine ? 'solo-human' : 'solo-cpu';
}

/** Prompts deferred until after judge/performance spectacle completes. */
const PERF_SPECTACLE_DEFERRED_PROMPTS = new Set([
  'pick_judge_success_live',
]);

/** Prompts that pause spectacle after yell_opp, before live outcomes. */
const PERF_SPECTACLE_MID_PROMPTS = new Set([
  'auto_yell_no_live_retry',
  'auto_yell_mill_extra_yell',
]);

function isMidSpectacleYellRetryPrompt(s) {
  return PERF_SPECTACLE_MID_PROMPTS.has(s?.pending_prompt?.type);
}

function pendingPromptBlocksPerfSpectacle(next) {
  const pr = next?.pending_prompt;
  if (!pr) return false;
  if (PERF_SPECTACLE_DEFERRED_PROMPTS.has(pr.type)) return false;
  if (PERF_SPECTACLE_MID_PROMPTS.has(pr.type)) return false;
  return true;
}

function shouldSuppressLivePipelineBanner(msg, prev, next, logFrom) {
  if (!msg || msg === 'No Lives played this turn.') return false;
  if (!isLivePipelineLogBanner(msg) && msg !== '=== Performance Phase ==='
      && msg !== '=== Live Start Effects ===' && msg !== '=== Live Win/Loss Check Phase ===') {
    return false;
  }
  if (isEmptyLiveSkipTransition(prev, next)) return true;
  const slice = (next?.log || []).slice(logFrom ?? 0);
  if (slice.some(e => e.msg === 'No Lives played this turn.')) return true;
  return false;
}

function logTransitionHasLivePerformance(prev, next) {
  const from = prev?.log?.length || 0;
  return (next?.log || []).slice(from).some(e => / performed Live! Blades: /.test(e.msg || ''));
}

function playerHadLivePerformanceForTurn(next, pid, turn) {
  if (!next?.players?.[pid] || turn == null) return false;
  if (!logHasLivePerformanceForTurn(next, turn)) return false;
  const name = next.players[pid].name || '';
  if (!name) return false;
  let inTurn = 1;
  for (const e of next.log || []) {
    const t = parseTurnMarker(e.msg);
    if (t != null) inTurn = t;
    if (inTurn !== turn) continue;
    const msg = e.msg || '';
    if (msg.startsWith(name) && (msg.includes(' performed Live! Blades: ') || msg.includes(' has no valid Live cards'))) {
      return true;
    }
  }
  return false;
}

function playerHasLiveInStorage(state, pid) {
  if (!state?.players?.[pid]) return false;
  return (state.players[pid].live_zone || []).some(c => isLiveTypeCard(c));
}

function playerHadLivePerformance(next, pid, prev = null, showTurn = null) {
  if (!next?.players?.[pid]) return false;
  if (isLiveSetPlacementOnly(prev, next)) return false;
  const turn = showTurn ?? (prev ? inferLiveShowTurn(prev, next) : null);
  if (turn != null && logHasLivePerformanceForTurn(next, turn)) {
    return playerHadLivePerformanceForTurn(next, pid, turn);
  }
  if (!shouldIgnoreStaleLivePerfSignals(prev, next)) {
    if (playerHasLiveInStorage(next, pid) || playerHasLiveInStorage(prev, pid)
        || playerHasLiveInStorage(G._deferPerfSpectaclePrev, pid)) {
      return true;
    }
  }
  const ids = perfLivePerfSuccessIds(next, pid);
  if (ids?.size) return true;
  if (prev && perfLiveSuccessCountFromLog(next, pid, prev) > 0) return true;
  if ((perfYellRevealInline(next)?.[pid]?.length || 0) > 0) return true;
  if (!prev) return false;
  const name = next.players[pid].name || '';
  if (!name) return false;
  const log = (next.log || []).slice(prev.log?.length || 0);
  return log.some(e => {
    const msg = e.msg || '';
    return msg.startsWith(name) && (msg.includes(' performed Live! Blades: ') || msg.includes(' has no valid Live cards'));
  });
}

function collectPerfRoundLiveCards(next, pid, prev = null, showTurn = null) {
  if (!next?.players?.[pid]) return [];
  if (isLiveSetPlacementOnly(prev, next)) return [];
  const turn = showTurn ?? inferLiveShowTurn(prev, next);
  if (!playerHadLivePerformance(next, pid, prev, turn)) return [];
  const deferred = G._deferPerfSpectaclePrev;
  const okIds = perfLivePerfSuccessIds(next, pid);
  const yellIds = new Set((perfYellRevealInline(next)?.[pid] || []).map(c => c?.instance_id).filter(Boolean));
  const byId = new Map();
  const add = (c) => {
    if (!c?.instance_id) return;
    if (!isLiveTypeCard(c)) return;
    if (!byId.has(c.instance_id)) byId.set(c.instance_id, enrichCard(c));
  };
  if (deferred?.players?.[pid]) {
    (deferred.players[pid].live_zone || []).forEach(add);
  }
  (next.players[pid].live_zone || []).forEach(add);
  // WR / success when this round's Live iids are known, or log confirms performance.
  if (okIds?.size) {
  (next.players[pid].success_lives || []).forEach(add);
  (next.players[pid].waiting_room || []).forEach(add);
  } else {
    const performed = playerHadLivePerformanceForTurn(next, pid, turn);
    const logSuccess = perfLiveSuccessCountFromLog(next, pid, prev);
    if (performed && (logSuccess > 0 || playerLiveRoundSucceeded(next, pid))) {
      (next.players[pid].success_lives || []).forEach(add);
      (next.players[pid].waiting_room || []).forEach(add);
    }
  }
  let cards = [...byId.values()];
  if (okIds?.size) {
    cards = cards.filter(c => okIds.has(c.instance_id) || yellIds.has(c.instance_id));
  }
  return clampLiveZoneCards(cards);
}

function synthesizePerfPrevFromNext(prev, next, showTurn = null) {
  if (!next?.players) return null;
  const turn = showTurn ?? inferLiveShowTurn(prev, next);
  const base = deepCloneState(prev || next);
  base.players = base.players || {};
  let any = false;
  for (const pid of ['p1', 'p2']) {
    const liveCards = collectPerfRoundLiveCards(next, pid, prev, turn);
    if (!liveCards.length) continue;
    any = true;
    base.players[pid] = base.players[pid] || { ...next.players[pid] };
    base.players[pid].live_zone = liveCards.map(c => ({ ...c, revealed: false }));
  }
  if (!any) return null;
  // Synthesized replay baseline must be live_set even when CPU batched main → judge in one poll.
  base.phase = 'live_set';
  base.turn = turn;
  return base;
}

function resolvedPerfSignalsInline(next) {
  if (!next) return false;
  const lps = next.live_perf_success;
  if ((lps?.p1?.length || 0) + (lps?.p2?.length || 0) > 0) return true;
  const snap = next._live_perf_snapshot;
  if ((snap?.p1?.length || 0) + (snap?.p2?.length || 0) > 0) return true;
  const yr = perfYellRevealInline(next);
  return !!yr && ((yr.p1?.length || 0) + (yr.p2?.length || 0) > 0);
}

/** Post-judge carry-over counts only for spectacle recovery — never during LIVE placement. */
function resolvedPerfSignalsForTransition(prev, next) {
  if (!resolvedPerfSignalsInline(next)) return false;
  if (isEmptyLiveSkipTransition(prev, next)) return false;
  if (isLiveSetPlacementOnly(prev, next)) return false;
  if (shouldIgnoreStaleLivePerfSignals(prev, next)) return false;
  if (isMainOrActivePhase(next?.phase)) {
    const undone = scanUndoneLiveSpectacleTurn(next, prev);
    return undone != null && !liveSpectacleDoneForTurn(undone);
  }
  return true;
}

/** Includes client yell cache — for spectacle playback after state advances, not round gating. */
function resolvedPerfSignals(next) {
  if (resolvedPerfSignalsInline(next)) return true;
  const cached = G._perfYellRevealCache;
  return !!cached && ((cached.p1?.length || 0) + (cached.p2?.length || 0) > 0);
}

function currentRoundHasLivePerformance(prev, next) {
  if (!next) return false;
  if (isEmptyLiveSkipTransition(prev, next)) return false;
  if (shouldIgnoreStaleLivePerfSignals(prev, next)) {
    return logTransitionHasLivePerformance(prev, next);
  }
  if (!isLiveSetPlacementOnly(prev, next)) {
    if (liveRoundHasLiveCardsForRound(prev) || liveRoundHasLiveCardsForRound(next)) return true;
  } else if (isLeavingLiveSetPhase(prev, next) && liveRoundHasLiveCardsForRound(prev)) {
    return true;
  }
  if (logTransitionHasLivePerformance(prev, next)) return true;
  return resolvedPerfSignalsForTransition(prev, next);
}

function perfRoundHasShow(prev, next) {
  if (isLiveSetPlacementOnly(prev, next) || liveSetPlacementInProgress(next)) return false;
  if (currentRoundHasLivePerformance(prev, next)) return true;
  const showTurn = inferLiveShowTurn(prev, next);
  if (logHasLivePerformanceForShowTurn(prev, next, showTurn)) return true;
  if (spectacleRecoveryContext(prev, next) && scanUndoneLiveSpectacleTurn(next, prev) != null) return true;
  const ph = next?.phase;
  if (isLiveSetPhase(ph)) return false;
  return false;
}

function shouldSkipPhaseBanner(phase, s) {
  if (phase === 'coin_flip' || phase === 'setup') return true;
  if (phase === 'active_first' || phase === 'active_second') return true;
  if (['live_start_effects', 'live_success_effects', 'live_judge'].includes(phase)) return true;
  if (phase === 'live_set' || phase === 'live_set_first' || phase === 'live_set_second') return false;
  if (phase === 'main_first' || phase === 'main_second') return false;
  if (phase === 'live_performance_first' || phase === 'live_performance_second') {
    return !liveRoundHasLiveCards(s);
  }
  return false;
}

/** Dedupe key for phase splashes (esp. Main) so replay cannot re-fire the same entry. */
function phaseBannerDedupeKey(phase, s) {
  if (!phase || !s) return null;
  if (phase !== 'main_first' && phase !== 'main_second'
      && phase !== 'live_set' && phase !== 'live_set_first' && phase !== 'live_set_second'
      && phase !== 'live_performance_first' && phase !== 'live_performance_second') {
    return null;
  }
  return `${s.turn || 0}:${phase}:${s.active_player || ''}`;
}

function clearPhaseBannerShownKeys() {
  G._phaseBannerShownKeys = new Set();
}

function markPhaseBannerShown(phase, s) {
  const key = phaseBannerDedupeKey(phase, s);
  if (!key) return;
  if (!G._phaseBannerShownKeys) G._phaseBannerShownKeys = new Set();
  G._phaseBannerShownKeys.add(key);
}

function markPhaseBannerShownForState(s) {
  if (!s?.phase) return;
  markPhaseBannerShown(s.phase, s);
}

function phaseBannerAlreadyShown(phase, s) {
  const key = phaseBannerDedupeKey(phase, s);
  if (!key) return false;
  return !!G._phaseBannerShownKeys?.has(key);
}

function phaseBannerAlreadyQueued(phase, copy) {
  const titleKey = copy?.titleKey;
  const title = copy?.title;
  return (G._bannerQueue || []).some((b) => {
    if (titleKey && b.titleKey === titleKey) return true;
    if (title && b.title === title) return true;
    return false;
  });
}

function hPhase(p){
  const key = 'phaseId.' + p;
  const val = t(key);
  return val !== key ? val : p;
}

function formatSidebarInfoHtml(s) {
  const ap = s.players[s.active_player];
  const fp = s.players[s.first_player];
  const turn = s.turn || 1;
  const phase = hPhase(s.phase);
  const active = ap?.name || '—';
  const first = fp?.name || '—';
  return t('game.sidebarInfo', {
    turn: `<span class="turn-k">${escapeHtmlText(t('splash.turn', { turn }))}</span>`,
    phase: `<b class="ap">${escapeHtmlText(phase)}</b>`,
    active: `<b>${escapeHtmlText(active)}</b>`,
    first: `<b>${escapeHtmlText(first)}</b>`,
  });
}

function localizeBannerSpec(spec) {
  if (!spec) return spec;
  const out = { ...spec };
  if (spec.titleKey) out.title = t(spec.titleKey, spec.titleVars);
  if (spec.subtitleKey) out.subtitle = t(spec.subtitleKey, spec.subtitleVars);
  if (spec.detailKey) out.detail = t(spec.detailKey, spec.detailVars);
  return out;
}

function splashBanner(opts) {
  return localizeBannerSpec(opts);
}

function splashPerformanceTitle() {
  return t('phaseBanner.performanceTitle');
}

function splashLivePhaseTitle() {
  return t('phaseBanner.liveTitle');
}

function isSplashPerformanceTitle(title) {
  return (title || '').trim() === splashPerformanceTitle();
}

function isSplashLivePhaseTitle(title) {
  return (title || '').trim() === splashLivePhaseTitle();
}

function isSplashTurnTitle(title) {
  const trimmed = (title || '').trim();
  if (!trimmed) return false;
  return /^Turn \d+$/.test(trimmed) || /^ターン\s*\d+$/.test(trimmed) || /^Turno \d+$/.test(trimmed);
}

function parseLivePhaseLogPlayerName(msg) {
  if (!msg) return null;
  const m = msg.match(/^(.+?)(?:'s|') Live Phase\.$/);
  return m ? m[1] : null;
}

function liveJudgeScoreDetail(sc1, sc2, n1, n2, s, myId, prev) {
  const iWin = sc1 > sc2 ? playerNameMatches(n1, s, myId) : sc2 > sc1 ? playerNameMatches(n2, s, myId) : false;
  const iLose = sc1 < sc2 ? playerNameMatches(n1, s, myId) : sc2 < sc1 ? playerNameMatches(n2, s, myId) : false;
  if (sc1 === sc2 && sc1 > 0) {
    const myBefore = (prev?.players?.[myId]?.success_lives || []).length;
    const oppId = myId === 'p1' ? 'p2' : 'p1';
    const oppBefore = (prev?.players?.[oppId]?.success_lives || []).length;
    if (myBefore >= 2 && oppBefore >= 2) return t('splash.liveJudgeTieCappedBoth');
    if (myBefore >= 2) {
      return iWin ? t('splash.liveJudgeTieYouCappedWin') : t('splash.liveJudgeTieOppEarns');
    }
    if (oppBefore >= 2) {
      return iWin ? t('splash.liveJudgeTieYouEarns') : t('splash.liveJudgeTieOppCappedWin');
    }
    return t('splash.liveJudgeTieBothSucceed');
  }
  const winName = sc1 > sc2 ? n1 : n2;
  if (iWin) return t('splash.liveJudgeYouWin');
  if (iLose) return t('splash.liveJudgeOppWin');
  return t('splash.liveJudgeNamedWin', { name: winName });
}

function isSplashTurnBanner(spec) {
  return spec?.titleKey === 'splash.turn' || isSplashTurnTitle(spec?.title);
}

function isSplashPerformanceBanner(spec) {
  return spec?.titleKey === 'phaseBanner.performanceTitle' || isSplashPerformanceTitle(spec?.title);
}

function refreshCenterBannerI18n() {
  const root = el('center-banner');
  if (!root?.classList.contains('show') || !G._lastBannerSpec) return;
  const loc = localizeBannerSpec(G._lastBannerSpec);
  setSplashTitle(el('cb-title'), loc.title || '');
  el('cb-sub').textContent = loc.subtitle || '';
  const det = el('cb-detail');
  if (loc.detail) {
    det.textContent = loc.detail;
    det.style.display = '';
  } else {
    det.textContent = '';
    det.style.display = 'none';
  }
}

function refreshBannerQueueI18n() {
  if (!G._bannerQueue?.length) return;
  G._bannerQueue = G._bannerQueue.map(b => localizeBannerSpec(b));
}

function phaseBannerMainTitle(activePid, myId, activeName) {
  const isMe = !G.isSpectator && activePid === (myId || G.playerId);
  if (isMe) return t('phaseBanner.yourMain');
  const name = activeName || '—';
  const last = name.slice(-1);
  const bannerKey = (last === 's' || last === 'S') ? 'phaseBanner.theirMainS' : 'phaseBanner.theirMain';
  return t(bannerKey, { name });
}

function phaseBannerLiveTitle(activePid, myId, activeName) {
  // Spectators are not a seat — never use "Your …" even when viewing that player's POV.
  const isMe = !G.isSpectator && activePid === (myId || G.playerId);
  if (isMe) return t('phaseBanner.yourLive');
  const name = activeName || '—';
  if (name === '—') return t('phaseBanner.liveTitle');
  const last = name.slice(-1);
  const bannerKey = (last === 's' || last === 'S') ? 'phaseBanner.theirLiveS' : 'phaseBanner.theirLive';
  return t(bannerKey, { name });
}

function livePhaseTitleForState(s, myId) {
  const activePid = s?.active_player;
  return phaseBannerLiveTitle(activePid, myId || G.playerId, s?.players?.[activePid]?.name || '');
}

function livePhaseMessage(title, text) {
  return String(text || '')
    .replace(/^LIVE Phase\s+—\s+/, `${title} — `)
    .replace(/^ライブフェイズ\s*—\s*/, `${title} — `)
    .replace(/^Fase Live\s+—\s+/, `${title} — `);
}

function phaseBarOppMainText(name) {
  if (!name) return t('phaseMsg.mainOpp', { name: '—' });
  const last = name.slice(-1);
  const key = (last === 's' || last === 'S') ? 'phaseMsg.mainOppS' : 'phaseMsg.mainOpp';
  return t(key, { name });
}

function isActiveGameplay(s) {
  const st = s?.status;
  return !!st && st !== 'waiting' && st !== 'ready' && st !== 'finished';
}

/** Allow Performance spectacle when the match ends on this Live round (3rd Success Live). */
function isSpectacleEligibleGameplay(next, prev) {
  if (isActiveGameplay(next)) return true;
  if (next?.status !== 'finished' || !prev) return false;
  if (!perfRoundHasShow(prev, next)
      && !currentRoundHasLivePerformance(prev, next)
      && !logTransitionHasLivePerformance(prev, next)
      && !liveRoundHasLiveCards(prev)) {
    return false;
  }
  const perfPrev = buildPerfSpectaclePrev(prev, next);
  if (G._perfSpectacleDoneKey === perfSpectacleTurnKey(perfPrev, next)) return false;
  return true;
}

function possessiveName(name) {
  if (!name) return '';
  const last = name.slice(-1);
  if (last === 's' || last === 'S') return `${name}'`;
  return `${name}'s`;
}

function phaseBannerCopy(phase, s, myId) {
  const activePid = s?.active_player;
  const active = s?.players?.[activePid]?.name || '—';
  const isMe = !G.isSpectator && activePid === (myId || G.playerId);
  const last = active.slice(-1);
  const theirMainKey = (last === 's' || last === 'S') ? 'phaseBanner.theirMainS' : 'phaseBanner.theirMain';
  const mainEntry = isMe
    ? { titleKey: 'phaseBanner.yourMain' }
    : { titleKey: theirMainKey, titleVars: { name: active } };
  const theirLiveKey = (last === 's' || last === 'S') ? 'phaseBanner.theirLiveS' : 'phaseBanner.theirLive';
  const liveEntry = isMe
    ? { titleKey: 'phaseBanner.yourLive' }
    : { titleKey: theirLiveKey, titleVars: { name: active } };
  const map = {
    coin_flip: { titleKey: 'phaseBanner.coinFlipTitle', subtitleKey: 'phaseBanner.coinFlipSub' },
    setup: { titleKey: 'phaseBanner.setupTitle', subtitleKey: 'phaseBanner.setupSub' },
    active_first: { titleKey: 'phaseBanner.activeTitle', subtitleKey: 'phaseBanner.activeSub' },
    active_second: { titleKey: 'phaseBanner.activeTitle', subtitleKey: 'phaseBanner.activeSub' },
    main_first: { ...mainEntry, subtitleKey: null },
    main_second: { ...mainEntry, subtitleKey: null },
    live_set: { ...liveEntry, subtitleKey: 'phaseBanner.liveSub' },
    live_set_first: { ...liveEntry, subtitleKey: 'phaseBanner.liveSub' },
    live_set_second: { ...liveEntry, subtitleKey: 'phaseBanner.liveSub' },
    live_start_effects: { titleKey: 'phaseBanner.liveStartTitle', subtitleKey: 'phaseBanner.liveStartSub' },
    live_success_effects: { titleKey: 'phaseBanner.liveSuccessTitle', subtitleKey: 'phaseBanner.liveSuccessSub' },
    live_performance_first: { titleKey: 'phaseBanner.performanceTitle', subtitleKey: 'phaseBanner.performanceSub' },
    live_performance_second: { titleKey: 'phaseBanner.performanceTitle', subtitleKey: 'phaseBanner.performanceSub' },
    live_judge: { titleKey: 'phaseBanner.liveJudgeTitle', subtitleKey: 'phaseBanner.liveJudgeSub' },
  };
  const entry = map[phase];
  if (!entry) return splashBanner({ title: hPhase(phase), subtitle: '' });
  return splashBanner(entry);
}

function playerNameMatches(name, s, myId) {
  const me = s?.players?.[myId];
  return name === me?.name || name === s?.players?.[myId]?.name;
}

function isLivePipelineLogBanner(msg) {
  if (!msg) return false;
  if (msg === '=== Performance Phase ===') return true;
  if (msg === '=== Live Start Effects ===') return true;
  if (msg === '=== Live Win/Loss Check Phase ===') return true;
  if (msg === '=== LIVE Phase ===') return true;
  if (/attempts the Live performance!/.test(msg)) return true;
  if (/ performed Live! Blades: /.test(msg)) return true;
  return false;
}

function isPostLivePipelineLogBanner(msg) {
  return /^=== Turn \d+ begins ===$/.test(msg || '');
}

function shouldDeferLogBannerDuringLivePlayback(msg) {
  return isLivePipelineLogBanner(msg) || isPostLivePipelineLogBanner(msg);
}

function flushPostLiveLogBanners(prev, next, myId, opts = {}) {
  if (!next || (!isActiveGameplay(next) && next.status !== 'finished')) return;
  if (isLiveRoundPlaybackActive() || liveSetPlacementInProgress(next)) return;
  const from = prev?.log?.length || 0;
  let queuedTurnBegin = false;
  for (const entry of (next.log || []).slice(from)) {
    if (opts.emptySkip && shouldSuppressLivePipelineBanner(entry.msg, prev, next, from)) continue;
    if (!isPostLivePipelineLogBanner(entry.msg)) continue;
    const banner = parseLogToBanner(entry.msg, entry.kind, next, myId, prev, from);
    if (banner) {
      queueCenterBanner(banner);
      queuedTurnBegin = true;
    }
  }
  if (prev && prev.phase !== next.phase && !leavingEmptyLivePipeline(prev, next)) {
    if (livePlaybackBlocksMainPhaseUi(next, prev)) return;
    if (!(queuedTurnBegin && liveRoundSpectacleDone(prev, next))) {
      showPhaseTransitionBanner(next, myId, prev);
    }
  }
}

function parseLogToBanner(msg, kind, s, myId, prev = null, logFrom = 0) {
  if (!msg) return null;
  const gameplayOrFinalRound = isActiveGameplay(s)
    || (s?.status === 'finished' && (resolvedPerfSignals(s) || logTransitionHasLivePerformance(prev, s)));
  if (!gameplayOrFinalRound) return null;
  if (prev && shouldSuppressLivePipelineBanner(msg, prev, s, logFrom)) return null;
  if (shouldSuppressPostSpectacleSplash(msg, null, prev, s)) return null;
  const me = s.players?.[myId];
  const oppId = myId === 'p1' ? 'p2' : 'p1';

  let m;
  if ((m = msg.match(/^=== Turn (\d+) begins ===$/))) {
    return splashBanner({
      titleKey: 'splash.turn',
      titleVars: { turn: m[1] },
      subtitleKey: null,
      kind: 'phase',
      duration: PHASE_BANNER_MS,
    });
  }
  if (/— Active Phase:|— Energy Phase:|— Draw Phase\./.test(msg)) return null;
  if ((m = msg.match(/^(.+?) — Deck refresh: shuffled (\d+) card\(s\) from Waiting Room into a new deck\./))) {
    const [, name, n] = m;
    const isMe = playerNameMatches(name, s, myId);
    return splashBanner({
      titleKey: isMe ? 'splash.deckRefresh' : 'splash.deckRefreshOpp',
      titleVars: isMe ? null : { name },
      subtitleKey: 'splash.deckRefreshSub',
      subtitleVars: { n },
      kind: 'phase',
      duration: 2600,
    });
  }
  if (msg === '=== LIVE Phase ===') {
    return null;
  }
  const livePhasePlayer = parseLivePhaseLogPlayerName(msg);
  if (livePhasePlayer !== null) {
    const isMe = !G.isSpectator && playerNameMatches(livePhasePlayer, s, myId);
    const last = livePhasePlayer.slice(-1);
    const oppKey = (last === 's' || last === 'S') ? 'phaseBanner.theirLiveS' : 'phaseBanner.theirLive';
    return splashBanner({
      titleKey: isMe ? 'phaseBanner.yourLive' : oppKey,
      titleVars: isMe ? null : { name: livePhasePlayer },
      subtitleKey: 'phaseBanner.liveSub',
      kind: 'live',
      duration: LIVE_BANNER_MS,
    });
  }
  if (/— LIVE Phase\./.test(msg) || /secretly place 0–3 cards/.test(msg)) return null;
  if (msg === '=== Performance Phase ===') {
    return null;
  }
  if (msg === 'Both players reveal Live storage simultaneously.') return null;
  if (msg === '=== Live Win/Loss Check Phase ===') {
    if (!liveRoundHasLiveCards(s)) return null;
    if (!bothPlayersClearedLiveThisRound(s)) return null;
    return splashBanner({
      titleKey: 'phaseBanner.liveJudgeTitle',
      subtitleKey: null,
      kind: 'live',
      duration: LIVE_BANNER_MS,
    });
  }
  if (msg === '=== Live Round ===') return null;
  if (msg === '=== Live Start Effects ===') {
    if (!liveRoundHasLiveCards(s)) return null;
    if (G._liveStartBannerPresentedSeq === (s?.seq ?? 0)) return null;
    return splashBanner({
      titleKey: 'phaseBanner.liveStartTitle',
      subtitleKey: null,
      kind: 'live',
      duration: LIVE_BANNER_MS,
    });
  }
  if (msg === 'No Lives played this turn.') {
    if (prev) {
      const slice = (s.log || []).slice(logFrom);
      if (!slice.some(e => e.msg === 'No Lives played this turn.')) return null;
    }
    const showTurn = emptyLiveRoundShowTurn(prev, s) ?? inferLiveShowTurn(prev, s);
    if (emptyLiveRoundAlreadyPresented(showTurn)) return null;
    if (prev && isMainOrActivePhase(prev.phase) && isMainOrActivePhase(s?.phase)
        && !isLeavingLiveSetPhase(prev, s)
        && !newLogEntries(prev, s).some(e => e.msg === msg)) {
      return null;
    }
    return splashBanner({
      titleKey: 'splash.noLives',
      subtitleKey: null,
      kind: 'phase',
      duration: 1400,
    });
  }
  if (/attempts the Live performance!/.test(msg)) {
    const name = msg.replace(' attempts the Live performance!', '');
    const isMe = playerNameMatches(name, s, myId);
    return splashBanner({
      titleKey: isMe ? 'splash.youAttemptLive' : 'splash.theyAttemptLive',
      titleVars: isMe ? null : { name },
      subtitleKey: 'splash.attemptSub',
      kind: 'live-attempt',
      duration: 2300,
    });
  }
  if (/waits for a later turn/.test(msg)) {
    const name = msg.split(' waits')[0];
    const isMe = playerNameMatches(name, s, myId);
    return splashBanner({
      titleKey: isMe ? 'splash.youWait' : 'splash.theyWait',
      titleVars: isMe ? null : { name },
      subtitleKey: isMe ? 'splash.youWaitSub' : 'splash.theyWaitSub',
      kind: 'action',
      duration: 1900,
    });
  }
  if ((m = msg.match(/^(.+?) performed Live! Blades: (\d+) \| Hearts: \[([^\]]*)\] \| Live success: (\d+) \| Failed: (\d+)(?: \| Round: failed \(not all Lives succeeded\))?/))) {
    const [, name, blades, , ok, fail] = m;
    const isMe = playerNameMatches(name, s, myId);
    const roundFailed = /Round: failed \(not all Lives succeeded\)/.test(msg);
    let subKey;
    let subVars;
    if (roundFailed) {
      subKey = 'splash.perfRoundFailed';
      subVars = { ok };
    } else if (fail === '0') {
      subKey = 'splash.perfCleared';
      subVars = { ok };
    } else {
      subKey = 'splash.perfMixed';
      subVars = { ok, fail };
    }
    const sub = t(subKey, subVars);
    const liveTitleVars = isMe ? null : { name: getLocale() === 'ja' ? name : possessiveName(name) };
    return splashBanner({
      titleKey: isMe ? 'splash.yourLivePerformance' : 'splash.theirLive',
      titleVars: liveTitleVars,
      subtitleKey: 'splash.perfSubYell',
      subtitleVars: { blades, sub },
      kind: 'live-perform',
      duration: 2900,
    });
  }
  if (/has no valid Live cards!/.test(msg)) return null;
  if ((m = msg.match(/^Live Scores: (.+?) = (\d+) \| (.+?) = (\d+)/))) {
    const [, n1, s1, n2, s2] = m;
    const sc1 = +s1;
    const sc2 = +s2;
    if (sc1 === 0 && sc2 === 0 && !bothPlayersClearedLiveThisRound(s)) return null;
    if (!bothPlayersClearedLiveThisRound(s)) return null;
    const myScore = playerNameMatches(n1, s, myId) ? sc1 : sc2;
    const oppScore = playerNameMatches(n1, s, myId) ? sc2 : sc1;
    const iWin = sc1 > sc2 ? playerNameMatches(n1, s, myId) : sc2 > sc1 ? playerNameMatches(n2, s, myId) : false;
    const iLose = sc1 < sc2 ? playerNameMatches(n1, s, myId) : sc2 < sc1 ? playerNameMatches(n2, s, myId) : false;
    const detail = liveJudgeScoreDetail(sc1, sc2, n1, n2, s, myId, prev);
    if (G._postSpectacleSplashPause && bothPlayersClearedLiveThisRound(s)) return null;
    if (G._perfSpectacleDoneKey && prev) {
      const perfPrev = buildPerfSpectaclePrev(prev, s) || prev;
      if (G._perfSpectacleDoneKey === perfSpectacleTurnKey(perfPrev, s)) return null;
    }
    holdLiveJudgeOverlay(
      myScore,
      oppScore,
      detail,
      iWin ? 'good' : iLose ? 'bad' : '',
      3800
    );
    return null;
  }
  if (/ wins this Live!/.test(msg)) {
    const name = msg.split(' wins this Live!')[0];
    const isMe = playerNameMatches(name, s, myId);
    return splashBanner({
      titleKey: isMe ? 'splash.successLiveYou' : 'splash.successLiveThey',
      titleVars: isMe ? null : { name },
      subtitleKey: isMe ? 'splash.successLiveSubYou' : 'splash.successLiveSubThey',
      kind: 'success',
      duration: 2800,
    });
  }
  if (msg === 'Both players wait — Live cards stay in storage.') {
    return splashBanner({
      titleKey: 'splash.bothWait',
      subtitleKey: 'splash.bothWaitSub',
      kind: 'action',
      duration: 2100,
    });
  }
  if (/ turn [—–-] Main Phase \(Active/.test(msg)) {
    return null; // covered by phase transition banner (Your / opponent Main Phase)
  }
  if (msg.startsWith('Game started!')) {
    return splashBanner({
      titleKey: 'splash.gameStart',
      subtitleKey: null,
      kind: 'phase',
      duration: PHASE_BANNER_MS,
    });
  }
  if (msg.startsWith('--- Turn ') && msg.endsWith(' ---')) {
    return null; // skip minor turn divider if Turn N begins follows
  }
  return null;
}

function isLiveJudgePhase(s) {
  return s?.phase === 'live_judge' && liveStorageHasCards(s);
}

function setJudgeHint(text, tone) {
  const hint = el('judge-hint');
  if (!hint) return;
  clearTimeout(G._judgeHintTimer);
  if (!text) {
    hint.classList.remove('show', 'good', 'bad');
    hint.textContent = '';
    return;
  }
  hint.textContent = text;
  hint.classList.add('show');
  hint.classList.toggle('good', tone === 'good');
  hint.classList.toggle('bad', tone === 'bad');
  G._judgeHintTimer = setTimeout(() => {
    hint.classList.remove('show', 'good', 'bad');
  }, 12000);
}

function holdLiveJudgeOverlay(myScore, oppScore, hint, tone, ms) {
  if (G._perfSpectacleActive || G._skipJudgeOverlay || G._postSpectacleSplashPause) return;
  G._liveJudgeOverlayHold = true;
  G._liveJudgeScores = { my: myScore, opp: oppScore };
  el('ljo-my-scr').textContent = myScore;
  el('ljo-op-scr').textContent = oppScore;
  const root = el('live-judge-overlay');
  if (root) { root.classList.add('show'); root.hidden = false; }
  if (hint) setJudgeHint(hint, tone);
  clearTimeout(G._liveJudgeOverlayTimer);
  G._liveJudgeOverlayTimer = setTimeout(() => {
    G._liveJudgeOverlayHold = false;
    G._liveJudgeScores = null;
    if (!isLiveJudgePhase(G.gameState)) setJudgeHint('', '');
    if (G.gameState?.players) {
      const myId = G.playerId;
      const oppId = myId === 'p1' ? 'p2' : 'p1';
      updateLiveJudgeOverlay(G.gameState, G.gameState.players[myId], G.gameState.players[oppId], myId);
    } else {
      el('live-judge-overlay')?.classList.remove('show');
    }
  }, ms);
}

function clearPerfSpectacleDoneKeysOnly() {
  G._perfSpectacleDoneKey = null;
  G._perfSpectacleDoneTurns = null;
  try { sessionStorage.removeItem(PERF_SPECTACLE_DONE_STORAGE_KEY); } catch (e) {}
}

function clearPerfSpectacleDoneStorage() {
  clearPerfSpectacleDoneKeysOnly();
  G._liveStorageRevealDoneTurns = null;
}

function collectPerfSpectacleDoneTurnsFromLog(s) {
  const turns = new Set();
  if (!s?.log) return turns;
  let inTurn = 1;
  for (const e of s.log) {
    const t = parseTurnMarker(e.msg);
    if (t != null) inTurn = t;
    if (/ performed Live! Blades: /.test(e.msg || '')) {
      turns.add(inTurn);
    }
  }
  return turns;
}

function primeReplayEmptyLivePresentedFromLog(s) {
  if (!s?.log?.length) return;
  let inTurn = 1;
  for (const e of s.log) {
    const t = parseTurnMarker(e.msg);
    if (t != null) inTurn = t;
    if (e.msg !== 'No Lives played this turn.') continue;
    if (!G._emptyLiveRoundPresentedTurns) G._emptyLiveRoundPresentedTurns = new Set();
    G._emptyLiveRoundPresentedTurns.add(inTurn);
    if (!G._perfSpectacleDoneTurns) G._perfSpectacleDoneTurns = new Set();
    G._perfSpectacleDoneTurns.add(inTurn);
  }
}

function primePerfSpectacleDoneKeysFromLog(s) {
  const turns = collectPerfSpectacleDoneTurnsFromLog(s);
  if (!turns.size) return;
  const pending = detectPendingLiveSpectacleTurn(null, s);
  if (pending != null) turns.delete(pending);
  if (!turns.size) return;
  G._perfSpectacleDoneTurns = turns;
  const keys = [...turns].sort((a, b) => a - b).map(t => `${t}:live_show`);
  G._perfSpectacleDoneKey = keys[keys.length - 1];
  try {
    if (G.roomId) {
      sessionStorage.setItem(PERF_SPECTACLE_DONE_STORAGE_KEY, JSON.stringify({
        roomId: G.roomId,
        key: G._perfSpectacleDoneKey,
        keys,
      }));
    }
  } catch (e) {}
}

function savePerfSpectacleDoneKey(perfPrev, next, forceTurn = null) {
  const turn = forceTurn ?? inferLiveShowTurn(perfPrev, next);
  const key = `${turn}:live_show`;
  if (Number.isFinite(turn)) {
    if (!G._perfSpectacleDoneTurns) G._perfSpectacleDoneTurns = new Set();
    G._perfSpectacleDoneTurns.add(turn);
  }
  G._perfSpectacleDoneKey = key;
  try {
    if (G.roomId) {
      const keys = G._perfSpectacleDoneTurns
        ? [...G._perfSpectacleDoneTurns].sort((a, b) => a - b).map(t => `${t}:live_show`)
        : [key];
      sessionStorage.setItem(PERF_SPECTACLE_DONE_STORAGE_KEY, JSON.stringify({
        roomId: G.roomId,
        key,
        keys,
      }));
    }
  } catch (e) {}
}

function restorePerfSpectacleDoneKey() {
  if (G._perfSpectacleDoneKey || !G.roomId) return;
  try {
    const raw = sessionStorage.getItem(PERF_SPECTACLE_DONE_STORAGE_KEY);
    if (!raw) return;
    const data = JSON.parse(raw);
    if (data?.roomId !== G.roomId) return;
    if (Array.isArray(data.keys) && data.keys.length) {
      G._perfSpectacleDoneTurns = new Set(
        data.keys
          .map(k => parseInt(String(k).split(':')[0], 10))
          .filter(n => Number.isFinite(n))
      );
      G._perfSpectacleDoneKey = data.keys[data.keys.length - 1];
      return;
    }
    if (data?.key) G._perfSpectacleDoneKey = data.key;
  } catch (e) {}
}

function prevWasInLiveSetPlacement(prev) {
  const ph = prev?.phase;
  return ph === 'live_set' || ph === 'live_set_first' || ph === 'live_set_second';
}

function spectacleAlreadyPlayedForState(next, prev = null) {
  if (!next) return false;
  const showTurn = primaryLiveShowTurn(prev, next);
  if (showTurn == null) return false;
  const doneKey = `${showTurn}:live_show`;
  if (liveSpectacleDoneForTurn(showTurn)) return true;
  if (G._perfSpectacleDoneKey === doneKey) return true;
  try {
    const raw = sessionStorage.getItem(PERF_SPECTACLE_DONE_STORAGE_KEY);
    if (raw) {
      const data = JSON.parse(raw);
      if (data?.roomId === G.roomId) {
        if (Array.isArray(data.keys) && data.keys.includes(doneKey)) {
          G._perfSpectacleDoneKey = doneKey;
          return true;
        }
        if (data?.key === doneKey) {
          G._perfSpectacleDoneKey = doneKey;
          return true;
        }
      }
    }
  } catch (e) {}
  // Only trust explicit client playback markers (memory / sessionStorage).
  // Do not infer "already played" from perf signals in state — CPU/PvP can advance
  // to live_judge before the client ever ran the spectacle (see debug H-A).
  return false;
}

function markSpectacleDoneFromState(s, prev = null) {
  if (!s || G._perfSpectacleDoneKey) return;
  if (detectPendingLiveSpectacleTurn(prev, s) != null) return;
  const showTurn = inferLiveShowTurn(prev, s);
  if (liveSpectacleOwed(prev, s, showTurn)) return;
  if (!spectacleAlreadyPlayedForState(s, prev)) return;
  savePerfSpectacleDoneKey(buildPerfSpectaclePrev(prev, s), s);
}

function perfSpectacleTurnKey(perfPrev, next) {
  return `${inferLiveShowTurn(perfPrev, next)}:live_show`;
}

function liveRoundSpectacleDone(prev, s) {
  if (!s) return false;
  return spectacleDoneForTransition(prev, s);
}

function isPostSpectaclePhaseBanner(phase) {
  return ['active_first', 'active_second', 'main_first', 'main_second',
    'live_success_effects', 'live_judge', 'live_start_effects',
    'live_performance_first', 'live_performance_second'].includes(phase);
}

function isPostSpectacleLogSplash(msg) {
  if (!msg) return false;
  if (isPostLivePipelineLogBanner(msg)) return false;
  if (/ wins this Live!/.test(msg)) return true;
  if (msg === '=== Live Win/Loss Check Phase ===') return true;
  if (msg === '=== Live Start Effects ===') return true;
  if (isLivePipelineLogBanner(msg)) return true;
  return false;
}

function shouldSuppressPostSpectacleSplash(msg, phase, prev, s) {
  if (isLiveRoundPlaybackActive()) {
    if (phase && (phase.startsWith('main_') || phase === 'active_first' || phase === 'active_second')) return true;
    if (msg && /^=== Turn \d+ begins ===$/.test(msg)) return true;
  }
  if (livePlaybackBlocksMainPhaseUi(s, prev)) {
    if (phase && phase.startsWith('main_')) return true;
    if (msg && /^=== Turn \d+ begins ===$/.test(msg)) return true;
  }
  if (prev && shouldIgnoreStaleLivePerfSignals(prev, s)) return false;
  if (!G._postSpectacleSplashPause && !(prev && liveRoundSpectacleDone(prev, s))) return false;
  if (msg && isPostSpectacleLogSplash(msg)) return true;
  if (phase && isPostSpectaclePhaseBanner(phase)) return true;
  return false;
}

function queueTurnBeginBannerForState(s) {
  const turn = s?.turn;
  if (!turn) return;
  queueCenterBanner(splashBanner({
    titleKey: 'splash.turn',
    titleVars: { turn },
    subtitleKey: null,
    kind: 'phase',
    duration: PHASE_BANNER_MS,
  }));
}

function isLivePerformancePhase(phase) {
  return phase === 'live_performance_first' || phase === 'live_performance_second'
    || phase === 'live_success_effects';
}

function isLiveSpectaclePipelinePhase(phase) {
  return phase === 'live_set' || phase === 'live_start_effects'
    || isLivePerformancePhase(phase) || phase === 'live_judge';
}

function ensurePollHoldReleased(s) {
  if (!G._livePollHold) return;
  const ph = s?.phase;
  if (isLiveSpectaclePipelinePhase(ph) || ph === 'live_success_effects') return;
  if (G.animating && (ph === 'live_set' || isLiveSpectaclePipelinePhase(ph))) return;
  TCG_DEBUG.log('poll', 'release hold (left live pipeline)', { phase: ph, animating: G.animating });
  releaseLivePolls();
}

function clearCenterBannerQueue() {
  G._bannerDrainGen = (G._bannerDrainGen || 0) + 1;
  G._bannerQueue = [];
  cancelCenterBannerAutoDismiss();
  el('center-banner')?.classList.remove('show');
  resolveCenterBannerShow();
  if (G._bannerActive) {
    G._bannerActive = false;
    notifyBannerIdleWaiters();
  }
}

async function waitForCenterBannersClear() {
  await waitForBannersIdle();
  clearCenterBannerQueue();
}

/** Wait for any active splash, dismiss it, but keep queued pipeline banners. */
async function prepareLiveRoundBannerSlot() {
  await waitForBannersIdle();
  dismissCenterBannerIfShowing();
}

function markPerfSplashShown(showTurn, prev, next) {
  if (!G._perfSplashShownTurns) G._perfSplashShownTurns = new Set();
  const turns = new Set(candidateLiveShowTurns(prev, next));
  if (showTurn != null) turns.add(showTurn);
  for (const t of turns) {
    if (t != null) G._perfSplashShownTurns.add(t);
  }
  if (showTurn != null) G._perfSplashShownForTurn = showTurn;
}

/** Block duplicate Performance splashes when show-turn inference oscillates (1 vs 2). */
function perfSplashAlreadyShown(showTurn, prev, next) {
  const turns = new Set(candidateLiveShowTurns(prev, next));
  if (showTurn != null) turns.add(showTurn);
  for (const t of turns) {
    if (t != null && G._perfSplashShownTurns?.has(t)) return true;
    if (t != null && G._perfSplashShownForTurn === t) return true;
  }
  return false;
}

function performancePhaseBannerShowing() {
  const root = el('center-banner');
  if (!root?.classList.contains('show')) return false;
  return isSplashPerformanceBanner(G._lastBannerSpec)
    || isSplashPerformanceTitle(el('cb-title')?.textContent);
}

function performancePhaseBannerQueued() {
  return (G._bannerQueue || []).some(b => isSplashPerformanceBanner(b));
}

async function ensurePerformancePhaseSplash(showTurn, prev, next) {
  if (!shouldShowMatchSplash()) return;
  const p = prev ?? G.gameState;
  const n = next ?? G.gameState;
  const turn = showTurn ?? primaryLiveShowTurn(p, n) ?? inferLiveShowTurn(p, n);
  if (perfSplashAlreadyShown(turn, p, n)) return;
  if (performancePhaseBannerShowing() || performancePhaseBannerQueued()) {
    await waitForBannersIdle();
    markPerfSplashShown(turn, p, n);
    return;
  }
  await showCenterBannerNow(splashBanner({
    titleKey: 'phaseBanner.performanceTitle',
    subtitleKey: null,
    kind: 'live',
    duration: LIVE_BANNER_MS,
  }));
  markPerfSplashShown(turn, p, n);
}

/** Decide reveal flip, empty-round skip, and whether to run Performance spectacle. */
function liveRoundPresentationPlan(prev, next, opts = {}) {
  if (!prev || !next) return { needsLiveReveal: false, wantsSpectacle: false, wantsEmptyRound: false, hadLives: false };
  if (isLiveSetPlacementOnly(prev, next) || liveSetPlacementInProgress(next)) {
    return { needsLiveReveal: false, wantsSpectacle: false, wantsEmptyRound: false, hadLives: false, showTurn: inferLiveShowTurn(prev, next) };
  }
  rememberPerfSpectacleBaseline(prev, next);
  const leavingLiveSet = isLeavingLiveSetPhase(prev, next);
  const emptyRound = isEmptyLiveSkipTransition(prev, next);
  const needsLiveReveal = shouldRevealLiveStorageForRound(prev, next, emptyRound);
  const hadLives = roundHasLivePerformanceSignals(prev, next);
  const showTurn = opts.forceSpectacleTurn ?? inferLiveShowTurn(prev, next);
  let wantsSpectacle = emptyRound ? false : shouldTriggerPerfSpectacle(prev, next);
  if (!emptyRound && !wantsSpectacle && shouldForceLiveSpectacle(prev, next)) {
    wantsSpectacle = true;
    TCG_DEBUG.log('live', 'force spectacle (log/atomic pipeline)', TCG_DEBUG.trans(prev, next));
  }
  if (!emptyRound && !wantsSpectacle && opts.forceSpectacleTurn != null && !liveSpectacleDoneForTurn(showTurn)
      && roundHasLivePerformanceSignals(prev, next) && perfRoundHasShow(prev, next)
      && !pendingPromptBlocksPerfSpectacle(next)) {
    wantsSpectacle = true;
    TCG_DEBUG.log('live', 'force spectacle (pending turn gate)', { showTurn, ...TCG_DEBUG.trans(prev, next) });
  }
  if (!emptyRound && !wantsSpectacle && liveRoundRequiresSpectacle(prev, next, showTurn)) {
    wantsSpectacle = true;
    TCG_DEBUG.log('live', 'force spectacle (live cards in round)', { showTurn, ...TCG_DEBUG.trans(prev, next) });
  }
  const wantsEmptyRound = leavingLiveSet && emptyRound;
  return { needsLiveReveal, wantsSpectacle, wantsEmptyRound, hadLives, showTurn };
}

/** Play reveal + performance spectacle when the match ends on the same Live round (e.g. 3rd Success Live). */
async function maybePlayFinalLiveRoundPresentation(prev, next, newEntries) {
  if (!prev || !next) return false;
  const plan = liveRoundPresentationPlan(prev, next);
  if (!plan.needsLiveReveal && !plan.wantsSpectacle && !plan.wantsEmptyRound) return false;
  TCG_DEBUG.log('live', 'maybePlayFinalLiveRoundPresentation', plan, TCG_DEBUG.trans(prev, next));
  G.animating = true;
  try {
    await presentLiveRound(prev, next, G.playerId, { newEntries });
    return true;
  } finally {
    G.animating = false;
    releaseLivePollsAndFlush();
  }
}

function syncLogAfterLivePresentation(next, prev) {
  catchUpGameLog(next, prev ?? G.gameState);
}

function isLiveSuccessDiscardPrompt(state) {
  const pr = state?.pending_prompt;
  if (pr?.type !== 'effect_discard_hand') return false;
  if (state?.phase === 'live_success_effects') return true;
  if (state?._performance_continue) return true;
  return isLiveSpectaclePipelinePhase(state?.phase);
}

function isPostLiveSkillPrompt(state) {
  const pr = state?.pending_prompt;
  if (!pr) return false;
  if (pr.type === 'pick_judge_success_live') return true;
  if (isLiveSuccessDiscardPrompt(state)) return true;
  if (state?.phase === 'live_success_effects' && !isMidSpectacleYellRetryPrompt(state)) return true;
  return false;
}

function shouldDeferLiveSuccessDiscardUi(s, myId) {
  if (!isLiveSuccessDiscardPrompt(s)) return false;
  if (s.pending_prompt?.responder !== myId) return false;
  if (G._perfSpectacleActive) return true;
  if (G._deferredHandDrawIids?.size) return true;
  if (G._liveRoundPostSpectacleReady) return false;
  if (G.animating) return true;
  return false;
}

function shouldDeferPromptForLivePresentation(s, myId) {
  if (shouldDeferLiveSuccessDiscardUi(s, myId)) return true;
  const pr = s?.pending_prompt;
  if (!pr || pr.responder !== myId) return false;
  if (pr.type === 'pick_judge_success_live' && s.phase === 'live_judge'
      && !G._perfSpectacleActive && !G._liveSpectacleGateRunning
      && !G._liveRoundPlaybackActive && !G.animating) {
    return false;
  }
  if (isMidSpectacleYellRetryPrompt(s)) {
    // Only defer while early yell spectacle is still animating. Otherwise surface
    // Yes/No (Kurage mill / no-live retry) so Performance can reach heart check.
    if (G._perfSpectacleActive) {
      const phase = G._perfSpectaclePhase || 'closed';
      if (perfPhaseIdx(phase) < perfPhaseIdx('yell_opp')) return true;
    }
    return false;
  }
  if (G._liveRoundPlaybackActive && !G._liveRoundPostSpectacleReady) {
    if (s.phase === 'live_start_effects') return false;
    if (PERF_SPECTACLE_DEFERRED_PROMPTS.has(pr.type) || isLiveSuccessDiscardPrompt(s)) return true;
  }
  if (!PERF_SPECTACLE_DEFERRED_PROMPTS.has(pr.type)) return false;
  const spectaclePrev = G._deferPerfSpectaclePrev;
  // Spectacle finished — surface pick_judge_success_live even while presentLiveRound still holds animating.
  if (liveRoundSpectacleDone(spectaclePrev, s)) {
    return false;
  }
  // Steps 7-8: judge success pick must show after spectacle segment even if replay skipped.
  if (G._liveRoundPostSpectacleReady) {
    return false;
  }
  if (G._perfSpectacleActive || G._deferredLiveState) return true;
  if (G.animating) return true;
  if (G._liveRoundPlaybackActive && spectaclePrev
      && liveRoundPresentationPlan(spectaclePrev, s).wantsSpectacle) {
    return true;
  }
  return false;
}

function clearLiveSuccessHandDeferral(s) {
  if (!isLiveSuccessDiscardPrompt(s) && !G._deferredHandDrawIids?.size) return;
  G._deferredHandDrawIids = null;
}

function collectLiveSuccessDeferredDrawIids(prev, next) {
  if (!isLiveSuccessDiscardPrompt(next) || !prev) return new Set();
  return new Set(
    diffCardMoves(prev, next)
      .filter(m => isHiddenSourceToHand(m))
      .map(m => m.iid)
      .filter(Boolean)
  );
}

function syncLiveSuccessPresentationDefer(prev, next) {
  if (!isLiveSuccessDiscardPrompt(next)) return;
  const iids = collectLiveSuccessDeferredDrawIids(prev, next);
  if (iids.size) G._deferredHandDrawIids = iids;
  if (next.pending_prompt) G._deferredPromptState = next;
}

const LIVE_PERF_LOG_RE = / performed Live! Blades: /;

function liveSuccessDeferredEffectLogEntries(prev, next, newEntries) {
  if (!isLiveSuccessDiscardPrompt(next)) return [];
  const from = prev?.log?.length || 0;
  const entries = newEntries?.length ? newEntries : (next.log || []).slice(from);
  const perfIdx = entries.findIndex(e => LIVE_PERF_LOG_RE.test(e.msg || ''));
  if (perfIdx >= 0) {
    const drawBefore = entries.slice(0, perfIdx).filter(logEntryHasDeckToHandDrawAnim);
    const after = entries.slice(perfIdx + 1);
    const drawAfter = after.filter(logEntryHasDeckToHandDrawAnim);
    const logOnlyAfter = after.filter(e => !logEntryHasDeckToHandDrawAnim(e));
    return [...drawBefore, ...drawAfter, ...logOnlyAfter];
  }
  const perfInPrev = (prev?.log || []).some(e => LIVE_PERF_LOG_RE.test(e.msg || ''));
  return perfInPrev
    ? entries.filter(e => logEntryHasDeckToHandDrawAnim(e) || !e.anim?.length)
    : entries.filter(logEntryHasDeckToHandDrawAnim);
}

function liveSuccessFollowUpLogEntries(prev, next, newEntries) {
  return liveSuccessDeferredEffectLogEntries(prev, next, newEntries);
}

async function playLiveSuccessDeferredEffects(prev, final, myId, newEntries) {
  if (isPresentationSuperseded() || final?.status === 'finished') return false;
  if (!isLiveSuccessDiscardPrompt(final)) return false;
  const effectEntries = liveSuccessDeferredEffectLogEntries(prev, final, newEntries);
  const deferIids = collectLiveSuccessDeferredDrawIids(prev, final);
  if (!effectEntries.length && !deferIids.size) {
    clearLiveSuccessHandDeferral(final);
    if (final.pending_prompt?.responder === myId) ensurePendingPromptSurfaced(final, myId);
    return false;
  }
  TCG_DEBUG.log('live', 'playLiveSuccessDeferredEffects', {
    effectEntries: effectEntries.length,
    deferDraws: deferIids.size,
  });
  if (!G._deferredPromptState?.pending_prompt) G._deferredPromptState = final;
  let playback = logSyncPlaybackFromPrev(prev, final);
  playback.phase = final.phase;
  G.gameState = playback;
  G._prevLogLen = playback.log.length;
  renderGame(playback, { skipHand: true, skipOppHand: true, skipLog: true, skipPrompt: true });
  updateOpponentSkillWaitBanner(final, myId);
  const animatedDrawIids = new Set();
  try {
    for (const entry of effectEntries) {
      if (entry.anim?.length) {
        let specs = entry.anim.filter(spec => findCardInState(final, spec.iid, spec.pid));
        specs = specs.filter(spec => {
          if (spec.to === 'hand' && ['main_deck', 'energy_deck'].includes(spec.from)) {
            if (animatedDrawIids.has(spec.iid)) return false;
            animatedDrawIids.add(spec.iid);
          }
          return true;
        });
        if (specs.length > 1) await playAnimSpecBatch(specs, playback, final, myId);
        else if (specs.length === 1) await playAnimSpec(specs[0], playback, final, myId, 0);
      }
      appendSingleLogEntry(entry);
      playback.log.push(entry);
      G._prevLogLen = playback.log.length;
      await sleep(ANIM_STEP_MS);
    }
    const pendingDrawIids = deferIids.size ? deferIids : animatedDrawIids;
    if (pendingDrawIids.size) {
      const tailMoves = pickDeferredHandDrawMoves(
        diffCardMoves(playback, final),
        pendingDrawIids
      ).filter(m => !animatedDrawIids.has(m.iid));
      if (tailMoves.length) {
        const prevRects = collectCardRects();
        const handBefore = collectHandSlotRects();
        G._animHideIids = animHideIidsForMoves(playback, tailMoves);
        await playCardMoveAnimations(playback, final, prevRects, myId, handBefore, collectHandSlotRects(), tailMoves);
        G._animHideIids = null;
      }
    }
    clearLiveSuccessHandDeferral(final);
    G.gameState = final;
    renderGame(final, {
      skipHand: final.pending_prompt?.responder !== myId && handLayoutUnchanged(prev, final, myId),
      skipLog: true,
    });
  } finally {
    G._deferredHandDrawIids = null;
    clearDeferredPromptState();
  }
  updateOpponentSkillWaitBanner(final, myId);
  if (final.pending_prompt?.responder === myId) {
    ensurePendingPromptSurfaced(final, myId);
  }
  return true;
}

/** Orchestrate live storage reveal, WR discards, and Performance spectacle for one round. */
async function presentLiveRound(prev, next, myId, opts = {}) {
  if (isLeavingLiveSetPhase(prev, next) && liveStorageHasCards(prev)) {
    refreshLiveSetStorageBaseline(prev);
  }
  prev = effectiveEmptyLiveRoundPrev(prev, next);
  if (isLiveSetPlacementOnly(prev, next) || liveSetPlacementInProgress(next)) {
    TCG_DEBUG.log('live', 'presentLiveRound skip (live_set placement open)');
    return { reveal: false, spectacle: false, empty: false };
  }
  const plan = liveRoundPresentationPlan(prev, next, opts);
  const forceEmpty = !!opts.forceEmptyRound;
  const emptySkip = plan.wantsEmptyRound || forceEmpty || isEmptyLiveSkipTransition(prev, next);
  let needsLiveReveal = plan.needsLiveReveal;
  let wantsSpectacle = plan.wantsSpectacle;
  const showTurnForReveal = opts.forceSpectacleTurn ?? plan.showTurn ?? inferLiveShowTurn(prev, next);
  // Match the gate: if reveal was falsely sealed but opp storage is still face-down, re-arm flips
  // BEFORE the no-plan early return (otherwise sealed turns never recover).
  if (showTurnForReveal != null
      && liveStorageRevealDoneForTurn(showTurnForReveal)
      && shouldResetLiveStorageRevealDone(prev, next, showTurnForReveal, myId)) {
    G._liveStorageRevealDoneTurns?.delete(showTurnForReveal);
    needsLiveReveal = true;
    TCG_DEBUG.warn('live', 'presentLiveRound: reveal-done reset — opponent storage still face-down', {
      showTurn: showTurnForReveal,
    });
  }
  if (!needsLiveReveal && !emptySkip && !liveStorageRevealDoneForTurn(showTurnForReveal)
      && (wantsSpectacle || isLeavingLiveSetPhase(prev, next))) {
    const checkBoard = G._livePostRevealBoard || next;
    if (checkBoard?.players && liveStorageHadFaceDownOppBluff(checkBoard, myId)) {
      needsLiveReveal = true;
      TCG_DEBUG.log('live', 'force reveal (face-down opponent storage before spectacle/performance)');
    }
  }
  if (emptySkip) {
    clearEmptyLiveRoundPerfState();
    needsLiveReveal = shouldRevealLiveStorageForRound(prev, next, true);
    wantsSpectacle = false;
    const scenario = emptyLiveRoundScenario(prev, next);
    if (scenario === 'solo-human') {
      TCG_DEBUG.log('live', 'solo empty round: skip reveal, WR flight only', { scenario, ...TCG_DEBUG.trans(prev, next) });
    } else if (scenario === 'solo-cpu' || scenario === 'both-members') {
      TCG_DEBUG.log('live', 'empty round: reveal opponent/member bluffs before WR', { scenario, ...TCG_DEBUG.trans(prev, next) });
    }
  }
  const allowFinishedSpectacle = next?.status === 'finished' && wantsSpectacle;
  if (isPresentationSuperseded() || (next?.status === 'finished' && !allowFinishedSpectacle)) {
    return { reveal: false, spectacle: false, empty: false };
  }
  if (!needsLiveReveal && !wantsSpectacle && !emptySkip) {
    const judgePick = next.phase === 'live_judge'
      && next.pending_prompt?.type === 'pick_judge_success_live';
    if (judgePick) {
      G._liveRoundPostSpectacleReady = true;
      await awaitResolvePostLivePrompts(prev, next, myId, opts);
      ensurePendingPromptSurfaced(G.gameState || next, myId);
    }
    TCG_DEBUG.log('live', 'presentLiveRound skip (no plan)', plan);
    return { reveal: false, spectacle: false, empty: false };
  }

  const g = TCG_DEBUG.group('live', `presentLiveRound reveal=${needsLiveReveal} spectacle=${wantsSpectacle} empty=${emptySkip}`);
  TCG_DEBUG.log('live', 'plan', { ...plan, needsLiveReveal, wantsSpectacle, emptySkip }, TCG_DEBUG.trans(prev, next));
  syncLiveSuccessPresentationDefer(prev, next);
  const spectacleLogBaseline = (prev?.log || []).length;
  if (wantsSpectacle) {
    G._prevLogLen = Math.max(G._prevLogLen || 0, spectacleLogBaseline);
  } else if (!emptySkip) {
    catchUpGameLog(next, prev);
  } else {
    G._prevLogLen = Math.max(G._prevLogLen || 0, spectacleLogBaseline);
  }
  holdLivePolls();
  G._liveRoundPlaybackActive = true;
  G._liveRoundPostSpectacleReady = false;
  let spectacleRan = false;
  let revealRan = false;
  let emptyWrPlayed = false;
  let spectacleFailedOwed = false;
  const showTurnForSpectacle = opts.forceSpectacleTurn ?? plan.showTurn ?? inferLiveShowTurn(prev, next);
  if (!wantsSpectacle && !emptySkip && (opts.forceSpectacleTurn != null || plan.showTurn != null)) {
    const replayPlan = liveRoundPresentationPlan(prev, next, {
      forceSpectacleTurn: opts.forceSpectacleTurn ?? plan.showTurn,
    });
    wantsSpectacle = replayPlan.wantsSpectacle && !isEmptyLiveSkipTransition(prev, next);
  }
  const spectaclePlaybackPending = !emptySkip && !liveSpectacleDoneForTurn(showTurnForSpectacle)
    && (wantsSpectacle || liveSpectacleOwed(prev, next, showTurnForSpectacle)
        || liveRoundRequiresSpectacle(prev, next, showTurnForSpectacle));
  const runStorageReveal = needsLiveReveal;
  // Empty LIVE skip always defers committing `next` — that state already includes
  // Active/Energy/Draw. Committing early shows the new hand card, then turn-prep
  // reverts and redraws it.
  const deferStorageOutcomes = spectaclePlaybackPending || emptySkip
    || (emptySkip && shouldAnimateEmptyLiveStorageWr(prev, next));
  try {
    await prepareLiveRoundBannerSlot();
    await waitForBlockingOverlaysIdle(next);

    if (runStorageReveal) {
      if (liveStorageRevealDoneForTurn(showTurnForReveal)) {
        revealRan = true;
        TCG_DEBUG.log('live', 'presentLiveRound: skip reveal (already done for turn)', { showTurn: showTurnForReveal });
      } else {
        revealRan = !!(await playLivePhaseTransition(prev, next, myId, {
          deferFinal: deferStorageOutcomes,
          skipIntroBanner: wantsSpectacle || emptySkip
              || perfSplashAlreadyShown(showTurnForReveal, prev, next),
        }));
        if (!revealRan && liveStorageRevealDoneForTurn(showTurnForReveal)) {
          revealRan = true;
        }
      }
    }
    if (needsLiveReveal && !revealRan && !emptySkip && !liveStorageRevealDoneForTurn(showTurnForReveal)) {
      let revealFrom = buildLiveRevealPlayback(prev, next, myId);
      if (!revealFrom || !liveStorageHasCards(revealFrom)) {
        const synth = synthesizePerfPrevFromNext(prev, next, showTurnForReveal);
        if (synth) revealFrom = buildFaceDownLivePlayback(synth, myId);
      }
      if (revealFrom && liveStorageHasCards(revealFrom)) {
        TCG_DEBUG.warn('live', 'presentLiveRound: retry reveal after playLivePhaseTransition miss');
        revealRan = await runLiveStorageRevealSequence(revealFrom, next, myId, {
          deferWrDiscards: deferStorageOutcomes,
          skipIntroBanner: wantsSpectacle || emptySkip
              || perfSplashAlreadyShown(showTurnForReveal, prev, next),
        });
      }
      if (!revealRan && liveStorageRevealBypassOk(prev, next, showTurnForReveal, myId)) {
        TCG_DEBUG.warn('live', 'presentLiveRound: bypass reveal (batched poll / server already revealed)', {
          showTurn: showTurnForReveal,
        });
        const synthBoard = buildPerfSpectaclePrev(prev, next)
          || synthesizePerfPrevFromNext(prev, next, showTurnForReveal);
        if (synthBoard) {
          G._livePostRevealBoard = deepCloneState(synthBoard);
          G.gameState = synthBoard;
          renderGame(synthBoard, { skipLog: true });
        }
        markLiveStorageRevealDone(showTurnForReveal);
        revealRan = true;
      }
    }

    await awaitLiveStartPromptsIfNeeded(prev, next, myId);
    await presentSkippedLiveStartBanners(prev, next, myId);
    next = pickSpectacleStateForPerf(G.gameState || next);
    if (!emptySkip) {
      const afterStartPlan = liveRoundPresentationPlan(prev, next, {
        ...opts,
        forceSpectacleTurn: opts.forceSpectacleTurn ?? showTurnForSpectacle,
      });
      const afterTurn = afterStartPlan.showTurn ?? showTurnForSpectacle;
      wantsSpectacle = afterStartPlan.wantsSpectacle
        || (!liveSpectacleDoneForTurn(afterTurn)
            && (liveRoundRequiresSpectacle(prev, next, afterTurn)
                || liveSpectacleOwed(prev, next, afterTurn)
                // After multi-skill Live Start resolves, always play the owed show once
                // performance / Main is reached — never fall through to the no-spectacle path.
                || (liveRoundHadLivesPlayed(prev, next, afterTurn)
                    && !pendingPromptBlocksPerfSpectacle(next)
                    && next.phase !== 'live_start_effects'
                    && (isLivePerformancePhase(next.phase)
                        || next.phase === 'live_judge'
                        || isMainOrActivePhase(next.phase)
                        || liveRoundJudgeReady(next)
                        || logHasLivePerformanceForShowTurn(prev, next, afterTurn, { strict: true })))));
      if (wantsSpectacle) {
        TCG_DEBUG.log('live', 'presentLiveRound: spectacle armed after Live Start wait', {
          showTurn: afterTurn,
          phase: next.phase,
        });
      }
    }

    if (emptySkip) {
      const wrFrom = buildEmptyLiveWrPlayback(prev, next) || prev;
      if (wrFrom && liveStorageHasCards(wrFrom)) {
        G.gameState = wrFrom;
        renderGame(wrFrom, { skipLog: true });
        if (needsLiveReveal && !revealRan) {
          revealRan = await runLiveStorageRevealSequence(wrFrom, next, myId, {
            deferWrDiscards: true,
            skipIntroBanner: true,
          });
        }
      }
      await queueEmptyLiveRoundBanner();
      await waitForBannersIdle();
      const revealBoard = G._livePostRevealBoard || wrFrom;
      if (revealBoard && collectLiveBluffDiscards(revealBoard, next).length) {
        emptyWrPlayed = await playLiveStorageWrDiscards(revealBoard, next, myId, {
          initialDelayMs: LIVE_BLUFF_WR_DELAY_MS,
        });
      } else if (wrFrom && liveStorageHasCards(wrFrom)) {
        TCG_DEBUG.warn('live', 'empty round: no WR discards collected', TCG_DEBUG.trans(prev, next));
      }
      G._livePostRevealBoard = null;
      // Reset to post-WR / pre-turn-prep board so a premature final hand cannot linger.
      if (typeof buildPostEmptyLivePreTurnPrepState === 'function') {
        const prePrep = buildPostEmptyLivePreTurnPrepState(prev, next);
        G.gameState = prePrep;
        renderGame(prePrep, { skipLog: true });
      }
      await playEmptySkipTurnPrepSequence(prev, next, opts.newEntries || [], myId);
      G._liveRoundPostSpectacleReady = true;
      markEmptyLiveRoundPresented(prev, next);
    } else if (wantsSpectacle) {
      if (needsLiveReveal && !revealRan) {
        const heldBoard = G._livePostRevealBoard || next;
        const canSkipFlip = (typeof liveStorageRevealBypassOk === 'function'
            && liveStorageRevealBypassOk(prev, next, showTurnForReveal, myId))
          || !(typeof liveStorageHadFaceDownOppBluff === 'function'
            && (liveStorageHadFaceDownOppBluff(heldBoard, myId)
              || liveStorageHadFaceDownOppBluff(next, myId)));
        if (canSkipFlip) {
          TCG_DEBUG.warn('live', 'presentLiveRound: skip flip — storage already resolved; run spectacle', {
            showTurn: showTurnForReveal,
          });
          markLiveStorageRevealDone(showTurnForReveal);
          revealRan = true;
        }
      }
      if (needsLiveReveal && !revealRan) {
        spectacleFailedOwed = true;
        G._spectacleRecoveryPending = {
          prev, s: next, newEntries: opts.newEntries || [], myId,
        };
        TCG_DEBUG.warn('live', 'presentLiveRound: spectacle blocked — storage reveal did not run', {
          showTurn: showTurnForSpectacle,
        });
      } else {
      G._deferredLiveState = next;
      const spectacleNext = pickSpectacleStateForPerf(next);
      const perfPrev = buildPerfSpectaclePrev(prev, spectacleNext);
      G._perfSpectaclePrevForDraws = perfPrev;
      G._yellPerfDeferredDrawIids = collectYellPerformanceDrawIids(perfPrev, next);
      if (G._yellPerfDeferredDrawIids.size) {
        G._deferredHandDrawIids = new Set(G._yellPerfDeferredDrawIids);
      }
      TCG_DEBUG.log('live', 'runPerformanceSpectacle', {
        perfPrevSeq: perfPrev?.seq,
        doneKey: G._perfSpectacleDoneKey,
        usedRevealBoard: !!G._livePostRevealBoard,
      });
      spectacleRan = await runPerformanceSpectacle(perfPrev, spectacleNext, myId, {
        forceShowTurn: opts.forceSpectacleTurn ?? plan.showTurn,
      });
      if (spectacleRan) {
        // Prefer post-mid-spectacle state (prompt cleared) over gate-entry `next`.
        holdLiveRoundBoardForPrompts(G.gameState || G._deferredLiveState || next, myId);
        G._liveRoundPostSpectacleReady = true;
      } else if (liveRoundHadLivesPlayed(prev, next, showTurnForSpectacle)) {
        spectacleFailedOwed = true;
        G._spectacleRecoveryPending = {
          prev, s: next, newEntries: opts.newEntries || [], myId,
        };
        TCG_DEBUG.warn('live', 'presentLiveRound: spectacle owed but did not run', { showTurn: showTurnForSpectacle });
      }
      }
    } else if (liveRoundHadLivesPlayed(prev, next, showTurnForSpectacle)
        && !liveSpectacleDoneForTurn(showTurnForSpectacle)
        && next.phase !== 'live_start_effects'
        && !pendingPromptBlocksPerfSpectacle(next)) {
      // Safety net: never seal a Lives round as "presented" without Performance.
      spectacleFailedOwed = true;
      G._spectacleRecoveryPending = {
        prev, s: next, newEntries: opts.newEntries || [], myId,
      };
      TCG_DEBUG.warn('live', 'presentLiveRound: owed spectacle missed — arm recovery', {
        showTurn: showTurnForSpectacle,
        phase: next.phase,
      });
    } else {
      let wrAnimated = false;
      if (!runStorageReveal && shouldAnimateEmptyLiveStorageWr(prev, next)) {
        wrAnimated = await playLiveStorageWrDiscards(prev, next, myId);
      }
      G.gameState = wrAnimated ? G.gameState : next;
      renderGame(G.gameState, { skipLog: true });
      if (liveStorageHasCards(G._livePostRevealBoard || G.gameState)) {
        G._liveStorageOutcomePending = true;
        ensureLivePostRevealBoardSnapshot(G.gameState);
      }
      G._liveRoundPostSpectacleReady = true;
    }

    if (!spectacleFailedOwed) {
    if (opts.newEntries?.length && !emptySkip) {
      applyTurnPrepEntriesToState(G.gameState || next, next, opts.newEntries);
    }

    const settledNext = await awaitResolvePostLivePrompts(prev, next, myId, opts);
    const latest = (G.gameState && (G.gameState.seq ?? 0) >= (settledNext?.seq ?? 0))
      ? G.gameState
      : (settledNext || next);

    if (!emptyWrPlayed) {
      const storageBoard = G._livePostRevealBoard || latest;
      let storageRan = false;
      if (G._liveStorageOutcomePending) {
        storageRan = await playDeferredLiveStorageOutcomes(storageBoard, latest, myId);
        if (storageRan) {
          if (spectacleRan && G._yellPerfDeferredDrawIids?.size) {
            await playYellPerfDeferredDraws(G._perfSpectaclePrevForDraws, latest, myId);
          }
          commitLiveRoundAfterOutcomes(latest);
        } else {
          commitLiveRoundAfterOutcomes(latest);
        }
      } else if (await playDeferredLiveStorageOutcomes(storageBoard, latest, myId)) {
        if (spectacleRan && G._yellPerfDeferredDrawIids?.size) {
          await playYellPerfDeferredDraws(G._perfSpectaclePrevForDraws, latest, myId);
        }
        G._livePostRevealBoard = null;
        storageRan = true;
      } else if (spectacleRan && G._yellPerfDeferredDrawIids?.size) {
        await playYellPerfDeferredDraws(G._perfSpectaclePrevForDraws, latest, myId);
      }
      if (G._liveStorageOutcomePending && !storageRan) {
        commitLiveRoundAfterOutcomes(latest);
      }
    } else {
      if (G._liveStorageOutcomePending) commitLiveRoundAfterOutcomes(latest);
      else G._livePostRevealBoard = null;
    }

    syncLogAfterLivePresentation(next, prev);
    if (spectacleRan) {
      surfaceLogAfterSpectacle(next, spectacleLogBaseline);
    } else if (emptySkip) {
      surfaceLogAfterSpectacle(next, spectacleLogBaseline);
    } else {
      catchUpGameLog(next, prev);
    }
    await waitForBannersIdle();
    }
  } finally {
    G._liveRoundPlaybackActive = false;
    if (!spectacleFailedOwed) {
      G._liveRoundPostSpectacleReady = false;
      clearLiveRoundTransientCaches();
    }
    releaseLivePollsAndFlush();
    g.end();
  }

  if (!spectacleFailedOwed) {
    flushPostLiveLogBanners(prev, next, myId, { emptySkip });
    ensureLiveRoundStateCommitted(prev, next, myId);
  }
  if (emptySkip) nudgeCpuAfterStatePresentation(G.gameState || next);
  TCG_DEBUG.log('live', 'presentLiveRound done', { reveal: revealRan, spectacle: spectacleRan, empty: emptySkip, spectacleFailedOwed });
  return { reveal: revealRan, spectacle: spectacleRan, empty: emptySkip, spectacleFailedOwed };
}

function handleSpectatorPollError(msg) {
  if (!G.isSpectator) return false;
  const fallback = (global.LLTCG_I18N && typeof global.LLTCG_I18N.t === 'function')
    ? global.LLTCG_I18N.t('spectate.sessionEnded')
    : 'Spectator session ended.';
  void leaveSpectatorMode({ toastMsg: msg || fallback });
  return true;
}

// pullLatestState: client/js/game-sync.js

function enteringPerformanceShow(prev, next) {
  if (!prev || !next || prev.phase === next.phase) return false;
  const from = prev.phase;
  const to = next.phase;
  if (from === 'live_set' && to !== 'live_set' && liveRoundHasLiveCards(prev)) return true;
  if (from === 'live_start_effects' && to !== 'live_start_effects') return true;
  if (isLivePerformancePhase(from) && to === 'live_judge') return true;
  if (collectLiveRevealFlips(prev, next).size > 0) return true;
  return false;
}

function rememberPerfSpectacleBaseline(prev, next) {
  if (G.isTutorial || !prev || !next) return;
  if (isLeavingLiveSetPhase(prev, next)) {
    if (isEmptyLiveSkipTransition(prev, next) || isMemberOnlyLiveStorageRound(prev, next)) {
      G._deferPerfSpectaclePrev = null;
      return;
    }
    G._deferPerfSpectaclePrev = augmentPerfSpectaclePrev(prev, next);
  } else if (prev.phase === 'live_start_effects' && next.phase !== 'live_start_effects'
      && roundHasLivePerformanceSignals(prev, next) && !G._deferPerfSpectaclePrev) {
    G._deferPerfSpectaclePrev = augmentPerfSpectaclePrev(prev, next);
  }
}

function resolvePerfSpectacleBaseline(prev, next) {
  const revealBoard = G._livePostRevealBoard;
  if (revealBoard && (liveStorageHasCards(revealBoard) || liveRoundHasLiveCards(revealBoard))) {
    return revealBoard;
  }
  const playback = G.gameState;
  if (playback && playback !== next
      && (liveStorageHasCards(playback) || liveRoundHasLiveCards(playback))) {
    return playback;
  }
  return prev;
}

function buildPerfSpectaclePrev(prev, next) {
  const baseline = resolvePerfSpectacleBaseline(prev, next);
  let base = G._deferPerfSpectaclePrev;
  if (base) {
    return augmentPerfSpectaclePrev(base, next || baseline);
  }
  if (baseline && (liveStorageHasCards(baseline) || liveRoundHasLiveCards(baseline))) {
    return augmentPerfSpectaclePrev(baseline, next || baseline);
  }
  const showTurn = inferLiveShowTurn(baseline, next);
  const synth = synthesizePerfPrevFromNext(baseline, next, showTurn);
  if (synth) return augmentPerfSpectaclePrev(synth, next || baseline);
  return baseline && next ? augmentPerfSpectaclePrev(baseline, next) : baseline;
}

/** True when server state is far enough along to play Yell / blade / success spectacle. */
function performanceSpectacleReady(prev, next) {
  const ph = next?.phase;
  if (ph === 'live_judge') return true;
  if (isLivePerformancePhase(ph) && liveRoundJudgeReady(next)) return true;
  if (ph === 'live_success_effects') return liveRoundJudgeReady(next);
  if (newLogHasLivePerformance(prev, next) && liveRoundJudgeReady(next)) return true;
  if (isLeavingLiveSetPhase(prev, next) && liveRoundHasLiveCardsForRound(prev)
      && (liveRoundJudgeReady(next) || isLivePerformancePhase(ph) || newLogHasLivePerformance(prev, next))) {
    return true;
  }
  const prevPh = prev?.phase;
  const leavingLivePipeline = (isLiveSetPhase(prevPh) || prevPh === 'live_start_effects')
    && !isLiveSetPhase(ph) && ph !== 'live_start_effects';
  if (isLiveSetPhase(prevPh) && !isLiveSetPhase(ph)) {
    if (roundHasLivePerformanceSignals(prev, next)) {
      return true;
    }
  }
  if (prevPh === 'live_start_effects' && ph !== 'live_start_effects' && !next.pending_prompt) {
    if (currentRoundHasLivePerformance(prev, next)
        || enteringPerformanceShow(prev, next)) {
      return true;
    }
  }
  if (leavingLivePipeline) {
    if (currentRoundHasLivePerformance(prev, next)) {
      return true;
    }
  }
  // Main-phase recovery only when THIS poll brought new performance evidence.
  if (isMainOrActivePhase(ph)) {
    const slice = newLogEntries(prev, next);
    if (!newLogHasLivePerformance(prev, next) && !logSliceHasLivePipelineSignals(slice)) {
      return false;
    }
    const undone = scanUndoneLiveSpectacleTurn(next, prev);
    if (undone != null && !liveSpectacleDoneForTurn(undone)
        && liveSpectacleOwed(prev, next, undone)
        && (resolvedPerfSignalsForTransition(prev, next)
            || logHasLivePerformanceForShowTurn(prev, next, undone, { strict: true }))) {
      return true;
    }
  }
  return false;
}

/** Main gate: should this poll update run the full Performance show animation? */
function shouldTriggerPerfSpectacle(prev, next) {
  if (!prev || !next || G._perfSpectacleActive || G.isTutorial) {
    return false;
  }
  if (spectacleDoneForTransition(prev, next)) {
    TCG_DEBUG.log('live', 'spectacle skip: already played this round', { showTurn: primaryLiveShowTurn(prev, next) });
    return false;
  }
  const showTurn = primaryLiveShowTurn(prev, next);
  if (isEmptyLiveSkipTransition(prev, next) || !liveRoundHadLivesPlayed(prev, next, showTurn)) {
    TCG_DEBUG.log('live', 'spectacle skip: member-only / no Lives round');
    return false;
  }
  if (!isSpectacleEligibleGameplay(next, prev)) {
    TCG_DEBUG.log('live', 'spectacle skip: not eligible', { status: next?.status, phase: next?.phase });
    return false;
  }
  const ph = next.phase;
  if (isLiveSetPhase(ph) && !isLeavingLiveSetPhase(prev, next)) {
    return false;
  }
  // Leaving live_set with Live cards — only once performance is actually owed / logged.
  if (isLeavingLiveSetPhase(prev, next) && liveRoundHasLiveCardsForRound(prev)
      && showTurn != null && !liveSpectacleDoneForTurn(showTurn)) {
    if (next.phase === 'live_start_effects'
        || (pendingPromptBlocksPerfSpectacle(next)
            && !logHasLivePerformanceForShowTurn(prev, next, showTurn, { strict: true })
            && !newLogHasLivePerformance(prev, next))) {
      TCG_DEBUG.log('live', 'spectacle defer (leaving live_set): live_start_effects or prompt', { phase: ph });
      return false;
    }
    const ready = liveRoundJudgeReady(next)
      || isLivePerformancePhase(ph)
      || ph === 'live_judge'
      || newLogHasLivePerformance(prev, next)
      || logHasLivePerformanceForShowTurn(prev, next, showTurn, { strict: true });
    if (!ready || pendingPromptBlocksPerfSpectacle(next)) {
      TCG_DEBUG.log('live', 'spectacle defer (leaving live_set): not ready', { showTurn, phase: ph });
      return false;
    }
    TCG_DEBUG.log('live', 'spectacle trigger (leaving live_set)', { showTurn, phase: ph });
    return true;
  }
  // After multi-skill Live Start resolves, leave live_start_effects into the show.
  if (prev?.phase === 'live_start_effects' && ph !== 'live_start_effects'
      && showTurn != null && !liveSpectacleDoneForTurn(showTurn)
      && !pendingPromptBlocksPerfSpectacle(next)) {
    const deferredHasLives = !!(G._deferPerfSpectaclePrev
      && liveRoundHasLiveCardsForRound(G._deferPerfSpectaclePrev));
    if (liveRoundHadLivesPlayed(prev, next, showTurn)
        || deferredHasLives
        || liveRoundBoardHasLiveCards(prev)
        || logHasLivePerformanceForShowTurn(prev, next, showTurn, { strict: true })
        || isLivePerformancePhase(ph)
        || ph === 'live_judge'
        || liveRoundJudgeReady(next)) {
      TCG_DEBUG.log('live', 'spectacle trigger (leaving live_start_effects)', { showTurn, phase: ph });
      return true;
    }
  }
  // CPU/PvP can resolve the whole live pipeline in one poll (log has perf, phase already main_*).
  if (newLogHasLivePerformance(prev, next) && perfRoundHasShow(prev, next)) {
    if (liveRoundJudgeReady(next) && !pendingPromptBlocksPerfSpectacle(next)) {
      return true;
    }
  }
  // Live Success / judge pick prompts run after the performance spectacle replays.
  if (pendingPromptBlocksPerfSpectacle(next)) {
    TCG_DEBUG.log('live', 'spectacle skip: pending prompt', next.pending_prompt?.type);
    return false;
  }
  if (liveRoundRequiresSpectacle(prev, next, showTurn)) {
    TCG_DEBUG.log('live', 'spectacle trigger (live cards)', { showTurn, phase: ph });
    return true;
  }
  if (!performanceSpectacleReady(prev, next)) {
    TCG_DEBUG.log('live', 'spectacle skip: performance not ready yet', { phase: ph });
    return false;
  }
  if (!perfRoundHasShow(prev, next)) { TCG_DEBUG.log('live', 'spectacle skip: no show in round'); return false; }
  if (next.phase === 'live_start_effects') { TCG_DEBUG.log('live', 'spectacle skip: live_start_effects'); return false; }
  const perfPrev = buildPerfSpectaclePrev(prev, next);
  const doneKey = perfSpectacleTurnKey(perfPrev, next);
  TCG_DEBUG.log('live', 'spectacle trigger', { doneKey, phase: next.phase });
  return true;
}

// Pending state queue + live poll hold: client/js/state-apply.js

function commitDeferredLiveState(fallback) {
  const s = G._deferredLiveState || fallback;
  G._deferredLiveState = null;
  if (!s) return;
  G.gameState = s;
  G._deferPerfSpectaclePrev = null;
  const skipPrompt = shouldDeferPromptForLivePresentation(s, G.playerId);
  renderGame(s, { skipLog: true, skipPrompt });
}

/** After empty-round skip, commit server phase so UI does not stay on optimistic live_set. */
function ensureLiveRoundStateCommitted(prev, next, myId) {
  if (!next || isPresentationSuperseded()) return;
  const cur = G.gameState;
  if (!cur) {
    G.gameState = next;
    renderGame(next, { skipLog: true });
    return;
  }
  const staleLiveSet = isLiveSetPhase(cur.phase) && !isLiveSetPhase(next.phase);
  const behindSeq = (cur.seq ?? 0) < (next.seq ?? 0);
  const turnDesync = cur.active_player !== next.active_player || cur.phase !== next.phase;
  if (!staleLiveSet && !behindSeq && !turnDesync) return;
  G.gameState = next;
  const moves = prev ? diffCardMoves(prev, next) : [];
  renderGame(next, {
    skipLog: true,
    skipHand: handLayoutDeferForPlayer(moves, myId),
    skipOppHand: shouldDeferOpponentHandLayout(moves, next, myId),
  });
}

function tryFlushSpectacleRecovery() {
  const pending = G._spectacleRecoveryPending;
  if (!pending || G.animating || G._perfSpectacleActive || G._liveSpectacleGateRunning) return;
  if (!shouldRecoverMissedLiveSpectacle(pending.prev, pending.s)) {
    G._spectacleRecoveryPending = null;
    G._spectacleRecoveryAttempts = 0;
    return;
  }
  const now = performance.now();
  const flushKey = `${pending.s?.seq ?? 0}:${detectPendingLiveSpectacleTurn(pending.prev, pending.s) ?? ''}`;
  if (G._spectacleRecoveryFlushKey === flushKey && now - (G._spectacleRecoveryFlushAt ?? 0) < 800) return;
  if (G._spectacleRecoveryFlushKey === flushKey) {
    G._spectacleRecoveryAttempts = (G._spectacleRecoveryAttempts || 0) + 1;
  } else {
    G._spectacleRecoveryAttempts = 1;
  }
  // Same owed spectacle thrashing recovery → releaseLivePolls → poll=0 storm.
  if ((G._spectacleRecoveryAttempts || 0) > 6) {
    TCG_DEBUG.warn('live', 'spectacle recovery gave up', { flushKey, attempts: G._spectacleRecoveryAttempts });
    const giveUpTurn = detectPendingLiveSpectacleTurn(pending.prev, pending.s)
      ?? inferLiveShowTurn(pending.prev, pending.s);
    if (giveUpTurn != null) {
      // Seal so Main→Main cannot keep re-arming ghost Performance playback.
      markLiveStorageRevealDone(giveUpTurn);
      savePerfSpectacleDoneKey(
        buildPerfSpectaclePrev(pending.prev, pending.s) || pending.prev,
        pending.s,
        giveUpTurn
      );
    }
    G._spectacleRecoveryPending = null;
    G._spectacleRecoveryAttempts = 0;
    G._deferPerfSpectaclePrev = null;
    clearLiveRoundTransientCaches();
    sweepStaleLiveStorageFlipDom(pending.s || G.gameState, G.playerId);
    return;
  }
  G._spectacleRecoveryFlushKey = flushKey;
  G._spectacleRecoveryFlushAt = now;
  G._spectacleRecoveryPending = null;
  void runLiveSpectacleGate(pending.prev, pending.s, pending.newEntries, pending.myId);
}

function perfSpectacleHasContent(prev, next) {
  if (!liveRoundHadLivesPlayed(prev, next)) return false;
  const perfPrev = buildPerfSpectaclePrev(prev, next);
  if (!perfPrev || !next) return false;
  if (liveRoundRequiresSpectacle(prev, next)) return true;
  if (perfRoundHasShow(perfPrev, next)) return true;
  for (const pid of ['p1', 'p2']) {
    if (perfLiveAttempts(perfPrev, next, pid).length) return true;
  }
  return false;
}

async function runPerformanceSpectacle(perfPrev, next, myId, opts = {}) {
  if (isLiveSetPlacementOnly(perfPrev, next) || liveSetPlacementInProgress(next)) {
    TCG_DEBUG.log('live', 'spectacle skip: live_set placement open');
    return false;
  }
  const spectacleNext = pickSpectacleStateForPerf(next);
  // Defense-in-depth: never open the spectacle while pre-performance Live Start
  // effects are still resolving this round (owner must clear their prompt first).
  if (spectacleNext?.phase === 'live_start_effects' && !currentRoundLogHasPerformance(spectacleNext)) {
    TCG_DEBUG.log('live', 'spectacle skip: live_start_effects still resolving');
    return false;
  }
  if (!perfSpectacleHasContent(perfPrev, next)) {
    TCG_DEBUG.log('live', 'spectacle skip: no content');
    return false;
  }
  TCG_DEBUG.log('live', 'spectacle start', TCG_DEBUG.trans(perfPrev, next));
  perfCloseSpectacle();
  G._skipJudgeOverlay = true;
  let ran = false;
  try {
    await ensurePerformancePhaseSplash(inferLiveShowTurn(perfPrev, next), perfPrev, next);
    await perfSeekPhase(perfPrev, next, myId, 'judge', { forward: true, animate: true });
    await perfSleep(500);
    ran = G._perfSpectaclePhase === 'judge';
  } finally {
    perfCloseSpectacle();
    G._skipJudgeOverlay = false;
    if (ran) {
      savePerfSpectacleDoneKey(perfPrev, next, opts.forceShowTurn ?? null);
      G._postSpectacleSplashPause = true;
      G._perfYellRevealCache = null;
      G._deferPerfSpectaclePrev = null;
    }
    TCG_DEBUG.log('live', 'spectacle end', { ran, doneKey: G._perfSpectacleDoneKey });
  }
  return ran;
}

const BLOCKING_OVERLAY_IDS = [
  'overlay-prompt', 'overlay-mull', 'overlay-coin', 'overlay-pick',
  'overlay-hand-pick', 'overlay-heart', 'overlay-live', 'overlay-surveil',
  'modal-card', 'overlay-zone',
];

function isMyBlockingPromptOpen(s) {
  const pr = s?.pending_prompt;
  if (!pr || pr.responder !== G.playerId) return false;
  if (pr.type === 'surveil_arrange') return el('overlay-surveil')?.classList.contains('open');
  return isBlockingOverlayOpen();
}

function shouldHideOpponentLiveStorageFaces(s) {
  if (!s?.players) return false;
  // Reveal playback owns opponent face visibility — do not suppress during flip sequence.
  if (G._liveRevealFlips?.size) return false;
  if (isLiveSetPhase(s.phase) && liveSetPlacementInProgress(s)) return true;
  if (G._liveRoundPlaybackActive && !G._livePostRevealBoard && !G._liveStorageOutcomePending) return true;
  return false;
}

function shouldHoldStateForLocalPrompt(incoming) {
  if (G.isSpectator) return false;
  const cur = G.gameState;
  if (!cur || !isMyBlockingPromptOpen(cur)) return false;
  if (!incoming || incoming.seq <= (cur.seq ?? 0)) return false;
  const pr = cur.pending_prompt;
  if (!pr) return false;
  const inPr = incoming.pending_prompt;
  if (inPr?.type === 'pick_looked_deck_hand' && pr.responder === inPr.responder) {
    closeM('overlay-hand-pick');
    G._promptSubmitKey = null;
    return false;
  }
  if (inPr?.type === 'pay_energy_reveal_live_wr_superset'
      && (inPr.step === 'pick_wr_live' || pr.type === 'pay_energy_reveal_live_wr_superset')) {
    closeM('overlay-hand-pick');
    closeM('overlay-pick');
    G._promptSubmitKey = null;
    return false;
  }
  // Server cleared or replaced our prompt (e.g. phase timer auto-resolve) — apply immediately.
  if (!inPr || inPr.responder !== pr.responder) {
    closeM('overlay-surveil');
    closeM('overlay-hand-pick');
    closeM('overlay-pick');
    closeM('overlay-heart');
    el('overlay-prompt')?.classList.remove('open');
    return false;
  }
  if (inPr.type !== pr.type) return true;
  const inSrc = inPr.source_id || inPr.card_instance_id || inPr.source_instance_id || '';
  const curSrc = pr.source_id || pr.card_instance_id || pr.source_instance_id || '';
  if (inSrc !== curSrc) return true;
  if ((inPr.ability_index ?? '') !== (pr.ability_index ?? '')) return true;
  if (incoming.phase !== cur.phase) return true;
  if (incoming.active_player !== cur.active_player) return true;
  return false;
}

function isBlockingOverlayOpen() {
  return BLOCKING_OVERLAY_IDS.some(id => el(id)?.classList.contains('open'));
}

function hasBlockingGamePrompt(s) {
  return !!s?.pending_prompt;
}

function waitForBlockingOverlaysIdle(state) {
  if (!isBlockingOverlayOpen()) return Promise.resolve();
  return new Promise(resolve => {
    const started = performance.now();
    const tick = () => {
      if (!isBlockingOverlayOpen()) {
        resolve();
        return;
      }
      if (performance.now() - started > 120000) {
        resolve();
        return;
      }
      requestAnimationFrame(tick);
    };
    tick();
  });
}

function ensurePendingPromptSurfaced(s, myId) {
  if (!s) return;
  // Never re-apply an older snapshot after live-start / spectacle advanced the board.
  // (Re-surfacing gate-entry state was showing Natsumi / Kurage / Live Start prompts after Performance.)
  const live = G.gameState;
  const liveSeq = live?.seq ?? 0;
  const snapSeq = s.seq ?? 0;
  if (live && liveSeq > snapSeq) {
    s = live;
  } else if (live && liveSeq === snapSeq) {
    // Equal seq: prefer the copy that already cleared the prompt (mid-spectacle resolve).
    if (s.pending_prompt && !live.pending_prompt) {
      s = live;
    } else if (!s.pending_prompt && live.pending_prompt) {
      s = live;
    }
  }
  const pr = s?.pending_prompt;
  if (!pr || pr.responder !== myId) {
    if (!pr) {
      if (el('overlay-prompt')?.classList.contains('open')) closeM('overlay-prompt');
      if (G.animating && el('overlay-hand-pick')?.classList.contains('open')) {
        closeM('overlay-hand-pick');
        clearPickerCardHover();
      }
      clearDeferredPromptState({ skipBannerRefresh: true });
    }
    return;
  }
  // Live board already moved past this prompt (resolved / timed out / advanced phase).
  if (live && (live.seq ?? 0) >= snapSeq && !live.pending_prompt) {
    clearDeferredPromptState({ skipBannerRefresh: true });
    if (el('overlay-prompt')?.classList.contains('open')) closeM('overlay-prompt');
    if (el('overlay-hand-pick')?.classList.contains('open')) {
      closeM('overlay-hand-pick');
      clearPickerCardHover();
    }
    return;
  }
  // Do not resurrect mid-yell Kurage / retry prompts after the Performance segment finished.
  if (isMidSpectacleYellRetryPrompt(s) && !G._perfSpectacleActive
      && (G._liveRoundPostSpectacleReady || currentRoundLogHasPerformance(live || s))) {
    return;
  }
  // Live Start family must not reopen once this round has already performed.
  if ((s.phase === 'live_start_effects' || pr.type === 'optional_live_start'
      || (typeof pr.type === 'string' && pr.type.startsWith('live_start_')))
      && currentRoundLogHasPerformance(live || s)
      && !G._perfSpectacleActive && !G._liveRoundPlaybackActive) {
    return;
  }
  if (typeof isPromptSubmitting === 'function' && isPromptSubmitting(s)) return;
  const surfKey = typeof promptIdentityKey === 'function'
    ? promptIdentityKey(s)
    : `${s.seq}:${pr.type}:${pr.step || ''}:${pr.card_instance_id || pr.source_instance_id || pr.source_id || ''}:${pr.ability_index ?? ''}`;
  if (G._lastSurfacedPromptKey === surfKey && (
    el('overlay-prompt')?.classList.contains('open')
    || el('overlay-hand-pick')?.classList.contains('open')
  )) return;
  // Same identity already answered this round — do not reopen after overlay close.
  if (G._lastResolvedPromptKey === surfKey) return;
  G._lastSurfacedPromptKey = surfKey;
  if (isLiveSuccessDiscardPrompt(s)) clearLiveSuccessHandDeferral(s);
  if (pr.type === 'pick_judge_success_live') G._deferPerfSpectaclePrev = null;
  // Never downgrade G.gameState to an older seq.
  if ((G.gameState?.seq ?? 0) > snapSeq) {
    renderPrompt(G.gameState, myId);
    updateOpponentSkillWaitBanner(G.gameState, myId);
    updatePhaseActionButton(G.gameState, myId);
    return;
  }
  if (G.gameState?.seq !== s.seq || G.gameState?.pending_prompt?.type !== pr.type) {
    G.gameState = s;
    renderGame(s, { skipLog: true });
  }
  renderPrompt(G.gameState, myId);
  updateOpponentSkillWaitBanner(G.gameState, myId);
  updatePhaseActionButton(G.gameState, myId);
}

const PERF_PHASES = ['intro', 'hearts_check', 'hearts_grey', 'live_start', 'yell_mine', 'yell_opp', 'outcomes_mine', 'outcomes', 'judge'];
function perfPhaseIdx(p) {
  if (!p || p === 'closed') return -1;
  const i = PERF_PHASES.indexOf(p);
  return i >= 0 ? i : -1;
}
function perfIsIntroFamily(p) {
  const i = perfPhaseIdx(p);
  if (G.isTutorial && p === 'live_start') return true;
  return i >= perfPhaseIdx('intro') && i < perfPhaseIdx('yell_mine') && p !== 'live_start';
}

function perfTutorialKeepsSpectacle(phase) {
  return G.isTutorial && phase === 'live_start';
}

/** Yell-only pacing: 1 below 6 blades; scales down with blade count and accelerates per card. */
function perfYellPaceScale(totalBlade, stepIndex = 0, stepCount = 1) {
  if (totalBlade < 6) return 1;
  const heavy = Math.min(1, (totalBlade - 5) / 5);
  const minScale = 0.24;
  const startScale = 0.82 - heavy * 0.38;
  const progress = stepCount > 1 ? stepIndex / (stepCount - 1) : 0;
  const accelDrop = 0.12 + heavy * 0.4;
  return Math.max(minScale, startScale * (1 - progress * accelDrop));
}

function perfSleepYell(ms, pace = 1) {
  return perfSleep(Math.max(8, Math.round(ms * pace)));
}

function perfSleep(ms) {
  return new Promise(resolve => {
    const start = performance.now();
    const tick = () => {
      if (G._perfSpectacleAborted) return resolve();
      if (performance.now() - start >= ms) return resolve();
      setTimeout(tick, Math.min(40, ms));
    };
    setTimeout(tick, Math.min(40, ms));
  });
}

async function perfFlashSplash(text, ms, variant) {
  const splash = el('perf-splash');
  const txt = el('perf-splash-txt');
  if (!splash || !txt) return;
  const coupleBanner = variant === 'live-start' && el('center-banner')?.classList.contains('show');
  if (coupleBanner) cancelCenterBannerAutoDismiss();
  setSplashTitle(txt, text);
  splash.classList.toggle('live-start', variant === 'live-start');
  splash.classList.add('show');
  if (variant === 'live-start') sfxPlaySplash('splash_live_start');
  await perfSleep(ms);
  splash.classList.remove('show', 'live-start');
  if (coupleBanner) dismissCenterBannerIfShowing();
  await perfSleep(SPLASH_FADE_MS);
  if (txt.textContent === text) setSplashTitle(txt, '');
}

function drainTutorialNavQueue() {
  const queued = G.tutorialQueuedDir;
  G.tutorialQueuedDir = 0;
  if (queued) tutorialNav(queued);
}

function perfCardEl(card, kind) {
  const c = enrichCard(card);
  const liveSlot = kind === 'live' || isLiveCard(c) || c.card_type === 'ライブ';
  const d = document.createElement('div');
  d.className = 'perf-card ' + (liveSlot ? 'live' : 'member');
  if (c.instance_id) d.dataset.iid = c.instance_id;
  if (liveSlot && !isLiveCard(c)) appendLiveStorageMemberFace(d, c);
  else appendCardFace(d, c, { sideways: false });
  if (!liveSlot) {
    const inWait = typeof memberInWait === 'function' ? memberInWait(c) : !!c.in_wait;
    if (inWait) {
      d.classList.add('member-in-wait');
      const wd = document.createElement('div');
      wd.className = 'wdim';
      wd.textContent = 'Wait';
      d.appendChild(wd);
    }
    const rawBlade = (Number(c.blade) || 0) + (Number(c.live_blade_bonus) || 0);
    // Wait members do not contribute blade — show 0 so the spectacle matches the board.
    const blade = inWait ? 0 : rawBlade;
    if (blade > 0 || inWait) {
      const badge = document.createElement('div');
      badge.className = 'perf-member-blade' + (inWait ? ' perf-member-blade-wait' : '');
      badge.appendChild(mkGameIcon('icon_blade.png', 'bicon sm', 'Blade'));
      const num = document.createElement('span');
      num.textContent = String(blade);
      badge.appendChild(num);
      d.appendChild(badge);
    }
  }
  return d;
}

function perfHeartStatKey(color) {
  return normalizeHeartColor(color || 'any');
}

function cardYellBladeHeartColors(card) {
  const colors = [];
  (card?.blade_hearts || []).forEach(bh => {
    if (typeof bh === 'string') {
      if (bh === 'draw' || bh === 'score') return;
      colors.push(bh);
      return;
    }
    const t = bh?.type || '';
    if (t === 'draw' || t === 'score') return;
    colors.push(bh.color || bh.type || 'any');
  });
  return colors;
}

/** Ordered yell blade-heart rows for spectacle icons + fly anims (card order, no pool grouping). */
function cardYellBladeHeartEntries(card, opts = {}) {
  const yellWildcard = !!opts.yellWildcard;
  return cardYellBladeHeartColors(card).map(raw => ({
    raw,
    display: yellWildcard ? 'any' : bladeHeartDisplayColor(raw),
  }));
}

function cardYellDrawIconCount(card) {
  let n = 0;
  (card?.blade_hearts || []).forEach(bh => {
    if (bh === 'draw' || (typeof bh === 'object' && bh?.type === 'draw')) n++;
  });
  if (card?.yell_draw_icon || card?.special_heart === CARD_SPECIAL_HEART_DRAW) n++;
  return n;
}

function cardYellScoreIconCount(card) {
  let n = 0;
  (card?.blade_hearts || []).forEach(bh => {
    if (bh === 'score' || (typeof bh === 'object' && bh?.type === 'score')) n++;
  });
  if (card?.yell_score_icon || card?.special_heart === CARD_SPECIAL_HEART_SCORE) n++;
  return n;
}

function appendYellSpecialIcons(container, card, { lg = true } = {}) {
  if (!container || !card) return;
  if (cardYellDrawIconCount(card) > 0) {
    container.appendChild(mkGameIcon('sp_draw.png', lg ? 'hicon lg' : 'hicon fld', 'Yell draw'));
  }
  if (cardYellScoreIconCount(card) > 0) {
    container.appendChild(mkGameIcon('sp_score.png', lg ? 'hicon lg' : 'hicon fld', 'Yell score'));
  }
}

function buildHeartPoolFromRows(rows) {
  const pool = [];
  (rows || []).forEach(hg => {
    for (let i = 0; i < (hg.count || 1); i++) pool.push(normalizeHeartColor(hg.color));
  });
  return pool;
}

function perfStageHeartsForPlayer(ctx, pid) {
  const board = ctx?.next?.stage_board;
  const isMine = pid === ctx?.myId;
  const stageHearts = isMine
    ? (board?.mine?.stage_hearts || aggregateStageHeartsFromStage(ctx.next.players[pid]?.stage))
    : (board?.opp?.stage_hearts || aggregateStageHeartsFromStage(ctx.next.players[pid]?.stage));
  const continuous = perfContinuousHeartsForPlayer(ctx, pid);
  return mergeHeartStatRows(stageHearts, continuous);
}

function clientCountLiveZoneCards(p) {
  return (p?.live_zone || []).filter(c => c != null).length;
}

function clientLiveZoneHasGroupLive(p, group) {
  return (p?.live_zone || []).some(lc => lc && isLiveTypeCard(lc) && (lc.group || '') === group);
}

function appendClientContinuousHeartsFromSpec(out, spec) {
  (spec || []).forEach(h => {
    for (let i = 0; i < (h.count || 1); i++) out.push(normalizeHeartColor(h.color || 'any'));
  });
}

/** Client fallback when stage_board.continuous_heart_grants is missing. */
function computeContinuousHeartGrantsClient(state, pid) {
  const p = state?.players?.[pid];
  if (!p) return [];
  const grants = [];
  Object.entries(p.stage || {}).forEach(([slot, member]) => {
    if (!member) return;
    const memberHearts = [];
    for (const ab of member.abilities || []) {
      if ((ab.trigger || '') !== 'continuous') continue;
      const type = ab.type || '';
      if (type === 'blade_if_live_zone_group_live' && (ab.hearts || []).length) {
        const min = Number(ab.min_count ?? 3);
        const group = ab.group || 'Nijigasaki';
        if (clientCountLiveZoneCards(p) >= min && clientLiveZoneHasGroupLive(p, group)) {
          appendClientContinuousHeartsFromSpec(memberHearts, ab.hearts);
        }
      }
      if (type === 'continuous_hearts_in_slot' && (ab.slot || '') === slot) {
        appendClientContinuousHeartsFromSpec(memberHearts, ab.hearts);
      }
      if (type === 'continuous_heart_if_live_zone_group_hearts') {
        let total = 0;
        (p.live_zone || []).forEach(lc => {
          if (!lc || !isLiveTypeCard(lc) || (lc.group || '') !== (ab.group || 'Superstar')) return;
          (lc.required_hearts || lc.hearts || []).forEach(h => { total += h.count || 1; });
        });
        if (total >= Number(ab.min_required_total ?? 8)) {
          appendClientContinuousHeartsFromSpec(memberHearts, ab.hearts);
        }
      }
    }
    if (!memberHearts.length) return;
    grants.push({
      instance_id: member.instance_id || '',
      slot,
      who: member.name_en || member.name || 'Member',
      hearts: memberHearts,
    });
  });
  return grants;
}

function perfContinuousHeartGrantsForPlayer(ctx, pid) {
  const board = ctx?.next?.stage_board;
  const isMine = pid === ctx?.myId;
  const grants = isMine
    ? board?.mine?.continuous_heart_grants
    : board?.opp?.continuous_heart_grants;
  if (grants?.length) return grants;
  return computeContinuousHeartGrantsClient(ctx?.next, pid);
}

function perfContinuousHeartsForPlayer(ctx, pid) {
  const board = ctx?.next?.stage_board;
  const isMine = pid === ctx?.myId;
  const rows = isMine ? board?.mine?.continuous_hearts : board?.opp?.continuous_hearts;
  if (rows?.length) return rows;
  const flat = [];
  perfContinuousHeartGrantsForPlayer(ctx, pid).forEach(g => flat.push(...(g.hearts || [])));
  const map = {};
  flat.forEach(c => {
    const key = normalizeHeartColor(c);
    map[key] = (map[key] || 0) + 1;
  });
  return sortHeartsByDisplayOrder(Object.keys(map).map(color => ({ color, count: map[color] })));
}

function perfMemberCardElForGrant(ctx, pid, grant) {
  const isMine = pid === ctx.myId;
  const stageRow = el(isMine ? 'perf-stage-row' : 'perf-opp-stage');
  if (!stageRow || !grant?.instance_id) return null;
  return stageRow.querySelector(`[data-iid="${grant.instance_id}"]`);
}

/** ALL blade hearts display as icon_b_all; resolve to best missing live color when applied. */
/** First colored live requirement not covered by exact matches (wildcards reserved for payment). */
function firstMissingColoredHeartForRequirements(pool, required) {
  const specifics = pool
    .map(h => normalizeHeartColor(h))
    .filter(h => h !== 'any')
    .slice();
  for (const req of sortHeartRequirements(required || [])) {
    const color = normalizeHeartColor(req.color || 'any');
    if (color === 'any') continue;
    const need = req.count || 1;
    for (let i = 0; i < need; i++) {
      const idx = specifics.indexOf(color);
      if (idx >= 0) specifics.splice(idx, 1);
      else return color;
    }
  }
  return null;
}

function liveCardsHaveYellHeartsWildcard(liveCards) {
  for (const lc of liveCards || []) {
    for (const ab of lc.abilities || []) {
      if (ab.type === 'yell_hearts_wildcard') return true;
    }
    const text = `${lc.text || ''}${lc.text_jp || ''}`;
    if (/revealed for Yell may be treated as any color/i.test(text)
        || /エールで出た.*任意の色/.test(text)) {
      return true;
    }
  }
  return false;
}

/** Next color for a wildcard Yell heart: missing specifics first, then any. */
function resolveAllBladeHeartColorForPool(pool, liveCards) {
  for (const lc of liveCards || []) {
    const missing = firstMissingColoredHeartForRequirements(pool, lc.required_hearts || []);
    if (missing) return missing;
  }
  return 'any';
}

function resolveYellBladeHeartColor(color, ctx, pid, opts = {}) {
  const raw = String(color || 'any').toLowerCase();
  const pool = opts.extraOwned
    ? opts.extraOwned
    : buildHeartPoolFromRows(perfStageHeartsForPlayer(ctx, pid));
  const liveCards = opts.liveCards
    || perfSpectacleLiveCards(ctx.prev, ctx.next, pid).map(enrichCard);

  if (opts.yellWildcard && raw !== 'draw') {
    return resolveAllBladeHeartColorForPool(pool, liveCards);
  }
  if (raw !== 'all') return raw === 'gray' ? 'any' : raw;

  return resolveAllBladeHeartColorForPool(pool, liveCards);
}

function perfMarkYellBladeHearts(chip, card, opts = {}) {
  const entries = cardYellBladeHeartEntries(card, opts);
  if (!entries.length) return null;
  chip.querySelector('.perf-yell-blade-hearts')?.remove();
  const wrap = document.createElement('div');
  wrap.className = 'perf-yell-blade-hearts';
  entries.forEach(({ raw, display }) => {
    const icon = mkBladeHeartIcon(display);
    icon.dataset.yellBladeRaw = raw;
    wrap.appendChild(icon);
  });
  chip.appendChild(wrap);
  return wrap;
}

function perfGetHeartCountMap(container) {
  const map = {};
  if (!container || container.textContent === '—') return map;
  container.querySelectorAll('.heart-stat-row').forEach(row => {
    const icon = row.querySelector('.hicon');
    const key = icon?.dataset?.color || 'any';
    const text = [...row.childNodes].find(n => n.nodeType === Node.TEXT_NODE);
    map[key] = parseInt(text?.textContent || '0', 10) || 0;
  });
  return map;
}

function perfSetHeartCountMap(container, map) {
  if (!container) return;
  container.innerHTML = '';
  const keys = Object.keys(map).filter(k => map[k] > 0)
    .sort((a, b) => heartDisplaySortKey(a) - heartDisplaySortKey(b));
  if (!keys.length) {
    container.textContent = '—';
    return;
  }
  keys.forEach(key => {
    const row = document.createElement('span');
    row.className = 'heart-stat-row';
    row.appendChild(mkHeartIcon(key, true, false));
    row.appendChild(document.createTextNode(String(map[key])));
    container.appendChild(row);
  });
}

function perfHeartRowForColor(container, color) {
  const key = perfHeartStatKey(color);
  for (const row of container.querySelectorAll('.heart-stat-row')) {
    if (row.querySelector('.hicon')?.dataset?.color === key) return row;
  }
  return null;
}

function perfIncrementHeartStat(container, color) {
  const key = perfHeartStatKey(color);
  const existing = perfHeartRowForColor(container, key);
  if (existing) {
    const text = [...existing.childNodes].find(n => n.nodeType === Node.TEXT_NODE);
    const next = (parseInt(text?.textContent || '0', 10) || 0) + 1;
    if (text) text.textContent = String(next);
    else existing.appendChild(document.createTextNode(String(next)));
    return existing;
  }
  if (container.textContent === '—') container.textContent = '';
  const row = document.createElement('span');
  row.className = 'heart-stat-row';
  row.appendChild(mkHeartIcon(key, true, false));
  row.appendChild(document.createTextNode('1'));
  container.appendChild(row);
  if (container.querySelectorAll('.heart-stat-row').length >= 4) container.classList.add('dense');
  return row;
}

/** Landing rect for a yell blade heart fly — panel counts stay stale until the heart arrives. */
function perfHeartFlyTargetRect(heartsEl, color) {
  const key = perfHeartStatKey(color);
  const existing = perfHeartRowForColor(heartsEl, key);
  if (existing) return existing.getBoundingClientRect();

  const map = perfGetHeartCountMap(heartsEl);
  map[key] = (map[key] || 0) + 1;
  const hostRect = heartsEl.getBoundingClientRect();
  const ghost = document.createElement('div');
  ghost.className = heartsEl.className;
  ghost.style.cssText = 'position:fixed;visibility:hidden;pointer-events:none;display:flex;flex-direction:row;flex-wrap:nowrap;align-items:center;justify-content:center;gap:6px 10px';
  ghost.style.left = hostRect.left + 'px';
  ghost.style.top = hostRect.top + 'px';
  ghost.style.width = Math.max(hostRect.width, 1) + 'px';
  document.body.appendChild(ghost);
  perfSetHeartCountMap(ghost, map);
  const targetRow = perfHeartRowForColor(ghost, key);
  const rect = targetRow?.getBoundingClientRect() || hostRect;
  ghost.remove();
  return rect;
}

function perfYellBladeHeartOrigin(fromEl, chip) {
  if (fromEl?.getBoundingClientRect) {
    const r = fromEl.getBoundingClientRect();
    if (r.width >= 1 && r.height >= 1) {
      return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
    }
  }
  const wrap = chip?.querySelector?.('.perf-yell-blade-hearts');
  if (wrap) {
    const r = wrap.getBoundingClientRect();
    return {
      x: r.left + Math.min(12, Math.max(8, r.width * 0.28)),
      y: r.top + Math.min(12, Math.max(8, r.height * 0.28)),
    };
  }
  const c = chip?.getBoundingClientRect?.();
  if (!c) return null;
  return { x: c.left + Math.min(14, c.width * 0.09), y: c.top + Math.min(12, c.height * 0.06) };
}

async function perfRevealYellCardFromDeck(chip, deckEl, isMine, pace = 1) {
  if (!chip || G._perfSpectacleAborted) return;
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduced || !deckEl) {
    chip.classList.add('show');
    return;
  }
  const layer = el('perf-spectacle');
  const deckRect = deckEl.getBoundingClientRect();
  if (!layer || deckRect.width < 4) {
    chip.classList.add('show');
    return;
  }
  const yellRow = chip.parentElement;
  // Absolute stack chips need a layout pass before measuring the top slot.
  void yellRow?.offsetWidth;
  let chipW = chip.offsetWidth;
  let chipH = chip.offsetHeight;
  const rowRect = yellRow?.getBoundingClientRect?.();
  if (chipW < 4 || chipH < 4) {
    chipW = rowRect?.width >= 4 ? rowRect.width : deckRect.width;
    chipH = rowRect?.height >= 4 ? rowRect.height : deckRect.height;
  }
  const flyDur = Math.max(0.14, 0.48 * pace);
  const fadeDur = Math.max(0.1, 0.38 * pace);
  const fly = document.createElement('div');
  fly.className = 'perf-yell-card perf-yell-flying';
  fly.innerHTML = chip.innerHTML;
  fly.style.setProperty('--yell-stack-x', '0px');
  fly.style.setProperty('--yell-stack-y', '0px');
  fly.style.setProperty('--yell-stack-scale', '1');
  fly.style.width = chipW + 'px';
  fly.style.height = chipH + 'px';
  fly.style.left = (deckRect.left + deckRect.width / 2) + 'px';
  fly.style.top = (deckRect.top + deckRect.height / 2) + 'px';
  fly.style.transform = 'translate(-50%, -50%) scale(0.42) rotateY(88deg)';
  fly.style.opacity = '0';
  fly.style.transition = `transform ${flyDur}s cubic-bezier(.2,.9,.2,1), opacity ${fadeDur}s ease`;
  layer.appendChild(fly);
  chip.style.visibility = 'hidden';
  deckEl.classList.add('perf-deck-draw');
  sfxPlayCard('yell_reveal', { volume: 0.38 });
  await perfSleepYell(40, pace);
  if (G._perfSpectacleAborted) {
    fly.remove();
    chip.style.visibility = '';
    deckEl.classList.remove('perf-deck-draw');
    return;
  }
  let chipRect = chip.getBoundingClientRect();
  if (chipRect.width < 4 || chipRect.height < 4) {
    chipRect = (rowRect?.width >= 4 ? rowRect : null) || yellRow?.getBoundingClientRect?.() || deckRect;
  }
  const sx = deckRect.left + deckRect.width / 2;
  const sy = deckRect.top + deckRect.height / 2;
  const tx = chipRect.left + chipRect.width / 2;
  const ty = chipRect.top + chipRect.height / 2;
  fly.style.opacity = '1';
  fly.style.transform = `translate(calc(-50% + ${tx - sx}px), calc(-50% + ${ty - sy}px)) scale(1) rotateY(0deg)`;
  await perfSleepYell(500, pace);
  fly.remove();
  chip.style.visibility = '';
  chip.classList.add('show');
  deckEl.classList.remove('perf-deck-draw');
}

async function perfFlyMemberHeartToPanel(fromEl, heartsEl, color, opts = {}) {
  const pace = opts.pace ?? 1;
  if (!heartsEl || G._perfSpectacleAborted) return;
  const layer = el('perf-spectacle');
  if (!layer) return;
  let sx;
  let sy;
  if (fromEl) {
    const r = fromEl.getBoundingClientRect();
    sx = r.left + r.width / 2;
    sy = r.top + r.height * 0.32;
  } else {
    const host = heartsEl.getBoundingClientRect();
    sx = host.left + host.width * 0.5;
    sy = host.top + host.height * 0.5;
  }
  const fly = document.createElement('div');
  fly.className = 'perf-heart-fly';
  const display = normalizeHeartColor(color || 'any');
  const flyIcon = mkHeartIcon(display, true, false);
  fly.appendChild(flyIcon);
  fly.style.left = sx + 'px';
  fly.style.top = sy + 'px';
  fly.style.transform = 'translate(-50%, -50%)';
  const heartFlyDur = Math.max(0.16, 0.56 * pace);
  const heartFadeDur = Math.max(0.08, 0.1 * pace);
  fly.style.transition = `transform ${heartFlyDur}s ease-out, opacity ${heartFadeDur}s ease`;
  layer.appendChild(fly);
  fromEl?.classList?.add('perf-member-heart-pulse');
  sfxPlayCard('hearts_gain', { volume: 0.34 });
  await perfSleepYell(40, pace);
  if (G._perfSpectacleAborted) { fly.remove(); fromEl?.classList?.remove('perf-member-heart-pulse'); return; }
  const to = perfHeartFlyTargetRect(heartsEl, display);
  const tx = to.left + to.width / 2;
  const ty = to.top + to.height / 2;
  fly.style.transform = `translate(calc(-50% + ${tx - sx}px), calc(-50% + ${ty - sy}px)) scale(1.12)`;
  await perfSleepYell(560, pace);
  fly.style.opacity = '0';
  await perfSleepYell(100, pace);
  fly.remove();
  fromEl?.classList?.remove('perf-member-heart-pulse');
  perfIncrementHeartStat(heartsEl, display);
}

async function perfFlyBladeHeartToPanel(fromEl, heartsEl, color, chip, displayColor = null, opts = {}) {
  const pace = opts.pace ?? 1;
  if (!heartsEl || G._perfSpectacleAborted) return;
  const layer = el('perf-spectacle');
  if (!layer) return;
  const origin = perfYellBladeHeartOrigin(fromEl, chip);
  if (!origin) return;
  const sx = origin.x;
  const sy = origin.y;
  const fly = document.createElement('div');
  fly.className = 'perf-heart-fly';
  const resolvedDisplay = bladeHeartDisplayColor(color);
  const cardDisplay = bladeHeartDisplayColor(fromEl?.dataset?.bladeHeart || displayColor || color);
  const display = opts.flyResolvedColor ? resolvedDisplay : bladeHeartDisplayColor(displayColor || fromEl?.dataset?.bladeHeart || color);
  const flyIcon = fromEl.classList?.contains('bheart') && display === cardDisplay
    ? fromEl.cloneNode(true)
    : mkBladeHeartIcon(display, true);
  fly.appendChild(flyIcon);
  fly.style.left = sx + 'px';
  fly.style.top = sy + 'px';
  fly.style.transform = 'translate(-50%, -50%)';
  const heartFlyDur = Math.max(0.16, 0.56 * pace);
  const heartFadeDur = Math.max(0.08, 0.1 * pace);
  fly.style.transition = `transform ${heartFlyDur}s ease-out, opacity ${heartFadeDur}s ease`;
  layer.appendChild(fly);
  const bladeWrap = fromEl?.closest?.('.perf-yell-blade-hearts');
  fromEl?.remove?.();
  sfxPlayCard('hearts_gain', { volume: 0.34 });
  await perfSleepYell(40, pace);
  if (G._perfSpectacleAborted) { fly.remove(); return; }
  const to = perfHeartFlyTargetRect(heartsEl, color);
  const tx = to.left + to.width / 2;
  const ty = to.top + to.height / 2;
  fly.style.transform = `translate(calc(-50% + ${tx - sx}px), calc(-50% + ${ty - sy}px)) scale(1.12)`;
  await perfSleepYell(560, pace);
  fly.style.opacity = '0';
  await perfSleepYell(100, pace);
  fly.remove();
  if (bladeWrap && !bladeWrap.childElementCount) bladeWrap.remove();
  perfIncrementHeartStat(heartsEl, color);
}

function perfMeasureRailSlotW(rail) {
  if (!rail) return 280;
  const deck = rail.querySelector('.perf-deck-pile');
  const deckW = deck?.offsetWidth || 120;
  const innerGap = parseFloat(getComputedStyle(rail).gap) || 10;
  const memberW = deckW;
  // One yell stack slot (+ peek room for layered cards), not a 3-wide grid.
  const yellFoot = memberW + 14;
  return deckW + innerGap + yellFoot;
}

function tcgMobileSpectacleLayout() {
  return document.documentElement.classList.contains('tcg-mobile-viewport');
}

function clearPerfMobileInlineLayout() {
  const root = el('perf-spectacle');
  if (!root) return;
  root.querySelectorAll('.perf-live-row, .perf-stage-row').forEach(node => {
    node.style.removeProperty('--perf-live-w');
    node.style.removeProperty('--perf-live-h');
    node.style.removeProperty('--perf-live-row-h');
    node.style.removeProperty('--perf-stage-member-w');
    node.style.removeProperty('--perf-stage-member-h');
    node.style.removeProperty('--perf-stage-row-h');
  });
  root.querySelectorAll('.perf-col-body').forEach(node => {
    node.style.removeProperty('--perf-rail-slot-w');
  });
  root.querySelectorAll('.perf-col-side, .perf-yell-row').forEach(node => {
    node.style.removeProperty('transform');
  });
}

function layoutPerfRailSlots() {
  if (tcgMobileSpectacleLayout()) {
    clearPerfMobileInlineLayout();
    return;
  }
  ['perf-col-mine', 'perf-col-opp'].forEach(id => {
    const col = el(id);
    const body = col?.querySelector('.perf-col-body');
    const rail = body?.querySelector('.perf-yell-rail');
    if (body && rail) {
      const slot = Math.min(perfMeasureRailSlotW(rail), Math.max(160, window.innerWidth * 0.44));
      body.style.setProperty('--perf-rail-slot-w', slot + 'px');
    }
  });
}

function perfLiveRowMaxW(body) {
  if (!body) return Math.max(120, window.innerWidth * 0.36);
  const stack = body.querySelector('.perf-card-stack');
  if (stack?.clientWidth > 0) return Math.max(120, stack.clientWidth);
  const bodyW = body.clientWidth;
  const rail = body.querySelector('.perf-yell-rail');
  const bodyGap = parseFloat(getComputedStyle(body).gap) || 24;
  const railW = rail ? perfMeasureRailSlotW(rail) : 400;
  return Math.max(120, bodyW - railW - bodyGap);
}

function layoutPerfLiveRow(row) {
  if (!row) return;
  const cards = row.querySelectorAll('.perf-card.live');
  const count = cards.length;
  row.classList.toggle('perf-live-multi', count >= 2);
  if (tcgMobileSpectacleLayout()) {
    row.style.removeProperty('--perf-live-w');
    row.style.removeProperty('--perf-live-h');
    row.style.removeProperty('--perf-live-row-h');
    row.style.transform = '';
    return;
  }
  if (count < 2) {
    row.style.removeProperty('--perf-live-w');
    row.style.removeProperty('--perf-live-h');
    row.style.removeProperty('--perf-live-row-h');
    row.style.transform = '';
    return;
  }

  requestAnimationFrame(() => {
    const body = row.closest('.perf-col-body');
    const availW = perfLiveRowMaxW(body) - 6;
    const baseW = Math.min(240, Math.max(168, window.innerWidth * 0.26));
    const baseH = baseW * (63 / 88);
    const gap = parseFloat(getComputedStyle(row).gap) || 8;
    const naturalW = count * baseW + (count - 1) * gap;
    let scale = count === 3 ? 0.78 : 0.88;
    if (naturalW * scale > availW) scale = Math.max(0.38, availW / naturalW);
    else if (naturalW > availW) scale = Math.max(0.38, availW / naturalW);
    scale = Math.min(1, scale);
    const cardW = baseW * scale;
    const cardH = baseH * scale;
    const wStr = cardW.toFixed(1) + 'px';
    const hStr = cardH.toFixed(1) + 'px';
    if (row.style.getPropertyValue('--perf-live-w') !== wStr) {
      row.style.setProperty('--perf-live-w', wStr);
      row.style.setProperty('--perf-live-h', hStr);
      row.style.setProperty('--perf-live-row-h', hStr);
    }
  });
}

function layoutPerfStageRow(row) {
  if (!row) return;
  const cards = row.querySelectorAll('.perf-card.member');
  const count = cards.length;
  row.classList.toggle('perf-stage-multi', count >= 3);
  if (tcgMobileSpectacleLayout()) {
    row.style.removeProperty('--perf-stage-member-w');
    row.style.removeProperty('--perf-stage-member-h');
    row.style.removeProperty('--perf-stage-row-h');
    return;
  }
  row.style.removeProperty('--perf-stage-member-w');
  row.style.removeProperty('--perf-stage-member-h');
  row.style.removeProperty('--perf-stage-row-h');
  if (count < 3) return;

  requestAnimationFrame(() => {
    const body = row.closest('.perf-col-body');
    const availW = perfLiveRowMaxW(body) - 6;
    const baseW = Math.min(136, Math.max(100, window.innerWidth * 0.16));
    const baseH = baseW * (88 / 63);
    const gap = parseFloat(getComputedStyle(row).gap) || 8;
    const naturalW = count * baseW + (count - 1) * gap;
    let scale = 0.88;
    if (naturalW * scale > availW) scale = Math.max(0.5, availW / naturalW);
    else if (naturalW > availW) scale = Math.max(0.5, availW / naturalW);
    scale = Math.min(1, scale);
    const cardW = baseW * scale;
    const cardH = baseH * scale;
    row.style.setProperty('--perf-stage-member-w', cardW.toFixed(1) + 'px');
    row.style.setProperty('--perf-stage-member-h', cardH.toFixed(1) + 'px');
    row.style.setProperty('--perf-stage-row-h', cardH.toFixed(1) + 'px');
  });
}

function layoutPerfStageRows() {
  layoutPerfStageRow(el('perf-stage-row'));
  layoutPerfStageRow(el('perf-opp-stage'));
}

/** Keep player label/outcomes left of widening live rows — shift name block left on overlap. */
function layoutPerfColSide(col) {
  if (!col) return;
  const side = col.querySelector('.perf-col-side');
  if (tcgMobileSpectacleLayout()) {
    if (side) side.style.removeProperty('transform');
    return;
  }
  const stack = col.querySelector('.perf-card-stack');
  const liveRow = col.querySelector('.perf-live-row');
  if (!side || !stack) return;

  requestAnimationFrame(() => {
    const stage = col.closest('.perf-stage');
    const stageRect = stage?.getBoundingClientRect();
    const gap = 12;
    const liveCards = liveRow?.querySelectorAll('.perf-card.live') || [];
    const target = liveCards.length ? liveRow : stack;
    const sideRect = side.getBoundingClientRect();
    const targetRect = target.getBoundingClientRect();
    let nextTransform = '';
    if (sideRect.right > targetRect.left - gap) {
      const overlap = sideRect.right - (targetRect.left - gap);
      const stageLeft = (stageRect?.left ?? 0) + 6;
      const maxShift = Math.max(0, sideRect.left - stageLeft);
      const shift = Math.min(overlap, maxShift);
      if (shift > 1) nextTransform = `translateX(-${shift.toFixed(1)}px)`;
    }
    if (side.style.transform !== nextTransform) side.style.transform = nextTransform;
  });
}

function layoutPerfColSides() {
  layoutPerfColSide(el('perf-col-mine'));
  layoutPerfColSide(el('perf-col-opp'));
}

function layoutPerfLiveRows() {
  layoutPerfRailSlots();
  layoutPerfLiveRow(el('perf-mine-lives'));
  layoutPerfLiveRow(el('perf-opp-lives'));
  layoutPerfStageRows();
  layoutPerfYellRail(el('perf-mine-yell')?.closest('.perf-yell-rail'));
  layoutPerfYellRail(el('perf-opp-yell')?.closest('.perf-yell-rail'));
  layoutPerfColSides();
  requestAnimationFrame(() => {
    layoutPerfRailSlots();
    layoutPerfLiveRow(el('perf-mine-lives'));
    layoutPerfLiveRow(el('perf-opp-lives'));
    layoutPerfYellRail(el('perf-mine-yell')?.closest('.perf-yell-rail'));
    layoutPerfYellRail(el('perf-opp-yell')?.closest('.perf-yell-rail'));
    layoutPerfColSides();
  });
}

function layoutPerfYellStack(yellRow) {
  if (!yellRow) return;
  const chips = [...yellRow.querySelectorAll('.perf-yell-card')];
  const n = chips.length;
  const maxPeek = 5;
  const step = 3;
  chips.forEach((chip, i) => {
    const depthFromTop = n - 1 - i;
    const d = Math.min(depthFromTop, maxPeek);
    chip.style.setProperty('--yell-stack-x', `${-d * step}px`);
    chip.style.setProperty('--yell-stack-y', `${d * step}px`);
    chip.style.setProperty('--yell-stack-scale', String(1 - d * 0.012));
    chip.style.zIndex = String(i + 1);
    chip.classList.toggle('perf-yell-stack-top', depthFromTop === 0);
  });
  let badge = yellRow.querySelector('.perf-yell-stack-count');
  if (n > 1) {
    if (!badge) {
      badge = document.createElement('div');
      badge.className = 'perf-yell-stack-count';
      badge.setAttribute('aria-hidden', 'true');
      yellRow.appendChild(badge);
    }
    badge.textContent = String(n);
    badge.hidden = false;
    badge.style.zIndex = String(n + 5);
  } else if (badge) {
    badge.hidden = true;
  }
}

function layoutPerfYellRail(rail) {
  if (!rail) return;
  const yellRow = rail.querySelector('.perf-yell-row');
  if (!yellRow) return;
  yellRow.classList.remove('perf-yell-many');
  yellRow.style.removeProperty('transform');
  layoutPerfYellStack(yellRow);
}

function perfFillHearts(container, hearts) {
  if (!container) return;
  container.innerHTML = '';
  container.classList.remove('dense');
  if (!hearts?.length) {
    container.textContent = '—';
    return;
  }
  appendHeartStatCounts(container, hearts, { lg: true, field: false });
  container.style.flexDirection = 'row';
  container.style.flexWrap = 'nowrap';
  container.style.alignItems = 'center';
  if (groupHeartsByColor(hearts).length >= 4) container.classList.add('dense');
}

function perfRenderBladeRow(bladeEl, totalBlade, { pending = false } = {}) {
  if (!bladeEl) return null;
  bladeEl.hidden = false;
  bladeEl.classList.toggle('perf-blade-pending', pending);
  bladeEl.classList.toggle('perf-blade-active', !pending);
  bladeEl.innerHTML = '';
  bladeEl.appendChild(mkGameIcon('icon_blade.png', 'bicon', 'Blade'));
  const num = document.createElement('span');
  num.className = 'perf-blade-num';
  num.textContent = String(totalBlade);
  bladeEl.appendChild(num);
  return num;
}

function perfOutcomeLabel(att) {
  if (att.success) return '✓ Live Success';
  if (att.fail) return '✗ Live Failed';
  return '… Live';
}

/** Index of the current Performance round in the log (not prior turns). */
function currentPerformanceRoundLogStart(next) {
  const log = next?.log || [];
  for (let i = log.length - 1; i >= 0; i--) {
    const msg = log[i]?.msg || '';
    if (msg === '=== Performance Phase ===' || msg === '=== Live Start Effects ===') return i;
  }
  for (let i = log.length - 1; i >= 0; i--) {
    if ((log[i]?.msg || '') === '=== LIVE Phase ===') return i + 1;
  }
  return 0;
}

function playerAttemptedLiveThisRound(next, pid) {
  if (Array.isArray(next?.live_attempt)) {
    return next.live_attempt.includes(pid);
  }
  const lrs = next?.live_round_success;
  if (lrs && typeof lrs === 'object' && Object.keys(lrs).length) {
    return Object.prototype.hasOwnProperty.call(lrs, pid);
  }
  const name = next?.players?.[pid]?.name;
  if (!name) return false;
  const start = currentPerformanceRoundLogStart(next);
  for (let i = start; i < (next?.log || []).length; i++) {
    const msg = next.log[i]?.msg || '';
    if (msg.includes(`${name} is performing Live with`)) return true;
  }
  return false;
}

/** True when this player's performance result is logged for the current Live round. */
function playerHasPerfLogThisRound(next, pid) {
  const name = next?.players?.[pid]?.name;
  if (!name) return false;
  const start = currentPerformanceRoundLogStart(next);
  for (let i = start; i < (next?.log || []).length; i++) {
    const msg = next.log[i]?.msg || '';
    if (!msg.startsWith(name)) continue;
    if (msg.includes(' performed Live! Blades: ')) return true;
    if (msg.includes(' has no valid Live cards')) return true;
  }
  return false;
}

/** Both sides that attempted a Live this round have a performance log line. */
function liveRoundPerfLogsComplete(next) {
  if (!next?.players) return false;
  const attempted = ['p1', 'p2'].filter(pid => playerAttemptedLiveThisRound(next, pid));
  if (!attempted.length) {
    const start = currentPerformanceRoundLogStart(next);
    return (next.log || []).slice(start).some(e => e.msg === 'No Lives played this turn.');
  }
  return attempted.every(pid => playerHasPerfLogThisRound(next, pid));
}

/** Judge / full spectacle replay may run — all perf logs in, no mid-pipeline blocker prompt. */
function liveRoundJudgeReady(next) {
  if (!next) return false;
  if (next.phase === 'live_judge') return true;
  if (!liveRoundPerfLogsComplete(next)) return false;
  const pr = next.pending_prompt;
  if (pr && !PERF_SPECTACLE_DEFERRED_PROMPTS.has(pr.type)
      && !PERF_SPECTACLE_MID_PROMPTS.has(pr.type)) {
    return false;
  }
  if (next.phase === 'live_success_effects') return true;
  const start = currentPerformanceRoundLogStart(next);
  return (next.log || []).slice(start).some(e => /^Live Scores: /.test(e.msg || ''));
}

function perfLiveSuccessCountFromLog(next, pid, prev = null) {
  const name = next?.players?.[pid]?.name;
  if (!name) return 0;
  if (!playerAttemptedLiveThisRound(next, pid)) return 0;
  const esc = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const re = new RegExp(`^${esc} performed Live! Blades: \\d+ \\| Hearts: \\[[^\\]]*\\] \\| Live success: (\\d+)`);
  const start = prev ? (prev.log?.length || 0) : currentPerformanceRoundLogStart(next);
  const log = (next.log || []).slice(start);
  for (let i = log.length - 1; i >= 0; i--) {
    const m = (log[i].msg || '').match(re);
    if (m) return parseInt(m[1], 10);
  }
  return 0;
}

function perfLivePerfSuccessIds(next, pid) {
  const direct = next?.live_perf_success?.[pid];
  if (direct?.length) return new Set(direct);
  if (isLiveSetPhase(next?.phase)) return null;
  const snap = next?._live_perf_snapshot?.[pid];
  if (snap?.length) return new Set(snap);
  return null;
}

/** Overall Live round cleared (every Live card passed hearts). */
function playerLiveRoundSucceeded(next, pid) {
  if (isLiveSetPhase(next?.phase)) {
    if (next?.live_round_success && pid in next.live_round_success) {
      return !!next.live_round_success[pid];
    }
    return false;
  }
  const perfYellPhases = ['live_performance_first', 'live_performance_second'];
  if (perfYellPhases.includes(next?.phase ?? '')) {
    const snap = next?._live_round_success_snapshot;
    if (snap && pid in snap) return !!snap[pid];
    return false;
  }
  if (next?.live_round_success && pid in next.live_round_success) {
    if (!playerHasPerfLogThisRound(next, pid)) {
      return false;
    }
    return !!next.live_round_success[pid];
  }
  const snap = next?._live_round_success_snapshot;
  if (snap && pid in snap) return !!snap[pid];
  if (!playerAttemptedLiveThisRound(next, pid)) return false;
  const name = next?.players?.[pid]?.name;
  if (!name) return false;
  const esc = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const re = new RegExp(
    `^${esc} performed Live! Blades: \\d+ \\| Hearts: \\[[^\\]]*\\] \\| Live success: (\\d+) \\| Failed: (\\d+)(?: \\| Round: failed \\(not all Lives succeeded\\))?`
  );
  const start = currentPerformanceRoundLogStart(next);
  for (let i = (next?.log || []).length - 1; i >= start; i--) {
    const msg = next.log[i].msg || '';
    const m = msg.match(re);
    if (!m) continue;
    if (/Round: failed \(not all Lives succeeded\)/.test(msg)) return false;
    const ok = parseInt(m[1], 10);
    const fail = parseInt(m[2], 10);
    return ok > 0 && fail === 0;
  }
  return false;
}

function playerClearedLiveThisRound(next, pid) {
  return playerLiveRoundSucceeded(next, pid);
}

function bothPlayersClearedLiveThisRound(next) {
  return playerClearedLiveThisRound(next, 'p1') && playerClearedLiveThisRound(next, 'p2');
}

function perfSucceededLiveIids(next, pid, beforeZone) {
  const perfOkIds = perfLivePerfSuccessIds(next, pid);
  if (perfOkIds?.size) return perfOkIds;

  const beforeLive = (beforeZone || []).filter(c => isLiveTypeCard(c));
  const liveIds = new Set((next.players?.[pid]?.live_zone || []).map(c => c.instance_id));
  const successLiveIds = new Set((next.players?.[pid]?.success_lives || []).map(c => c.instance_id));
  const fromState = new Set();
  beforeLive.forEach(c => {
    const iid = c.instance_id;
    if (!iid) return;
    if (successLiveIds.has(iid) || liveIds.has(iid)) fromState.add(iid);
  });

  const logOk = perfLiveSuccessCountFromLog(next, pid);
  if (logOk > 0) {
    let assigned = fromState.size;
    for (const c of beforeLive) {
      if (fromState.has(c.instance_id)) continue;
      if (assigned < logOk) {
        fromState.add(c.instance_id);
        assigned++;
      }
    }
    if (fromState.size) return fromState;
  }
  if (fromState.size && playerLiveRoundSucceeded(next, pid)) return fromState;

  if (!playerLiveRoundSucceeded(next, pid)) return new Set();
  const succeeded = new Set(fromState);
  beforeLive.forEach(c => {
    if (liveIds.has(c.instance_id) || successLiveIds.has(c.instance_id)) succeeded.add(c.instance_id);
  });
  if (logOk <= 0) return succeeded;
  let assigned = succeeded.size;
  for (const c of beforeLive) {
    if (succeeded.has(c.instance_id)) continue;
    if (assigned < logOk) {
      succeeded.add(c.instance_id);
      assigned++;
    }
  }
  return succeeded;
}

function perfResolveLiveAttemptCard(perfPrev, next, pid, c) {
  let raw = c;
  if (!raw?.card_no || raw.card_no === '?' || raw.score == null) {
    const revealed = perfFindRevealedLiveMeta(next, pid, c.instance_id)
      || perfFindRevealedLiveMeta(perfPrev, pid, c.instance_id);
    if (revealed) raw = { ...c, ...revealed };
  }
  const card = enrichCard({ ...raw, revealed: true });
  card.revealed = true;
  return card;
}

function perfLiveAttempts(prev, next, pid) {
  const perfPrev = buildPerfSpectaclePrev(prev, next);
  const before = perfMergedLiveZone(perfPrev, next, pid);
  const succeededIds = perfSucceededLiveIids(next, pid, before);
  const wrIds = new Set((next.players?.[pid]?.waiting_room || []).map(c => c.instance_id));
  const seen = new Set();
  const attempts = [];

  const pushAttempt = (raw, succeededOverride = null) => {
    const iid = raw?.instance_id || '';
    if (!iid || seen.has(iid)) return;
    if (!isPerfSpectacleLiveSlotCard(raw, next, pid)) {
      const meta = perfFindRevealedLiveMeta(next, pid, iid);
      if (!isLiveTypeCard(meta) && !isLiveTypeCard(raw)) return;
    }
    seen.add(iid);
    const card = perfResolveLiveAttemptCard(perfPrev, next, pid, raw);
    if (!isLiveTypeCard(card)) return;
    const inSuccessZone = (next.players?.[pid]?.success_lives || [])
      .some(c => c.instance_id === iid);
    const succeeded = succeededOverride !== null
      ? succeededOverride
      : (succeededIds.has(iid) || inSuccessZone);
    attempts.push({
      card,
      success: succeeded,
      // Only mark fail when the card is actually in WR and not in Success.
      fail: !succeeded && wrIds.has(iid),
    });
  };

  before.forEach(c => {
    if (!isPerfSpectacleLiveSlotCard(c, next, pid)) return;
    pushAttempt(c);
  });
  for (const iid of succeededIds) {
    if (seen.has(iid)) continue;
    pushAttempt(perfLookupLiveAttemptCard(perfPrev, next, pid, iid), true);
  }
  const logOk = perfLiveSuccessCountFromLog(next, pid);
  if (logOk > attempts.filter(a => a.success).length) {
    before.forEach(c => {
      if (attempts.filter(a => a.success).length >= logOk) return;
      if (!seen.has(c.instance_id)) pushAttempt(c, true);
    });
  }
  const snapIds = next?._live_perf_snapshot?.[pid];
  if (snapIds?.length) {
    snapIds.forEach(iid => {
      if (!iid || seen.has(iid)) return;
      pushAttempt(perfLookupLiveAttemptCard(perfPrev, next, pid, iid));
    });
  }

  if (TCG_DEBUG.on && TCG_DEBUG.cats.has('live')) {
    TCG_DEBUG.log('live', `attempts ${pid}`, attempts.map(a => ({
      card: a.card?.name_en || a.card?.name,
      ok: a.success,
      score: a.card?.score,
    })));
  }
  return attempts;
}

function perfCardSuccessScore(attempts) {
  return (attempts || []).filter(a => a.success).reduce((s, a) => {
    const c = enrichCard(a.card);
    return s + (Number(c?.score) || 0) + Number(c?.live_score_bonus || 0);
  }, 0);
}

/** True when Yell-dependent Live Score bonuses should still apply (Performance or post-Live carryover). */
function perfHasYellJudgeContext(s) {
  if (['live_performance_first', 'live_performance_second', 'live_success_effects', 'live_judge'].includes(s?.phase)) {
    return true;
  }
  return !!(s?._live_round_success_snapshot || s?._yell_reveal_snapshot || s?._yell_blade_snapshot);
}

function liveCardsHaveDrawPerYellDraw(liveCards) {
  // Kept for callers; Yell draw icons always apply (not gated on Live continuous).
  return true;
}

/** Live Score modifiers on stage (matches server getLiveScoreBonus when judge log unavailable). */
function perfLiveScoreBonusForPlayer(ctx, pid) {
  if (!playerLiveRoundSucceeded(ctx.next, pid)) return 0;
  const board = ctx?.next?.stage_board;
  const side = pid === ctx.myId ? board?.mine : board?.opp;
  const fromBoard = Number(side?.live_score_bonus ?? 0);
  if (fromBoard > 0) return fromBoard;
  return computeLiveScoreStageBonus(ctx.next, pid, { yellContext: perfHasYellJudgeContext(ctx.next) });
}

function perfJudgeScoresFromLog(next, myId) {
  if (!bothPlayersClearedLiveThisRound(next)) return null;
  const start = currentPerformanceRoundLogStart(next);
  for (let i = (next?.log || []).length - 1; i >= start; i--) {
    const m = (next.log[i].msg || '').match(/^Live Scores: (.+?) = (\d+) \| (.+?) = (\d+)/);
    if (!m) continue;
    const [, n1, s1, n2, s2] = m;
    const sc1 = parseInt(s1, 10);
    const sc2 = parseInt(s2, 10);
    const myIsN1 = playerNameMatches(n1, next, myId);
    return { my: myIsN1 ? sc1 : sc2, opp: myIsN1 ? sc2 : sc1 };
  }
  return null;
}

/** Authoritative judge totals — prefer server log, else card scores + stage_board bonus. */
function perfJudgeTotals(ctx) {
  const fromLog = perfJudgeScoresFromLog(ctx.next, ctx.myId);
  const cardMy = perfCardSuccessScore(ctx.mineAttempts);
  const cardOpp = perfCardSuccessScore(ctx.oppAttempts);
  if (fromLog) {
    return {
      my: fromLog.my,
      opp: fromLog.opp,
      cardMy,
      cardOpp,
      bonusMy: Math.max(0, fromLog.my - cardMy),
      bonusOpp: Math.max(0, fromLog.opp - cardOpp),
      fromLog: true,
    };
  }
  const bonusMy = perfLiveScoreBonusForPlayer(ctx, ctx.myId);
  const bonusOpp = perfLiveScoreBonusForPlayer(ctx, ctx.oppId);
  return {
    my: cardMy + bonusMy,
    opp: cardOpp + bonusOpp,
    cardMy,
    cardOpp,
    bonusMy,
    bonusOpp,
    fromLog: false,
  };
}

function perfSuccessScore(attempts) {
  return perfCardSuccessScore(attempts);
}

function perfBothSucceeded(ctx) {
  return playerClearedLiveInCtx(ctx, ctx.myId) && playerClearedLiveInCtx(ctx, ctx.oppId);
}

function playerClearedLiveInCtx(ctx, pid) {
  return playerLiveRoundSucceeded(ctx.next, pid);
}

function perfLookupLiveAttemptCard(perfPrev, next, pid, instanceId) {
  const merged = perfMergedLiveZone(perfPrev, next, pid);
  const pools = [
    ...merged,
    ...(next?.players?.[pid]?.live_zone || []),
    ...(next?.players?.[pid]?.success_lives || []),
    ...(next?.players?.[pid]?.waiting_room || []),
  ];
  for (const c of pools) {
    if ((c?.instance_id || '') === instanceId) return c;
  }
  const meta = perfFindRevealedLiveMeta(next, pid, instanceId);
  return meta || { instance_id: instanceId };
}

function perfTieSuccessLiveVerdict(ctx) {
  const myBefore = (ctx.prev?.players?.[ctx.myId]?.success_lives || []).length;
  const oppBefore = (ctx.prev?.players?.[ctx.oppId]?.success_lives || []).length;
  const myCapped = myBefore >= 2;
  const oppCapped = oppBefore >= 2;
  if (myCapped && oppCapped) {
    return { text: 'Tie — already at 2 Success Lives; neither adds another', cls: 'tie' };
  }
  if (myCapped) {
    return { text: 'Tie — you already have 2 Success Lives; opponent earns one', cls: 'tie' };
  }
  if (oppCapped) {
    return { text: 'Tie — opponent already has 2 Success Lives; you earn one', cls: 'tie' };
  }
  return { text: 'Tie — both earn a Success Live!', cls: 'tie' };
}

function perfJudgeVerdict(ctx) {
  const { opp } = ctx;
  const mineOk = playerClearedLiveInCtx(ctx, ctx.myId);
  const oppOk = playerClearedLiveInCtx(ctx, ctx.oppId);
  const { my: myScore, opp: oppScore, bonusMy, bonusOpp } = perfJudgeTotals(ctx);
  const modifierNote = (bonusMy > 0 || bonusOpp > 0)
    ? ' (includes Live Score modifiers)'
    : '';
  if (mineOk && oppOk) {
    if (myScore > oppScore) return { text: 'You win the Live!' + modifierNote, cls: 'win' };
    if (oppScore > myScore) return { text: `${opp?.name || 'Opponent'} wins the Live!` + modifierNote, cls: 'lose' };
    const tieVerdict = perfTieSuccessLiveVerdict(ctx);
    return { text: tieVerdict.text + modifierNote, cls: tieVerdict.cls };
  }
  if (mineOk) {
    if (playerAttemptedLiveThisRound(ctx.next, ctx.oppId)
        && !playerHasPerfLogThisRound(ctx.next, ctx.oppId)) {
      return { text: '', cls: 'tie' };
    }
    return { text: 'You win the Live!', cls: 'win' };
  }
  if (oppOk) {
    if (!ctx.mineAttempts.length) {
      return { text: `${opp?.name || 'Opponent'} wins the Live!`, cls: 'lose' };
    }
    return { text: 'You lose the Live!', cls: 'lose' };
  }
  return { text: 'Nobody clears a Live this turn', cls: 'tie' };
}

function perfMkCardScoreBadge(cardOrScore, opts = {}) {
  const card = (cardOrScore && typeof cardOrScore === 'object') ? enrichCard(cardOrScore) : null;
  const info = card
    ? liveCardScoreInfo(card, opts)
    : { base: Number(cardOrScore || 0), effective: Number(cardOrScore || 0), bonus: 0, cardBonus: 0, extraBonus: 0 };
  const wrap = document.createElement('div');
  wrap.className = 'perf-card-score';
  wrap.appendChild(mkGameIcon('sp_score.png', 'ticon', 'Score'));
  const val = document.createElement('span');
  val.className = 'perf-card-score-val';
  val.textContent = String(info.base);
  wrap.appendChild(val);
  if (info.bonus > 0) {
    const bonus = document.createElement('span');
    bonus.className = 'perf-card-score-bonus';
    bonus.textContent = '+' + info.bonus;
    wrap.appendChild(bonus);
    const tip = [`Printed ${info.base}`];
    if (info.cardBonus > 0) tip.push(`${info.cardBonus} from card effects`);
    if (info.extraBonus > 0) tip.push(`${info.extraBonus} from Live Score modifiers`);
    wrap.title = tip.join(' + ');
  }
  return wrap;
}

/** Yellow sp_score +N — one badge on the live row (adds to total judge score, not per Live). */
function perfMkYellScoreTotalBadge(amount) {
  const wrap = document.createElement('div');
  wrap.className = 'perf-card-score perf-yell-score-total';
  wrap.title = 'Adds to your total Live performance score';
  wrap.appendChild(mkGameIcon('sp_score.png', 'ticon', 'Yell score'));
  const bonus = document.createElement('span');
  bonus.className = 'perf-card-score-bonus';
  bonus.textContent = '+' + amount;
  wrap.appendChild(bonus);
  return wrap;
}

function perfResetYellEffectAccumulators() {
  G._perfYellScoreAccum = { p1: 0, p2: 0 };
  G._perfYellDrawPending = { p1: 0, p2: 0 };
}

function perfClearYellScoreTotalBadges() {
  document.querySelectorAll('.perf-yell-score-total').forEach(n => n.remove());
}

function perfClearYellDrawPendingBadges() {
  document.querySelectorAll('.perf-yell-draw-pending').forEach(n => n.remove());
}

async function perfApplyYellScoreBonus(ctx, pid, addAmount, { pace = 1, animate = true } = {}) {
  const n = Number(addAmount) || 0;
  if (n <= 0) return;
  const row = el(pid === ctx.myId ? 'perf-mine-lives' : 'perf-opp-lives');
  if (!row) return;
  if (!G._perfYellScoreAccum) perfResetYellEffectAccumulators();
  G._perfYellScoreAccum[pid] = (G._perfYellScoreAccum[pid] || 0) + n;
  const total = G._perfYellScoreAccum[pid];
  let badge = row.querySelector('.perf-yell-score-total');
  if (!badge) {
    badge = perfMkYellScoreTotalBadge(total);
    row.appendChild(badge);
  } else {
    const bonusEl = badge.querySelector('.perf-card-score-bonus');
    if (bonusEl) bonusEl.textContent = '+' + total;
  }
  if (animate) {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    badge.classList.remove('show', 'punch');
    requestAnimationFrame(() => {
      badge.classList.add('show');
      if (!reduced) badge.classList.add('punch');
      sfxPlayCard('card_place', { volume: 0.24 });
    });
    await perfSleepYell(reduced ? 80 : 220, pace);
  } else {
    badge.classList.add('show');
  }
}

function perfBumpYellDrawPending(deckEl, pid, addAmount, { animate = true } = {}) {
  const n = Number(addAmount) || 0;
  if (n <= 0 || !deckEl) return;
  if (!G._perfYellDrawPending) perfResetYellEffectAccumulators();
  G._perfYellDrawPending[pid] = (G._perfYellDrawPending[pid] || 0) + n;
  const total = G._perfYellDrawPending[pid];
  let badge = deckEl.querySelector('.perf-yell-draw-pending');
  if (!badge) {
    badge = document.createElement('div');
    badge.className = 'perf-yell-draw-pending';
    deckEl.appendChild(badge);
  }
  badge.replaceChildren();
  badge.appendChild(mkGameIcon('sp_draw.png', 'ticon', 'Yell draw'));
  const val = document.createElement('span');
  val.className = 'perf-yell-draw-val';
  val.textContent = '+' + total;
  badge.appendChild(val);
  if (animate) {
    badge.classList.remove('show', 'punch');
    requestAnimationFrame(() => {
      badge.classList.add('show');
      if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        badge.classList.add('punch');
      }
      sfxPlayCard('card_place', { volume: 0.22 });
    });
  } else {
    badge.classList.add('show');
  }
}

/** Stage-wide Live Score bonus on the last successful Live card badge for this player. */
function perfLiveCardBadgeExtraBonus(ctx, pid, successIndex, successCount) {
  if (successIndex !== successCount - 1) return 0;
  return perfLiveScoreBonusForPlayer(ctx, pid);
}

function perfClearLiveCardScores() {
  document.querySelectorAll('.perf-card-score').forEach(n => n.remove());
}

function perfClearJudgeUi() {
  perfClearLiveCardScores();
  el('perf-judge-fx-layer') && (el('perf-judge-fx-layer').innerHTML = '');
  el('perf-judge-title')?.setAttribute('hidden', '');
  el('perf-judge-title')?.classList.remove('show');
  el('perf-judge-duel')?.setAttribute('hidden', '');
  el('perf-judge-duel')?.classList.remove('show');
  const solo = el('perf-judge-solo');
  if (solo) {
    solo.hidden = true;
    solo.classList.remove('show', 'win', 'lose', 'tie');
    solo.textContent = '';
  }
  const verdictEl = el('perf-verdict');
  if (verdictEl) {
    verdictEl.textContent = '';
    verdictEl.className = 'perf-verdict';
  }
}

function perfClearYellUi() {
  perfClearYellScoreTotalBadges();
  perfClearYellDrawPendingBadges();
  ['perf-mine-yell', 'perf-opp-yell'].forEach(id => {
    const row = el(id);
    if (!row) return;
    row.innerHTML = '';
    row.style.transform = '';
    row.classList.remove('perf-yell-many');
  });
  ['perf-mine-outcomes', 'perf-opp-outcomes'].forEach(id => {
    const node = el(id);
    if (node) node.innerHTML = '';
  });
}

function perfClearHeartsBladeUi() {
  ['perf-mine-hearts', 'perf-opp-hearts'].forEach(id => {
    const node = el(id);
    if (!node) return;
    node.innerHTML = '';
    node.textContent = '';
    node.classList.remove('dense');
  });
  ['perf-mine-blade', 'perf-opp-blade'].forEach(id => {
    const node = el(id);
    if (!node) return;
    node.hidden = true;
    node.innerHTML = '';
    node.classList.remove('perf-blade-pending', 'perf-blade-active');
  });
}

function perfLiveCardEl(ctx, pid, iid) {
  const rowId = pid === ctx.myId ? 'perf-mine-lives' : 'perf-opp-lives';
  return el(rowId)?.querySelector(`.perf-card.live[data-iid="${CSS.escape(iid)}"]`) || null;
}

function perfApplyLiveCardScores(ctx, { show = true, animate = false } = {}) {
  perfClearLiveCardScores();
  const attach = (pid, attempts) => {
    const successes = attempts.filter(a => a.success);
    successes.forEach((att, i) => {
      const cardEl = perfLiveCardEl(ctx, pid, att.card.instance_id);
      if (!cardEl) return;
      const extraBonus = perfLiveCardBadgeExtraBonus(ctx, pid, i, successes.length);
      const badge = perfMkCardScoreBadge(att.card, { extraBonus });
      cardEl.appendChild(badge);
      if (show) {
        if (animate) {
          setTimeout(() => badge.classList.add('show'), i * 140);
        } else {
          badge.classList.add('show');
        }
      }
    });
  };
  attach(ctx.myId, ctx.mineAttempts);
  attach(ctx.oppId, ctx.oppAttempts);
  requestAnimationFrame(() => layoutPerfLiveRows());
}

async function perfAnimateCount(el, from, to, ms = 420) {
  if (!el) return;
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduced || from === to) {
    el.textContent = String(to);
    return;
  }
  const start = performance.now();
  await new Promise(resolve => {
    const tick = now => {
      const t = Math.min(1, (now - start) / ms);
      const eased = 1 - Math.pow(1 - t, 3);
      el.textContent = String(Math.round(from + (to - from) * eased));
      if (t < 1) requestAnimationFrame(tick);
      else {
        el.classList.add('tick');
        sfxPerf('skill_tick', 0.3);
        setTimeout(() => el.classList.remove('tick'), 180);
        resolve();
      }
    };
    requestAnimationFrame(tick);
  });
}

async function perfFlyScoreBadges(sources, targetEl, duration = 340) {
  const layer = el('perf-judge-fx-layer');
  if (!layer || !targetEl || !sources.length) return;
  const tr = targetEl.getBoundingClientRect();
  const tx = tr.left + tr.width / 2;
  const ty = tr.top + tr.height / 2;
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduced) {
    sources.forEach(s => { s.style.opacity = '0'; });
    return;
  }
  const flies = sources.map(src => {
    const sr = src.getBoundingClientRect();
    const fly = src.cloneNode(true);
    fly.classList.add('perf-score-fly');
    fly.style.left = `${sr.left}px`;
    fly.style.top = `${sr.top}px`;
    fly.style.width = `${sr.width}px`;
    fly.style.height = `${sr.height}px`;
    layer.appendChild(fly);
    src.style.opacity = '0';
    const dx = tx - (sr.left + sr.width / 2);
    const dy = ty - (sr.top + sr.height / 2);
    requestAnimationFrame(() => {
      fly.style.transform = `translate(${dx}px, ${dy}px) scale(0.55)`;
      fly.style.opacity = '0.9';
    });
    return fly;
  });
  await perfSleep(duration);
  flies.forEach(f => f.remove());
}

function perfJudgeSoloLine(ctx) {
  const verdict = perfJudgeVerdict(ctx);
  const mineOk = playerClearedLiveInCtx(ctx, ctx.myId);
  const oppOk = playerClearedLiveInCtx(ctx, ctx.oppId);
  if (mineOk && !oppOk) return { text: 'You win the Live!', cls: 'win' };
  if (oppOk && !mineOk) return { text: verdict.text, cls: 'lose' };
  if (verdict.cls === 'win') return { text: 'You win the Live!', cls: 'win' };
  if (verdict.cls === 'lose') return { text: verdict.text, cls: 'lose' };
  return { text: verdict.text, cls: verdict.cls };
}

function perfCloseSpectacle() {
  G._perfSpectacleAbort?.();
  G._perfSpectacleAbort = null;
  G._perfSpectacleAborted = false;
  G._perfCtx = null;
  G._perfLiveReveal = null;
  G._perfSpectaclePhase = 'closed';
  G._perfSpectacleActive = false;
  G._skipJudgeOverlay = false;
  clearTimeout(G._liveJudgeOverlayTimer);
  G._liveJudgeOverlayTimer = null;
  G._liveJudgeOverlayHold = false;
  G._liveJudgeScores = null;
  const ljo = el('live-judge-overlay');
  if (ljo) {
    ljo.classList.remove('show');
    ljo.hidden = true;
  }
  setJudgeHint('', '');
  if (G._perfLiveRowResize) {
    window.removeEventListener('resize', G._perfLiveRowResize);
    G._perfLiveRowResize = null;
  }
  document.body.classList.remove('perf-spectacle-active');
  sweepStaleLiveStorageFlipDom(G.gameState, G.playerId);
  const root = el('perf-spectacle');
  if (root) {
    root.classList.remove('show');
    root.hidden = true;
  }
  el('perf-splash')?.classList.remove('show', 'live-start');
  el('perf-judge-panel')?.classList.remove('show');
  el('perf-judge-panel')?.setAttribute('hidden', '');
  perfClearJudgeUi();
  perfClearYellUi();
  perfClearHeartsBladeUi();
  G._perfYellScoreAccum = null;
  G._perfYellDrawPending = null;
  el('perf-fx-toast')?.classList.remove('show');
  el('perf-col-mine')?.classList.remove('in');
  el('perf-col-opp')?.classList.remove('in');
  ['perf-stage-row', 'perf-opp-stage', 'perf-mine-deck', 'perf-opp-deck',
    'perf-mine-lives', 'perf-opp-lives', 'perf-mine-outcomes', 'perf-opp-outcomes'].forEach(id => {
    const node = el(id);
    if (node) node.innerHTML = '';
  });
  if (G.playerId && G.gameState) {
    updateOpponentSkillWaitBanner(G.gameState, G.playerId);
  }
}

function perfOpenSpectacle() {
  const root = el('perf-spectacle');
  if (!root) return;
  G._perfSpectacleActive = true;
  G._skipJudgeOverlay = true;
  document.body.classList.add('perf-spectacle-active');
  root.hidden = false;
  root.classList.add('show');
  el('opp-skill-wait')?.classList.remove('show');
  if (el('opp-skill-wait')) el('opp-skill-wait').hidden = true;
  if (!G._perfLiveRowResize) {
    G._perfLiveRowResize = () => { if (G._perfSpectacleActive) layoutPerfLiveRows(); };
    window.addEventListener('resize', G._perfLiveRowResize);
  }
  if (tcgMobileSpectacleLayout()) clearPerfMobileInlineLayout();
}

function perfBuildContext(prev, next, myId) {
  const yellReveal = perfYellRevealFor(next);
  const nextForPerf = mergePerfYellRevealState(next, yellReveal);
  const oppId = myId === 'p1' ? 'p2' : 'p1';
  return {
    prev, next: nextForPerf, myId, oppId,
    key: `${next.seq}:${next.turn}:${next.phase}`,
    me: nextForPerf.players[myId],
    opp: nextForPerf.players[oppId],
    mineAttempts: perfLiveAttempts(prev, nextForPerf, myId),
    oppAttempts: perfLiveAttempts(prev, nextForPerf, oppId),
  };
}

function perfFirstPlayerId(ctx) {
  return ctx?.next?.first_player || ctx?.prev?.first_player || 'p1';
}

function perfSecondPlayerId(ctx) {
  const first = perfFirstPlayerId(ctx);
  return first === 'p1' ? 'p2' : 'p1';
}

function perfSecondPlayerHasYellCards(ctx) {
  const pid = perfSecondPlayerId(ctx);
  if ((ctx?.next?.yell_reveal?.[pid]?.length || 0) > 0) return true;
  return estimateYellBlade(ctx?.next, pid) > 0;
}

function perfFirstPlayerHasYellCards(ctx) {
  const pid = perfFirstPlayerId(ctx);
  if ((ctx?.next?.yell_reveal?.[pid]?.length || 0) > 0) return true;
  return estimateYellBlade(ctx?.next, pid) > 0;
}

function perfLiveZoneCards(state, pid) {
  return (state?.players?.[pid]?.live_zone || []).filter(c =>
    isLiveTypeCard(c) || isRedactedLiveZoneCard(c));
}

function perfCacheLiveReveal(state) {
  if (!state?.players) return;
  G._perfLiveReveal = G._perfLiveReveal || {};
  for (const pid of ['p1', 'p2']) {
    for (const c of perfLiveZoneCards(state, pid)) {
      if (c.instance_id && c.card_no && c.card_no !== '?') {
        G._perfLiveReveal[`${pid}:${c.instance_id}`] = c;
      }
    }
  }
}

function perfFindRevealedLiveMeta(state, pid, instanceId) {
  if (!state?.players?.[pid] || !instanceId) return null;
  const cached = G._perfLiveReveal?.[`${pid}:${instanceId}`];
  if (cached?.card_no && cached.card_no !== '?') return cached;
  const p = state.players[pid];
  const pools = [p.live_zone, p.success_lives, p.waiting_room];
  for (const pool of pools) {
    for (const c of pool || []) {
      if (c.instance_id === instanceId && c.card_no && c.card_no !== '?') return c;
    }
  }
  const yellIds = new Set((perfYellRevealInline(state)?.[pid] || []).map(c => c?.instance_id).filter(Boolean));
  if (yellIds.has(instanceId)) {
    for (const c of p.hand || []) {
      if (c.instance_id === instanceId && c.card_no && c.card_no !== '?') return c;
    }
  }
  if (G.isTutorial && G.tutorialData?.steps) {
    for (const step of G.tutorialData.steps) {
      const c = (step.state?.players?.[pid]?.live_zone || []).find(
        x => x.instance_id === instanceId && x.card_no && x.card_no !== '?'
      );
      if (c) return c;
    }
  }
  return null;
}

function perfSpectacleLiveCards(prev, next, pid) {
  perfCacheLiveReveal(next);
  perfCacheLiveReveal(prev);
  const perfPrev = buildPerfSpectaclePrev(prev, next);
  const lockCards = perfMergedLiveZone(perfPrev, next, pid)
    .filter(c => isPerfSpectacleLiveSlotCard(c, next, pid));
  if (!lockCards.length) {
    const roundCards = collectPerfRoundLiveCards(next, pid, prev, inferLiveShowTurn(prev, next));
    if (roundCards.length) {
      return roundCards.map(c => ({ ...c, revealed: true }));
    }
    return clampLiveZoneLive(perfLiveZoneCards(next, pid));
  }
  const nextById = new Map(perfLiveZoneCards(next, pid).map(c => [c.instance_id, c]));
  const revealed = lockCards.map(c => {
    let meta = nextById.get(c.instance_id);
    if (!meta || !meta.card_no || meta.card_no === '?') {
      meta = perfFindRevealedLiveMeta(next, pid, c.instance_id)
        || perfFindRevealedLiveMeta(prev, pid, c.instance_id);
    }
    if (!meta || !meta.card_no || meta.card_no === '?') {
      return { ...c, revealed: true };
    }
    return { ...c, ...meta, revealed: true };
  });
  return clampLiveZoneLive(revealed);
}

async function perfPopulateBase(ctx) {
  perfClearYellUi();
  perfClearHeartsBladeUi();
  perfResetYellEffectAccumulators();
  const { prev, next, myId, oppId, me, opp } = ctx;
  el('perf-mine-lbl').textContent = me?.name || 'You';
  el('perf-opp-lbl').textContent = opp?.name || 'Opponent';
  const mineLives = el('perf-mine-lives');
  const oppLives = el('perf-opp-lives');
  const mineStage = el('perf-stage-row');
  const oppStage = el('perf-opp-stage');
  [mineLives, oppLives, mineStage, oppStage].forEach(n => { if (n) n.innerHTML = ''; });
  const mineLiveCards = perfSpectacleLiveCards(prev, next, myId)
    .filter(c => isLiveTypeCard(enrichCard(c)) || isPerfSpectacleLiveSlotCard(c, next, myId));
  const oppLiveCards = perfSpectacleLiveCards(prev, next, oppId)
    .filter(c => isLiveTypeCard(enrichCard(c)) || isPerfSpectacleLiveSlotCard(c, next, oppId));
  const mineEnriched = mineLiveCards.map(c => enrichCard({ ...c, revealed: true }));
  const oppEnriched = oppLiveCards.map(c => enrichCard({ ...c, revealed: true }));
  await Promise.all([...mineEnriched, ...oppEnriched].map(c => ensureCardImageLoaded(c)));
  mineEnriched.forEach(c => mineLives?.appendChild(perfCardEl(c, 'live')));
  oppEnriched.forEach(c => oppLives?.appendChild(perfCardEl(c, 'live')));
  Object.values(me?.stage || {}).forEach(m => { if (m) mineStage?.appendChild(perfCardEl(enrichCard(m), 'member')); });
  Object.values(opp?.stage || {}).forEach(m => { if (m) oppStage?.appendChild(perfCardEl(enrichCard(m), 'member')); });
  perfSetYellSideInstant(ctx, myId, false);
  perfSetYellSideInstant(ctx, oppId, false);
  requestAnimationFrame(() => layoutPerfLiveRows());
}

function perfSetYellSideInstant(ctx, pid, showAllCards) {
  const board = ctx.next.stage_board;
  const isMine = pid === ctx.myId;
  const stageHearts = isMine
    ? (board?.mine?.stage_hearts || aggregateStageHeartsFromStage(ctx.next.players[pid]?.stage))
    : (board?.opp?.stage_hearts || aggregateStageHeartsFromStage(ctx.next.players[pid]?.stage));
  const yellCards = ctx.next.yell_reveal?.[pid] || [];
  const totalBlade = stageYellBladeFor(ctx.next, pid, ctx.myId);
  const heartsEl = el(isMine ? 'perf-mine-hearts' : 'perf-opp-hearts');
  const bladeEl = el(isMine ? 'perf-mine-blade' : 'perf-opp-blade');
  const deckEl = el(isMine ? 'perf-mine-deck' : 'perf-opp-deck');
  const yellRow = el(isMine ? 'perf-mine-yell' : 'perf-opp-yell');
  if (!heartsEl || !bladeEl || !deckEl || !yellRow) return;

  yellRow.innerHTML = '';
  if (showAllCards) {
    yellCards.forEach(card => {
      const chip = document.createElement('div');
      chip.className = 'perf-yell-card show';
      const c = enrichCard(card);
      appendPerfYellCardFace(chip, c);
      perfMarkYellBladeHearts(chip, c, { yellWildcard: liveCardsHaveYellHeartsWildcard(
        perfSpectacleLiveCards(ctx.prev, ctx.next, pid).map(enrichCard)
      ) });
      yellRow.appendChild(chip);
    });
    layoutPerfYellStack(yellRow);
    let yellScoreTotal = 0;
    let yellDrawTotal = 0;
    yellCards.forEach(c => {
      yellScoreTotal += cardYellScoreIconCount(c);
      yellDrawTotal += cardYellDrawIconCount(c);
    });
    if (yellScoreTotal) {
      if (!G._perfYellScoreAccum) perfResetYellEffectAccumulators();
      G._perfYellScoreAccum[pid] = yellScoreTotal;
      const livesRow = el(isMine ? 'perf-mine-lives' : 'perf-opp-lives');
      const badge = perfMkYellScoreTotalBadge(yellScoreTotal);
      badge.classList.add('show');
      livesRow?.appendChild(badge);
    }
    if (yellDrawTotal && liveCardsHaveDrawPerYellDraw(liveCards)) {
      perfBumpYellDrawPending(deckEl, pid, yellDrawTotal, { animate: false });
    }
    perfRenderBladeRow(bladeEl, 0, { pending: false });
    const finalHearts = mergeHeartStatRows(
      stageHearts,
      perfContinuousHeartsForPlayer(ctx, pid),
      aggregateYellBladeHeartsFromCards(yellCards, ctx, pid)
    );
    perfFillHearts(heartsEl, finalHearts);
    layoutPerfYellRail(yellRow.closest('.perf-yell-rail'));
    layoutPerfLiveRows();
  } else {
    yellRow.innerHTML = '';
    yellRow.style.transform = '';
    yellRow.classList.remove('perf-yell-many');
    perfRenderBladeRow(bladeEl, totalBlade, { pending: true });
    perfFillHearts(heartsEl, stageHearts);
  }
}

function perfSetOutcomesInstant(ctx, firstOnly) {
  const firstPid = perfFirstPlayerId(ctx);
  const secondPid = perfSecondPlayerId(ctx);
  const mineOut = el('perf-mine-outcomes');
  const oppOut = el('perf-opp-outcomes');
  if (mineOut) mineOut.innerHTML = '';
  if (oppOut) oppOut.innerHTML = '';
  const renderPid = (pid) => {
    const isMine = pid === ctx.myId;
    const out = isMine ? mineOut : oppOut;
    const attempts = isMine ? ctx.mineAttempts : ctx.oppAttempts;
    attempts.forEach(att => {
      const b = document.createElement('div');
      // Never treat "unknown / not yet classified" as a failure — that caused
      // spectacle to show Live Failed while the log later showed success.
      const cls = att.success ? 'ok' : (att.fail ? 'fail' : 'pending');
      b.className = 'perf-outcome show ' + cls;
      b.textContent = perfOutcomeLabel(att);
      out?.appendChild(b);
    });
  };
  renderPid(firstPid);
  if (!firstOnly) renderPid(secondPid);
}

function perfSetJudgeInstant(ctx) {
  const bothSucceeded = perfBothSucceeded(ctx);
  const { my: myScore, opp: oppScore } = perfJudgeTotals(ctx);
  const verdict = perfJudgeVerdict(ctx);
  el('perf-splash')?.classList.remove('show', 'live-start');
  perfClearJudgeUi();
  if (bothSucceeded) perfApplyLiveCardScores(ctx, { show: true, animate: false });
  const judgePanel = el('perf-judge-panel');
  const duelEl = el('perf-judge-duel');
  const soloEl = el('perf-judge-solo');
  const myNum = el('perf-judge-my-num');
  const oppNum = el('perf-judge-opp-num');
  el('perf-judge-my-lbl').textContent = 'You';
  el('perf-judge-opp-lbl').textContent = ctx.opp?.name || 'Opponent';
  const titleEl = el('perf-judge-title');
  if (bothSucceeded) {
    titleEl?.removeAttribute('hidden');
    titleEl?.classList.add('show');
    duelEl?.removeAttribute('hidden');
    duelEl?.classList.add('show');
    if (myNum) myNum.textContent = String(myScore);
    if (oppNum) oppNum.textContent = String(oppScore);
    soloEl.hidden = true;
  } else {
    titleEl?.setAttribute('hidden', '');
    titleEl?.classList.remove('show');
    duelEl?.setAttribute('hidden', '');
    duelEl?.classList.remove('show');
    const solo = perfJudgeSoloLine(ctx);
    soloEl.hidden = false;
    soloEl.textContent = solo.text;
    soloEl.className = 'perf-judge-solo show ' + solo.cls;
  }
  const verdictEl = el('perf-verdict');
  if (verdictEl) {
    if (bothSucceeded) {
      verdictEl.textContent = verdict.text;
      verdictEl.className = 'perf-verdict show ' + verdict.cls;
    } else {
      verdictEl.textContent = '';
      verdictEl.className = 'perf-verdict';
    }
  }
  judgePanel?.removeAttribute('hidden');
  judgePanel?.classList.add('show');
}

function perfApplyPhaseInstant(ctx, phase) {
  if (phase === 'live_start') {
    if (perfTutorialKeepsSpectacle(phase)) {
      el('perf-mine-outcomes').innerHTML = '';
      el('perf-opp-outcomes').innerHTML = '';
      el('perf-splash')?.classList.remove('show', 'live-start');
      el('perf-judge-panel')?.classList.remove('show');
      el('perf-judge-panel')?.setAttribute('hidden', '');
      el('perf-col-mine')?.classList.add('in');
      el('perf-col-opp')?.classList.add('in');
      perfSetYellSideInstant(ctx, ctx.myId, false);
      perfSetYellSideInstant(ctx, ctx.oppId, false);
      const toast = el('perf-fx-toast');
      if (toast) {
        toast.textContent = 'Live Start — optional effects before Yell';
        toast.classList.add('show');
      }
      return;
    }
    perfCloseSpectacle();
    return;
  }
  el('perf-fx-toast')?.classList.remove('show');
  el('perf-mine-outcomes').innerHTML = '';
  el('perf-opp-outcomes').innerHTML = '';
  el('perf-splash')?.classList.remove('show', 'live-start');
  el('perf-judge-panel')?.classList.remove('show');
  el('perf-judge-panel')?.setAttribute('hidden', '');
  el('perf-fx-toast')?.classList.remove('show');

  const showIntro = perfIsIntroFamily(phase) || phase === 'intro';
  el('perf-col-mine')?.classList.toggle('in', showIntro || perfPhaseIdx(phase) > perfPhaseIdx('intro'));
  el('perf-col-opp')?.classList.toggle('in', showIntro || perfPhaseIdx(phase) > perfPhaseIdx('intro'));

  if (phase === 'intro' || phase === 'closed' || perfIsIntroFamily(phase)) {
    perfSetYellSideInstant(ctx, ctx.myId, false);
    perfSetYellSideInstant(ctx, ctx.oppId, false);
    if (phase !== 'closed' && perfIsIntroFamily(phase)) return;
    if (phase === 'intro' || phase === 'closed') return;
  }
  perfSetYellSideInstant(ctx, perfFirstPlayerId(ctx), perfPhaseIdx(phase) >= perfPhaseIdx('yell_mine'));
  perfSetYellSideInstant(ctx, perfSecondPlayerId(ctx), perfPhaseIdx(phase) >= perfPhaseIdx('yell_opp'));
  if (phase === 'outcomes_mine') perfSetOutcomesInstant(ctx, true);
  else if (perfPhaseIdx(phase) >= perfPhaseIdx('outcomes')) perfSetOutcomesInstant(ctx, false);
  if (perfPhaseIdx(phase) >= perfPhaseIdx('judge')) perfSetJudgeInstant(ctx);
}

async function perfAnimateYellSide(ctx, pid) {
  const board = ctx.next.stage_board;
  const isMine = pid === ctx.myId;
  const stageHearts = isMine
    ? (board?.mine?.stage_hearts || aggregateStageHeartsFromStage(ctx.next.players[pid]?.stage))
    : (board?.opp?.stage_hearts || aggregateStageHeartsFromStage(ctx.next.players[pid]?.stage));
  const yellCards = ctx.next.yell_reveal?.[pid] || [];
  const totalBlade = stageYellBladeFor(ctx.next, pid, ctx.myId);
  const heartsEl = el(isMine ? 'perf-mine-hearts' : 'perf-opp-hearts');
  const bladeEl = el(isMine ? 'perf-mine-blade' : 'perf-opp-blade');
  const deckEl = el(isMine ? 'perf-mine-deck' : 'perf-opp-deck');
  const yellRow = el(isMine ? 'perf-mine-yell' : 'perf-opp-yell');
  const yellRail = yellRow?.closest('.perf-yell-rail');
  if (!heartsEl || !bladeEl || !deckEl || !yellRow) return;

  perfFillHearts(heartsEl, stageHearts);
  const bladeNum = perfRenderBladeRow(bladeEl, totalBlade, { pending: false });
  yellRow.innerHTML = '';
  const grants = perfContinuousHeartGrantsForPlayer(ctx, pid);
  const grantHeartCount = grants.reduce((n, g) => n + (g.hearts?.length || 0), 0);
  const yellSteps = Math.max(yellCards.length, totalBlade, 1);
  const introPace = perfYellPaceScale(totalBlade, 0, yellSteps + grantHeartCount);
  sfxPerf('turn_tick', 0.48);
  await perfSleepYell(400, introPace);
  layoutPerfRailSlots();
  layoutPerfYellRail(yellRail);
  layoutPerfLiveRows();
  await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));

  let ownedPool = buildHeartPoolFromRows(stageHearts);
  const liveCards = perfSpectacleLiveCards(ctx.prev, ctx.next, pid).map(enrichCard);
  const yellWildcard = liveCardsHaveYellHeartsWildcard(liveCards);
  for (let gi = 0; gi < grants.length; gi++) {
    if (G._perfSpectacleAborted) return;
    const grant = grants[gi];
    const memberEl = perfMemberCardElForGrant(ctx, pid, grant);
    const hearts = grant.hearts || [];
    for (let hi = 0; hi < hearts.length; hi++) {
      if (G._perfSpectacleAborted) return;
      const heartPace = introPace * (1 - (hi / Math.max(1, hearts.length)) * 0.15);
      const rawColor = hearts[hi];
      await perfFlyMemberHeartToPanel(memberEl, heartsEl, rawColor, { pace: heartPace });
      ownedPool.push(normalizeHeartColor(rawColor));
      await perfSleepYell(60, heartPace);
    }
    if (hearts.length) await perfSleepYell(120, introPace);
  }

  let remaining = totalBlade;
  const drawSteps = Math.max(yellCards.length, totalBlade);
  for (let i = 0; i < drawSteps; i++) {
    if (G._perfSpectacleAborted) return;
    const cardPace = perfYellPaceScale(totalBlade, i, yellSteps);
    remaining = Math.max(0, totalBlade - (i + 1));
    if (bladeNum) bladeNum.textContent = String(remaining);
    const card = yellCards[i] ? enrichCard(yellCards[i]) : null;
    if (!card) {
      deckEl?.classList.add('perf-deck-draw');
      sfxPerf('turn_tick', 0.32);
      await perfSleepYell(180, cardPace);
      deckEl?.classList.remove('perf-deck-draw');
      continue;
    }
    await ensureCardImageLoaded(card);
    const chip = document.createElement('div');
    chip.className = 'perf-yell-card';
    appendPerfYellCardFace(chip, card);
    yellRow.appendChild(chip);
    layoutPerfYellStack(yellRow);
    await perfSleepYell(60, cardPace);
    await perfRevealYellCardFromDeck(chip, deckEl, isMine, cardPace);
    await perfSleepYell(160, cardPace);
    const scoreIcons = card ? cardYellScoreIconCount(card) : 0;
    const drawIcons = card ? cardYellDrawIconCount(card) : 0;
    if (scoreIcons > 0) {
      await perfApplyYellScoreBonus(ctx, pid, scoreIcons, { pace: cardPace });
    }
    if (drawIcons > 0 && liveCardsHaveDrawPerYellDraw(liveCards)) {
      perfBumpYellDrawPending(deckEl, pid, drawIcons, { animate: true });
      await perfSleepYell(120, cardPace);
    }
    const bladeEntries = card ? cardYellBladeHeartEntries(card, { yellWildcard }) : [];
    if (bladeEntries.length) {
      const bladeWrap = perfMarkYellBladeHearts(chip, card, { yellWildcard });
      const icons = bladeWrap ? [...bladeWrap.querySelectorAll('.hicon.bheart')] : [];
      for (let j = 0; j < bladeEntries.length; j++) {
        if (G._perfSpectacleAborted) return;
        const heartPace = cardPace * (1 - (j / Math.max(1, bladeEntries.length)) * 0.22);
        const { raw: rawColor } = bladeEntries[j];
        const icon = icons[j] || null;
        const resolved = resolveYellBladeHeartColor(rawColor, ctx, pid, {
          extraOwned: ownedPool,
          yellWildcard,
          liveCards,
        });
        ownedPool.push(normalizeHeartColor(resolved));
        await perfFlyBladeHeartToPanel(icon, heartsEl, resolved, chip, rawColor, {
          flyResolvedColor: yellWildcard,
          pace: heartPace,
        });
      }
    }
    layoutPerfYellRail(yellRail);
    await perfSleepYell(bladeEntries.length ? 80 : 180, cardPace);
  }
  const tailPace = perfYellPaceScale(totalBlade, yellCards.length, yellSteps);
  while (remaining > 0) {
    if (G._perfSpectacleAborted) return;
    remaining--;
    bladeNum.textContent = String(remaining);
    await perfSleepYell(90, tailPace);
  }
  const finalHearts = mergeHeartStatRows(
    stageHearts,
    perfContinuousHeartsForPlayer(ctx, pid),
    aggregateYellBladeHeartsFromCards(yellCards, ctx, pid)
  );
  perfFillHearts(heartsEl, finalHearts);
  yellRow.querySelectorAll('.perf-yell-blade-hearts').forEach(w => w.remove());
  heartsEl.classList.add('pulse');
  sfxPerf('hearts_gain', 0.32);
  layoutPerfYellRail(yellRail);
  layoutPerfColSides();
  await perfSleepYell(350, tailPace);
  heartsEl.classList.remove('pulse');
}

async function perfAnimateOutcomesForPid(ctx, pid) {
  const isMine = pid === ctx.myId;
  const out = el(isMine ? 'perf-mine-outcomes' : 'perf-opp-outcomes');
  const attempts = isMine ? ctx.mineAttempts : ctx.oppAttempts;
  if (!out) return;
  for (const att of attempts) {
    const b = document.createElement('div');
    const cls = att.success ? 'ok' : (att.fail ? 'fail' : 'pending');
    b.className = 'perf-outcome ' + cls;
    b.textContent = perfOutcomeLabel(att);
    out.appendChild(b);
    await perfSleep(80);
    b.classList.add('show');
    if (att.success || att.fail) {
      sfxPlayCard(att.success ? 'live_success' : 'live_fail', { volume: att.success ? 0.48 : 0.44 });
    }
    await perfSleep(350);
  }
}

async function perfAnimateOutcomes(ctx, firstOnly) {
  const firstPid = perfFirstPlayerId(ctx);
  const secondPid = perfSecondPlayerId(ctx);
  el('perf-mine-outcomes').innerHTML = '';
  el('perf-opp-outcomes').innerHTML = '';
  await perfAnimateOutcomesForPid(ctx, firstPid);
  if (!firstOnly) await perfAnimateOutcomesForPid(ctx, secondPid);
  await perfSleep(250);
}

async function perfAnimateJudge(ctx) {
  const bothSucceeded = perfBothSucceeded(ctx);
  const { my: myScore, opp: oppScore, cardMy, cardOpp, bonusMy, bonusOpp } = perfJudgeTotals(ctx);
  const verdict = perfJudgeVerdict(ctx);
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  el('perf-splash')?.classList.remove('show', 'live-start');
  perfClearJudgeUi();
  el('perf-judge-panel')?.removeAttribute('hidden');
  el('perf-judge-panel')?.classList.add('show');

  el('perf-judge-my-lbl').textContent = 'You';
  el('perf-judge-opp-lbl').textContent = ctx.opp?.name || 'Opponent';

  const duelEl = el('perf-judge-duel');
  const titleEl = el('perf-judge-title');
  const soloEl = el('perf-judge-solo');
  const myNum = el('perf-judge-my-num');
  const oppNum = el('perf-judge-opp-num');
  const verdictEl = el('perf-verdict');

  if (!bothSucceeded) {
    titleEl?.setAttribute('hidden', '');
    titleEl?.classList.remove('show');
    duelEl?.setAttribute('hidden', '');
    const solo = perfJudgeSoloLine(ctx);
    soloEl.hidden = false;
    soloEl.textContent = solo.text;
    soloEl.className = 'perf-judge-solo ' + solo.cls;
    requestAnimationFrame(() => soloEl.classList.add('show'));
    sfxPerf(solo.cls === 'win' ? 'live_success' : 'live_fail', 0.42);
    if (verdictEl) {
      verdictEl.textContent = '';
      verdictEl.className = 'perf-verdict';
    }
    await perfSleep(reduced ? 600 : 1500);
    return;
  }

  const mineBadges = [];
  const oppBadges = [];
  const badgeStagger = reduced ? 0 : 90;
  const mineSuccesses = ctx.mineAttempts.filter(a => a.success);
  const oppSuccesses = ctx.oppAttempts.filter(a => a.success);
  mineSuccesses.forEach((att, i) => {
    const cardEl = perfLiveCardEl(ctx, ctx.myId, att.card.instance_id);
    if (!cardEl) return;
    const extraBonus = perfLiveCardBadgeExtraBonus(ctx, ctx.myId, i, mineSuccesses.length);
    const badge = perfMkCardScoreBadge(att.card, { extraBonus });
    cardEl.appendChild(badge);
    mineBadges.push(badge);
    setTimeout(() => {
      badge.classList.add('show');
      if (!reduced) badge.classList.add('punch');
      sfxPlayCard('card_place', { volume: 0.26 });
    }, reduced ? 0 : i * badgeStagger);
  });
  oppSuccesses.forEach((att, i) => {
    const cardEl = perfLiveCardEl(ctx, ctx.oppId, att.card.instance_id);
    if (!cardEl) return;
    const extraBonus = perfLiveCardBadgeExtraBonus(ctx, ctx.oppId, i, oppSuccesses.length);
    const badge = perfMkCardScoreBadge(att.card, { extraBonus });
    cardEl.appendChild(badge);
    oppBadges.push(badge);
    setTimeout(() => {
      badge.classList.add('show');
      if (!reduced) badge.classList.add('punch');
      sfxPlayCard('card_place', { volume: 0.26 });
    }, reduced ? 0 : i * badgeStagger);
  });

  const staggerMs = reduced ? 0 : Math.max(mineBadges.length, oppBadges.length) * badgeStagger + 180;
  await perfSleep(staggerMs);

  titleEl?.removeAttribute('hidden');
  titleEl?.classList.remove('show');
  duelEl?.removeAttribute('hidden');
  duelEl?.classList.remove('show');
  soloEl.hidden = true;
  if (myNum) myNum.textContent = '0';
  if (oppNum) oppNum.textContent = '0';
  if (verdictEl) {
    verdictEl.textContent = '';
    verdictEl.className = 'perf-verdict';
  }
  requestAnimationFrame(() => {
    titleEl?.classList.add('show');
    duelEl?.classList.add('show');
  });
  sfxPerf('skill_tick', 0.38);
  await perfSleep(reduced ? 60 : 220);

  if (mineBadges.length || oppBadges.length) sfxPlayCard('card_fly', { volume: 0.32 });
  await Promise.all([
    perfFlyScoreBadges(mineBadges, myNum),
    perfFlyScoreBadges(oppBadges, oppNum),
  ]);
  const cardPhaseMy = cardMy;
  const cardPhaseOpp = cardOpp;
  await Promise.all([
    perfAnimateCount(myNum, 0, cardPhaseMy, reduced ? 1 : 420),
    perfAnimateCount(oppNum, 0, cardPhaseOpp, reduced ? 1 : 420),
  ]);
  if (bonusMy > 0 || bonusOpp > 0) {
    await perfSleep(reduced ? 40 : 180);
    if (bonusMy > 0 && myNum) {
      myNum.classList.add('tick');
      await perfAnimateCount(myNum, cardPhaseMy, myScore, reduced ? 1 : 280);
      myNum.classList.remove('tick');
    }
    if (bonusOpp > 0 && oppNum) {
      oppNum.classList.add('tick');
      await perfAnimateCount(oppNum, cardPhaseOpp, oppScore, reduced ? 1 : 280);
      oppNum.classList.remove('tick');
    }
  }
  await perfSleep(reduced ? 60 : 220);
  if (verdictEl) {
    verdictEl.textContent = verdict.text;
    verdictEl.className = 'perf-verdict show ' + verdict.cls;
    sfxPerf(verdict.cls === 'win' ? 'live_success' : (verdict.cls === 'lose' ? 'live_fail' : 'turn_tick'), 0.4);
  }
  await perfSleep(reduced ? 400 : 950);
}

function perfYellRevealSignature(state, pid) {
  return (state?.yell_reveal?.[pid] || []).map(c => c?.instance_id || '').join(',');
}

async function perfReAnimateYellSideIfChanged(ctx, nextState, pid, prevSig) {
  const newSig = perfYellRevealSignature(nextState, pid);
  if (!newSig || newSig === prevSig) return ctx;
  Object.assign(ctx, perfBuildContext(ctx.prev, nextState, ctx.myId));
  perfClearYellUi();
  await perfAnimateYellSide(ctx, pid);
  return ctx;
}

async function awaitMidSpectacleYellRetryPrompts(ctx, myId) {
  let workCtx = ctx;
  let cur = G._deferredLiveState || G.gameState || workCtx.next;
  while (isMidSpectacleYellRetryPrompt(cur)) {
    const pr = cur.pending_prompt;
    const owner = pr.owner || pr.responder;
    const sigBefore = perfYellRevealSignature(cur, owner);
    ensurePendingPromptSurfaced(cur, myId);
    await waitForPipelinePromptResolution(myId, {
      targetState: cur,
      isResolved: (s) => {
        if (!s?.pending_prompt) return true;
        if (s.pending_prompt.type !== 'auto_yell_no_live_retry'
            && s.pending_prompt.type !== 'auto_yell_mill_extra_yell') return true;
        return false;
      },
    });
    cur = G._deferredLiveState || G.gameState || cur;
    if (G._deferredLiveState) {
      G.gameState = cur;
    }
    workCtx = await perfReAnimateYellSideIfChanged(workCtx, cur, owner, sigBefore);
    G._perfSpectaclePhase = owner === perfFirstPlayerId(workCtx) ? 'yell_mine' : 'yell_opp';
  }
  const final = G._deferredLiveState || G.gameState || cur;
  if (final && final !== workCtx.next) {
    Object.assign(workCtx, perfBuildContext(workCtx.prev, final, workCtx.myId));
    if (G._deferredLiveState) G._deferredLiveState = final;
  }
  return workCtx;
}

async function perfSeekPhase(prev, next, myId, targetPhase, { forward = true, animate = true } = {}) {
  if (targetPhase === 'closed' || !targetPhase) {
    perfCloseSpectacle();
    return;
  }
  if (targetPhase === 'live_start') {
    if (perfTutorialKeepsSpectacle(targetPhase)) {
      const ctxKey = `${next.seq}:${next.turn}:${next.phase}`;
      if (!G._perfCtx || G._perfCtx.key !== ctxKey) {
        G._perfCtx = perfBuildContext(prev, next, myId);
        await perfPopulateBase(G._perfCtx);
      }
      perfApplyPhaseInstant(G._perfCtx, 'live_start');
      G._perfSpectaclePhase = 'live_start';
      perfOpenSpectacle();
      return;
    }
    perfCloseSpectacle();
    G._perfSpectaclePhase = 'live_start';
    return;
  }
  const ctxKey = `${next.seq}:${next.turn}:${next.phase}`;
  const yellTotal = (perfYellRevealFor(next)?.p1?.length || 0) + (perfYellRevealFor(next)?.p2?.length || 0);
  const ctxYellTotal = (G._perfCtx?.next?.yell_reveal?.p1?.length || 0)
    + (G._perfCtx?.next?.yell_reveal?.p2?.length || 0);
  if (!G._perfCtx || G._perfCtx.key !== ctxKey || yellTotal > ctxYellTotal) {
    G._perfCtx = perfBuildContext(prev, next, myId);
    await perfPopulateBase(G._perfCtx);
  }
  let ctx = G._perfCtx;
  const cur = G._perfSpectaclePhase || 'closed';
  const curIdx = perfPhaseIdx(cur);
  const tgtIdx = perfPhaseIdx(targetPhase);
  const yellOppIdx = perfPhaseIdx('yell_opp');
  const needsOppYellAnim = forward && perfSecondPlayerHasYellCards(ctx)
    && (cur === 'outcomes_mine' || curIdx < yellOppIdx)
    && tgtIdx >= yellOppIdx
    && cur !== 'yell_opp';
  if (!animate || (cur !== 'closed' && tgtIdx <= curIdx)) {
    if (needsOppYellAnim && targetPhase === 'yell_opp') {
      perfOpenSpectacle();
      if (G.isTutorial) {
        const step = G.tutorialData?.steps?.[G.tutorialStep];
        const hl = step?.highlights || [];
        renderTutorialSpotlight(hl);
        positionTutorialBubble(hl);
      }
      await perfAnimateYellSide(ctx, perfSecondPlayerId(ctx));
      G._perfSpectaclePhase = 'yell_opp';
      if (G.isTutorial) {
        repositionTutorialPerfYellBubble(G.tutorialData?.steps?.[G.tutorialStep]);
      }
      return;
    }
    perfApplyPhaseInstant(ctx, targetPhase);
    G._perfSpectaclePhase = targetPhase;
    perfOpenSpectacle();
    if (targetPhase === 'intro' && cur === 'closed') {
      el('perf-col-mine')?.classList.add('in');
      el('perf-col-opp')?.classList.add('in');
      void perfFlashSplash(t('splash.liveStartFlash'), 1300, 'live-start');
      if (G.isTutorial) {
        const step = G.tutorialData?.steps?.[G.tutorialStep];
        if (tutorialPerfIntroPlaymatStep(step)) {
          requestAnimationFrame(() => positionTutorialBubble(step.highlights || []));
        }
      }
    }
    return;
  }
  perfOpenSpectacle();
  let aborted = false;
  G._perfSpectacleAborted = false;
  G._perfSpectacleAbort = () => {
    aborted = true;
    G._perfSpectacleAborted = true;
  };
  if (curIdx < perfPhaseIdx('intro') && tgtIdx >= perfPhaseIdx('intro')) {
    el('perf-col-mine')?.classList.remove('in');
    el('perf-col-opp')?.classList.remove('in');
    const splashP = animate
      ? perfFlashSplash(t('splash.liveStartFlash'), 1300, 'live-start')
      : Promise.resolve();
    requestAnimationFrame(() => {
      el('perf-col-mine')?.classList.add('in');
      el('perf-col-opp')?.classList.add('in');
    });
    if (G.isTutorial) {
      const step = G.tutorialData?.steps?.[G.tutorialStep];
      if (tutorialPerfIntroPlaymatStep(step)) {
        positionTutorialBubble(step.highlights || []);
      }
    }
    if (aborted) return;
    await splashP;
    if (aborted) return;
    perfApplyPhaseInstant(ctx, 'intro');
    G._perfSpectaclePhase = 'intro';
    if (G.isTutorial) {
      const step = G.tutorialData?.steps?.[G.tutorialStep];
      if (tutorialPerfIntroPlaymatStep(step)) {
        positionTutorialBubble(step.highlights || []);
      }
    }
    if (aborted) return;
  }
  if (tgtIdx >= perfPhaseIdx('intro') && tgtIdx < perfPhaseIdx('yell_mine') && tgtIdx > perfPhaseIdx(G._perfSpectaclePhase || 'closed')) {
    perfApplyPhaseInstant(ctx, targetPhase);
    G._perfSpectaclePhase = targetPhase;
    if (tgtIdx < perfPhaseIdx('yell_mine')) return;
    if (aborted) return;
  }
  if (curIdx < perfPhaseIdx('yell_mine') && tgtIdx >= perfPhaseIdx('yell_mine')) {
    if (G.isTutorial) {
      const step = G.tutorialData?.steps?.[G.tutorialStep];
      const hl = step?.highlights || [];
      renderTutorialSpotlight(hl);
      positionTutorialBubble(hl);
    }
    await perfAnimateYellSide(ctx, perfFirstPlayerId(ctx));
    G._perfSpectaclePhase = 'yell_mine';
    if (G.isTutorial) {
      repositionTutorialPerfYellBubble(G.tutorialData?.steps?.[G.tutorialStep]);
    }
    if (aborted) return;
  }
  if (needsOppYellAnim) {
    if (G.isTutorial) {
      const step = G.tutorialData?.steps?.[G.tutorialStep];
      const hl = step?.highlights || [];
      renderTutorialSpotlight(hl);
      positionTutorialBubble(hl);
    }
    await perfAnimateYellSide(ctx, perfSecondPlayerId(ctx));
    G._perfSpectaclePhase = 'yell_opp';
    if (G.isTutorial) {
      repositionTutorialPerfYellBubble(G.tutorialData?.steps?.[G.tutorialStep]);
    }
    if (aborted) return;
    if (tgtIdx > yellOppIdx) {
      ctx = await awaitMidSpectacleYellRetryPrompts(ctx, myId);
      G._perfCtx = ctx;
      if (aborted) return;
    }
    if (tgtIdx <= yellOppIdx) return;
  }
  if (curIdx < perfPhaseIdx('outcomes_mine') && tgtIdx >= perfPhaseIdx('outcomes_mine') && tgtIdx < perfPhaseIdx('outcomes')) {
    await perfAnimateOutcomes(ctx, true);
    G._perfSpectaclePhase = 'outcomes_mine';
    if (G.isTutorial) {
      const step = G.tutorialData?.steps?.[G.tutorialStep];
      if (step?.id === 't1_success') {
        renderTutorialSpotlight(step.highlights || []);
        positionTutorialBubble(step.highlights || []);
      }
    }
    if (aborted) return;
  }
  if (curIdx < perfPhaseIdx('outcomes') && tgtIdx >= perfPhaseIdx('outcomes')) {
    if (G._perfSpectaclePhase === 'yell_opp') {
      el('perf-mine-outcomes').innerHTML = '';
      el('perf-opp-outcomes').innerHTML = '';
      await perfAnimateOutcomesForPid(ctx, perfFirstPlayerId(ctx));
      await perfAnimateOutcomesForPid(ctx, perfSecondPlayerId(ctx));
    } else if (G._perfSpectaclePhase === 'outcomes_mine') {
      await perfAnimateOutcomesForPid(ctx, perfSecondPlayerId(ctx));
    } else {
      await perfAnimateOutcomes(ctx, false);
    }
    G._perfSpectaclePhase = 'outcomes';
    if (G.isTutorial) {
      const step = G.tutorialData?.steps?.[G.tutorialStep];
      if (step?.spectacle === 'outcomes') {
        renderTutorialSpotlight(step.highlights || []);
        positionTutorialBubble(step.highlights || []);
      }
    }
    if (aborted) return;
  }
  if (curIdx < perfPhaseIdx('judge') && tgtIdx >= perfPhaseIdx('judge')) {
    await perfAnimateJudge(ctx);
    G._perfSpectaclePhase = 'judge';
  }
}

const TUTORIAL_PERF_LOCK = {
  t1_perf_intro: 't1_live_p2',
  t1_hearts_check: 't1_live_p2',
  t1_hearts_grey: 't1_live_p2',
  t1_yell: 't1_live_p2',
  t1_yell_hearts: 't1_live_p2',
  t1_success: 't1_live_p2',
  t1_yell_opp: 't1_live_p2',
  t1_fail: 't1_live_p2',
  t1_judge: 't1_live_p2',
  t2_perf_intro: 't2_live_p2',
  t2_live_start_offer: 't2_live_p2',
  t2_yell_mine: 't2_live_p2',
  t2_yell_opp: 't2_live_p2',
  t2_outcomes: 't2_live_p2',
  t2_judge: 't2_live_p2',
  t3_perf_intro: 't3_live2',
  t3_yell_mine: 't3_live2',
  t3_yell_opp: 't3_live2',
  t3_outcomes: 't3_live2',
  t3_judge: 't3_live2',
};

function findTutorialPerfPrevState(stepId) {
  const lockId = TUTORIAL_PERF_LOCK[stepId];
  if (!lockId || !G.tutorialData?.steps) return null;
  const lockStep = G.tutorialData.steps.find(s => s.id === lockId);
  return lockStep?.state ? prepareTutorialState(lockStep.state) : null;
}

async function syncTutorialSpectacle(prev, next, step, forward, myId) {
  const phase = step?.spectacle;
  if (!phase) {
    G._perfSpectacleAbort?.();
    if (G._perfSpectaclePhase && G._perfSpectaclePhase !== 'closed') perfCloseSpectacle();
    return;
  }
  const perfPrev = findTutorialPerfPrevState(step.id) || prev;
  const curIdx = perfPhaseIdx(G._perfSpectaclePhase || 'closed');
  const tgtIdx = perfPhaseIdx(phase);
  const animate = forward && tgtIdx > curIdx;
  if (animate && phase !== 'live_start' && phase !== 'intro') {
    await waitForBannersIdle();
    await waitForBlockingOverlaysIdle(next);
  }
  if (forward && phase === 'intro' && (G._perfSpectaclePhase || 'closed') === 'closed') {
    queuePerformancePhaseBanner();
  }
  await perfSeekPhase(perfPrev, next, myId, phase, { forward, animate });
  if (phase === 'judge' && forward) {
    savePerfSpectacleDoneKey(perfPrev, next);
    G._postSpectacleSplashPause = true;
  }
}

function shouldSuppressLiveJudgeMatOverlay(s) {
  if (!s || !bothPlayersClearedLiveThisRound(s)) return false;
  if (G._postSpectacleSplashPause) return true;
  if (G._perfSpectacleDoneKey) {
    const perfPrev = buildPerfSpectaclePrev(null, s);
    if (perfPrev && G._perfSpectacleDoneKey === perfSpectacleTurnKey(perfPrev, s)) return true;
  }
  return false;
}

function updateLiveJudgeOverlay(s, me, opp, myId) {
  const root = el('live-judge-overlay');
  if (!root || !me || !opp) return;
  if (G._perfSpectacleActive || G._skipJudgeOverlay) {
    root.classList.remove('show');
    root.hidden = true;
    return;
  }
  if (shouldSuppressLiveJudgeMatOverlay(s)) {
    root.classList.remove('show');
    root.hidden = true;
    return;
  }
  const bothCleared = bothPlayersClearedLiveThisRound(s);
  const show = (G._liveJudgeOverlayHold && bothCleared)
    || (isLiveJudgePhase(s) && bothCleared);
  root.classList.toggle('show', show);
  root.hidden = !show;
  if (!show) return;
  const cached = G._liveJudgeOverlayHold && G._liveJudgeScores;
  const oppId = myId === 'p1' ? 'p2' : 'p1';
  el('ljo-my-scr').textContent = cached ? G._liveJudgeScores.my : judgeLiveTotalScore(s, myId, me.live_zone);
  el('ljo-op-scr').textContent = cached ? G._liveJudgeScores.opp : judgeLiveTotalScore(s, oppId, opp.live_zone);
}

function notifyBannerIdleWaiters() {
  const waiters = G._bannerIdleWaiters || [];
  G._bannerIdleWaiters = [];
  waiters.forEach(r => r());
}

function waitForBannersIdle() {
  if (!G._bannerActive && G._bannerQueue.length === 0) return Promise.resolve();
  return new Promise(resolve => {
    G._bannerIdleWaiters = G._bannerIdleWaiters || [];
    G._bannerIdleWaiters.push(resolve);
  });
}

function scheduleLiveStorageFlip(cardEl, flipKey, flipKeys, staggerMs = 0, layoutPrefix = 'opp') {
  if (shouldSuppressLiveStorageFlipsNow()) return;
  if (flipKeys && !flipKeys.has(flipKey)) return;
  scheduleCardFlipReveal(cardEl, flipKey, flipKeys, {
    staggerMs,
    durationMs: LIVE_STORAGE_FLIP_MS,
    requireLiveStorageFlip: true,
  });
}

function ensureLiveStorageFlipScheduled(cardEl, card, flipKey, flipKeys, staggerMs, prefix) {
  if (!cardEl || cardEl.classList.contains('revealed')) return;
  if (!cardEl.classList.contains('live-storage-flip')) return;
  if (shouldSuppressLiveStorageFlipsNow()) return;
  if (flipKeys && !flipKeys.has(flipKey)) return;
  G._liveFlipScheduled = G._liveFlipScheduled || new Set();
  if (G._liveFlipScheduled.has(flipKey)) return;
  const flipGen = G._liveFlipGen || 0;
  ensureCardImageLoaded(card).finally(() => {
    if (flipGen !== (G._liveFlipGen || 0)) return;
    if (shouldSuppressLiveStorageFlipsNow()) return;
    if (flipKeys && !flipKeys.has(flipKey)) return;
    if (!cardEl.isConnected || cardEl.classList.contains('revealed')) return;
    if (!cardEl.classList.contains('live-storage-flip')) return;
    requestAnimationFrame(() => {
      if (flipGen !== (G._liveFlipGen || 0) || shouldSuppressLiveStorageFlipsNow()) return;
      layoutLiveSlots(prefix);
      requestAnimationFrame(() => {
        if (flipGen !== (G._liveFlipGen || 0) || shouldSuppressLiveStorageFlipsNow()) return;
        scheduleLiveStorageFlip(cardEl, flipKey, flipKeys, staggerMs, prefix);
      });
    });
  });
}

function scheduleStageFlipReveal(cardEl, flipKey, flipKeys) {
  scheduleCardFlipReveal(cardEl, flipKey, flipKeys, {
    durationMs: STAGE_FLIP_MS,
  });
}

function shouldShowMatchSplash() {
  if (G.isTutorial) return false;
  if (!G.roomId) return false;
  if (G._presentationAborted) return false;
  if (!el('screen-game')?.classList.contains('active')) return false;
  return true;
}

function queueCenterBanner(opts) {
  if (!opts?.title && !opts?.titleKey) return;
  if (!shouldShowMatchSplash()) return;
  const loc = localizeBannerSpec(opts);
  TCG_DEBUG.log('banner', 'queue', loc.title, loc.subtitle || '', `(q=${(G._bannerQueue?.length || 0) + 1})`);
  G._bannerQueue.push({ ...opts, title: loc.title, subtitle: loc.subtitle, detail: loc.detail });
  if (!G._bannerActive) drainCenterBannerQueue();
}

function drainCenterBannerQueue() {
  const gen = G._bannerDrainGen || 0;
  G._bannerActive = true;
  const next = () => {
    if (gen !== (G._bannerDrainGen || 0)) {
      G._bannerActive = false;
      notifyBannerIdleWaiters();
      return;
    }
    const b = G._bannerQueue.shift();
    if (!b) {
      G._bannerActive = false;
      notifyBannerIdleWaiters();
      return;
    }
    showCenterBannerNow(b).then(() => {
      if (gen !== (G._bannerDrainGen || 0)) return;
      setTimeout(next, 70);
    });
  };
  next();
}

function cancelCenterBannerAutoDismiss() {
  clearTimeout(G._cbTimer);
  G._cbTimer = null;
}

function resolveCenterBannerShow() {
  const resolve = G._cbResolve;
  if (!resolve) return;
  G._cbResolve = null;
  resolve();
}

function dismissCenterBannerIfShowing() {
  const root = el('center-banner');
  cancelCenterBannerAutoDismiss();
  root?.classList.remove('show');
  resolveCenterBannerShow();
}

function shouldLogPerformancePhaseDivider(title, titleKey) {
  if (titleKey !== 'phaseBanner.performanceTitle' && !isSplashPerformanceTitle(title)) return true;
  if (G._perfSpectacleActive) return true;
  const s = G.gameState;
  const ph = s?.phase;
  if (liveSetPlacementInProgress(s)) return false;
  if (isLiveSetPhase(ph)) return false;
  return isLiveSpectaclePipelinePhase(ph);
}

function showCenterBannerNow(spec) {
  const banner = localizeBannerSpec(spec);
  const { title, subtitle, detail, kind = 'phase', duration = PHASE_BANNER_MS, judgeClass = '' } = banner;
  const titleKey = spec?.titleKey;
  return new Promise(resolve => {
    const root = el('center-banner');
    if (!root) { resolve(); return; }
    if (!String(title || '').trim()) { resolve(); return; }
    if (!shouldShowMatchSplash()) { resolve(); return; }
    G._lastBannerSpec = { ...spec };
    sfxPlaySplash(sfxSplashForBanner({ kind, title }));
    setSplashTitle(el('cb-title'), title || '');
    el('cb-sub').textContent = subtitle || '';
    const det = el('cb-detail');
    if (detail) {
      det.textContent = detail;
      det.style.display = '';
    } else {
      det.textContent = '';
      det.style.display = 'none';
    }
    root.classList.remove('show', 'kind-phase', 'kind-live', 'kind-live-attempt', 'kind-live-perform',
      'kind-judge', 'kind-success', 'kind-action', 'win-me', 'lose-me');
    root.classList.add('show', `kind-${kind}`);
    if (judgeClass) root.classList.add(judgeClass);
    if ((kind === 'phase' || (kind === 'live' && phaseLabelFromBannerTitle(title))) && shouldLogPerformancePhaseDivider(title, titleKey)) {
      appendPhaseLogDivider(title);
    }
    cancelCenterBannerAutoDismiss();
    G._cbResolve = resolve;
    G._cbTimer = setTimeout(() => {
      root.classList.remove('show');
      if (isSplashTurnBanner({ titleKey, title })) {
        G._postSpectacleSplashPause = false;
      }
      setTimeout(resolveCenterBannerShow, SPLASH_FADE_MS);
    }, duration);
  });
}

function isLivePipelinePhase(phase) {
  return isLiveSpectaclePipelinePhase(phase) || phase === 'live_success_effects';
}

function leavingEmptyLivePipeline(prev, next) {
  if (!prev || !next) return false;
  if (liveSetPlacementInProgress(next)) return false;
  const fromLive = prev.phase === 'live_set' || isLivePipelinePhase(prev.phase);
  if (!fromLive) return false;
  if (isLivePipelinePhase(next.phase) || next.phase === 'live_set') return false;
  if (liveRoundBoardHasLiveCards(prev) || liveRoundBoardHasLiveCards(next)) return false;
  const from = prev.log?.length || 0;
  const slice = (next.log || []).slice(from);
  if (slice.some(e => e.msg === 'No Lives played this turn.')) return true;
  if (!slice.length && fullLogHasEmptyLiveSkip(next)) return true;
  const showTurn = emptyLiveRoundShowTurn(prev, next) ?? inferLiveShowTurn(prev, next);
  if (!slice.length && showTurn != null && fullLogHasEmptyLiveSkipForTurn(next, showTurn)) return true;
  return !perfRoundHasShow(prev, next);
}

function centerBannerForPhase(phase, copy) {
  const livePhases = ['live_set', 'live_set_first', 'live_set_second',
    'live_performance_first', 'live_performance_second'];
  const liveKind = livePhases.includes(phase)
    || copy?.titleKey === 'phaseBanner.liveTitle'
    || copy?.titleKey === 'phaseBanner.yourLive'
    || copy?.titleKey === 'phaseBanner.theirLive'
    || copy?.titleKey === 'phaseBanner.theirLiveS'
    || copy?.titleKey === 'phaseBanner.livePlayer'
    || copy?.titleKey === 'phaseBanner.livePlayerS'
    || copy?.titleKey === 'phaseBanner.performanceTitle';
  if (liveKind) {
    return { ...copy, kind: 'live', duration: LIVE_BANNER_MS, subtitle: copy?.subtitle || '' };
  }
  return { ...copy, kind: 'phase', duration: PHASE_BANNER_MS };
}

function queuePerformancePhaseBanner(showTurn, prev, next) {
  const p = prev ?? G.gameState;
  const n = next ?? G.gameState;
  const turn = showTurn ?? primaryLiveShowTurn(p, n) ?? inferLiveShowTurn(p, n);
  if (perfSplashAlreadyShown(turn, p, n)) return;
  if (performancePhaseBannerShowing() || performancePhaseBannerQueued()) return;
  queueCenterBanner(splashBanner({
    titleKey: 'phaseBanner.performanceTitle',
    subtitleKey: null,
    kind: 'live',
    duration: LIVE_BANNER_MS,
  }));
  markPerfSplashShown(turn, p, n);
}

function showPhaseTransitionBanner(s, myId, prev) {
  if (livePlaybackBlocksMainPhaseUi(s, prev)) return;
  if (prev && leavingEmptyLivePipeline(prev, s)) {
    queueMainPhaseBannerAfterEmptyLiveSkip(s, myId);
    return;
  }
  if (shouldSuppressPostSpectacleSplash(null, s.phase, prev, s)) return;
  if (shouldSkipPhaseBanner(s.phase, s)) return;
  if (phaseBannerAlreadyShown(s.phase, s)) return;
  const copy = phaseBannerCopy(s.phase, s, myId);
  if (!copy?.title) return;
  if (phaseBannerAlreadyQueued(s.phase, copy)) {
    markPhaseBannerShown(s.phase, s);
    return;
  }
  markPhaseBannerShown(s.phase, s);
  queueCenterBanner(centerBannerForPhase(s.phase, copy));
}

function queueStateAnnouncements(prev, s, myId, opts = {}) {
  if (!s || !isActiveGameplay(s)) return;
  if (document.querySelector('#screen-game')?.classList.contains('active') === false) return;
  if (isReplayViewing() && !opts.replayForward) return;
  const holdLivePlayback = !!opts.holdLivePlayback || isLiveRoundPlaybackActive();
  const placementOpen = liveSetPlacementInProgress(s);
  const enteringLiveSet = isEnteringLiveSetPhase(prev, s);

  if (prev && prev.phase !== s.phase && !holdLivePlayback && !livePlaybackBlocksMainPhaseUi(s, prev) && (!placementOpen || enteringLiveSet)) {
    if (!shouldSkipStaleLiveLogAnnouncements(prev, s)) {
      showPhaseTransitionBanner(s, myId, prev);
    }
    G._lastPhase = s.phase;
  } else if (prev && prev.phase !== s.phase) {
    G._lastPhase = s.phase;
  } else if (G._lastPhase == null) {
    G._lastPhase = s.phase;
  }

  const baseline = G._announceBaseline ?? 0;
  let from = Math.max(prev?.log?.length || 0, baseline);
  if (shouldSkipStaleLiveLogAnnouncements(prev, s)) {
    from = s.log?.length || from;
  }
  const entries = (s.log || []).slice(from);
  const emptyLiveSkip = opts.emptyLiveSkip || isEmptyLiveSkipTransition(prev, s);
  for (const entry of entries) {
    if (holdLivePlayback && shouldDeferLogBannerDuringLivePlayback(entry.msg)) continue;
    if (placementOpen && (shouldDeferLogBannerDuringLivePlayback(entry.msg) || isPostLivePipelineLogBanner(entry.msg))) continue;
    if (emptyLiveSkip && shouldSuppressLivePipelineBanner(entry.msg, prev, s, from)) continue;
    const banner = parseLogToBanner(entry.msg, entry.kind, s, myId, prev, from);
    if (banner) {
      if (entry.msg === 'No Lives played this turn.') markEmptyLiveRoundPresented(prev, s);
      queueCenterBanner(banner);
    }
  }
}

function processGameAnnouncements(prev, s, myId) {
  queueStateAnnouncements(prev, s, myId);
}

function announceLogEntry(entry, s, myId) {
  if (isReplayViewing() && !G._replayForwardApply) return;
  const prev = G.gameState;
  if (shouldSkipStaleLiveLogAnnouncements(prev, s)) return;
  const from = prev?.log?.length || 0;
  const banner = parseLogToBanner(entry.msg, entry.kind, s, myId, prev, from);
  if (banner) {
    if (entry.msg === 'No Lives played this turn.') markEmptyLiveRoundPresented(prev, s);
    queueCenterBanner(banner);
  }
}
function effectiveCost(card, hand){
  let base=card.cost||0;
  if(!card.abilities?.length||!hand?.length) return base;
  const others=hand.filter(c=>c.instance_id!==card.instance_id).length;
  for(const ab of card.abilities){
    if(ab.trigger==='continuous'&&ab.type==='hand_cost_reduction')
      base=Math.max(0,base-others*(ab.per_other_card||1));
  }
  return base;
}
function memberBlocksBaton(card){
  return (card.abilities||[]).some(ab=>ab.trigger==='continuous'&&ab.type==='no_baton');
}
function cardMatchesSubunit(card, subunit) {
  if (!card || !subunit) return false;
  const want = canonicalSubunitKey(subunit);
  if (canonicalSubunitKey(card.subunit || '') === want) return true;
  return (card.subunits || []).some(s => canonicalSubunitKey(s) === want);
}
function memberBatonRestricted(member, batonFrom) {
  for (const ab of member?.abilities || []) {
    if (ab.type === 'no_baton_except_subunit') {
      const allowed = ab.subunit || '';
      if (!batonFrom || !cardMatchesSubunit(batonFrom, allowed)) return true;
    }
    if (ab.trigger === 'continuous' && ab.type === 'no_baton') return true;
  }
  return false;
}
function stageMemberEnteredThisTurn(member, turn) {
  if (!member) return false;
  return Number(member.entered_turn || 0) === Number(turn || 1);
}
function handCostAllowsBaton(card, me) {
  return effectiveCost(card, me?.hand || []) >= 1;
}
function maybeResetBatonTouchToggle(prev, s) {
  if (prev && s && prev.turn !== s.turn) {
    G.batonTouchEnabled = true;
    syncBatonTouchToggleUi();
  }
}
function shouldShowBatonTouchToggle(s, myId) {
  if (!s || !myId) return false;
  const ph = s.phase;
  if (ph !== 'main_first' && ph !== 'main_second') return false;
  if (s.active_player !== myId) return false;
  if (isLiveRoundPlaybackActive()) return false;
  return true;
}
function syncBatonTouchToggleUi() {
  const wrap = el('baton-touch-toggle-wrap');
  const btn = el('baton-touch-toggle');
  if (!btn) return;
  const s = G.gameState;
  const myId = G.playerId;
  const show = shouldShowBatonTouchToggle(s, myId);
  if (wrap) {
    wrap.hidden = !show;
    wrap.classList.toggle('show', show);
    wrap.setAttribute('aria-hidden', show ? 'false' : 'true');
  }
  const on = G.batonTouchEnabled !== false;
  btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  btn.classList.toggle('baton-on', on);
  btn.classList.toggle('baton-off', !on);
  btn.title = on ? t('game.batonToggleOn') : t('game.batonToggleOff');
  btn.textContent = t('game.baton');
}
function toggleBatonTouchMode() {
  G.batonTouchEnabled = !G.batonTouchEnabled;
  syncBatonTouchToggleUi();
  const s = G.gameState;
  const myId = G.playerId;
  if (!s || !myId) return;
  syncMyStagePlayHints(s, myId);
  updatePlayTargetChrome(s, myId);
  const card = getPlayPreviewCard(s, myId);
  if (card) refreshHandPreviewPanel(card, s, myId);
}
function memberPlayModeForSlot(card, slot, s, myId) {
  const me = s?.players?.[myId];
  const exist = me?.stage?.[slot];
  if (!exist) return 'empty';
  if (stageMemberEnteredThisTurn(exist, s?.turn)) return null;
  if (memberBlocksBaton(exist) || memberBatonRestricted(exist, card)) {
    return 'overplay';
  }
  if (!handCostAllowsBaton(card, me)) {
    return 'overplay';
  }
  if (G.batonTouchEnabled !== false) {
    return 'baton';
  }
  return 'overplay';
}
function stageMemberEffectiveCost(member, me) {
  let base = member?.cost || 0;
  if (!member?.abilities?.length || !me) return base;
  const successLives = me.success_lives || [];
  const successScore = successLives.reduce((s, c) => s + (c.score || 0), 0);
  const zoneCount = (me.energy_zone || []).length;
  for (const ab of member.abilities) {
    if (ab.trigger !== 'continuous') continue;
    if (ab.type === 'self_stage_cost_bonus' && successScore >= (ab.min_success_score_sum || 0)) {
      base += ab.amount || 0;
    }
    if (ab.type === 'self_stage_cost_per_success') {
      base += successLives.length * (ab.amount || 1);
    }
    if (ab.type === 'cost_bonus_if_min_energy' && zoneCount >= (ab.min_energy || 10)) {
      base += ab.amount || 0;
    }
  }
  return base;
}
function memberStackedEnergyIds(member) {
  if (member?.stacked_energy_ids?.length) return member.stacked_energy_ids;
  return (member?.stacked_energy || []).map(e => e.instance_id).filter(Boolean);
}
function countMemberStackedEnergy(me, member) {
  if (!member) return 0;
  if (Array.isArray(member.stacked_energy) && member.stacked_energy.length) {
    return member.stacked_energy.length;
  }
  const ids = memberStackedEnergyIds(member);
  if (ids.length && me) {
    const zoneIds = new Set((me.energy_zone || []).map(e => e.instance_id).filter(Boolean));
    return ids.filter(id => zoneIds.has(id)).length;
  }
  return 0;
}
function appendMemberStackedEnergyBadge(slotEl, member, me) {
  const count = countMemberStackedEnergy(me, member);
  if (count <= 0) return;
  const stack = member.stacked_energy || [];
  const sample = stack[0] || { card_no: 'LL-E-001-SD', name_en: 'Energy' };
  const wrap = document.createElement('div');
  wrap.className = 'member-stacked-energy';
  wrap.title = `${count} Energy stacked under this Member`;
  const chip = document.createElement('div');
  chip.className = 'echip used';
  syncEnergyChipFace(chip, sample, true);
  wrap.appendChild(chip);
  if (count > 1) {
    const badge = document.createElement('span');
    badge.className = 'stacked-energy-count';
    badge.textContent = '×' + count;
    wrap.appendChild(badge);
  }
  slotEl.appendChild(wrap);
}
function estimateBatonWrEnergyActivation(me, occupant, incomingCard) {
  if (!me || !occupant || !incomingCard) return 0;
  const occ = enrichCard(occupant);
  const inc = enrichCard(incomingCard);
  for (const ab of occ.abilities || []) {
    if (ab.trigger !== 'on_leave_stage' || ab.type !== 'activate_if_baton_to_wr') continue;
    const isMember = inc.card_type === 'メンバー' || inc.card_type_en === 'Member';
    if (!isMember) continue;
    if (ab.group && (inc.group || '') !== ab.group) continue;
    if ((inc.cost || 0) < (ab.min_baton_cost || 10)) continue;
    const want = ab.count || 2;
    const inactive = (me.energy_zone || []).filter(e => !energyChipActive(e)).length;
    return Math.min(want, inactive);
  }
  return 0;
}
function affordableEnergyForBatonPlay(me, occupant, incomingCard = null) {
  return (me?.energy_zone || []).filter(energyChipActive).length;
  }
function sumSc(z){ return (z||[]).reduce((s,c)=>s+(Number(c.score)||0)+Number(c.live_score_bonus||0),0); }

function judgeLiveTotalScore(s, pid, zone) {
  if (!zone?.length) return 0;
  const cardSum = sumSc(zone);
  const myId = G.playerId;
  const board = s?.stage_board;
  const side = pid === myId ? board?.mine : board?.opp;
  return cardSum + Number(side?.live_score_bonus ?? 0);
}

