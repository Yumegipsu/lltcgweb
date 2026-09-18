#!/usr/bin/env node
/** Merge social/profile/friends/profileMod/friendPush from runtime i18n into locales/*.json */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import vm from 'vm';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const SECTIONS = ['social', 'profile', 'friends', 'profileMod', 'friendPush'];
const TARGET_LOCALES = ['es', 'ko', 'zh', 'th', 'pt', 'fr'];

function makeSandbox(locale) {
  const store = Object.create(null);
  const sandbox = {
    console,
    localStorage: {
      getItem(k) { return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null; },
      setItem(k, v) { store[k] = String(v); },
      removeItem(k) { delete store[k]; },
    },
    document: {
      documentElement: { lang: locale },
      body: { classList: { _c: new Set(), remove() {}, add() {} } },
      querySelectorAll: () => [],
    },
    window: {},
    navigator: { language: locale },
  };
  sandbox.window = sandbox;
  vm.runInNewContext(fs.readFileSync(path.join(root, 'i18n.js'), 'utf8'), sandbox, { filename: 'i18n.js' });
  sandbox.LLTCG_I18N.setLocale(locale);
  return sandbox;
}

/** @returns {{ path: string }[]} */
function leafPaths(obj, prefix = '') {
  const out = [];
  for (const [k, v] of Object.entries(obj)) {
    const p = prefix ? `${prefix}.${k}` : k;
    if (v && typeof v === 'object' && !Array.isArray(v)) out.push(...leafPaths(v, p));
    else out.push({ path: p });
  }
  return out;
}

function setPath(obj, dotted, value) {
  const parts = dotted.split('.');
  let node = obj;
  for (let i = 0; i < parts.length - 1; i++) {
    node[parts[i]] = node[parts[i]] || {};
    node = node[parts[i]];
  }
  node[parts[parts.length - 1]] = value;
}

function lookup(obj, dotted) {
  return dotted.split('.').reduce((o, k) => (o && o[k] != null ? o[k] : null), obj);
}

const enExtracted = JSON.parse(fs.readFileSync(path.join(root, 'locales', 'en_extracted.json'), 'utf8'));

for (const loc of TARGET_LOCALES) {
  const file = path.join(root, 'locales', `${loc}.json`);
  const json = JSON.parse(fs.readFileSync(file, 'utf8'));
  const sandbox = makeSandbox(loc);
  for (const sec of SECTIONS) {
    if (!enExtracted[sec]) continue;
    json[sec] = json[sec] || {};
    for (const { path: keyPath } of leafPaths(enExtracted[sec], sec)) {
      const rel = keyPath.slice(sec.length + 1);
      const val = sandbox.LLTCG_I18N.t(keyPath);
      const fallback = lookup(enExtracted[sec], rel);
      setPath(json[sec], rel, val === keyPath ? fallback : val);
    }
  }
  fs.writeFileSync(file, JSON.stringify(json, null, 2) + '\n', 'utf8');
  console.log(`Updated locales/${loc}.json`);
}
