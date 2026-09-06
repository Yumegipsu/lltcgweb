#!/usr/bin/env node
/**
 * Regression: Performance spectacle must show every Live in live_show.played_lives.
 * Partial hydrate used to early-return and hide the rest of the opponent row.
 * Run: node scripts/verify_perf_live_lock.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const spectacleJs = fs.readFileSync(path.join(root, 'client/js/spectacle.js'), 'utf8');
const indexHtml = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const boardRenderJs = fs.readFileSync(path.join(root, 'client/js/board-render.js'), 'utf8');
const sources = [spectacleJs, indexHtml, boardRenderJs];

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
  'liveCardIidKey',
  'sameLiveIid',
  'normalizeLiveIidList',
  'liveShowTurnFromBoards',
  'liveShowRoundKey',
  'liveShowPlayedIidsFromBoard',
  'rememberLiveShowPlayedLives',
  'liveShowPlayedIidsForPid',
  'hydratePlayedLiveCard',
  'cardsFromPlayedLiveLock',
  'hydrateSpectacleLiveCard',
  'perfCacheLiveReveal',
  'perfFindRevealedLiveMeta',
  'perfLiveZoneCards',
  'perfMergedLiveZone',
  'isPerfSpectacleLiveSlotCard',
  'clampLiveZoneCards',
  'clampLiveZoneLive',
  'liveZoneSlot',
  'perfSpectacleLiveCards',
  'collectPerfRoundLiveCards',
  'inferLiveShowTurn',
  'isLiveSetPlacementOnly',
  'playerHadLivePerformance',
  'playerHadLivePerformanceForTurn',
  'playerHasPerfLogThisRound',
  'perfLivePerfSuccessIds',
  'perfYellRevealInline',
  'perfLiveSuccessCountFromLog',
  'perfLiveFailCountFromLog',
  'playerLiveRoundSucceeded',
  'collectWrLivesMatchingPerformingLog',
  'perfPerformingLiveNamesFromLog',
  'parseTurnMarker',
  'buildPerfSpectaclePrev',
  'synthesizePerfPrevFromNext',
  'resolvePerfSpectacleBaseline',
  'augmentPerfSpectaclePrev',
  'isEmptyLiveSkipTransition',
  'liveRoundHasLiveCards',
];

let failed = 0;
function ok(label, cond) {
  if (cond) console.log(`OK: ${label}`);
  else {
    failed++;
    console.log(`FAIL: ${label}`);
  }
}

const body = `
const LIVE_ZONE_MAX = 3;
var G = {
  _perfLiveReveal: {},
  _liveShowPlayedIids: {},
  _deferPerfSpectaclePrev: null,
  _livePostRevealBoard: null,
  isTutorial: false,
  tutorialData: null,
};
function isLiveTypeCard(c) { return c?.card_type_en === 'Live' || c?.card_type === 'ライブ'; }
function isRedactedLiveZoneCard(c) { return !!c?.instance_id && !c.revealed && c.card_no === '?'; }
function enrichCard(c) { return c; }
function deepCloneState(s) { return JSON.parse(JSON.stringify(s)); }
function isLiveSetPhase() { return false; }
function liveSetPlacementInProgress() { return false; }
function intvalTurn(t) { const n = parseInt(t, 10); return Number.isFinite(n) ? n : 0; }
function isEmptyLiveSkipTransition() { return false; }
function isLiveSetPlacementOnly() { return false; }
function playerHadLivePerformance() { return true; }
function playerHadLivePerformanceForTurn() { return true; }
function playerHasPerfLogThisRound() { return true; }
function perfLivePerfSuccessIds() { return new Set(); }
function perfYellRevealInline() { return null; }
function perfLiveSuccessCountFromLog() { return 0; }
function perfLiveFailCountFromLog() { return 0; }
function playerLiveRoundSucceeded() { return true; }
function parseTurnMarker() { return null; }
function inferLiveShowTurn(prev, next) { return next?.live_show?.turn ?? next?.turn ?? prev?.turn ?? null; }
function liveRoundHasLiveCards(s) {
  if (!s?.players) return false;
  for (const pid of ['p1', 'p2']) {
    for (const c of s.players[pid]?.live_zone || []) {
      if (isLiveTypeCard(c)) return true;
    }
  }
  return false;
}
function resolvePerfSpectacleBaseline(prev) { return prev; }
function augmentPerfSpectaclePrev(prev) { return prev; }
function synthesizePerfPrevFromNext(prev) { return prev; }
function buildPerfSpectaclePrev(prev) { return prev; }
${fnNames.filter((n) => ![
  'inferLiveShowTurn',
  'isLiveSetPlacementOnly',
  'playerHadLivePerformance',
  'playerHadLivePerformanceForTurn',
  'playerHasPerfLogThisRound',
  'perfLivePerfSuccessIds',
  'perfYellRevealInline',
  'perfLiveSuccessCountFromLog',
  'perfLiveFailCountFromLog',
  'playerLiveRoundSucceeded',
  'parseTurnMarker',
  'buildPerfSpectaclePrev',
  'synthesizePerfPrevFromNext',
  'resolvePerfSpectacleBaseline',
  'augmentPerfSpectaclePrev',
  'isEmptyLiveSkipTransition',
  'liveRoundHasLiveCards',
].includes(n)).map(extractFn).join('\n')}
`;

const sandbox = { console };
vm.createContext(sandbox);
vm.runInContext(body, sandbox);
const {
  cardsFromPlayedLiveLock,
  perfSpectacleLiveCards,
  liveCardIidKey,
  G,
} = sandbox;

const liveA = {
  instance_id: 'opp-live-1', card_no: 'PL!-bp1-001-L', card_type_en: 'Live',
  name_en: 'Live A', revealed: true, live_slot: 0, score: 1,
};
const liveB = {
  instance_id: 42, card_no: 'PL!-bp1-002-L', card_type_en: 'Live',
  name_en: 'Live B', revealed: true, live_slot: 1, score: 1,
};
const liveC = {
  instance_id: 'opp-live-3', card_no: 'PL!-bp1-003-L', card_type_en: 'Live',
  name_en: 'Live C', revealed: true, live_slot: 2, score: 1,
};

const partialPrev = {
  turn: 5,
  phase: 'live_set',
  live_show: {
    turn: 5,
    stage: 'performance',
    played_lives: { p1: [], p2: ['opp-live-1', '42', 'opp-live-3'] },
  },
  players: {
    p1: { name: 'me', live_zone: [], success_lives: [], waiting_room: [] },
    p2: { name: 'opp', live_zone: [liveA, liveB, liveC], success_lives: [], waiting_room: [] },
  },
  log: [],
};
const partialNext = {
  ...partialPrev,
  phase: 'live_performance_p2',
  players: {
    p1: { name: 'me', live_zone: [], success_lives: [], waiting_room: [] },
    p2: {
      name: 'opp',
      live_zone: [
        { ...liveA, instance_id: 'opp-live-1' },
        { instance_id: 'opp-live-3', revealed: false, card_no: '?' },
      ],
      success_lives: [{ ...liveB, instance_id: 42 }],
      waiting_room: [],
    },
  },
  log: [
    { msg: '=== LIVE SHOW ===' },
    { msg: 'opp is performing Live with "Live A", "Live B", "Live C".' },
    { msg: 'opp performed Live! Blades: 9 | Hearts: [pink] | Live success: 3 | Failed: 0' },
  ],
};

G._perfLiveReveal = { 'p2:opp-live-3': liveC };
G._liveShowPlayedIids = {};

const locked = cardsFromPlayedLiveLock(partialNext, 'p2', ['opp-live-1', '42', 'opp-live-3'], partialPrev);
ok('played_lives lock hydrates all 3 across iid type + success + reveal cache',
  !!locked && locked.length === 3
  && locked.every(c => c.card_type_en === 'Live' && c.card_no && c.card_no !== '?'));

const spectacleOpp = perfSpectacleLiveCards(partialPrev, partialNext, 'p2');
ok('perfSpectacleLiveCards keeps all locked opp Lives (no partial early-return)',
  spectacleOpp.length === 3
  && new Set(spectacleOpp.map(c => liveCardIidKey(c.instance_id))).size === 3);

// Incomplete lock must not return a truncated freeze (fall through / null).
const incomplete = cardsFromPlayedLiveLock(
  {
    players: {
      p2: {
        live_zone: [liveA],
        success_lives: [],
        waiting_room: [],
      },
    },
  },
  'p2',
  ['opp-live-1', 'missing-2', 'missing-3'],
  null
);
ok('incomplete played_lives lock returns null instead of truncated row', incomplete == null);

if (failed) process.exit(1);
console.log('verify_perf_live_lock: all checks passed');
