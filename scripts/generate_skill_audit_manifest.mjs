/**
 * Build skill runtime audit manifest + batch slices from cards.json.
 * Run:
 *   node scripts/generate_skill_audit_manifest.mjs
 *   node scripts/generate_skill_audit_manifest.mjs --booster=bp_vol1
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const cardsPath = path.join(root, 'cards.json');
const reportsDir = path.join(root, 'reports');
const batchesDir = path.join(reportsDir, 'skill_audit_batches');

const BATCH_SIZE = 55;

/** Booster box id → cards.json booster_pack filter string. */
const BOOSTER_FILTERS = {
  bp_vol1: 'ブースターパック vol.1',
  bp_next: 'ブースターパック NEXT STEP',
  bp_summer: 'ブースターパック　夏、はじまる。',
  bp_sapphire: 'ブースターパック SAPPHIRE MOON',
  bp_royal: 'ブースターパック Royal Holiday',
};

/** Product-line order for batching (plan execution order). */
const PREFIX_ORDER = [
  { id: 'PL!S', match: (no) => no.startsWith('PL!S-') },
  { id: 'PL!N', match: (no) => no.startsWith('PL!N-') },
  { id: 'PL!HS', match: (no) => no.startsWith('PL!HS-') },
  { id: 'PL!SP', match: (no) => no.startsWith('PL!SP-') },
  { id: 'PL!', match: (no) => no.startsWith('PL!-') || (no.startsWith('PL!') && !no.startsWith('PL!S-') && !no.startsWith('PL!N-') && !no.startsWith('PL!HS-') && !no.startsWith('PL!SP-')) },
  { id: 'LL', match: (no) => no.startsWith('LL-') || no.startsWith('LL!') },
  { id: 'OTHER', match: () => true },
];

function parseArgs(argv) {
  let booster = null;
  for (const arg of argv) {
    if (arg.startsWith('--booster=')) booster = arg.slice('--booster='.length).trim();
  }
  return { booster };
}

