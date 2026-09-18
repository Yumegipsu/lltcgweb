#!/usr/bin/env node
/**
 * Regression: high-frequency engine prompt strings must not stay English
 * when localized via LLTCG_LOG_I18N.localizePromptText for ja/es/ko/zh/th/pt.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import vm from 'vm';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');

const SAMPLES = [
  'Choose 1 Member card from your Waiting Room to add to your hand.',
  'Choose up to 2 Member card(s) from your Waiting Room to add to your hand.',
  'Choose 1 card card from your Waiting Room to add to your hand.',
  'Choose a card from your Waiting Room to add to your hand.',
  'Choose 1 Member on your Stage.',
  'Choose up to 2 Members on your Stage.',
  'Discard 1 card from your hand.',
  'Choose 2 cards from your hand to send to the Waiting Room.',
  'Look at the top 3 cards of your deck.',
  'Choose one effect',
  'Choose a heart color.',
  'Choose yourself or your opponent.',
  'Choose 1 Aqours Member for +Blade until Live ends.',
  'Choose 1 Saint Snow Member to position-change.',
  'Choose 1 other Liella! Member for +Blade.',
  'Choose an area to Position Change into.',
  'Choose 1 active Member on your Stage to put into Wait.',
  'Choose 1 Live card from your hand to reveal.',
  'Choose one effect:',
  'Position-change this Member?',
];

const ENGLISH_VERBS = /\b(?:Choose|Discard|Look at the top|Select|Pick|Add to hand|What do you like)\b/i;

const MIXED_LEAKS = {
  es: /\b(?:Choose|Miembro|Sala de espera)\b.*\b(?:Choose|Miembro)\b/i,
  ko: /\bChoose\b.*\b(?:멤버|대기실)\b/i,
  zh: /\bChoose\b.*\b(?:成员|等候室)\b/i,
  th: /\bChoose\b.*\b(?:สมาชิก|ห้องรอ)\b/i,
  pt: /\bChoose\b.*\b(?:Membro|Sala de Espera|mão)\b/i,
};

function loadScript(filename, sandbox) {
  const code = fs.readFileSync(path.join(root, filename), 'utf8');
  vm.runInNewContext(code, sandbox, { filename });
}

function assertLocalized(loc, input, output) {
  if (!output || output === input) {
    throw new Error(`[${loc}] unchanged: ${JSON.stringify(input)}`);
  }
  if (ENGLISH_VERBS.test(output)) {
    throw new Error(`[${loc}] English verb remained\n  in:  ${input}\n  out: ${output}`);
  }
  const mixed = MIXED_LEAKS[loc];
  if (mixed && mixed.test(output)) {
    throw new Error(`[${loc}] mixed EN + localized terms\n  in:  ${input}\n  out: ${output}`);
  }
}

const store = Object.create(null);
const sandbox = {
  console,
  localStorage: {
    getItem(k) { return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null; },
    setItem(k, v) { store[k] = String(v); },
    removeItem(k) { delete store[k]; },
  },
  document: {
    documentElement: { lang: 'en' },
    body: {
      classList: {
        _c: new Set(),
        remove(...xs) { xs.forEach((x) => this._c.delete(x)); },
        add(...xs) { xs.forEach((x) => this._c.add(x)); },
      },
    },
    getElementById() { return null; },
  },
};
sandbox.window = sandbox;
sandbox.globalThis = sandbox;
sandbox.global = sandbox;

loadScript('i18n.js', sandbox);
loadScript('log_i18n.js', sandbox);

const i18n = sandbox.LLTCG_I18N;
const logI18n = sandbox.LLTCG_LOG_I18N;
if (!i18n?.setLocale || !logI18n?.localizePromptText) {
  console.error('Missing LLTCG_I18N / LLTCG_LOG_I18N after load');
  process.exit(1);
}

const locales = ['ja', 'es', 'ko', 'zh', 'th', 'pt', 'fr'];
let failures = 0;
for (const loc of locales) {
  i18n.setLocale(loc);
  for (const sample of SAMPLES) {
    try {
      const out = logI18n.localizePromptText(sample, []);
      assertLocalized(loc, sample, out);
    } catch (e) {
      console.error(String(e.message || e));
      failures += 1;
    }
  }
}

if (failures) {
  console.error(`prompt localization test FAILED (${failures})`);
  process.exit(1);
}
console.log(`prompt localization OK (${SAMPLES.length} samples × ${locales.length} locales)`);
