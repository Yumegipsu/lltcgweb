#!/usr/bin/env node
/**
 * PvP live-round spectacle gating regressions.
 * Run: node scripts/verify_pvp_spectacle.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const spectacleJs = fs.readFileSync(path.join(root, 'client/js/spectacle.js'), 'utf8');
const sources = [html, spectacleJs];

function extractFn(name) {
  const re = new RegExp(`function ${name}\\([^)]*\\)\\s*\\{`);
  for (const src of sources) {
    const m = re.exec(src);
    if (!m) continue;
    let i = m.index + m[0].length;
    let depth = 1;
    while (i < src.length && depth > 0) {
      const ch = src[i++];
      if (ch === '{') depth++;
      else if (ch === '}') depth--;
    }
    return src.slice(m.index, i);
  }
  throw new Error(`missing function ${name}`);
}

const fnNames = [
  'parseTurnMarker',
  'turnAtLogIndex',
  'newLogEntries',
  'newLogHasLivePerformance',
  'logSliceHasLivePipelineSignals',
  'logTransitionHasLivePerformance',
  'isLiveSetPhase',
  'isLeavingLiveSetPhase',
  'isMainOrActivePhase',
  'isLiveSpectaclePipelinePhase',
  'isLivePerformancePhase',
  'isLiveSetPlacementOnly',
  'liveSetPlacementInProgress',
  'inferLiveShowTurn',
  'liveSpectacleDoneForTurn',
  'liveStorageRevealDoneForTurn',
  'markLiveStorageRevealDone',
  'logHasLivePerformanceForTurn',
  'logHasLivePerformanceForShowTurn',
  'shouldIgnoreStaleLivePerfSignals',
  'spectacleRecoveryContext',
  'resolvedPerfSignalsInline',
  'scanUndoneLiveSpectacleTurn',
  'detectPendingLiveSpectacleTurn',
  'emptyLiveRoundAlreadyPresented',
  'liveRoundHasLiveCards',
  'liveRoundHasLiveCardsForRound',
  'liveRoundBoardHasLiveCards',
  'liveRoundHadLivesPlayed',
  'liveSpectacleOwed',
  'liveSpectacleStillOwedOnBoard',
  'liveSpectacleStillPending',
  'liveSpectaclePendingForTransition',
  'shouldRevealLiveStorageForRound',
  'shouldResetLiveStorageRevealDone',
  'collectLiveRevealFlips',
  'shouldRunLiveRevealSequence',
  'shouldScheduleLiveStorageFlips',
  'resolveLiveRevealFlipKeys',
  'liveStorageFlipKeysForRender',
  'shouldSuppressLiveStorageFlipsNow',
  'isYellLivePickPrompt',
  'isYellAnyPickPrompt',
  'yellRevealPickCards',
  'markPerfSplashShown',
  'perfSplashAlreadyShown',
  'buildLiveRevealPlayback',
  'isSoloPlayerEmptyLiveRound',
  'liveStoragePlacementSides',
  'emptyLiveRoundScenario',
  'effectiveEmptyLiveRoundPrev',
  'effectiveLiveRoundPrev',
  'logHasSimultaneousLiveStorageReveal',
  'liveStorageRevealBypassOk',
  'liveStorageRevealFlipsActive',
  'liveStorageRevealDomComplete',
  'synthesizePerfPrevFromNext',
  'buildPerfSpectaclePrev',
  'collectPerfRoundLiveCards',
  'playerHadLivePerformance',
  'playerHadLivePerformanceForTurn',
  'perfLivePerfSuccessIds',
  'perfYellRevealInline',
  'perfLiveSuccessCountFromLog',
  'playerLiveRoundSucceeded',
  'resolvePerfSpectacleBaseline',
  'rememberPerfSpectacleBaseline',
  'roundHasLivePerformanceSignals',
  'liveStorageHadFaceDownOppBluff',
  'isLiveStorageRevealCard',
  'isMemberCard',
  'isRedactedLiveZoneCard',
  'augmentPerfSpectaclePrev',
  'clampLiveZoneCards',
  'liveZoneSlot',
  'buildEmptyLiveWrPlayback',
  'currentPerformanceRoundLogStart',
  'playerAttemptedLiveThisRound',
  'playerHasPerfLogThisRound',
  'liveRoundPerfLogsComplete',
  'liveRoundJudgeReady',
  'pendingPromptBlocksPerfSpectacle',
  'isEmptyLiveSkipTransition',
  'logTransitionHasEmptyLiveSkip',
  'emptyLiveRoundShowTurn',
  'fullLogHasEmptyLiveSkipForTurn',
  'isMemberOnlyLiveStorageRound',
  'leavingEmptyLivePipeline',
  'isLivePipelinePhase',
  'liveStorageBoardForPlayback',
  'collectLiveBluffDiscards',
  'fullLogHasEmptyLiveSkip',
  'emptyLiveRoundScenario',
  'debugLiveZonePreseedActive',
  'liveZoneLiveCards',
  'phaseBannerBlockedByLivePlacement',
  'isEnteringLiveSetPhase',
  'resolvedPerfSignalsForTransition',
  'ensurePerfSpectacleNotStaleDone',
  'clearPerfSpectacleDoneKeysOnly',
  'clearStalePerfDeferState',
  'spectacleDoneForTransition',
  'liveRoundSpectacleDone',
  'isPostSpectaclePhaseBanner',
  'isPostSpectacleLogSplash',
  'shouldSuppressPostSpectacleSplash',
  'cardMatchesWrPickClient',
  'isYellLivePickPrompt',
  'yellRevealPickCards',
  'perfYellRevealFor',
  'shouldSkipStaleLiveLogAnnouncements',
  'shouldTriggerPerfSpectacle',
  'liveStartPromptNeedsWait',
  'currentRoundLogHasPerformance',
  'currentPerformanceRoundLogStart',
];

const body = `
const PERF_SPECTACLE_DONE_STORAGE_KEY = 'tcg_perf_spectacle_done';
const PERF_SPECTACLE_DEFERRED_PROMPTS = new Set(['pick_judge_success_live']);
const PERF_SPECTACLE_MID_PROMPTS = new Set(['auto_yell_no_live_retry']);
const LIVE_ZONE_MAX = 3;
const TCG_DEBUG = { log() {}, warn() {}, group() { return { end() {} }; }, trans() { return {}; } };function isLiveTypeCard(c) { return c?.card_type_en === 'Live'; }
function isMemberCard(c) { return c?.card_type_en === 'Member'; }
function isRedactedLiveZoneCard(c) { return !!c?.instance_id && !c.revealed && c.card_no === '?'; }
function enrichCard(c) { return c; }
function deepCloneState(s) { return JSON.parse(JSON.stringify(s)); }
function liveStorageHasCards(s) {
  if (!s?.players) return false;
  return (s.players.p1?.live_zone?.length || 0) + (s.players.p2?.live_zone?.length || 0) > 0;
}
function buildFaceDownLivePlayback(board) { return board; }
function liveStorageBoardForPlayback(prev) { return prev; }
function collectLiveBluffDiscards() { return []; }
function shouldPresentEmptyLiveRound(prev, next) {
  return isEmptyLiveSkipTransition(prev, next);
}
function emptyLiveRoundPresentationPending(prev, next) {
  return shouldPresentEmptyLiveRound(prev, next);
}
function liveZoneSlot(c, i) { return c?.live_slot ?? i; }
function findCardInState(state, iid, pid) {
  if (!state?.players?.[pid] || !iid) return null;
  for (const zone of ['live_zone', 'hand', 'waiting_room']) {
    const c = (state.players[pid][zone] || []).find(x => x?.instance_id === iid);
    if (c) return c;
  }
  return null;
}function revertTurnPrepPlayerZones() {}
function emptyLiveRoundTurnFromLog() { return null; }
function logSliceHasLivePipelineSignals() { return false; }
function leavingEmptyLivePipeline() { return false; }
function shouldAnimateEmptyLiveStorageWr() { return false; }
function clearEmptyLiveRoundPerfState() {}
function liveRoundBoardHasLiveCards(s) { return liveRoundHasLiveCards(s); }
function liveRoundHasLiveCards(s) {
  if (!s?.players) return false;
  for (const pid of ['p1', 'p2']) {
    for (const c of s.players[pid]?.live_zone || []) {
      if (isLiveTypeCard(c)) return true;
    }
  }
  return false;
}
function liveRoundHasLiveCardsForRound(s) { return liveRoundHasLiveCards(s); }
function debugLiveZonePreseedActive() { return false; }
function liveRoundHadLivesPlayed() { return false; }
function shouldTriggerPerfSpectacle() { return false; }
function shouldForceLiveSpectacle() { return false; }
function liveRoundRequiresSpectacle() { return false; }
function perfRoundHasShow() { return false; }
function isLiveSetPlacementOnly() { return false; }
function currentRoundHasLivePerformance() { return false; }
function perfSpectacleTurnKey() { return '1:live_show'; }
function performanceSpectacleReady() { return false; }
function isSpectacleEligibleGameplay() { return true; }
function pendingPromptBlocksPerfSpectacle() { return false; }
function liveSetPlacementInProgress() { return false; }
function isEmptyLiveSkipTransition(prev, next) {
  if (!prev || !next) return false;
  const from = prev.log?.length || 0;
  const slice = (next.log || []).slice(from);
  if (slice.some(e => e.msg === 'No Lives played this turn.')) return true;
  return false;
}
function clearPerfSpectacleDoneStorage() {
  G._perfSpectacleDoneKey = null;
  G._perfSpectacleDoneTurns = null;
  G._liveStorageRevealDoneTurns = null;
}
function clearPerfSpectacleDoneKeysOnly() {
  G._perfSpectacleDoneKey = null;
  G._perfSpectacleDoneTurns = null;
}
function clearStaleLiveStorageFlipState() {}
function shouldSkipStaleLiveLogAnnouncements() { return false; }
function clearDeferredPromptState() {}
function el() { return null; }
function closeM() {}
function restorePerfSpectacleDoneKey() {}
function primaryLiveShowTurn(prev, next) {
  const turn = next?.turn ?? prev?.turn;
  return turn == null ? null : turn;
}
function candidateLiveShowTurns(prev, next) {
  const inferred = inferLiveShowTurn(prev, next);
  const turns = new Set();
  if (inferred != null) { turns.add(inferred); turns.add(inferred + 1); }
  if (prev?.turn != null) turns.add(prev.turn);
  if (next?.turn != null) turns.add(next.turn);
  return [...turns].filter(t => t >= 1);
}
function isLivePipelineLogBanner(msg) {
  return msg === '=== Performance Phase ===' || msg === '=== Live Show ===';
}
function isPostLivePipelineLogBanner(msg) {
  return msg === '=== Live Win/Loss Check Phase ===' || msg === '=== Live Start Effects ===';
}
function isLiveRoundPlaybackActive() { return !!G._liveRoundPlaybackActive; }
function livePlaybackBlocksMainPhaseUi(s, prev) {
  if (isLiveRoundPlaybackActive()) return true;
  if (G._liveStorageOutcomePending) return true;
  return false;
}
${fnNames.map(extractFn).join('\n')}
`;

const sandbox = { console, G: { isTutorial: false, roomId: 'test-room' } };
vm.createContext(sandbox);
vm.runInContext(body, sandbox, { filename: 'verify_pvp_spectacle.js' });

const {
  liveRoundHadLivesPlayed,
  liveSpectacleOwed,
  liveSpectacleStillPending,
  liveSpectaclePendingForTransition,
  detectPendingLiveSpectacleTurn,
  shouldIgnoreStaleLivePerfSignals,
  isEmptyLiveSkipTransition,
  scanUndoneLiveSpectacleTurn,
  ensurePerfSpectacleNotStaleDone,
  clearStalePerfDeferState,
  liveSpectacleDoneForTurn,
  liveStorageRevealDoneForTurn,
  markLiveStorageRevealDone,
  pendingPromptBlocksPerfSpectacle,
  shouldRevealLiveStorageForRound,
  shouldResetLiveStorageRevealDone,
  markPerfSplashShown,
  perfSplashAlreadyShown,
  buildLiveRevealPlayback,
  isSoloPlayerEmptyLiveRound,
  liveStoragePlacementSides,
  liveStorageHadFaceDownOppBluff,
  isLeavingLiveSetPhase,
  shouldSuppressPostSpectacleSplash,
  liveRoundSpectacleDone,
  logHasSimultaneousLiveStorageReveal,
  liveStorageRevealBypassOk,
  effectiveLiveRoundPrev,
  liveStorageRevealFlipsActive,
  synthesizePerfPrevFromNext,
  buildPerfSpectaclePrev,
  shouldScheduleLiveStorageFlips,
  resolveLiveRevealFlipKeys,
  liveStorageFlipKeysForRender,
  shouldSuppressLiveStorageFlipsNow,
  yellRevealPickCards,
  shouldSkipStaleLiveLogAnnouncements,
  shouldTriggerPerfSpectacle,
  liveStartPromptNeedsWait,
} = sandbox;

let failed = 0;
function ok(label, cond) {
  if (!cond) { console.error(`FAIL: ${label}`); failed++; }
  else console.log(`OK: ${label}`);
}

const liveCard = { card_type_en: 'Live', instance_id: 'live-1' };

// PvP batched poll: live_set -> main_second with full perf log
const pvpPrev = {
  turn: 3,
  phase: 'live_set',
  log: [
    { msg: '--- Turn 3 ---' },
    { msg: '=== LIVE Phase ===' },
  ],
  players: {
    p1: { name: 'Alice', live_zone: [liveCard] },
    p2: { name: 'Bob', live_zone: [liveCard] },
  },
  live_ready: { p1: true, p2: true },
};
const pvpNext = {
  turn: 3,
  phase: 'main_second',
  log: [
    ...pvpPrev.log,
    { msg: '=== Performance Phase ===' },
    { msg: 'Alice performed Live! Blades: 3 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Bob performed Live! Blades: 4 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Live Scores: Alice = 2 | Bob = 5' },
  ],
  players: {
    p1: { name: 'Alice', live_zone: [] },
    p2: { name: 'Bob', live_zone: [] },
  },
  _live_perf_snapshot: { p1: ['live-1'], p2: ['live-2'] },
  live_attempt: ['p1', 'p2'],
};
ok('PvP batched: not stale main→main', !shouldIgnoreStaleLivePerfSignals(pvpPrev, pvpNext));
ok('PvP batched: lives played', liveRoundHadLivesPlayed(pvpPrev, pvpNext, 3));
ok('PvP batched: spectacle owed', liveSpectacleOwed(pvpPrev, pvpNext, 3));
ok('PvP batched: pending turn', detectPendingLiveSpectacleTurn(pvpPrev, pvpNext) === 3);
ok('PvP batched: blocks announcements', liveSpectaclePendingForTransition(pvpPrev, pvpNext));

// Main→main baton with stale perf snapshot — no replay
const batonPrev = {
  turn: 4,
  phase: 'main_second',
  log: [
    { msg: '--- Turn 4 ---' },
    { msg: 'Alice performed Live! Blades: 5 | Hearts: [pink] | Live success: 1 | Failed: 0' },
  ],
  players: { p1: { name: 'Alice', live_zone: [liveCard] }, p2: { name: 'Bob' } },
  _live_perf_snapshot: { p1: ['live-1'] },
};
const batonNext = JSON.parse(JSON.stringify(batonPrev));
batonNext.log.push({ msg: 'Alice played Kosuzu Kachimachi to center area.' });
sandbox.G._perfSpectacleDoneTurns = new Set([4]);
ok('baton: stale signals ignored', shouldIgnoreStaleLivePerfSignals(batonPrev, batonNext));
ok('baton: no pending spectacle', detectPendingLiveSpectacleTurn(batonPrev, batonNext) == null);
ok('baton: spectacle not owed', !liveSpectacleOwed(batonPrev, batonNext, 4));
ok('baton: scanUndone null', scanUndoneLiveSpectacleTurn(batonNext, batonPrev) == null);

// Empty LIVE round
const emptyPrev = {
  turn: 2,
  phase: 'live_set',
  log: [{ msg: '--- Turn 2 ---' }, { msg: '=== LIVE Phase ===' }],
  players: { p1: { name: 'Alice' }, p2: { name: 'Bob' } },
  live_ready: { p1: true, p2: true },
};
const emptyNext = {
  turn: 2,
  phase: 'main_first',
  log: [
    ...emptyPrev.log,
    { msg: 'No Lives played this turn.' },
  ],
  players: { p1: { name: 'Alice' }, p2: { name: 'Bob' } },
};
ok('empty LIVE: is skip transition', isEmptyLiveSkipTransition(emptyPrev, emptyNext));
ok('empty LIVE: no lives played', !liveRoundHadLivesPlayed(emptyPrev, emptyNext, 2));
ok('empty LIVE: spectacle not owed', !liveSpectacleOwed(emptyPrev, emptyNext, 2));

// Stale done-key cleared when new perf log arrives for a different show turn
sandbox.G = { isTutorial: false, roomId: 'test-room', _perfSpectacleDoneKey: '1:live_show', _perfSpectacleDoneTurns: new Set([1]) };
const stalePrev = {
  turn: 2,
  phase: 'live_set',
  log: [{ msg: '--- Turn 2 ---' }],
  players: pvpPrev.players,
};
const staleNext = {
  turn: 2,
  phase: 'main_first',
  log: [
    { msg: '--- Turn 2 ---' },
    { msg: 'Alice performed Live! Blades: 2 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Bob performed Live! Blades: 3 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Live Scores: Alice = 1 | Bob = 2' },
  ],
  players: { p1: { name: 'Alice' }, p2: { name: 'Bob' } },
  live_attempt: ['p1', 'p2'],
};
ensurePerfSpectacleNotStaleDone(stalePrev, staleNext);
ok('stale done-key cleared for wrong turn', sandbox.G._perfSpectacleDoneKey == null);
ok('stale done-key: spectacle still owed', liveSpectacleOwed(stalePrev, staleNext, 2));
ok('stale done-key: reveal-done preserved', (() => {
  sandbox.G._liveStorageRevealDoneTurns = new Set([1]);
  sandbox.G._perfSpectacleDoneKey = '1:live_show';
  sandbox.G._perfSpectacleDoneTurns = new Set([1]);
  ensurePerfSpectacleNotStaleDone(stalePrev, staleNext);
  return sandbox.G._liveStorageRevealDoneTurns?.has(1);
})());

// Main→main must not seal an unplayed spectacle as done
sandbox.G = {
  isTutorial: false,
  roomId: 'test-room',
  playerId: 'p1',
  _perfSpectacleDoneTurns: null,
  _spectacleRecoveryPending: null,
};
const mainMainPrev = {
  turn: 3,
  phase: 'main_first',
  log: [
    { msg: '--- Turn 3 ---' },
    { msg: '=== Performance Phase ===' },
    { msg: 'Alice performed Live! Blades: 2 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Bob performed Live! Blades: 3 | Hearts: [pink] | Live success: 1 | Failed: 0' },
  ],
  players: {
    p1: { name: 'Alice', live_zone: [] },
    p2: { name: 'Bob', live_zone: [] },
  },
  live_attempt: ['p1', 'p2'],
  live_round_success: { p1: true, p2: true },
};
const mainMainNext = {
  ...mainMainPrev,
  phase: 'main_first',
  log: [
    ...mainMainPrev.log,
    { msg: 'Live Scores: Alice = 1 | Bob = 2' },
    { msg: 'Alice plays a Member.' },
  ],
  active_player: 'p1',
};
clearStalePerfDeferState(mainMainPrev, mainMainNext);
ok('main→main does not seal unfinished spectacle', !liveSpectacleDoneForTurn(3));
ok('main→main bare member play does not re-arm from log alone', !sandbox.G._spectacleRecoveryPending);
sandbox.G._deferPerfSpectaclePrev = {
  turn: 3,
  phase: 'live_set',
  players: {
    p1: { name: 'Alice', live_zone: [{ ...liveCard, instance_id: 'owed-p1' }] },
    p2: { name: 'Bob', live_zone: [{ ...liveCard, instance_id: 'owed-p2' }] },
  },
};
clearStalePerfDeferState(mainMainPrev, mainMainNext);
ok('main→main re-arms recovery while owed (deferred lives)', !!sandbox.G._spectacleRecoveryPending);
sandbox.G._deferPerfSpectaclePrev = null;
sandbox.G._spectacleRecoveryPending = null;

// PvP tie score: live_judge with pick deferred — spectacle owed, reveal only once
const tiePrev = {
  turn: 3,
  phase: 'live_set',
  log: [
    { msg: '--- Turn 3 ---' },
    { msg: '=== LIVE Phase ===' },
  ],
  players: {
    p1: { name: 'Alice', live_zone: [{ ...liveCard, instance_id: 'live-p1' }] },
    p2: { name: 'Bob', live_zone: [{ ...liveCard, instance_id: 'live-p2' }] },
  },
  live_ready: { p1: true, p2: true },
};
const tieNext = {
  turn: 3,
  phase: 'live_judge',
  log: [
    ...tiePrev.log,
    { msg: '=== Performance Phase ===' },
    { msg: 'Alice performed Live! Blades: 2 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Bob performed Live! Blades: 2 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Live Scores: Alice = 3 | Bob = 3' },
  ],
  players: {
    p1: { name: 'Alice', live_zone: [{ ...liveCard, instance_id: 'live-p1', revealed: true }] },
    p2: { name: 'Bob', live_zone: [{ ...liveCard, instance_id: 'live-p2', revealed: true }] },
  },
  live_attempt: ['p1', 'p2'],
  live_round_success: { p1: true, p2: true },
  pending_prompt: { type: 'pick_judge_success_live', responder: 'p1' },
};
ok('tie judge: spectacle owed', liveSpectacleOwed(tiePrev, tieNext, 3));
ok('tie judge: spectacle not blocked by pick', !pendingPromptBlocksPerfSpectacle(tieNext));
sandbox.markLiveStorageRevealDone(3);
ok('tie judge: reveal marked done', liveStorageRevealDoneForTurn(3));

// Reveal-once: synthetic live_set prev must not re-trigger reveal after markLiveStorageRevealDone
const revealOncePrev = {
  turn: 2,
  phase: 'live_set',
  log: [{ msg: '--- Turn 2 ---' }, { msg: '=== LIVE Phase ===' }],
  players: {
    p1: { name: 'Alice', live_zone: [{ ...liveCard, instance_id: 'live-p1' }] },
    p2: { name: 'Bob', live_zone: [{ ...liveCard, instance_id: 'live-p2' }] },
  },
};
const revealOnceNext = {
  turn: 2,
  phase: 'main_first',
  log: [
    ...revealOncePrev.log,
    { msg: '=== Performance Phase ===' },
    { msg: 'Alice performed Live! Blades: 2 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Bob performed Live! Blades: 3 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Live Scores: Alice = 1 | Bob = 2' },
  ],
  players: { p1: { name: 'Alice' }, p2: { name: 'Bob' } },
  live_attempt: ['p1', 'p2'],
  pending_prompt: { type: 'pick_live_start_skill', responder: 'p1' },
};
sandbox.markLiveStorageRevealDone(2);
ok('reveal-once: skip reveal when done (live_set prev)', !shouldRevealLiveStorageForRound(revealOncePrev, revealOnceNext));
ok('reveal-once: pending turn still set for deferred spectacle', detectPendingLiveSpectacleTurn(revealOncePrev, revealOnceNext) === 2);
ok('reveal-once: spectacle not owed when judge not ready', !liveSpectacleOwed(revealOncePrev, revealOnceNext, 2));
ok('reveal-once: still pending after reveal done', liveSpectacleStillPending(revealOncePrev, revealOnceNext, 2));

// Reveal done + main_first batched poll: spectacle must stay pending (regression FE3E85)
sandbox.G._liveStorageRevealDoneTurns = new Set([2]);
const revealDoneMainPrev = {
  turn: 2,
  phase: 'live_set',
  log: [{ msg: '--- Turn 2 ---' }, { msg: '=== LIVE Phase ===' }],
  players: {
    p1: { name: 'Alice', live_zone: [{ ...liveCard, instance_id: 'live-p1' }] },
    p2: { name: 'Bob', live_zone: [{ ...liveCard, instance_id: 'live-p2' }] },
  },
};
const revealDoneMainNext = {
  turn: 2,
  phase: 'main_first',
  log: [
    ...revealDoneMainPrev.log,
    { msg: '=== Performance Phase ===' },
    { msg: 'Alice performed Live! Blades: 2 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Bob performed Live! Blades: 3 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Live Scores: Alice = 1 | Bob = 2' },
  ],
  players: { p1: { name: 'Alice' }, p2: { name: 'Bob' } },
  live_attempt: ['p1', 'p2'],
};
ok('reveal-done main: pending turn detected', detectPendingLiveSpectacleTurn(revealDoneMainPrev, revealDoneMainNext) === 2);
ok('reveal-done main: spectacle still pending', liveSpectacleStillPending(revealDoneMainPrev, revealDoneMainNext, 2));
ok('reveal-done main: spectacle owed once judge ready', liveSpectacleOwed(revealDoneMainPrev, revealDoneMainNext, 2));
sandbox.G._liveStorageRevealDoneTurns = new Set();

// PvP empty round: opponent member bluffs must flip before "no Lives" splash
sandbox.G._liveStorageRevealDoneTurns = new Set();
const memberBluffPrev = {
  turn: 2,
  phase: 'live_set',
  log: [{ msg: '--- Turn 2 ---' }, { msg: '=== LIVE Phase ===' }],
  players: {
    p1: { live_zone: [{ card_type_en: 'Member', instance_id: 'm1', revealed: false }] },
    p2: { live_zone: [{ card_type_en: 'Member', instance_id: 'm2', revealed: false, card_no: '?' }] },
  },
};
const memberBluffNext = {
  turn: 2,
  phase: 'main_first',
  log: [...memberBluffPrev.log, { msg: 'No Lives played this turn.' }],
  players: { p1: { live_zone: [] }, p2: { live_zone: [] } },
};
ok('empty PvP bluffs: needs reveal (live_set prev)', shouldRevealLiveStorageForRound(memberBluffPrev, memberBluffNext, true));
ok('empty PvP bluffs: both sides placed', liveStoragePlacementSides(memberBluffPrev, memberBluffNext) === 2);
ok('empty PvP bluffs: not solo round', !isSoloPlayerEmptyLiveRound(memberBluffPrev, memberBluffNext));

sandbox.G._liveSetStorageBaseline = {
  turn: 2,
  phase: 'live_set',
  players: {
    p1: { live_zone: [{ card_type_en: 'Member', instance_id: 'm1', revealed: false }] },
    p2: { live_zone: [{ card_type_en: 'Member', instance_id: 'm2', revealed: false, card_no: '?' }] },
  },
};
const clearedPrev = {
  turn: 2,
  phase: 'main_first',
  log: memberBluffPrev.log,
  players: { p1: { live_zone: [] }, p2: { live_zone: [] } },
  seq: 11,
};
ok('empty PvP bluffs: merged baseline not solo', !isSoloPlayerEmptyLiveRound(clearedPrev, memberBluffNext));
ok('empty PvP bluffs: merged baseline needs reveal', shouldRevealLiveStorageForRound(clearedPrev, memberBluffNext, true));
sandbox.G._liveSetStorageBaseline = null;
ok('empty PvP bluffs: solo-human skip only when one side', !shouldRevealLiveStorageForRound(
  {
    turn: 2,
    phase: 'live_set',
    log: memberBluffPrev.log,
    players: {
      p1: { live_zone: [{ card_type_en: 'Member', instance_id: 'm1', revealed: false }] },
      p2: { live_zone: [] },
    },
  },
  memberBluffNext,
  true,
));

// Main-phase splash: in-turn main_first→main_second after spectacle must not suppress banner
const mainHandoffPrev = {
  turn: 3,
  phase: 'main_first',
  log: [
    { msg: '--- Turn 3 ---' },
    { msg: '=== Performance Phase ===' },
    { msg: 'Alice performed Live! Blades: 2 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Bob performed Live! Blades: 3 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Live Scores: Alice = 1 | Bob = 2' },
  ],
  players: { p1: { name: 'Alice' }, p2: { name: 'Bob' } },
};
const mainHandoffNext = { ...mainHandoffPrev, phase: 'main_second' };
sandbox.G._perfSpectacleDoneTurns = new Set([3]);
sandbox.G._postSpectacleSplashPause = false;
ok('main handoff: spectacle done for turn', liveRoundSpectacleDone(mainHandoffPrev, mainHandoffNext));
ok('main handoff: splash not suppressed', !shouldSuppressPostSpectacleSplash(null, 'main_second', mainHandoffPrev, mainHandoffNext));

// live_judge→main_first exit still suppresses duplicate post-spectacle splash
const judgeExitPrev = {
  turn: 3,
  phase: 'live_judge',
  log: mainHandoffPrev.log,
  players: mainHandoffPrev.players,
  pending_prompt: { type: 'pick_judge_success_live', responder: 'p1' },
};
const judgeExitNext = { ...judgeExitPrev, phase: 'main_first', pending_prompt: null };
ok('judge exit: splash still suppressed', shouldSuppressPostSpectacleSplash(null, 'main_first', judgeExitPrev, judgeExitNext));

// Flip reveal: opponent face-down must not be treated as reveal-complete when marked done prematurely
const faceDownOppPrev = {
  turn: 2,
  phase: 'live_set',
  players: {
    p1: { name: 'Alice', live_zone: [{ ...liveCard, instance_id: 'live-p1', revealed: true }] },
    p2: { name: 'Bob', live_zone: [{ card_type_en: 'Member', instance_id: 'm2', revealed: false, card_no: '?' }] },
  },
};
ok('flip retry: face-down opp bluff detected', liveStorageHadFaceDownOppBluff(faceDownOppPrev, 'p1'));
sandbox.G._liveStorageRevealDoneTurns = new Set([2]);
ok('flip retry: reveal flagged done', liveStorageRevealDoneForTurn(2));
sandbox.G._livePostRevealBoard = faceDownOppPrev;
ok('flip retry: reset when held board still face-down',
  shouldResetLiveStorageRevealDone(faceDownOppPrev, revealDoneMainNext, 2, 'p1'));
sandbox.G._livePostRevealBoard = faceDownOppPrev;
ok('flip retry: reset when post-reveal hold still face-down',
  shouldResetLiveStorageRevealDone(faceDownOppPrev, revealDoneMainNext, 2, 'p1'));
sandbox.G._livePostRevealBoard = null;
sandbox.G._liveStorageRevealDoneTurns = new Set();
ok('flip order: leaving live_set with lives', isLeavingLiveSetPhase(revealDoneMainPrev, revealDoneMainNext));
ok('flip order: face-down opp in live_set prev before main poll',
  liveStorageHadFaceDownOppBluff(revealDoneMainPrev, 'p1'));
sandbox.markLiveStorageRevealDone(2);
ok('flip order: skip reveal once marked done', !shouldRevealLiveStorageForRound(revealDoneMainPrev, revealDoneMainNext));
sandbox.G._liveStorageRevealDoneTurns = new Set();

// Reveal-done reset: stale live_set prev must NOT re-open reveal after post-reveal board is face-up
sandbox.markLiveStorageRevealDone(2);
sandbox.G._livePostRevealBoard = {
  turn: 2,
  phase: 'main_first',
  players: {
    p1: { live_zone: [{ ...liveCard, instance_id: 'live-p1', revealed: true }] },
    p2: { live_zone: [{ ...liveCard, instance_id: 'live-p2', revealed: true }] },
  },
};
ok('reveal-reset: no reset when post-reveal board face-up',
  !shouldResetLiveStorageRevealDone(revealDoneMainPrev, revealDoneMainNext, 2, 'p1'));
const stalePlayback = buildLiveRevealPlayback(revealDoneMainPrev, revealDoneMainNext, 'p1');
ok('reveal-reset: stale playback board still face-down from live_set prev',
  liveStorageHadFaceDownOppBluff(stalePlayback, 'p1'));
sandbox.G._livePostRevealBoard = {
  turn: 2,
  phase: 'main_first',
  players: {
    p1: { live_zone: [{ ...liveCard, instance_id: 'live-p1', revealed: true }] },
    p2: { live_zone: [{ card_type_en: 'Member', instance_id: 'm2', revealed: false, card_no: '?' }] },
  },
};
ok('reveal-reset: reset when current board still face-down',
  shouldResetLiveStorageRevealDone(revealDoneMainPrev, revealDoneMainNext, 2, 'p1'));
sandbox.G._livePostRevealBoard = null;
sandbox.G._liveStorageRevealDoneTurns = new Set();

// Face-down playback clone must not reset reveal-done when server state is cleared
sandbox.markLiveStorageRevealDone(2);
sandbox.G._liveRoundPlaybackActive = true;
sandbox.G.gameState = {
  turn: 2,
  phase: 'live_set',
  players: {
    p1: { live_zone: [{ ...liveCard, instance_id: 'live-p1', revealed: true }] },
    p2: { live_zone: [{ card_type_en: 'Member', instance_id: 'm2', revealed: false, card_no: '?' }] },
  },
};
const clearedServerNext = {
  turn: 2,
  phase: 'main_first',
  players: { p1: { live_zone: [] }, p2: { live_zone: [] } },
  log: revealDoneMainNext.log,
  live_attempt: ['p1', 'p2'],
};
ok('reveal-reset: ignore face-down playback when server cleared',
  !shouldResetLiveStorageRevealDone(revealDoneMainPrev, clearedServerNext, 2, 'p1'));
sandbox.G._liveRoundPlaybackActive = false;
sandbox.G.gameState = null;
sandbox.G._liveStorageRevealDoneTurns = new Set();

// Batched poll: P2 missed face-down snapshot — bypass reveal when log confirms simultaneous reveal
const withRevealLog = {
  ...clearedServerNext,
  log: [...clearedServerNext.log, { msg: 'Both players reveal Live storage simultaneously.' }],
};
const batchedP2Prev = {
  turn: 2,
  phase: 'live_set',
  seq: 8,
  log: revealDoneMainPrev.log,
  players: {
    p1: { name: 'Alice', live_zone: [] },
    p2: { name: 'Bob', live_zone: [] },
  },
  live_ready: { p1: true, p2: true },
};
const batchedP2Baseline = {
  turn: 2,
  phase: 'live_set',
  players: {
    p1: { name: 'Alice', live_zone: [{ ...liveCard, instance_id: 'live-p1', revealed: false }] },
    p2: { name: 'Bob', live_zone: [{ ...liveCard, instance_id: 'live-p2', revealed: false }] },
  },
};
ok('reveal-bypass: simultaneous reveal log detected',
  logHasSimultaneousLiveStorageReveal(withRevealLog, 2));
ok('reveal-bypass: simultaneous log bypasses without baseline',
  liveStorageRevealBypassOk(batchedP2Prev, withRevealLog, 2, 'p2'));
sandbox.G._liveSetStorageBaseline = batchedP2Baseline;
ok('reveal-bypass: no bypass when baseline provides animatable reveal',
  !liveStorageRevealBypassOk(batchedP2Prev, clearedServerNext, 2, 'p2'));
ok('reveal-bypass: no bypass while animatable face-down board exists',
  !liveStorageRevealBypassOk(revealDoneMainPrev, clearedServerNext, 2, 'p2'));
ok('reveal-bypass: no bypass while opponent still face-down on server',
  !liveStorageRevealBypassOk(faceDownOppPrev, revealDoneMainNext, 2, 'p1'));
const effPrev = effectiveLiveRoundPrev(batchedP2Prev, clearedServerNext);
ok('effectiveLiveRoundPrev: uses baseline when prev live_zone empty',
  effPrev?.players?.p1?.live_zone?.length > 0 && effPrev?.players?.p2?.live_zone?.length > 0);
sandbox.G._liveSetStorageBaseline = null;

// Reveal-once: flip scheduling guard treats in-flight flip keys as active
sandbox.G._liveFlipScheduled = new Set(['p2:live-opp']);
ok('reveal-once: scheduled flip key blocks duplicate scheduling',
  liveStorageRevealFlipsActive(new Set(['p2:live-opp']), 'p1'));
sandbox.G._liveFlipScheduled = new Set();
sandbox.G._liveStorageRevealDoneTurns = new Set([2]);
ok('reveal-once: marked turn skips shouldReveal',
  !shouldRevealLiveStorageForRound(revealDoneMainPrev, revealDoneMainNext));

// Splash guard: turn inference oscillation (1 vs 2) must not replay Performance banner
sandbox.G._perfSplashShownTurns = new Set();
sandbox.markPerfSplashShown(1, revealDoneMainPrev, revealDoneMainNext);
ok('splash guard: turn 1 marked', sandbox.G._perfSplashShownTurns.has(1));
ok('splash guard: turn 2 blocked after turn 1 shown',
  perfSplashAlreadyShown(2, revealDoneMainPrev, revealDoneMainNext));
ok('splash guard: primary turn blocked',
  perfSplashAlreadyShown(null, revealDoneMainPrev, revealDoneMainNext));
sandbox.G._perfSplashShownTurns = new Set();

// Stale live-storage flip replay during main phase (regression)
sandbox.G.playerId = 'p1';
const flipOwedPrev = {
  turn: 2,
  phase: 'live_set',
  players: {
    p1: { live_zone: [{ ...liveCard, instance_id: 'live-p1', revealed: true }] },
    p2: { live_zone: [{ card_type_en: 'Member', instance_id: 'm2', revealed: false, card_no: '?' }] },
  },
};
const flipOwedNext = {
  turn: 2,
  phase: 'main_first',
  players: {
    p1: { live_zone: [{ ...liveCard, instance_id: 'live-p1', revealed: true }] },
    p2: { live_zone: [{ card_type_en: 'Member', instance_id: 'm2', revealed: true }] },
  },
};
sandbox.G._liveStorageRevealDoneTurns = new Set();
ok('flip schedule: leaving live_set with face-down opp', shouldScheduleLiveStorageFlips(flipOwedPrev, flipOwedNext, 'p1'));
sandbox.markLiveStorageRevealDone(2);
ok('flip schedule: skip replay after reveal done', !shouldScheduleLiveStorageFlips(flipOwedPrev, flipOwedNext, 'p1'));
ok('flip schedule: resolve keys empty when reveal done',
  resolveLiveRevealFlipKeys(flipOwedPrev, flipOwedNext, 'p1').size === 0);
const mainFirstStaleFaceDown = { ...flipOwedPrev, phase: 'main_first' };
const mainSecondRevealed = { ...flipOwedNext, phase: 'main_second' };
ok('flip schedule: main→main baton ignores stale face-down',
  !shouldScheduleLiveStorageFlips(mainFirstStaleFaceDown, mainSecondRevealed, 'p1'));
ok('flip render: main phase filters done-turn keys',
  liveStorageFlipKeysForRender(mainSecondRevealed, new Set(['p2:m2']), 'p1').size === 0);
sandbox.G._liveStorageRevealDoneTurns = new Set();
sandbox.G._liveStorageRevealRunning = false;
sandbox.G._liveRoundPlaybackActive = false;
sandbox.G._liveStorageRevealAnimCount = 0;
sandbox.G._liveRevealFlips = new Set(['p2:m2']);
ok('flip suppress: sticky keys alone cannot arm flips outside reveal',
  shouldSuppressLiveStorageFlipsNow(mainSecondRevealed) === true);
ok('flip render: sticky keys cleared when suppress is on',
  liveStorageFlipKeysForRender(mainSecondRevealed, new Set(['p2:m2']), 'p1').size === 0);
sandbox.G._liveStorageRevealRunning = true;
ok('flip suppress: reveal sequence may arm flips',
  shouldSuppressLiveStorageFlipsNow(mainSecondRevealed) === false);
sandbox.G._liveStorageRevealRunning = false;

// Yell Live pick: group-only abilities must default filter to live, not member
const yellLivePr = {
  type: 'live_success_pick_yell_live',
  ability: { group: 'Superstar' },
  candidates: [{
    instance_id: 'yell-live-1',
    card_type_en: 'Live',
    group: 'Superstar',
  }],
};
const yellMemberPr = {
  type: 'pick_yell_member',
  ability: { group: 'Superstar', filter: 'member' },
  candidates: [{
    instance_id: 'yell-mem-1',
    card_type_en: 'Member',
    group: 'Superstar',
  }],
};
const yellMixedState = {
  yell_reveal: {
    p1: [
      { instance_id: 'yell-live-1', card_type_en: 'Live', group: 'Superstar' },
      { instance_id: 'yell-mem-1', card_type_en: 'Member', group: 'Superstar' },
    ],
  },
};
ok('yell pick: Superstar Live kept for live_success_pick_yell_live',
  yellRevealPickCards(yellLivePr, yellMixedState, 'p1').length === 1);
ok('yell pick: member prompt keeps Member only from mixed yell',
  yellRevealPickCards(yellMemberPr, yellMixedState, 'p1').length === 1
    && yellRevealPickCards(yellMemberPr, yellMixedState, 'p1')[0].instance_id === 'yell-mem-1');

ok('stale log announce: main baton skips pipeline replay',
  shouldSkipStaleLiveLogAnnouncements(mainFirstStaleFaceDown, mainSecondRevealed));
ok('stale log announce: live_set exit still allows banners',
  !shouldSkipStaleLiveLogAnnouncements(flipOwedPrev, flipOwedNext));

// Multi Live Start skills → leave live_start_effects must still arm flips/spectacle
const liveStartDeferPrev = {
  turn: 3,
  phase: 'live_set',
  seq: 20,
  players: {
    p1: { name: 'Alice', live_zone: [{ ...liveCard, instance_id: 'ls-p1', revealed: true }] },
    p2: { name: 'Bob', live_zone: [{ ...liveCard, instance_id: 'ls-p2', revealed: true }] },
  },
  log: [
    { msg: '=== Turn 3 begins ===' },
    { msg: '=== Live Show ===' },
  ],
};
const liveStartEffects = {
  turn: 3,
  phase: 'live_start_effects',
  seq: 21,
  players: {
    p1: { name: 'Alice', live_zone: [{ ...liveCard, instance_id: 'ls-p1', revealed: true }] },
    p2: { name: 'Bob', live_zone: [{ ...liveCard, instance_id: 'ls-p2', revealed: true }] },
  },
  pending_prompt: { type: 'optional_live_start', responder: 'p1' },
  log: [
    ...liveStartDeferPrev.log,
    { msg: '=== Live Start Effects ===' },
  ],
};
const afterManyLiveStarts = {
  turn: 3,
  phase: 'main_first',
  seq: 30,
  players: {
    p1: { name: 'Alice', live_zone: [] },
    p2: { name: 'Bob', live_zone: [] },
  },
  pending_prompt: null,
  live_attempt: ['p1', 'p2'],
  log: [
    ...liveStartEffects.log,
    { msg: '=== Performance Phase ===' },
    { msg: 'Alice is performing Live with "Pain".' },
    { msg: 'Bob is performing Live with "Symphony".' },
    { msg: 'Alice performed Live! Blades: 3 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Bob performed Live! Blades: 4 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Live Scores: Alice = 3 | Bob = 4' },
  ],
};
sandbox.G._perfSpectacleDoneTurns = new Set();
sandbox.G._liveStorageRevealDoneTurns = new Set([3]);
sandbox.G._deferPerfSpectaclePrev = liveStartDeferPrev;
ok('live_start leave: detectPending arms show turn',
  detectPendingLiveSpectacleTurn(liveStartEffects, afterManyLiveStarts) === 3);
ok('live_start leave: shouldTrigger arms spectacle',
  shouldTriggerPerfSpectacle(liveStartEffects, afterManyLiveStarts));
ok('live_start leave: had lives via deferred baseline',
  liveRoundHadLivesPlayed(liveStartEffects, afterManyLiveStarts, 3));
ok('live_start mid: prompt still needs wait',
  liveStartPromptNeedsWait(liveStartEffects, 'p1'));
ok('live_start mid: shouldTrigger defers while skills open',
  !shouldTriggerPerfSpectacle(liveStartDeferPrev, liveStartEffects));
sandbox.G._deferPerfSpectaclePrev = null;
sandbox.G._liveStorageRevealDoneTurns = new Set();

if (failed) process.exit(1);
console.log('verify_pvp_spectacle: all checks passed');
