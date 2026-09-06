# Tournament Mode — local smoke checklist

Tournaments are **on by default** (client + server). Kill switch: `TCG_TOURNAMENTS_ENABLED=0`.
Optional restrict: `TCG_TOURNAMENT_ALLOWLIST=id1,id2`. Client override: `?tournaments=0|1` or `localStorage.tcg_tournaments_enabled`.

**Local Docker fake login:** `compose.yaml` sets `TCG_LOCAL_FAKE_AUTH=1`. Opening localhost auto-signs in as **LocalDev1** (Nijigasaki starter + 10k Coins). Use `?local_user=2` for a second account.

Optional: `TCG_TOURNAMENT_WEBHOOK_URL` (Discord incoming webhook) fires on check-in open / bracket start / finish.

## Automated

```bash
composer test -- --filter Tournament
# or
./vendor/bin/phpunit tests/Tournament
```

Covers bracket pairing, title filter, lifecycle, Phase 2 settings/rules/delay, Phase 3 formats (Swiss / double-elim / Bo3), and `spectate_list` category `tournament`.

## Manual Hub flow

1. Start local PHP (`api.php` / `account.php`) with file GameStore + SQLite `data/tcg.db`.
2. Enable flags (above). Hard-refresh Hub — Tournament Mode should unlock (not “Coming Soon”).
3. Two signed-in accounts (or offline-dev users):
   - Host: Create event (start ~2–5 min out, check-in 5, min 2, fee optional).
     Choose format (single elim / double elim Winners-Losers / double elim 2-lives / Swiss), match length (Bo1 / Bo3), fog, rules template, stream delay.
     Before start, the bracket shows a **preview skeleton** sized to max/entrants (empty TBD seats).
     Titles use the same slur/link filters as web radio chat (`config/chat_slurs.txt`).
   - Host + entrants show Discord avatars next to names on the event detail view.
   - Host: Deposit prize **Coins** into the pool (cosmetic prizes not used — pool Coins only).
   - Both: Register (locks equipped deck; rules template rejects illegal decks).
4. When check-in opens: both Check in. Optional: close host tab; keep one client open (tick poll) or run:
   `TCG_TOURNAMENTS_ENABLED=1 php scripts/tournament_tick.php <ID>`
   Bulletin may toast a check-in reminder (~15 min window / just opened).
5. After `start_at`, tick builds the bracket/pairings and seeds rooms. Names fill into match cards; **Spectate** is a clear button for non-players.
6. Players: **Join my match** → board (Hostinger GameStore lock; match status becomes `live`).
   Spectators: bracket **Spectate**, detail **Spectate matches**, or hub Spectate → tournament list
   (always Hostinger origin under match-primary). Hidden hands follow fog setting.
7. Bo3: series score shows on cards; rooms reseed until 2 game wins. Prize payout remains Coins from the pool.
8. Finish / force result / connect forfeit (~3 min) → advances → Coins payout on final / standings.
9. Cancel mid-registration → entry + remaining host vault refunded.

## Notes

- Match rooms use `mode: "tournament"` on Hostinger GameStore (not VPS ranked overflow).
- Stream delay (15/30/60s) holds spectators on an empty board until a snapshot is at least that old; they never receive live seq. Tab-out can lag further.
- `scripts/tournament_tick.php` advances events with no browser tab.
- Tournament sky is purple; event times default to **Asia/Tokyo** and follow account `preferred_timezone` (UTC offset shown in the timezone picker).
- Register opens a deck picker (or Deck Builder shortcut) when no eligible deck is equipped.
- List/detail show `spectator_count` for live tournament rooms.
- Double elim **(2 lives)** is Swiss-style re-pair (eliminated at 2 losses).
- Double elim **(Winners/Losers)** is a classic WB/LB tree with grand final + bracket reset if the LB champ wins GF1.
- Swiss uses fixed round count from field size.
- Account SQLite tracks tournament history per user (`tcg_tournament_user_stats` / `tcg_tournament_h2h`): match W–L, H2H, coins earned from payouts, coins contributed (entry + host deposit, minus refunds). No profile UI yet.
- **Free** game mode is allowed: register with Deck Experiment presets, share passwords, or owned account decks (ownership not required for experiment decks).
- Finished games archive to `tcg_tournament_replays` on resolve; bracket **Watch Replay** stays available on the event page for any signed-in viewer after the tournament ends. Personal Recent copies skip FIFO trim while the public archive row exists.
