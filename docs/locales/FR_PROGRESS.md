# French (fr) locale progress

French is a first-class locale (`fr`, `document.documentElement.lang = 'fr'`), modeled on Brazilian Portuguese (`pt`).

## Glossary (locked)

| EN | FR |
|----|-----|
| On Enter | À l'entrée |
| On Leave | À la sortie |
| Live Start | Début de Live |
| Live Success | Live réussi |
| Activated | Activé |
| Always | Permanent |
| Automatic / Auto | Automatique |
| Once per turn | Une fois par tour |
| Twice per turn | Deux fois par tour |
| Center | Centre |
| Yell | Yell |
| Left Side | Côté gauche |
| Right Side | Côté droit |
| Main Phase | Phase principale |
| Live Phase | Phase Live |
| Stage | Scène |
| Waiting Room | Salle d'attente |
| Energy | Énergie |
| Hearts | Cœurs |
| Blade | Blade |
| Mulligan | Mulligan |
| Member | Membre |
| Baton Touch | Baton Touch |
| Performance | Performance |
| Success | Réussite |

Character / song / group brand names stay **English** (same as es/pt).

## Surfaces

| Surface | Path |
|---------|------|
| UI strings | `locales/fr.json` → `python scripts/inject_i18n_fr.py` → `STRINGS.fr` |
| Card rules | `cards.json` → `text_fr` |
| Logs / prompts | `log_i18n.js` FR rules |
| Tutorial | `tutorial_fr.json` |
| News | `news.json` `title.fr` / `body.fr` |
| Stamps | `stamps_i18n.json` → `fr` |

## Policy

Every new or changed player-facing string must include French in the same change — see `.cursor/rules/user-facing-copy.mdc`.

## Audit (2026-09-18 polish)

- UI: `locales/fr.json` vs `en_extracted` — **0 missing**; leftovers identical to EN are intentional keepers (Live, Wait, Scout, set names, placeholders, language names).
- Skills: all rules cards have `text_fr`; rebuilt via ES→FR pivot + exact overrides. Real English-phrase leak count driven to **0**.
- Known awkward hub copy fixed: `menu.unrankedPlay` → Partie libre; `options.matchChat` → Activer le chat texte en partie.
