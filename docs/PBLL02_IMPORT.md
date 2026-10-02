# PBLL02 — Premium Booster Love Live! DUO

Official expansion code: **PBLL02**  
JP product name: `プレミアムブースター ラブライブ！DUO`  
EN product name: **Premium Booster Love Live! DUO**  
Booster box id: **`pb_muse_duo`** · kind: **`pb_duo`**

Card list: https://llofficial-cardgame.com/cardlist/searchresults/?expansion=PBLL02  
Product: https://llofficial-cardgame.com/products/pbll_duo/  
JSON API: `https://llofficial-cardgame.com/manage/card-list-user/list?expansion=PBLL02` (**113** cards)

## Card prefixes

| Prefix | Role |
|--------|------|
| `PL!-pb2-*` | New DUO cards (members, lives, energy, parallels) |
| `PL!-bp3/4/5-*`, `PL!-pb1-*`, `PL!-sd1-*` | SRL / SECL reprints |

Image folder: **`PBLL02/`** on official CDN. Box art: `LLC_-PB07_box_image.png`.

## Tooling

```bash
python tools/scrape_expansion_v2.py PBLL02
python tools/import_pbll02_from_scrape.py
# locales (unique EN strings → MT + bracket maps)
python tools/fill_pbll02_locale_texts.py
```

Ability module: `pl_muse_pb2_abilities.py` → `abilities_for_pl_muse_pb2()`  
Handlers: `pl_muse_pb2_effects.php` (required from `effects.php`)

Progress tracker: [`PBLL02_PROGRESS.md`](../PBLL02_PROGRESS.md)

## Booster

Same DUO structure as `pb_superstar_duo`: 3 cards × 20 packs, kind `pb_duo`.  
Excluded from gacha (`booster_pack` contains `プレミアムブースター`).

## Deploy

Hostinger `tcg/...` paths + VPS pull when engine/`cards.json` abilities change.
