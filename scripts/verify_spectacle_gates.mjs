/**
 * Regression checks for live-round spectacle gating (377AA6-class bugs).
 * Run: node scripts/verify_spectacle_gates.mjs
 */
import fs from 'fs';
import path from 'path';
import vm from 'vm';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(__dirname, '..');
const sources = [
  fs.readFileSync(path.join(root, 'index.html'), 'utf8'),
  fs.readFileSync(path.join(root, 'client/js/spectacle.js'), 'utf8'),
];

const names = [
  'isLivePerformancePhase',
  'currentPerformancePhaseLogStart',
  'currentPerformanceRoundLogStart',
  'playerAttemptedLiveThisRound',
  'playerHasPerfLogThisRound',
  'liveRoundPerfLogsComplete',
  'liveRoundJudgeReady',
  'pendingPromptBlocksPerfSpectacle',
  'performanceSpectacleReady',
];

const chunks = [];
for (const name of names) {
  const re = new RegExp(`function ${name}\\([^)]*\\)\\s*\\{`);
  let found = null;
  let src = '';
  for (const s of sources) {
    const m = re.exec(s);
    if (m) { found = m; src = s; break; }
  }
  if (!found) throw new Error(`missing function ${name}`);
  const start = found.index;
  let depth = 0;
  let started = false;
  for (let i = start; i < src.length; i++) {
    const ch = src[i];
    if (ch === '{') { depth++; started = true; }
    else if (ch === '}') depth--;
    if (started && depth === 0) {
      chunks.push(src.slice(start, i + 1));
      break;
    }
  }
}

const PERF_SPECTACLE_DEFERRED_PROMPTS = new Set(['pick_judge_success_live']);
const PERF_SPECTACLE_MID_PROMPTS = new Set(['auto_yell_no_live_retry']);

const ctx = {
  PERF_SPECTACLE_DEFERRED_PROMPTS,
  PERF_SPECTACLE_MID_PROMPTS,
  G: {},
  console,
  isDeferredLiveSuccessPrompt: () => false,
  isLiveSuccessDiscardPrompt: () => false,
  isMidSpectacleYellRetryPrompt: () => false,
};
vm.createContext(ctx);
vm.runInContext(chunks.join('\n'), ctx);

const {
  liveRoundPerfLogsComplete,
  liveRoundJudgeReady,
  pendingPromptBlocksPerfSpectacle,
  performanceSpectacleReady,
} = ctx;

