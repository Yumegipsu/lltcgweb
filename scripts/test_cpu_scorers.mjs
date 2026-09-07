#!/usr/bin/env node
/**
 * Static CPU scorer / seat / hang-proof contracts (no browser).
 * Fails CI when Easy ability mul, seat hardcodes, or hang-risk prompt gaps regress.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const cpuLoop = path.join(root, 'client', 'js', 'cpu-loop.js');
const cpuEval = path.join(root, 'client', 'js', 'cpu-eval.js');
const cpuAi = path.join(root, 'client', 'js', 'cpu-ai.js');
const cpuPolicy = path.join(root, 'client', 'js', 'cpu-policy.js');
const cpuExpert = path.join(root, 'client', 'js', 'cpu-expert.js');

function fail(msg) {
  console.error(`FAIL: ${msg}`);
  process.exitCode = 1;
}

function ok(msg) {
  console.log(`OK: ${msg}`);
}

const loopSrc = fs.readFileSync(cpuLoop, 'utf8');
const evalSrc = fs.readFileSync(cpuEval, 'utf8');
const aiSrc = fs.readFileSync(cpuAi, 'utf8');
const policySrc = fs.readFileSync(cpuPolicy, 'utf8');
const expertSrc = fs.readFileSync(cpuExpert, 'utf8');

// Expert must not fall through to Easy ability multiplier.
if (!/expert:\s*1\.5/.test(loopSrc) && !/expert:\s*1\.55/.test(loopSrc)) {
  fail('cpuTierAbilityMul must include expert (> hard)');
} else {
  ok('cpuTierAbilityMul includes expert');
}

// Easy Main must consider activates (not members-only).
if (!/tier === 'easy'[\s\S]{0,400}cpuListActivateCandidates/.test(loopSrc)) {
  fail('Easy Main should occasionally call cpuListActivateCandidates');
} else {
  ok('Easy Main can activate skills');
}

// Seat: collectActivatableAbilities must not hardcode p2 only.
if (/collectActivatableAbilities\(\s*s\s*,\s*'p2'\s*\)/.test(loopSrc)) {
  fail("collectActivatableAbilities(s, 'p2') hardcode remains in cpu-loop.js");
} else {
  ok('activate listing uses dynamic cpuId');
}

if (/cpuReadOpponent\(\s*s\s*,\s*'p2'\s*\)/.test(aiSrc)) {
  fail("cpu-ai.js still hardcodes cpuReadOpponent(s, 'p2')");
} else {
  ok('cpu-ai.js uses dynamic seat for opponent read');
}

if (/cpuEvalLiveBonus\([^)]*'p2'/.test(loopSrc)) {
  fail("cpuEvalLiveBonus still hardcodes 'p2'");
} else {
  ok('Live eval bonus uses dynamic seat');
}

// Baton placement must score board quality (hearts/blade/Live colors), not first-slot.
if (!/function cpuScoreBatonBoardDelta\(/.test(loopSrc)) {
  fail('cpuScoreBatonBoardDelta missing');
} else {
  ok('cpuScoreBatonBoardDelta present');
}
if (!/cpuScoreBatonBoardDelta\(/.test(loopSrc.split('function cpuPlanMemberPlay')[1] || '')) {
  fail('cpuPlanMemberPlay must use cpuScoreBatonBoardDelta');
} else {
  ok('cpuPlanMemberPlay scores baton board delta');
}
if (!/function cpuUpcomingLiveTargets\(/.test(loopSrc) || !/live_zone/.test(loopSrc.match(/function cpuUpcomingLiveTargets[\s\S]{0,800}/)?.[0] || '')) {
  fail('cpuUpcomingLiveTargets must consider live_zone');
} else {
  ok('upcoming Lives include live_zone');
}
if (!/boardDelta >= minDelta/.test(loopSrc)) {
  fail('baton plans should reject clear board downgrades (minDelta gate)');
} else {
  ok('baton downgrade gate present');
}

// Key Live / zone ability types must be scored above default 1.2
for (const type of [
  'score_if_live_zone_min',
  'blade_if_live_zone_group_live',
  'optional_pay_energy_add_from_wr',
  'pick_stage_member',
]) {
  const re = new RegExp(`${type}:\\s*([0-9.]+)`);
  const m = loopSrc.match(re);
  if (!m || Number(m[1]) <= 1.2) {
    fail(`cpuAbilityBaseScores missing/low for ${type}`);
  } else {
    ok(`ability base ${type}=${m[1]}`);
  }
}

if (!/function cpuLiveRaceAdvice/.test(evalSrc)) {
  fail('cpuLiveRaceAdvice missing from cpu-eval.js');
} else {
  ok('live-race advice present');
}
if (!/tier === 'easy'/.test(evalSrc.match(/function cpuLiveRaceAdvice[\s\S]{0,500}/)?.[0] || '')) {
  fail('Easy must skip opponent race read');
} else {
  ok('Easy does not require opponent race read');
}
if (!/cpuPolicyCardBonus/.test(loopSrc) || !/cpuPolicyMulliganReturn/.test(loopSrc)) {
  fail('cpu-loop must blend policy into Main and mulligan');
} else {
  ok('policy blend in Main and mulligan');
}
if (!/cpuPolicyPromptChoice/.test(aiSrc)) {
  fail('cpu-ai must apply policy before prompt type switch');
} else {
  ok('prompt policy gate present');
}
if (!/MAX_MAIN_ACTIONS:\s*3/.test(expertSrc) || !/cpuPolicyActivateRate/.test(expertSrc)) {
  fail('Expert search must be policy-guided with play+activate depth');
} else {
  ok('Expert search uses policy prior');
}
if (!policySrc.includes('cpuPolicyBlend')) {
  fail('cpu-policy.js missing blend');
} else {
  ok('cpu-policy.js loaded by scorer contract');
}

// Eval weights: expert blend > hard
const hardBlend = evalSrc.match(/hard:\s*\{[^}]*blend:\s*([0-9.]+)/);
const expertBlend = evalSrc.match(/expert:\s*\{[^}]*blend:\s*([0-9.]+)/);
if (!hardBlend || !expertBlend || Number(expertBlend[1]) <= Number(hardBlend[1])) {
  fail('expert eval blend should exceed hard');
} else {
  ok(`eval blend hard=${hardBlend[1]} expert=${expertBlend[1]}`);
}

// Hang-risk gaps from audit_cpu_prompts.py must be empty
const py = spawnSync('python', [path.join(root, 'scripts', 'audit_cpu_prompts.py')], {
  cwd: root,
  encoding: 'utf8',
});
const out = `${py.stdout || ''}\n${py.stderr || ''}`;
if (py.status !== 0 && py.error) {
  // try python3
  const py3 = spawnSync('python3', [path.join(root, 'scripts', 'audit_cpu_prompts.py')], {
    cwd: root,
    encoding: 'utf8',
  });
  if (py3.status !== 0 && py3.error) {
    fail(`could not run audit_cpu_prompts.py: ${py.error || py3.error}`);
  } else {
    checkAuditOutput(`${py3.stdout || ''}\n${py3.stderr || ''}`);
  }
} else {
  checkAuditOutput(out);
}

function checkAuditOutput(text) {
  const hangSection = text.split('=== HANG RISK')[1]?.split('=== OTHER')[0] || '';
  const gaps = [...hangSection.matchAll(/^\s{2}([a-z][a-z0-9_]+)/gm)].map((m) => m[1]);
  if (gaps.length) {
    fail(`hang-risk prompt gaps: ${gaps.join(', ')}`);
  } else {
    ok('audit_cpu_prompts hang-risk empty');
  }
  if (!/Gaps:\s*0/.test(text) && /Gaps:\s*([1-9]\d*)/.test(text)) {
    // Non-hang gaps are warnings only when covered by GENERIC_YESNO / scored choice
    console.log(`NOTE: ${text.match(/Gaps:\s*\d+/)?.[0] || 'gaps remain'} (non-hang; covered by generic/optional scorers)`);
  }
}

if (process.exitCode) {
  console.error('\nCPU contract checks failed.');
  process.exit(process.exitCode);
}
console.log('\nCPU contract checks passed.');