function isPlainObject(v) {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function shortParams(node) {
  const skip = new Set(['type', 'trigger', 'then', 'else_then', 'on_success', 'on_fail', 'effect', 'effects', 'abilities', 'choices', 'branches', 'ability']);
  const params = {};
  for (const [key, value] of Object.entries(node)) {
    if (skip.has(key)) continue;
    if (value === null || ['string', 'number', 'boolean'].includes(typeof value)) {
      params[key] = value;
    }
  }
  return params;
}

function walkAbilitySubtree(node, ctx, out, seen) {
  if (!isPlainObject(node) && !Array.isArray(node)) return;
  if (isPlainObject(node)) {
    if (seen.has(node)) return;
    seen.add(node);
    const type = typeof node.type === 'string' ? node.type.trim() : '';
    const trigger = typeof node.trigger === 'string' && node.trigger.trim() ? node.trigger : ctx.trigger;
    if (type) {
      out.push({
        card_no: ctx.cardNo,
        name_en: ctx.cardName,
        card_type: ctx.cardType,
        group: ctx.group,
        ability_path: ctx.path,
        trigger: trigger || '(none)',
        type,
        params: shortParams(node),
        ability_index: ctx.abilityIndex,
      });
    }
    const currentCtx = { ...ctx, trigger, parentType: type || ctx.parentType };
    for (const [key, value] of Object.entries(node)) {
      if (key === 'text' || key === 'name' || key === 'name_en' || key === 'image') continue;
      if (Array.isArray(value)) {
        value.forEach((item, i) => {
          if (isPlainObject(item) || Array.isArray(item)) {
            walkAbilitySubtree(item, { ...currentCtx, path: `${ctx.path}.${key}[${i}]` }, out, seen);
          }
        });
      } else if (isPlainObject(value)) {
        for (const [childKey, childValue] of Object.entries(value)) {
          if (isPlainObject(childValue) || Array.isArray(childValue)) {
            const childPath = key === 'choices' ? `${ctx.path}.choices.${childKey}` : `${ctx.path}.${key}.${childKey}`;
            walkAbilitySubtree(childValue, { ...currentCtx, path: childPath }, out, seen);
          }
        }
        if (typeof value.type === 'string') {
          walkAbilitySubtree(value, { ...currentCtx, path: `${ctx.path}.${key}` }, out, seen);
        }
      }
    }
  } else {
    node.forEach((item, i) => walkAbilitySubtree(item, { ...ctx, path: `${ctx.path}[${i}]` }, out, seen));
  }
}

function collectCardsWithAbilities(cards) {
  const out = [];
  for (const card of cards) {
    const abilities = Array.isArray(card.abilities) ? card.abilities : [];
    if (!abilities.length) continue;
    const nodes = [];
    abilities.forEach((ab, i) => {
      walkAbilitySubtree(ab, {
        cardNo: String(card.card_no || ''),
        cardName: String(card.name_en || card.name || ''),
        cardType: String(card.card_type_en || card.card_type || ''),
        group: String(card.group || ''),
        path: `abilities[${i}]`,
        trigger: typeof ab?.trigger === 'string' ? ab.trigger : '',
        abilityIndex: i,
      }, nodes, new WeakSet());
    });
    out.push({
      card_no: card.card_no,
      name_en: card.name_en || card.name || '',
      card_type: card.card_type_en || card.card_type || '',
      group: card.group || '',
      booster_pack: card.booster_pack || '',
      ability_count: abilities.length,
      nodes,
    });
  }
  return out;
}

function productLine(cardNo) {
  for (const p of PREFIX_ORDER) {
    if (p.id !== 'OTHER' && p.match(cardNo)) return p.id;
  }
  return 'OTHER';
}

function sortCards(cards) {
  return [...cards].sort((a, b) => String(a.card_no).localeCompare(String(b.card_no)));
}

function chunk(arr, size) {
  const batches = [];
  for (let i = 0; i < arr.length; i += size) {
    batches.push(arr.slice(i, i + size));
  }
  return batches;
}

function orderByProductLine(withAbilities) {
  const byLine = new Map(PREFIX_ORDER.map((p) => [p.id, []]));
  for (const card of sortCards(withAbilities)) {
    const line = productLine(card.card_no);
    byLine.get(line)?.push(card);
    if (!byLine.has(line)) byLine.get('OTHER').push(card);
  }
  const orderedCards = [];
  for (const p of PREFIX_ORDER) {
    orderedCards.push(...(byLine.get(p.id) || []));
  }
  return orderedCards;
}

function writeBoosterBatch(boosterId, withAbilities) {
  const filter = BOOSTER_FILTERS[boosterId];
  if (!filter) {
    console.error(`Unknown --booster=${boosterId}. Known: ${Object.keys(BOOSTER_FILTERS).join(', ')}`);
    process.exit(1);
  }
  const filtered = withAbilities.filter((c) => c.booster_pack === filter);
  const orderedCards = orderByProductLine(filtered);
  const batchId = boosterId.replace(/^bp_/, ''); // bp_vol1 → vol1
  const batch = {
    batch: batchId,
    booster_id: boosterId,
    booster_filter: filter,
    generated_at: new Date().toISOString(),
    card_count: orderedCards.length,
    node_count: orderedCards.reduce((n, c) => n + c.nodes.length, 0),
    cards: orderedCards,
  };
  const manifest = {
    generated_at: batch.generated_at,
    booster_id: boosterId,
    booster_filter: filter,
    total_cards_with_abilities: orderedCards.length,
    total_ability_nodes: batch.node_count,
    batch_count: 1,
    batches: [{
      batch: batchId,
      file: `batch-${batchId}.json`,
      card_count: batch.card_count,
      node_count: batch.node_count,
      prefixes: [...new Set(orderedCards.map((c) => productLine(c.card_no)))],
      first_card: orderedCards[0]?.card_no || null,
      last_card: orderedCards[orderedCards.length - 1]?.card_no || null,
    }],
    cards: orderedCards,
  };

  fs.mkdirSync(batchesDir, { recursive: true });
  fs.writeFileSync(path.join(reportsDir, `skill_audit_manifest_${batchId}.json`), `${JSON.stringify(manifest, null, 2)}\n`);
  fs.writeFileSync(path.join(batchesDir, `batch-${batchId}.json`), `${JSON.stringify(batch, null, 2)}\n`);

  console.log(`Booster ${boosterId} (${filter})`);
  console.log(`Cards with abilities: ${manifest.total_cards_with_abilities}`);
  console.log(`Ability nodes: ${manifest.total_ability_nodes}`);
  console.log(`Wrote reports/skill_audit_manifest_${batchId}.json and reports/skill_audit_batches/batch-${batchId}.json`);
}

function writeGlobalBatches(withAbilities) {
  const orderedCards = orderByProductLine(withAbilities);
  const allBatches = chunk(orderedCards, BATCH_SIZE);
  const batchMeta = allBatches.map((cards, i) => {
    const idx = String(i + 1).padStart(2, '0');
    const prefixes = [...new Set(cards.map((c) => productLine(c.card_no)))];
    return {
      batch: idx,
      file: `batch-${idx}.json`,
      card_count: cards.length,
      node_count: cards.reduce((n, c) => n + c.nodes.length, 0),
      prefixes,
      first_card: cards[0]?.card_no || null,
      last_card: cards[cards.length - 1]?.card_no || null,
    };
  });

  const manifest = {
    generated_at: new Date().toISOString(),
    total_cards_with_abilities: withAbilities.length,
    total_ability_nodes: withAbilities.reduce((n, c) => n + c.nodes.length, 0),
    batch_size: BATCH_SIZE,
    batch_count: allBatches.length,
    batches: batchMeta,
    cards: orderedCards,
  };

  fs.mkdirSync(batchesDir, { recursive: true });
  fs.writeFileSync(path.join(reportsDir, 'skill_audit_manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);

  allBatches.forEach((cards, i) => {
    const idx = String(i + 1).padStart(2, '0');
    const batch = {
      batch: idx,
      generated_at: new Date().toISOString(),
      card_count: cards.length,
      node_count: cards.reduce((n, c) => n + c.nodes.length, 0),
      cards,
    };
    fs.writeFileSync(path.join(batchesDir, `batch-${idx}.json`), `${JSON.stringify(batch, null, 2)}\n`);
  });

  console.log(`Cards with abilities: ${manifest.total_cards_with_abilities}`);
  console.log(`Ability nodes: ${manifest.total_ability_nodes}`);
  console.log(`Batches: ${manifest.batch_count} (@ ${BATCH_SIZE} cards)`);
  for (const b of batchMeta) {
    console.log(`  batch-${b.batch}: ${b.card_count} cards, ${b.node_count} nodes [${b.prefixes.join(', ')}] ${b.first_card} … ${b.last_card}`);
  }
  console.log(`Wrote reports/skill_audit_manifest.json and reports/skill_audit_batches/batch-*.json`);
}

function main() {
  const { booster } = parseArgs(process.argv.slice(2));
  const raw = JSON.parse(fs.readFileSync(cardsPath, 'utf8'));
  const cards = Array.isArray(raw) ? raw : (raw.cards || []);
  const withAbilities = collectCardsWithAbilities(cards);

  if (booster) {
    writeBoosterBatch(booster, withAbilities);
    return;
  }
  writeGlobalBatches(withAbilities);
}

main();