function mkState(overrides = {}) {
  return {
    phase: 'live_success_effects',
    players: {
      p1: { name: 'Alice' },
      p2: { name: 'Bob' },
    },
    live_attempt: ['p1', 'p2'],
    log: [
      { msg: '=== Performance Phase ===' },
      { msg: 'Alice is performing Live with "Pain".' },
      { msg: 'Bob is performing Live with "Symphony".' },
      { msg: 'Alice performed Live! Blades: 3 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    ],
    pending_prompt: { type: 'effect_discard_hand', responder: 'p1' },
    ...overrides,
  };
}

let failed = 0;
function assert(label, cond) {
  if (!cond) {
    console.error('FAIL:', label);
    failed++;
  } else {
    console.log('ok:', label);
  }
}

// Symphony mid-round: Alice performed, Bob not yet — must block spectacle.
const mid = mkState();
assert('mid-round perf incomplete', !liveRoundPerfLogsComplete(mid));
assert('mid-round judge not ready', !liveRoundJudgeReady(mid));
assert('mid-round blocks spectacle prompt gate', pendingPromptBlocksPerfSpectacle(mid));
assert('mid-round performanceSpectacleReady false', !performanceSpectacleReady({}, mid));

// Both performed + Live Scores — judge ready.
const done = mkState({
  log: [
    ...mkState().log,
    { msg: 'Bob performed Live! Blades: 4 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: 'Live Scores: Alice = 2 | Bob = 5' },
  ],
  pending_prompt: null,
  phase: 'live_judge',
});
assert('complete round perf logs', liveRoundPerfLogsComplete(done));
assert('complete round judge ready', liveRoundJudgeReady(done));
assert('complete round allows spectacle', !pendingPromptBlocksPerfSpectacle(done));
assert('live_judge performanceSpectacleReady', performanceSpectacleReady({}, done));

// Sequential Live Start: first performer's "performed Live!" is before the 2nd
// Live Start header. Round-wide log helpers must still see it (refresh/judge copy).
const sequential = mkState({
  live_round_success: { p1: true, p2: false },
  live_show: { stage: 'judge' },
  phase: 'live_judge',
  pending_prompt: null,
  log: [
    { msg: '=== Performance Phase ===' },
    { msg: '=== Live Start Effects (Alice) ===' },
    { msg: 'Alice is performing Live with "Pain".' },
    { msg: 'Alice performed Live! Blades: 3 | Hearts: [pink] | Live success: 1 | Failed: 0' },
    { msg: '=== Live Start Effects (Bob) ===' },
    { msg: 'Bob is performing Live with "Symphony".' },
    { msg: 'Bob performed Live! Blades: 4 | Hearts: [pink] | Live success: 0 | Failed: 1 | Round: failed (not all Lives succeeded)' },
    { msg: 'Live Scores: Alice = 2 | Bob = 0' },
  ],
});
assert('sequential: first performer still has perf log', ctx.playerHasPerfLogThisRound(sequential, 'p1'));
assert('sequential: second performer has perf log', ctx.playerHasPerfLogThisRound(sequential, 'p2'));
assert('sequential: round perf logs complete', liveRoundPerfLogsComplete(sequential));

// #105: Main→Main baton must not re-arm prior-turn Performance from leftover defer boards.
const spectacleSrc = fs.readFileSync(path.join(root, 'client/js/spectacle.js'), 'utf8');
assert(
  '#105 Main→Main stillOwed returns false on staleMain',
  /if \(staleMain\) return false;/.test(spectacleSrc)
    && /#105/.test(spectacleSrc)
    && /G\._deferPerfSpectaclePrev = null;/.test(
      spectacleSrc.slice(spectacleSrc.indexOf('function sealLiveShowSpectacleTurn'))
    )
);

assert(
  'settledMainBlocksLiveSpectacle defined',
  /function settledMainBlocksLiveSpectacle\(prev, next\)/.test(spectacleSrc)
);
assert(
  'runLiveSpectacleGate blocks settled Main before defer prev',
  /if \(settledMainBlocksLiveSpectacle\(prev, s\)\)/.test(spectacleSrc)
    && spectacleSrc.indexOf('if (settledMainBlocksLiveSpectacle(prev, s))')
      < spectacleSrc.indexOf('const gatePrev = effectiveLiveRoundPrev(prev, s)')
);

function extractNamed(src, name) {
  const re = new RegExp(`function ${name}\\([^)]*\\)\\s*\\{`);
  const m = re.exec(src);
  if (!m) throw new Error(`missing function ${name}`);
  let depth = 0;
  let started = false;
  for (let i = m.index; i < src.length; i++) {
    const ch = src[i];
    if (ch === '{') { depth++; started = true; }
    else if (ch === '}') depth--;
    if (started && depth === 0) return src.slice(m.index, i + 1);
  }
  throw new Error(`unclosed function ${name}`);
}

const mainGateSandbox = { console, G: {} };
vm.createContext(mainGateSandbox);
vm.runInContext(`
function isSlideshowTutorial() { return false; }
${extractNamed(spectacleSrc, 'isLiveSetPhase')}
${extractNamed(spectacleSrc, 'isLeavingLiveSetPhase')}
${extractNamed(spectacleSrc, 'isLivePerformancePhase')}
${extractNamed(spectacleSrc, 'isLiveSpectaclePipelinePhase')}
${extractNamed(spectacleSrc, 'isMainOrActivePhase')}
${extractNamed(spectacleSrc, 'newLogEntries')}
${extractNamed(spectacleSrc, 'logSliceHasLivePipelineSignals')}
${extractNamed(spectacleSrc, 'newLogHasLivePerformance')}
${extractNamed(spectacleSrc, 'settledMainBlocksLiveSpectacle')}
`, mainGateSandbox);

const { settledMainBlocksLiveSpectacle } = mainGateSandbox;
const mainPrev = {
  phase: 'main_first',
  log: [
    { msg: '=== Performance Phase ===' },
    { msg: 'Alice performed Live! Blades: 6 | Live success: 1 | Failed: 0' },
  ],
};
const onEnter = {
  phase: 'main_first',
  live_show: null,
  log: [
    ...mainPrev.log,
    { msg: 'Alice played Shizuku Osaka to center area.' },
  ],
};
assert('On Enter Main→Main blocks Performance', settledMainBlocksLiveSpectacle(mainPrev, onEnter));

const leavingLive = {
  phase: 'live_set',
  log: [{ msg: '=== LIVE Phase ===' }],
};
const intoPerf = {
  phase: 'live_start_effects',
  live_show: { stage: 'reveal', started_at: 1 },
  log: [...leavingLive.log, { msg: '=== Live Start Effects ===' }],
};
assert(
  'leaving live_set into Live Start does not block',
  !settledMainBlocksLiveSpectacle(leavingLive, intoPerf)
);

const batchedPrev = {
  phase: 'live_set',
  log: [{ msg: '=== LIVE Phase ===' }],
};
const batchedMain = {
  phase: 'main_first',
  live_show: null,
  log: [
    ...batchedPrev.log,
    { msg: '=== Performance Phase ===' },
    { msg: 'Alice performed Live! Blades: 4 | Live success: 1 | Failed: 0' },
  ],
};
assert(
  'batched live_set→Main with new Performance log is not blocked',
  !settledMainBlocksLiveSpectacle(batchedPrev, batchedMain)
);

assert(
  'settled Main drop sweeps leftover card-flight clones',
  /function sweepStaleCardFlights\(/.test(spectacleSrc)
    && /sweepStaleCardFlights\(\);/.test(
      spectacleSrc.slice(spectacleSrc.indexOf('function dropStaleLiveRoundPlaybackBoards'))
    )
);

const stateApplySrc = fs.readFileSync(path.join(root, 'client/js/state-apply.js'), 'utf8');
assert(
  'holdLivePolls arms a settled-Main watchdog',
  /LIVE_POLL_HOLD_WATCHDOG_MS/.test(stateApplySrc)
    && /livePollHoldWatchdogMayRelease/.test(stateApplySrc)
    && /armLivePollHoldWatchdog\(\)/.test(stateApplySrc)
);

const sfxSrc = fs.readFileSync(path.join(root, 'sfx.js'), 'utf8');
assert(
  'sfx.js swallows Audio.play() rejections',
  /function swallowPlay\(/.test(sfxSrc)
    && /node\.play\(\)/.test(sfxSrc)
    && /p\.catch\(function \(\) \{ \/\* autoplay \*\/ \}\)/.test(sfxSrc)
    && /if \(!unlocked\) return;/.test(sfxSrc)
);

const stampsSrc = fs.readFileSync(path.join(root, 'client/js/stamps.js'), 'utf8');
assert(
  'stamps.js catches voice Audio.play() rejections',
  /voiceAudio\.play\(\)/.test(stampsSrc)
    && /p\.catch\(function \(\) \{\}\)/.test(stampsSrc)
);

if (failed) {
  process.exit(1);
}
console.log('All spectacle gate checks passed.');
