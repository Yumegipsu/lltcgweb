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

function extractFn(name) {
  const re = new RegExp(`function ${name}\\([^)]*\\)\\s*\\{`, 'g');
  const m = re.exec(html);
  if (!m) throw new Error(`missing function ${name}`);
  let i = m.index + m[0].length;
  let depth = 1;
  while (i < html.length && depth > 0) {
    const ch = html[i++];
    if (ch === '{') depth++;
    else if (ch === '}') depth--;
  }
  return html.slice(m.index, i);
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
  'liveSpectaclePendingForTransition',
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
];

const body = `
const PERF_SPECTACLE_DONE_STORAGE_KEY = 'tcg_perf_spectacle_done';
const PERF_SPECTACLE_DEFERRED_PROMPTS = new Set(['pick_judge_success_live']);
const PERF_SPECTACLE_MID_PROMPTS = new Set(['auto_yell_no_live_retry']);
const TCG_DEBUG = { log() {}, warn() {}, group() { return { end() {} }; }, trans() { return {}; } };
function isLiveTypeCard(c) { return c?.card_type_en === 'Live'; }
function enrichCard(c) { return c; }
function liveStorageHasCards() { return false; }
function buildPerfSpectaclePrev(prev) { return prev; }
function clearPerfSpectacleDoneStorage() {
  G._perfSpectacleDoneKey = null;
  G._perfSpectacleDoneTurns = null;
}
function restorePerfSpectacleDoneKey() {}
${fnNames.map(extractFn).join('\n')}
`;

const sandbox = { console, G: { isTutorial: false, roomId: 'test-room' } };
vm.createContext(sandbox);
vm.runInContext(body, sandbox, { filename: 'verify_pvp_spectacle.js' });

const {
  liveRoundHadLivesPlayed,
  liveSpectacleOwed,
  liveSpectaclePendingForTransition,
  detectPendingLiveSpectacleTurn,
  shouldIgnoreStaleLivePerfSignals,
  isEmptyLiveSkipTransition,
  scanUndoneLiveSpectacleTurn,
  ensurePerfSpectacleNotStaleDone,
  liveSpectacleDoneForTurn,
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

if (failed) process.exit(1);
console.log('verify_pvp_spectacle: all checks passed');
