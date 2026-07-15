# Chinese (zh-Hans) name maps for Loveca

Prep for Chinese language support in Love Live TCG (Loveca). **Name data only** — no full UI locale yet.

## Script choice

- **Primary:** Simplified Chinese (`zh-Hans`)
- **Authority:** [LLWiki](https://llwiki.org/zh/LLWiki:%E9%A6%96%E9%A1%B5), especially [Template:Membermapping/core](https://llwiki.org/zh/Template:Membermapping/core)
- **Cross-check:** [Moegirl LoveLive!](https://zh.moegirl.org.cn/LoveLive!) / local mirrors
- Traditional Chinese (`zh-Hant`) is deferred as a second map if needed

## Files

| File | Role |
|------|------|
| [`locales/zh_names.json`](../../locales/zh_names.json) | Characters, JP keys, groups, schools, subunits |
| [`locales/zh_songs.json`](../../locales/zh_songs.json) | Live titles → `{ "zh", "method" }` |
| [`tcg_name_keys.json`](tcg_name_keys.json) | Distinct Member/Live keys extracted from `cards.json` |
| [`zh_name_gaps.json`](zh_name_gaps.json) | Coverage stats + optional song follow-ups |

Shape mirrors `locales/ko_names.json` / `locales/ko_songs.json`. Map keys are TCG `name_en` (and JP `name` under `characters_jp`).

## Character conventions

1. Use LLWiki **primary** Chinese names (wiki titles / Membermapping).
2. Prefer community forms over literal dictionary readings:
   - **南小鸟** (not 南琴梨)
   - **矢泽妮可** (not 矢泽日香 / 妮歌)
3. Katakana / foreign given names → established phonetic hanzi: 黛雅、露比、妮可、香音、希奈子、芽衣…
4. Western / mixed names use middle dot `·`: 米雅·泰勒、艾玛·维尔德、薇恩·玛格丽特.
5. Keep Latin brand units: μ's, Aqours, Liella!, QU4RTZ, R3BIRTH, CatChu!, etc.
6. Translated school/club phrases follow LLWiki titles (e.g. 虹咲学园学园偶像同好会、莲之空女学院学园偶像俱乐部).

### Contested aliases (canonical first)

| Canonical (use in maps) | Aliases / avoid preferring |
|-------------------------|----------------------------|
| 南小鸟 | 南琴梨 |
| 矢泽妮可 | 矢泽日香、矢泽妮歌 |
| 黑泽黛雅 / 黑泽露比 | 大雅 / 露比伊 |
| 米雅·泰勒 | 米娅·泰勒 (alias OK; map stores 米雅) |
| 钟岚珠 / 唐可可 | Spelling variants across Moegirl vs LLWiki — LLWiki primary |
| 渡边曜 | 渡边曜子 |
| 涩谷香音 | 澁谷かのん is JP orthography, not a CN alternate |

### μ's (LLWiki canonical)

高坂穗乃果、绚濑绘里、南小鸟、园田海未、星空凛、西木野真姬、东条希、小泉花阳、矢泽妮可

### Aqours (high-signal)

高海千歌、樱内梨子、松浦果南、**黑泽黛雅**、渡边曜、津岛善子、国木田花丸、小原鞠莉、**黑泽露比**

### Niji / Liella / Hasunosora

Use Membermapping primaries (e.g. 上原步梦、宫下爱、涩谷香音、唐可可、叶月恋、米女芽衣、日野下花帆、村野沙耶香、大泽瑠璃乃…).

## Song / Live conventions

| Method | Meaning |
|--------|---------|
| `semantic` | Widely used Chinese **译名** (LLWiki / community) |
| `sino` | Shared-character / Sino reading, often keeping English fragments |
| `keep` | Retain Latin / EN (or JP original) as zh display — default for English-primary titles |
| `phonetic` | Reserved (KO hangul phonetics); zh-Hans maps mostly use `keep` instead of invented pinyin |

Examples:

- **Snow halation** → 雪色光晕 (`semantic`) — recommend showing 译名 in zh mode; keep JP/EN in ja/en
- **Stellar Stream** → Stellar Stream (`keep`)
- **絶対的LOVER** → 绝对的LOVER (`sino`)

Page titles on LLWiki often stay JP/EN; the Chinese **译名** field is secondary community display — that is what `semantic` maps capture.

## Coverage (TCG catalog)

- Members EN: **65 / 65**
- Member JP keys: **75 / 75**
- Lives in map: **197** (catalog lives: 196; includes KO-only alias `Yumewazurai` when present)
- Song methods: semantic 48, sino 18, keep 131, phonetic 0

### Character gaps

_None_

### Song titles for optional later LLWiki 译名 pass

KO `semantic`/`sino` titles that still lack a curated CN entry were emitted as `keep` pending wiki pass:

_None_

English-primary titles intentionally use `keep` (not listed as gaps).

## Wiring later (out of scope for this prep)

1. Add `scripts/inject_i18n_zh_maps.py` (clone the KO injector).
2. Extend `cardLocaleName()` in `i18n.js` for `zh` / `zh-Hans`.
3. Full UI pack `locales/zh.json`, tutorials, stamps, Chiichan `/loveca` copy — separate work.

## References

- [LLWiki Membermapping/core](https://llwiki.org/zh/Template:Membermapping/core)
- Moegirl LoveLive! hub
- Existing KO pipeline: `scripts/inject_i18n_ko_maps.py`, `cardLocaleName` in `i18n.js`
