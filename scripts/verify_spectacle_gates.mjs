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

if (failed) {
  process.exit(1);
}
console.log('All spectacle gate checks passed.');
