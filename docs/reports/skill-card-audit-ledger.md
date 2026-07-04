# Skill Card Audit Ledger

This ledger tracks manual and automated skill-card audit batches. Keep entries append-only after merge so future automation runs can select the next unchecked cards from `cards.json` without re-auditing recent fixes.

## Batch 1 - 2026-07-03

Branch: `skill-card-audit-batch-1`
Base: `origin/main` at `24380f2` (`Deploy: Add Tomari Live Start prompt regression`)
Scope: first manual run, up to 3 representative skill cards. Recent manual fixes for Tomari and Rurino were intentionally avoided.

### Cards Checked

| Card | Card No. | Skill Surface | Audit Result | Evidence |
| --- | --- | --- | --- | --- |
| Ai Scream! | `LL-PR-004-PR` | `[Live Start]` opponent text answer; branches into both discard, both draw, or both-stage Blade modifier. | Works; no code fix needed. | `scripts/audit_card_interactions.mjs` reports exact server ability coverage; `SkillCardAuditBatch1Test::testAiScreamLiveStartOpponentTextAnswerAppliesBothStageBladeBonus` verifies prompt creation, opponent resolution, Live Start continuation, and both-stage Blade modifier application. |
| Rin Hoshizora | `PL!-PR-005-PR` | `[On Enter]` `player_choice`; draw/discard branch or wait all opponent cost <=2 Stage Members. | Works; no code fix needed. | Static audit covers `player_choice`, `draw_and_discard`, and `wait_opponent_stage_max_cost`; `SkillCardAuditBatch1Test::testRinPlayerChoiceCanWaitAllOpponentLowCostMembers` verifies the menu and low-cost opponent Stage wait resolution while preserving higher-cost Members. |
| Nico Yazawa | `PL!-PR-009-PR` | `[On Enter] / [Live Start]` optional self-wait to wait one opponent cost <=4 Stage Member. | Works; no code fix needed. | Static audit covers `optional_wait_self_wait_opp`; `SkillCardAuditBatch1Test::testNicoLiveStartOptionalWaitSelfWaitsOneOpponentMember` verifies Live Start prompt creation, optional yes path, self-wait, opponent wait, and Live Start phase recovery. |

### Blocked Items

None for this batch.

### Existing Warnings

`node scripts/audit_card_interactions.mjs` reports full exact server ability coverage (`581/581`) and no missing server routes. It also reports existing prompt UI coverage gaps not introduced by this batch: 68 server prompt types missing a client renderer branch, 64 server prompt types missing an exact CPU branch, 0 no-generic CPU risks, 11 client prompt orphans, and 41 CPU prompt orphans.

### Validation

- `node scripts/audit_card_interactions.mjs` - passed static server ability coverage; existing prompt renderer/CPU branch warnings recorded above.
- `php scripts/validate_json.php` - passed.
- `php scripts/validate_cards.php` - passed with 815 existing unknown-ability warnings.
- `php -l tests/Engine/SkillCardAuditBatch1Test.php` - passed.
- `php -l effects.php` - passed.
- Focused PHP stdin smoke for the three audited cards - passed. This exercised the same resolver paths as the new PHPUnit coverage because local Composer/PHPUnit dependencies were unavailable.
- `vendor/bin/phpunit --filter SkillCardAuditBatch1Test` - blocked: `vendor/bin/phpunit` not present in the isolated worktree.
- `phpunit --version` and `composer --version` - blocked: neither executable is on PATH in this environment.
- `bash scripts/lint_php.sh` - blocked under the default bash due CRLF handling (`set: pipefail\r: invalid option name`); rerun with Git Bash below.
- `C:/Program Files/Git/bin/bash.exe -lc "cd ... && bash scripts/lint_php.sh"` - passed (`PHP syntax OK`).

## Batch 2 - 2026-07-04

Branch: `cursor/tcg-skill-card-audit-3bd7`
Base: continued from Batch 1 ledger commit `1d012bd` while `origin/main` remained `24380f2` (`Deploy: Add Tomari Live Start prompt regression`)
Scope: next 3 unchecked skill cards in `cards.json` order after Batch 1.

### Cards Checked

