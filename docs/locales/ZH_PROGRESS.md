# Chinese (zh-Hans) localization progress

Variant: Simplified Chinese; locale code `zh`; `document.documentElement.lang = 'zh-Hans'`.  
Character / song display names use [`locales/zh_names.json`](../../locales/zh_names.json) / [`zh_songs.json`](../../locales/zh_songs.json). Card rules use Chinese brackets (`[入场时]`, `[Live开始]`, `[起动]`, …) for `skillKw` tooltips.

## Milestones

| ID | Description | Status |
|----|-------------|--------|
| M0 | Infrastructure (LOCALES, pickers, font, flag, inject maps, validate_i18n) | ☑ |
| M1–M2 | Full UI `locales/zh.json` incl. `skillKw` | ☑ |
| M3 | Interactive tutorial `tutorial_zh.json` | ☑ |
| M4 | Game log / prompts (`log_i18n.js` ZH) | ☑ |
| M5 | Card `text_zh` for all rules-bearing cards | ☑ |
| M6 | News + stamps `zh` | ☑ |
| M7 | Cursor rules require zh forever | ☑ |

## Skill brackets (locked)

| EN | ZH |
|----|----|
| On Enter / On Leave | 入场时 / 离场时 |
| Live Start / Live Success | Live开始 / Live成功 |
| Activated | 起动 |
| Always / Continuous | 永续 |
| Automatic | 自动 |
| Once / Twice per Turn | 每回合1次 / 每回合2次 |
| Center / Left / Right | 中央 / 左侧 / 右侧 |
| Yell | Yell |

## Pipeline

```bash
python scripts/build_zh_locale.py          # optional rebuild UI from EN
python scripts/inject_i18n_zh.py
python scripts/inject_i18n_zh_maps.py
python scripts/build_tutorial_locale_json.py --locale zh
python scripts/validate_tutorial_i18n.py --locale zh
python scripts/translate_text_zh_batch.py --rebuild-all-zh
python scripts/repair_text_zh_quality.py   # residual EN cleanup
php scripts/card_text_zh_coverage.php
python scripts/audit_text_zh_quality.py
php scripts/validate_i18n.php
```

## Future card additions

When shipping new printed abilities, populate **`text_es` + `text_ko` + `text_zh`** in the same change with matching localized brackets. Enforced by `.cursor/rules/user-facing-copy.mdc`.
