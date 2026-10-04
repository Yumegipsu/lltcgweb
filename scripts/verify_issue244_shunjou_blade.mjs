/**
 * Mirrors tcgClientSuccessCardCountWeight / blade_per_success_score_icon_group (#244).
 * Run: node scripts/verify_issue244_shunjou_blade.mjs
 */
function cardMatchesSubunit(card, subunit) {
  if (!card || !subunit) return false;
  const want = String(subunit).toLowerCase();
  if (String(card.subunit || '').toLowerCase() === want) return true;
  return (card.subunits || []).some((s) => String(s).toLowerCase() === want);
}

function tcgClientIsLilyWhiteCard(card) {
  return cardMatchesSubunit(card, 'lily white');
}

function tcgClientSuccessCardCountWeight(card, effectSource) {
  if (effectSource && !tcgClientIsLilyWhiteCard(effectSource)) return 1;
  for (const ab of (card?.abilities || [])) {
    if ((ab.type || '') !== 'success_count_as_two_for_subunit_effects') continue;
    const need = String(ab.subunit || 'lily white');
    if (!need || need.toLowerCase() === 'lily white') {
      if (!effectSource || tcgClientIsLilyWhiteCard(effectSource)) return 2;
    }
  }
  return 1;
}

function bladePerSuccessScoreIcon(member, successLives) {
  let n = 0;
  for (const c of successLives) {
    if (!c) continue;
    const g = String(c.group || '');
    if (!(g.includes('μ'))) continue;
    if (c.yell_score_icon || c.special_heart === 'icon_score.png') {
      n += tcgClientSuccessCardCountWeight(c, member);
    }
  }
  return n;
}

const umi = { subunit: 'lily white', abilities: [{ trigger: 'continuous', type: 'blade_per_success_score_icon_group', group: "μ's", amount: 1 }] };
const shunjou = {
  group: "μ's",
  subunit: 'lily white',
  yell_score_icon: true,
  special_heart: 'icon_score.png',
  abilities: [{ trigger: 'continuous', type: 'success_count_as_two_for_subunit_effects', subunit: 'lily white' }],
};
const other = { group: "μ's", yell_score_icon: true, special_heart: 'icon_score.png', abilities: [] };

const alone = bladePerSuccessScoreIcon(umi, [shunjou]);
const two = bladePerSuccessScoreIcon(umi, [shunjou, other]);
if (alone !== 2) throw new Error(`Shunjou alone expected 2, got ${alone}`);
if (two !== 3) throw new Error(`Shunjou+other expected 3, got ${two}`);
console.log('verify_issue244_shunjou_blade: PASS');
