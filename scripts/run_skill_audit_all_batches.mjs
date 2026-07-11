#!/usr/bin/env node
/**
 * Run engine + MCP skill audit for one or all batches.
 *
 * Usage:
 *   node scripts/run_skill_audit_all_batches.mjs --from=01 --to=25
 *   node scripts/run_skill_audit_all_batches.mjs --batch=01
 *   node scripts/run_skill_audit_all_batches.mjs --batch=vol1 --engine-only
 *   node scripts/run_skill_audit_all_batches.mjs --engine-only --from=01 --to=25
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');

function run(cmd, cmdArgs) {
  const r = spawnSync(cmd, cmdArgs, { cwd: ROOT, encoding: 'utf8', shell: process.platform === 'win32' });
  if (r.stdout) process.stdout.write(r.stdout);
  if (r.stderr) process.stderr.write(r.stderr);
  return r.status ?? 1;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const args = process.argv.slice(2);
let from = 1;
let to = 25;
let single = null;
let updateProgress = true;
let engineOnly = false;

for (const arg of args) {
  if (arg.startsWith('--from=')) from = Number(arg.split('=')[1]);
  else if (arg.startsWith('--to=')) to = Number(arg.split('=')[1]);
  else if (arg.startsWith('--batch=')) single = arg.split('=')[1];
  else if (arg === '--no-progress') updateProgress = false;
  else if (arg === '--engine-only') engineOnly = true;
}

const batchesDir = path.join(ROOT, 'reports', 'skill_audit_batches');
const batchFiles = fs.readdirSync(batchesDir).filter((f) => f.startsWith('batch-') && f.endsWith('.json'));
const batchIds = batchFiles
  .map((f) => f.replace('batch-', '').replace('.json', ''))
  .filter((id) => {
    if (single) {
      // Named batches (vol1) or zero-padded numeric (01).
      return id === single || id === String(single).padStart(2, '0');
    }
    const n = Number(id);
    return Number.isFinite(n) && n >= from && n <= to;
  })
  .sort((a, b) => {
    const na = Number(a);
    const nb = Number(b);
    if (Number.isFinite(na) && Number.isFinite(nb)) return na - nb;
    return String(a).localeCompare(String(b));
  });

async function main() {
let failures = 0;
for (const id of batchIds) {
  console.log(`\n=== Batch ${id} engine ===`);
  const engArgs = ['scripts/run_skill_audit_engine.php', `--batch=${id}`];
  if (updateProgress) engArgs.push('--update-progress');
  const engCode = run('php', engArgs);

  if (!engineOnly) {
    console.log(`\n=== Batch ${id} MCP ===`);
    const mcpArgs = ['scripts/run_skill_audit_mcp.mjs', `--batch=${id}`];
    if (updateProgress) mcpArgs.push('--update-progress');
    const mcpCode = run('node', mcpArgs);
    if (engCode !== 0 || mcpCode !== 0) failures++;
    if (id !== batchIds[batchIds.length - 1]) {
      console.log('Pausing 60s (rate limit)...');
      await sleep(60000);
    }
  } else if (engCode !== 0) {
    failures++;
  }
}

console.log(`\nDone. Batches: ${batchIds.length}, batches with failures: ${failures}`);
process.exit(failures > 0 ? 1 : 0);
}

await main();
