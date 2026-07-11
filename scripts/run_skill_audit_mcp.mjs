#!/usr/bin/env node
/**
 * MCP/browser skill runtime audit (tier A/B/C) for one manifest batch.
 *
 * Usage:
 *   node scripts/run_skill_audit_mcp.mjs --batch=01
 *   node scripts/run_skill_audit_mcp.mjs --batch=vol1 --update-progress
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const PW_ROOT = process.env.PLAYWRIGHT_PATH
  || 'C:/Users/super/tools/playwright-mcp/node_modules/playwright/index.mjs';

const BASE = (process.env.TCG_BASE || 'https://loveliveradio.ca/tcg/').replace(/\/?$/, '/');
const API = `${BASE}api.php`;

function usage() {
  console.log(`Usage: node scripts/run_skill_audit_mcp.mjs --batch=NN|vol1 [--update-progress] [--headed]`);
  process.exit(1);
}

const args = process.argv.slice(2);
let batchId = null;
let updateProgress = false;
let headed = false;
for (const arg of args) {
  if (arg.startsWith('--batch=')) {
    batchId = arg.split('=')[1]?.trim() || null;
    if (!batchId || !/^[A-Za-z0-9][A-Za-z0-9_-]*$/.test(batchId)) {
      console.error('Invalid --batch id:', batchId);
      usage();
    }
  } else if (arg === '--update-progress') updateProgress = true;
  else if (arg === '--headed') headed = true;
  else if (arg === '--help' || arg === '-h') usage();
  else {
    console.error('Unknown arg:', arg);
    usage();
  }
}
if (!batchId) usage();

const batchPath = path.join(ROOT, 'reports', 'skill_audit_batches', `batch-${batchId}.json`);
if (!fs.existsSync(batchPath)) {
  console.error('Missing batch:', batchPath);
  process.exit(1);
}
const batch = JSON.parse(fs.readFileSync(batchPath, 'utf8'));

const engineReportPath = path.join(ROOT, 'reports', 'skill_audit_engine', `batch-${batchId}.json`);
const engineByKey = new Map();
if (fs.existsSync(engineReportPath)) {
  const eng = JSON.parse(fs.readFileSync(engineReportPath, 'utf8'));
  for (const row of eng.results || []) {
    const key = `${row.card_no}|${row.ability_path}|${row.type}`;
    engineByKey.set(key, row);
  }
}

const { chromium } = await import(pathToFileURL(PW_ROOT).href);

async function apiPost(action, body) {
  const r = await fetch(`${API}?action=${action}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const d = await r.json();
  if (d?.error) throw new Error(`${action}: ${d.error}`);
  if (!r.ok) throw new Error(`${action}: HTTP ${r.status}`);
  return d;
}

async function getState(roomId, token) {
  const url = `${API}?action=get_state&room_id=${encodeURIComponent(roomId)}&token=${encodeURIComponent(token)}&seq=0&poll=0`;
  const r = await fetch(url);
  const d = await r.json();
  if (d?.error) throw new Error(`get_state: ${d.error}`);
  return d;
}

const PROMPT_TYPES = new Set([
  'optional_discard_prompt', 'pick_wr_to_hand', 'pick_wr_leave_stage_add', 'pick_looked_deck_hand',
  'ssd1_live_start_draw', 'ssd1_play_wr_empty', 'wait_opponent_stage_pick', 'player_choice',
]);

function nodeKey(node) {
  return `${node.card_no}|${node.ability_path}|${node.type}`;
}

function needsTierB(node) {
  const t = node.type || '';
  return PROMPT_TYPES.has(t)
    || t.includes('optional_discard')
    || t.includes('pick_wr')
    || t.includes('look_reveal')
    || t.includes('player_choice')
    || (node.trigger === 'activated' && t.includes('discard'));
}

function needsTierC(node) {
  const tr = node.trigger || '';
  return ['live_start', 'live_success', 'auto', 'automatic', 'on_enter_or_live_start'].includes(tr);
}

function expectedPhaseForCard(nodes) {
  const triggers = new Set(nodes.map((n) => n.trigger));
  if ([...triggers].some((t) => ['live_start', 'live_success', 'on_enter_or_live_start'].includes(t))
    && ![...triggers].some((t) => ['on_enter', 'activated', 'auto'].includes(t))) {
    return ['live_set', 'live_start_effects', 'main_first'];
  }
  return ['main_first', 'live_set'];
}

const results = [];
const counts = { MCP_PASS: 0, FAIL: 0, SKIP: 0 };
const consoleErrors = [];

const browser = await chromium.launch({ headless: !headed });
const context = await browser.newContext();
const page = await context.newPage();
page.on('console', (msg) => {
  if (msg.type() === 'error') consoleErrors.push(msg.text());
});
page.on('pageerror', (err) => consoleErrors.push(String(err)));

const cacheBust = Date.now();
const pageUrl = `${BASE}?nocache=${cacheBust}&debug=live,apply,poll`;

for (const cardEntry of batch.cards || []) {
  const cardNo = cardEntry.card_no;
  const nodes = cardEntry.nodes || [];
  const engSkipAll = nodes.every((n) => {
    const eng = engineByKey.get(nodeKey(n));
    return eng && (eng.status === 'SKIP' || eng.engine === 'SKIP');
  });

  if (engSkipAll) {
    for (const node of nodes) {
      results.push({ ...node, status: 'SKIP', mcp: 'SKIP', notes: 'engine SKIP' });
      counts.SKIP++;
    }
    continue;
  }

  let cardStatus = 'MCP_PASS';
  let cardNotes = [];
  let state = null;
  let roomId = null;
  let token = null;

  try {
    await page.goto(pageUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const start = await apiPost('debug_card_test_start', {
      card_no: cardNo,
      debug_mode: true,
      controller: 'self',
      zone: 'auto',
      name: 'Skill Audit',
    });
    roomId = start.room_id;
    token = start.player_token;
    state = await getState(roomId, token);

    const okPhases = expectedPhaseForCard(nodes);
    if (!okPhases.includes(state.phase)) {
      cardStatus = 'FAIL';
      cardNotes.push(`phase=${state.phase} expected one of ${okPhases.join(',')}`);
    }

    if (state.mode !== 'debug_card_test') {
      cardStatus = 'FAIL';
      cardNotes.push('mode!=debug_card_test');
    }

    const tierB = nodes.some(needsTierB);
    if (tierB && cardStatus === 'MCP_PASS') {
      const pp = state.pending_prompt;
      if (pp?.type) {
        cardNotes.push(`tierB prompt=${pp.type}`);
        if (pp.ability?.filter) cardNotes.push(`filter=${pp.ability.filter}`);
      }
    }

    const tierC = nodes.some(needsTierC);
    if (tierC && cardStatus === 'MCP_PASS') {
      cardNotes.push(`tierC phase=${state.phase}`);
    }

    if (consoleErrors.length > 0) {
      cardStatus = 'FAIL';
      cardNotes.push(`console_errors=${consoleErrors.length}`);
    }
  } catch (e) {
    cardStatus = 'FAIL';
    cardNotes.push(String(e.message || e));
  }

  for (const node of nodes) {
    const eng = engineByKey.get(nodeKey(node));
    if (eng && (eng.status === 'SKIP' || eng.engine === 'SKIP')) {
      results.push({ ...node, status: 'SKIP', mcp: 'SKIP', notes: 'engine SKIP' });
      counts.SKIP++;
      continue;
    }
    const status = cardStatus;
    results.push({
      ...node,
      status,
      mcp: status === 'MCP_PASS' ? 'PASS' : (status === 'SKIP' ? 'SKIP' : 'FAIL'),
      notes: cardNotes.join('; ') || 'tier A ok',
    });
    counts[status === 'MCP_PASS' ? 'MCP_PASS' : (status === 'SKIP' ? 'SKIP' : 'FAIL')]++;
  }

  consoleErrors.length = 0;
  await new Promise((r) => setTimeout(r, 1500));
}

await browser.close();

const report = {
  batch: batchId,
  generated_at: new Date().toISOString(),
  card_count: batch.card_count,
  node_count: results.length,
  summary: counts,
  results,
};

const outDir = path.join(ROOT, 'reports', 'skill_audit_mcp');
fs.mkdirSync(outDir, { recursive: true });
const reportPath = path.join(outDir, `batch-${batchId}.json`);
fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));

if (updateProgress) {
  const progressPath = path.join(ROOT, 'SKILL_RUNTIME_AUDIT_PROGRESS.txt');
  const date = new Date().toISOString().slice(0, 10);
  const lines = results.map((row) => {
    const eng = engineByKey.get(nodeKey(row));
    const engineCol = eng?.engine || '-';
    return `[${row.status}] ${row.card_no} | ${row.name_en} | ${row.trigger} | ${row.type} | ${row.ability_path} | ${engineCol} | ${row.mcp} | ${(row.notes || '').replace(/\|/g, '/')} | ${date}\n`;
  });
  fs.appendFileSync(progressPath, lines.join(''));
}

console.log(`MCP audit batch ${batchId}: PASS ${counts.MCP_PASS} FAIL ${counts.FAIL} SKIP ${counts.SKIP}`);
console.log(`Wrote ${reportPath}`);
if (counts.FAIL > 0) {
  const fails = [...new Set(results.filter((r) => r.status === 'FAIL').map((r) => r.card_no))];
  console.log('Failed cards:', fails.slice(0, 20).join(', '), fails.length > 20 ? '...' : '');
  process.exit(1);
}
