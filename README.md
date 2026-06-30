# lltcgweb

An English web version of a certain idol tabletop game.

- English translated.
- Interactive UI and animations
- 2000+ cards
- Unique skills fully implemented.
- Deck builder w/ autobuild
- Expand your starter deck with more cards from daily booster packs
- Play in Ranked and Unranked online PvP Lobbies
- Play against a CPU with 3 difficulty settings.
- How-to-play Tutorial

Playable at [https://loveliveradio.ca/tcg](https://loveliveradio.ca/tcg)

## Debugging tools

Launching the website with the `?debug` parameter ([loveliveradio.ca/tcg/?debug](https://loveliveradio.ca/tcg/?debug)) has some additional tools that may help with identifying issues and bug reporting.

- **Card Effect Test** mode is available when logged out. It lets you choose a specific card by ID and jump into a game scenario with you or the CPU having that card in hand in order to test its skill. It tries to start you with the conditions for a skill met (ex: have 1 or more Aqours members on the stage). Though it doesn't always work, so some gameplay may be required to activate the relevant skill.
- **Save replay** — during a match, use **Save replay** in the sidebar to download a `.json` file of the recorded action sequence. While signed out as a guest, open **Debug Replay** from the hub to load that file, step through actions (play/pause, prev/next), then take control as the saver vs CPU at the end.
- In games, there are options in the bottom right to **save a copy of the entire match log** so far as a txt file, or alternatively **copy the last 200 lines** to your clipboard.

---

# Repository guide

This section documents **what is in git** — the PHP/JS game and card data. Runtime state, art, and local dev tooling are excluded (see [Not in git](#not-in-git-by-design)).

---

## Quick map — where to edit what

| Goal | Start here |
|------|------------|
| UI, animations, prompts, CPU | `index.html` |
| Rules engine, card effects, prompts (server) | `effects.php` + set-specific `*_effects.php` |
| Card definitions & abilities | `cards.json` |
| Tutorial | `tutorial.json` |
| Deck legality | `deck_validate.php` |
| Account / collection / boosters / ranked | `account.php`, `db.php`, `booster.php`, `matchmaking.php` |
| Multiplayer rooms & turns | `api.php` |

---

## Runtime directories (on server, mostly not in git)

| Directory | Role |
|-----------|------|
| `data/` | SQLite account DB (`tcg.db`); `.htaccess` blocks HTTP access. Schema in `db.php`. |
| `games/` | Live match JSON per room; written by `api.php`. |
| `experiment_decks/` | Guest deck-experiment saves (`experiment_decks.php`). |
| `assets/`, `bg/`, `icons/`, `cardimg/` | Art and card-face cache — **not in git**; must exist on the host (see below). |

---

## Core application (PHP + client)

| File | Role |
|------|------|
| **`index.html`** | Entire game client: hub, lobby, board, hand, prompts, CPU AI, tutorial mode, collection/deck UI, performance spectacle, radio embed. |
| **`api.php`** | Game server: create/join room, long-poll state, submit actions, card catalog, experiment decks, debug card test, presence, image cache API. Includes `effects.php`. |
| **`effects.php`** | Main rules engine: phases, combat, Live pipeline, ability resolution, prompt queue, energy payment, WR/deck operations. Includes all `*_effects.php` modules. |
| **`cards.json`** | Master card catalog (~2100+ cards): stats, text, `abilities[]`, starter deck lists, image URLs. **Source of truth** for card data. |
| **`subunits.php`** | JP ↔ EN subunit name map for display and matching. |

### Account, collection, ranked

| File | Role |
|------|------|
| `account.php` | REST API: profile, starter pick, collection, daily boosters, deck presets, ranked queue, banner, account reset. |
| `db.php` | SQLite schema + helpers (`tcg_users`, `tcg_collection`, `tcg_deck_presets`, `tcg_rank`, …). |
| `booster.php` | Booster box catalog, pull rates, pity, `open_booster` simulation. |
| `deck_validate.php` | Legal deck rules (48 member / 12 live / 12 energy, copy limits, ownership). |
| `deckgen.php` | Random legal deck builder (CPU / preview / auto-build). |
| `matchmaking.php` | Ranked queue join/leave, Elo pairing, active match tracking. |
| `ranked_room.php` | Creates ranked `api.php` rooms from equipped presets; applies rating on finish. |
| `experiment_decks.php` | Guest-only deck experiment save/load by short password. |
| `llr_auth_load.php` | Loads production `llr_auth.php` or contributor `llr_auth_offline.php`. |
| `llr_auth_offline.php` | Offline fallback when production auth file is absent. |

### Card images

| File | Role |
|------|------|
| `cardimg.php` | Serves cached faces from `cardimg/`. |
| `cardimg_cache.php` | Save/lookup helpers; used by `api.php` `cache_card_image`. |

### Debug / test harness

| File | Role |
|------|------|
| `debug_card_test.php` | `?debug` mode: start CPU match with one card seeded on stage/hand/live for effect QA. |
| `replay.php` | `?debug` replay API: export, start, and step through saved action sequences. |

---

## Effect modules (`*_effects.php`)

Split by product line / import batch. Each is `require_once` from `effects.php`.

| File | Typical scope |
|------|----------------|
| `nijigasaki_effects.php` | Nijigasaki general |
| `n_bp5_effects.php` | Nijigasaki BP5 |
| `hs_bp6_effects.php` | Hasunosora BP6 |
| `hs_pb1_effects.php` | Hasunosora premium PB1 |
| `hs_cl1_effects.php` | Hasunosora CL1 |
| `s_bp5_effects.php` | Sunshine BP5 |
| `s_bp6_effects.php` | Sunshine BP6 |
| `s_sd1_effects.php` | Sunshine start deck |
| `sp_bp2_effects.php` | Superstar BP2 |
| `sp_bp5_effects.php` | Superstar BP5 |
| `pl_muse_gap_effects.php` | μ's gap / misc PL |
| `pl_sp_sd2_effects.php` | Superstar SD2 |
| `batch99_effects.php` | Late import batch (LL energy, PL!N promos, etc.) |

---

## Data JSON (in git)

| File | Role |
|------|------|
| `pack_listings.json` | Listing URLs for pack wrapper art (`booster.php`). |
| `playmat_zones.json` | Normalized zone hitboxes for board layout (`index.html`). |
| `tutorial.json` | Built tutorial steps with dialogue, highlights, and embedded game states. |
| `tutorial_script.json` | Source script used when rebuilding `tutorial.json` locally. |

---

## Not in git (by design)

| Category | Examples |
|----------|----------|
| **Game art & audio** | `assets/`, `bg/`, `icons/`, `cardimg/`, root `*.png` / `*.jpg` / `*.m4a` — supply on the server locally; not redistributed in this repo. |
| **Runtime state** | `data/tcg.db`, `games/*.json`, `experiment_decks/*.json`, `exports/` |
| **Secrets & deploy config** | `llr_auth.php`, `tcg_sync.local.php`, `.env`, `.env.deploy` |
| **Local dev tooling** | `/*.py`, `/scripts/`, `audit_*`, `build_tutorial.php`, `build_tutorial.py`, `audit_tutorial_browser.js` |
| **Scratch / operator notes** | `import_card_progress.txt`, `CARD_AUDIT_PROGRESS.txt`, `_live_titles_export.txt`, calibration `*_scan.png`, etc. |
| **Private docs** | `ACCOUNT_README.md` |

See `.gitignore` for the full list.

---

## Local development

```bash
cd lltcgweb
php -S localhost:8080
# Open http://localhost:8080/index.html
```

Guest lobby, CPU, tutorial (`?tutorial`), and `?debug` work without accounts. Collection, boosters, and ranked need a writable `data/` directory and art under `assets/`, `bg/`, `icons/`, and `cardimg/`.

**Typical effect change:**

1. Edit ability in `cards.json`.
2. Implement or adjust handler in `effects.php` or the set’s `*_effects.php`.
3. Mirror prompt UX in `index.html` if the server adds a new `pending_prompt.type`.
4. Test via guest CPU match or `?debug` + Card Effect Test.

---

## Deploy note (loveliveradio.ca)

Production deploy is handled from the **Chiichan** repo (`scripts/deploy-loveliveradio-ca.sh`). List **remote** paths with the `tcg/` prefix (e.g. `LLR_SITE_FILES="tcg/index.html tcg/api.php tcg/tcg_sync.php"`); files are read from this **lltcgweb** checkout (`LLR_TCG_ROOT`, default `../lltcgweb` next to Chiichan). Set **`LLR_LLTCGWEB_COMMIT_SUMMARY`** to a brief description of the change — the deploy script commits committable files here and **pushes to GitHub** after a successful Hostinger upload (`LLR_SKIP_LLTCGWEB_PUSH=1` to skip). **Docs-only** changes (e.g. `README.md`, `LICENSE`) are not deployed to the site but should still be pushed via `LLR_LLTCGWEB_REPO_FILES='README.md'` and the same push script. Ensure `data/`, `games/`, `cardimg/`, and `experiment_decks/` are writable on the host; art dirs must already be populated on the server.

### Multiplayer sync (SSE via wrapped → VPS)

PvP state sync uses **Server-Sent Events** through `https://loveliveradio.ca/wrapped/api.php?action=tcg_sync_stream` (same Hostinger→VPS path as radio chat). Game rules and room JSON stay on Hostinger `tcg/api.php`.

**On Hostinger (`tcg/`):** copy [`tcg_sync.local.php.example`](tcg_sync.local.php.example) to gitignored `tcg_sync.local.php`:

- `TCG_SYNC_PUBLISH_URL` — same host as `PYTHON_API_URL` in Chiichan `wrapped/api.php`, path `/api/tcg/sync/notify` (e.g. `http://YOUR_VPS:5001/api/tcg/sync/notify`).
- `TCG_SYNC_INTERNAL_TOKEN` — same value as `LLR_SITE_INTERNAL_TOKEN` in `wrapped/api.php`.
- `TCG_SYNC_SHARED_SECRET` — random hex; must match VPS env below.

**On VPS:** deploy Chiichan `wrapped/tcg_sync.py` + `wrapped/wrapped_api.py`; set `TCG_SYNC_SHARED_SECRET` in the `wrapped-api` systemd unit (or environment) to the same secret as Hostinger. Restart **`wrapped-api`** after deploy.

If `tcg_sync.local.php` is missing or disabled, clients fall back to legacy long-poll automatically.

---

## License

This project is licensed under the [MIT License](LICENSE).
