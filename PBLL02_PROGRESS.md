# PBLL02 — Premium Booster Love Live! DUO

Official expansion: **PBLL02**  
JP name: `プレミアムブースター ラブライブ！DUO`  
EN name: **Premium Booster Love Live! DUO**  
Booster box id: **`pb_muse_duo`** · kind: **`pb_duo`** (not gacha)

Official list: https://llofficial-cardgame.com/cardlist/searchresults/?expansion=PBLL02  
Product: https://llofficial-cardgame.com/products/pbll_duo/  
Scrape API: `manage/card-list-user/list?expansion=PBLL02` (113 cards)

## Tooling

- `tools/scrape_expansion_v2.py PBLL02` → Chiichan `all_cards_pbll02_*.json`
- `tools/import_pbll02_from_scrape.py` → `cards.json` (bypasses SQLCipher DB)
- Abilities: `pl_muse_pb2_abilities.py` + handlers `pl_muse_pb2_effects.php`
- Locale helpers: `tools/fill_pbll02_locale_texts.py`, `tools/apply_pbll02_exact_overrides.py`, `tools/audit_pbll02_locales.py`
- Skills JP dump: `docs/PBLL02_SKILLS_JP.md`

## Totals

| Scope | Count | Status |
|-------|------:|--------|
| Official expansion | 113 | listed |
| In `cards.json` with DUO booster_pack | 113 | ✅ |
| New `PL!-pb2-*` | 79 | ✅ |
| Reprints (bp3/bp4/bp5/pb1/sd1 SRL/SECL) | 34 | ✅ |
| Ability-bearing bases wired | ~41 | ✅ IR + EN |
| Vanilla / energy (no skill) | rest | ✅ |

## Checklist by card ID

Legend: `[S]` scraped `[E]` EN text `[L]` locales es/ko/zh/th/pt/fr `[A]` ability IR `[T]` engine test `[B]` in booster pool

### Reprints

- [x] PL!-bp3-019-SRL … PL!-bp3-026-SRL (+022-SECL) — S E A(reuse) B
- [x] PL!-bp4-019-SRL … PL!-bp4-026-SRL — S E A(reuse/vanilla) B
- [x] PL!-bp5-019-SRL … PL!-bp5-023-SRL — S E A(reuse/vanilla) B
- [x] PL!-pb1-028-SRL … PL!-pb1-033-SRL (+031-SECL) — S E A(reuse/vanilla) B
- [x] PL!-sd1-019-SRL … PL!-sd1-022-SRL (+020-SECL) — S E A(reuse/vanilla) B

### New DUO — members / lives / energy

- [x] PL!-pb2-000-R / -DUO — S E A B
- [x] PL!-pb2-001 … 018 (R/PP/P+) — S E A B
- [x] PL!-pb2-019 … 036 (N; 034/036 vanilla) — S E A B
- [x] PL!-pb2-037 … 042 (L) — S E A B
- [x] PL!-pb2-E00 … E15 (energy) — S E (no skill) B

### Locales / tests / deploy / prompts

- [x] Locale fields present for ability cards (es/ko/zh/th/pt/fr)
- [~] Full non-EN bodies — **partial**:
  - **ko: clean** (0 EN leftovers via glossary + exact)
  - **es/fr/pt: improved** but still mixed EN on ~20–40 ability cards (glossary partial)
  - **zh/th: brackets localized**; bodies still largely EN (MT APIs rate-limited/echo; short-word glossaries mangled — avoided)
  - Continue via expanding `tools/apply_pbll02_exact_overrides.py` EXACT map; audit with `tools/audit_pbll02_locales.py`
- [x] Multi-step prompt stubs deepened in `plMusePb2ResolvePrompt` (wait+discard→opp Wait, hand↔success swap, activate opp Wait+draw, distinct discard→opp Wait, center Blade, unstack+toggle, per-success choose, BiBi auto choose, Printemps activated cost modes). `optional_wait_self_discard_look_reveal` remapped to shared `optional_wait_self_look_reveal`
- [x] Client: discard counts + branch choice types for new DUO prompts (`prompt-renderer.js`)
- [x] Focused PHPUnit: `MusePb2PromptResolverTest`, `MusePb2000DoubleBatonTest`
- [ ] Hostinger deploy + VPS pull (this follow-up)

## Novel effect types (pl_muse_pb2_effects.php)

~35 novel types + continuation types (`pb2_apply_center_group_blade`, `pb2_begin_wait_opp_printed_hearts`, `pb2_add_subunit_live_from_wr`, `pb2_resume_per_success_choose`). Continuous blade hooks wired.

## Booster

`booster.php` → `pb_muse_duo`, filter `プレミアムブースター ラブライブ！DUO`, kind `pb_duo` (3 cards / 20 packs, excluded from gacha via プレミアムブースター pack name).
Assets: `assets/packs/boxes/pb_muse_duo.webp`, `assets/packs/pb_muse_duo-a.jpg`.
