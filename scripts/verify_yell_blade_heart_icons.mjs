/**
 * Client yell ALL-blade heart icon + entry-order regression.
 * Run: node scripts/verify_yell_blade_heart_icons.mjs
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');

function assert(cond, msg) {
  if (!cond) throw new Error(msg);
}

assert(html.includes('function cardYellBladeHeartEntries(card, opts = {})'),
  'cardYellBladeHeartEntries helper missing from index.html');
assert(html.includes('entries.forEach(({ raw, display }) =>'),
  'perfMarkYellBladeHearts should render per-entry icons');
assert(!html.includes('groupHeartsByColor(displayColors.map(c => ({ color: c, count: 1 })))'),
  'perfMarkYellBladeHearts must not group ALL blades through normalizeHeartColor');
assert(html.includes('const bladeEntries = card ? cardYellBladeHeartEntries(card'),
  'yell spectacle should animate blade entries in card order');
assert(html.includes('await perfFlyBladeHeartToPanel(icon, heartsEl, resolved, chip, rawColor'),
  'yell fly should run without skipping when icon node is absent');
assert(html.includes('String(color || \'any\').toLowerCase()'),
  'resolveYellBladeHeartColor should lowercase ALL blade tokens');

console.log('verify_yell_blade_heart_icons: all checks passed');
