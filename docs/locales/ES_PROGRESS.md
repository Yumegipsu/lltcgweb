# Spanish (es-419) localization progress

Variant: Latin American Spanish (`es-419`); `document.documentElement.lang = 'es'`.  
Character names stay English (`name_en`). Card rules use **Spanish** bracket triggers (`[Al entrar]`, `[Inicio de Live]`, `[Dos veces por turno]`, `[Lado izquierdo]`, etc.) for icon rendering via `i18n.js` `skillKw`.

## Milestones

| ID | Description | Status |
|----|-------------|--------|
| M0 | Infrastructure (locale plumbing, validate_i18n, selectors) | ☑ |
| M1 | Core menus + auth/hub hardcoded strings | ☑ |
| M2 | In-game UI, prompts, toasts | ☑ |
| M3 | Interactive tutorial (`tutorial_es.json`) + legacy keys | ☑ |
| M4 | Game log localization (`log_i18n.js`) | ☑ |
| M5 | Card `text_es` pipeline + batches 5a–5e | ☑ |
| M6 | News, booster rates, Star Gems strings | ☑ |
| M7 | Card `text_es` **quality pass** (full Spanish body, no EN leaks) | ☑ |

## Skill translation — complete (no batches left)

**All in-game card skills that have rules text are Spanish-translated.** There is no batch 5f or pending batch for the current `cards.json` pool.

| Category | Count | `text_es` | Notes |
|----------|------:|----------:|-------|
| English rules (batches 5a–5e) | 1,360 | 1,360 (100%) | Starters, BP01–BP04, PR/pb/CL, bp5+ |
| Japanese-only rules (no EN `text`) | 40 | 40 (100%) | Translated via exact map / glossary (e.g. `エネルギーを2枚…` → `Activa 2 Energía.`) |
| No rules text | 911 | — | Nothing to translate: 661 Energy, 182 Member, 68 Live (vanilla / no printed ability) |
| **Total printings in DB** | **2,271** | | |

Quality audit (`python scripts/audit_text_es_quality.py`): **0 leaks**, **0 glue issues** across 5a–5e.

**Future batches (when new cards ship):** add printings to `cards.json`, assign to batch `5e` (or add `5f` in `translate_text_es_batch.py` if a new product line needs it), run `--batch` / `--repair-leaks`, audit, deploy `tcg/cards.json`. Until then: **0 cards remaining**.

## UI namespaces (`i18n.js` → `STRINGS.es`)

756 `STRINGS.en` leaf keys matched in `STRINGS.es` (see `scripts/validate_i18n.php`).

Source of truth for ES copy: `locales/es.json` → inject via `python scripts/inject_i18n_es.py`.

Supplemental keys (spectate, booster paid/rates, prompt pick fallbacks): `python scripts/merge_supplemental_i18n.py`.

## Card `text_es` by set

Run coverage: `php scripts/card_text_es_coverage.php`  
Run quality audit: `python scripts/audit_text_es_quality.py`

### Coverage (field present)

| Batch | Set focus | Cards w/ English rules | `text_es` filled | Coverage |
|-------|-----------|----------------------|------------------|----------|
| 5a | Starters + core SD (`sd1`, `sd2`, STARTER DECK) | 112 | 112 | **100%** |
| 5b | BP01 (`PL!N-bp1`, `PL!HS-bp1`, `PL!SP-bp1`, `LL-bp1`) | 117 | 117 | **100%** |
| 5c | BP02–BP04 | 382 | 382 | **100%** |
| 5d | PR / pb / CL premium | 390 | 390 | **100%** |
| 5e | Remaining printings (bp5+) | 359 | 359 | **100%** |
| **All** | | **1,360** | **1,360** | **100%** |

### Quality (fully translated Spanish body)

Last audit: `python scripts/audit_text_es_quality.py` — **all batches OK** (0 leak detector hits, 0 glue-word issues after quoted-name scrub).

| Batch | English rules | Leaks | Glue issues | Status |
|-------|---------------|-------|-------------|--------|
| 5a | 110 | 0 | 0 | ☑ Quality OK |
| 5b | 117 | 0 | 0 | ☑ Quality OK |
| 5c | 344 | 0 | 0 | ☑ Quality OK |
| 5d | 390 | 0 | 0 | ☑ Quality OK |
| 5e | 359 | 0 | 0 | ☑ Quality OK |

**Remaining work for card skills: none** for the current card pool. See [Skill translation — complete](#skill-translation--complete-no-batches-left) above.

## Pipeline

```bash
# Apply / refresh a batch (glossary + locales/batch_{id}_exact.json + EXACT_TRANSLATIONS in script)
python scripts/translate_text_es_batch.py --batch 5b

# Re-translate cards whose text_es still has English fragments or EN skill brackets
python scripts/translate_text_es_batch.py --repair-leaks

# Coverage + quality checks
php scripts/card_text_es_coverage.php
python scripts/audit_text_es_quality.py
```

Hand-tuned exact maps: `locales/batch_5c_exact.json`, `batch_5d_exact.json`, `batch_5e_exact.json`. Shared exact strings + glossary: `scripts/translate_text_es_batch.py`.

### Formatting rules (skills)

- Bracket triggers → Spanish (`[Al entrar]`, `[Activada]`, `[Dos veces por turno]`, `[Lado izquierdo]`, `[Lado derecho]`, `[Centro]`, …).
- Preserve `\n`, bullets `•`, parentheses, heart symbols `♡`, quoted **character/song names in English**.
- Game terms per glossary: Escenario, Sala de espera, Energía, Corazones, Yell, Blade, Baton Touch, Wait, Zona de cartas Live, etc.

### Recent quality fixes (BP01 + premium)

- 20 BP01 promo strings: mixed EN/ES (`if you have`, `and colocar`, `mira the`, `for each`, …) → full exact translations.
- `[Left Side]` / `[Right Side]` → `[Lado izquierdo]` / `[Lado derecho]`.
- `[Twice per Turn]` → `[Dos veces por turno]`; `this Member` → `este Miembro`; `then put` → `y luego coloca`; `1 card de tu mano` ordering fix.

## Glossary (es-419)

| EN | ES |
|----|-----|
| Main Phase | Fase principal |
| Live Phase | Fase Live |
| Waiting Room | Sala de espera |
| Stage | Escenario |
| Performance | Presentación |
| Success pile / Success Live | Live exitoso / pila de éxito |
| Hearts | Corazones |
| Energy | Energía |
| Once per Turn | Una vez por turno |
| Twice per Turn | Dos veces por turno |
| Left Side | Lado izquierdo |
| Right Side | Lado derecho |
| mulligan | mulligan |

## Deploy cache-bust

When shipping locale changes, bump:

- `i18n.js?v=…`
- `log_i18n.js?v=…`
- `tutorial_es.json?v=…`
- `index.html` (inline changes)
- `cards.json` (set batches only)
- `news.json`