| Card | Card No. | Skill Surface | Audit Result | Evidence |
| --- | --- | --- | --- | --- |
| Ayumu Uehara & Kanon Shibuya & Kaho Hinoshita | `LL-bp1-001-R＋` | `[On Enter]` add 1 WR Member to hand; `[Live Start]` optionally discard exactly 3 named cards for +3 total Live Score. | Fixed. On Enter now creates a `pick_wr_to_hand` prompt instead of auto-taking the first matching WR Member. Optional Live Start prompts now carry/enforce `exact_total`, and CPU payload generation uses exact named discard counts. | `SkillCardAuditBatch2Test::testBp1OnEnterPromptsForWaitingRoomMemberChoice` verifies WR candidate choice and resolution. `SkillCardAuditBatch2Test::testBp1LiveStartRequiresExactlyThreeNamedDiscardsForScoreBonus` verifies prompt metadata, exact 3-card discard, WR movement, and +3 Live Score modifier. |
| You Watanabe & Natsumi Onitsuka & Rurino Osawa | `LL-bp2-001-R＋` | `[Always]` hand cost reduction; `[Always]` no Baton Touch; `[Live Start]` optionally discard any number of named cards for +1 Blade each. | Fixed client/CPU gaps; engine behavior matched. Human prompt selection now treats prompt-level `max_discard` as optional (`min: 0`) so players can discard fewer than all matching cards. CPU named-discard scoring now counts matching named cards instead of raw hand size. | `SkillCardAuditBatch2Test::testBp2ContinuousEffectsAndPartialLiveStartDiscardBladeBonus` verifies hand-cost reduction, no-Baton flag, prompt `max_discard`, partial discard resolution, and +1 Blade per discarded card. |
| Umi Sonoda & Yoshiko Tsushima & Rina Tennoji | `LL-bp3-001-R＋` | `[Activated]` once per turn shuffle up to 6 named WR Members to deck bottom and activate up to 6 Energy; `[Live Start]` optionally pay 6 Energy for +3 Blade. | Fixed activated WR selection; Live Start already worked. Activated ability now creates a `shuffle_named_from_waiting_pick` prompt when no WR IDs are supplied, validates selected matching Members on resolve, supports CPU/human payloads, marks once-per-turn after resolution, and preserves existing automatic Energy activation up to the ability cap. | `SkillCardAuditBatch2Test::testBp3ActivatedAbilityPromptsForNamedWaitingRoomMembers` verifies prompt candidates, selected WR movement to deck bottom, non-selected WR preservation, and Energy activation. `SkillCardAuditBatch2Test::testBp3LiveStartPayEnergyAppliesBladeBonus` verifies pay prompt metadata, Energy payment, and +3 Blade modifier. |

### Blocked Items

None for this batch.

### Existing Warnings

`node scripts/audit_card_interactions.mjs` reports full exact server ability coverage (`581/581`) and no missing server routes. Existing prompt UI/CPU audit warnings remain: 68 server prompt types missing a client renderer branch, 64 server prompt types missing an exact CPU branch, 0 no-generic CPU risks, 11 client prompt orphans, and 41 CPU prompt orphans.

`php scripts/validate_cards.php` passes with 815 existing unknown-ability warnings, including the audited cards' inline/activated handlers (`hand_cost_reduction`, `no_baton`, and `shuffle_named_from_waiting`) that are implemented outside `EffectRegistry`.

Residual risk: no browser automation was run for the new prompt overlays; coverage is focused on resolver contracts, prompt payloads, CPU branches, static JS syntax, and engine state transitions.

### Validation

- `php scripts/validate_json.php` - passed.
- `php scripts/validate_cards.php` - passed with 815 existing unknown-ability warnings.
- `php -l src/Game/AbilityResolverSwitchWaitingRoomSuccess.php src/Game/LiveStartEffects.php src/Game/AbilityResolverSwitchOptional.php src/Game/PromptResolver.php src/Game/ActivateAbility.php tests/Engine/SkillCardAuditBatch2Test.php` - passed.
- `bash scripts/lint_php.sh` - passed (`PHP syntax OK`).
- `vendor/bin/phpunit --filter SkillCardAuditBatch2Test` - passed (5 tests, 50 assertions).
- `composer test` - passed (31 tests, 175 assertions, 2 skipped).
- `node --check client/js/prompt-renderer.js` - passed.
- Inline `index.html` script extraction plus `node --check /tmp/index-inline-scripts.js` - passed.
- `node scripts/audit_card_interactions.mjs` - passed static server ability coverage; existing prompt renderer/CPU branch warnings recorded above.
