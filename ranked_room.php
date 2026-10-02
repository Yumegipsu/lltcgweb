<?php
/**
 * Ranked room creation and post-game ELO updates.
 *
 * Loads api.php as a library (TCG_API_LIB_ONLY). tcgCreateRankedRoom pairs equipped
 * deck presets; tcgOnGameFinished adjusts tcg_rank when a ranked match ends
 * (locally on Hostinger, or via Hostinger webhook when running on VPS).
 */
if (!defined('TCG_API_LIB_ONLY')) {
    define('TCG_API_LIB_ONLY', true);
}
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/matchmaking.php';
require_once __DIR__ . '/deck_validate.php';
require_once __DIR__ . '/booster.php';
require_once __DIR__ . '/match_bridge.php';

function tcgGetEquippedDeckLists(string $discordId): ?array {
    $row = tcgGetEquippedDeckRow($discordId);
    if (!$row) {
        return null;
    }
    return [
        'name' => tcgNormalizeDeckPresetName($row['name'] ?? 'Ranked Deck'),
        'main_nos' => json_decode($row['main_deck'], true) ?: [],
        'energy_nos' => json_decode($row['energy_deck'], true) ?: [],
        'sleeve_id' => tcgNormalizeSleeveId($row['sleeve_id'] ?? ''),
        'playmat_id' => tcgNormalizePlaymatId($row['playmat_id'] ?? ''),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($row['playmat_brightness'] ?? 1.0),
    ];
}

function tcgGetUserDisplayName(string $discordId): string {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT username FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['username'] ?? 'Player';
}

/**
 * Full-pool random legal deck for Randomized Decks mode (no collection ownership).
 *
 * @param list<array<string,mixed>> $allCards
 * @param array<string,array<string,mixed>> $cardMap
 * @return array{name:string,main_nos:list<string>,energy_nos:list<string>}|null
 */
function tcgGenerateValidatedRandomDeckLists(array $allCards, array $cardMap, int $maxAttempts = 8): ?array {
    require_once __DIR__ . '/deckgen.php';
    for ($i = 0; $i < $maxAttempts; $i++) {
        try {
            $gen = generateRandomDeckLists($allCards, null);
        } catch (Throwable $e) {
            continue;
        }
        $main = array_values(array_map('strval', $gen['main_deck'] ?? []));
        $energy = array_values(array_map('strval', $gen['energy_deck'] ?? []));
        $v = tcgValidateDeckLists($main, $energy, $cardMap, null);
        if (!$v['valid']) {
            continue;
        }
        return [
            'name' => (string)($gen['name_en'] ?? $gen['name'] ?? 'Random Deck'),
            'main_nos' => $main,
            'energy_nos' => $energy,
        ];
    }
    return null;
}

function tcgCreateRankedRoomPair(
    string $p1DiscordId,
    string $p2DiscordId,
    string $gameMode = TCG_GAME_MODE_STANDARD
): ?array {
    require_once __DIR__ . '/game_mode.php';
    $gameMode = tcgNormalizeGameMode($gameMode);
    $randomized = tcgIsRandomizedGameMode($gameMode);

    $cards = tcgLoadCardsData();
    $allCards = $cards['cards'] ?? [];
    $cardMap = tcgBuildCardMap($cards);

    if ($randomized) {
        $deck1 = tcgGenerateValidatedRandomDeckLists($allCards, $cardMap);
        $deck2 = tcgGenerateValidatedRandomDeckLists($allCards, $cardMap);
        if (!$deck1 || !$deck2) {
            tcgQueueLeave($p1DiscordId, $gameMode);
            tcgQueueLeave($p2DiscordId, $gameMode);
            return null;
        }
    } else {
        $deck1 = tcgGetEquippedDeckLists($p1DiscordId);
        $deck2 = tcgGetEquippedDeckLists($p2DiscordId);
        if (!$deck1 || !$deck2) {
            return null;
        }

        foreach ([$p1DiscordId => $deck1, $p2DiscordId => $deck2] as $uid => $deck) {
            $row = tcgGetEquippedDeckRow($uid);
            if ($gameMode === TCG_GAME_MODE_STARTERS) {
                if (!$row || ($row['source'] ?? '') !== 'starter') {
                    tcgQueueLeave($uid, $gameMode);
                    return null;
                }
                $starterKey = trim((string)($row['starter_key'] ?? ''));
                if ($starterKey === '' || !in_array($starterKey, tcgOwnedStarterKeys($uid), true)) {
                    tcgQueueLeave($uid, $gameMode);
                    return null;
                }
            }
            // Official starter lists skip collection ownership (claimed starters stay playable).
            $ownedCheck = (($row['source'] ?? '') === 'starter')
                ? null
                : tcgGetCollectionMap($uid);
            $v = tcgValidateDeckLists($deck['main_nos'], $deck['energy_nos'], $cardMap, $ownedCheck);
            if (!$v['valid']) {
                tcgUnequipIllegalEquippedLoadout($uid);
                tcgQueueLeave($uid, $gameMode);
                return null;
            }
        }
    }

    $roomId = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
    $p1Token = generateToken();
    $p2Token = generateToken();
    $deckChoiceLabel = $randomized ? 'random' : 'ranked';

    $main1 = buildDeck($allCards, $deck1['main_nos']);
    $energy1 = buildDeck($allCards, $deck1['energy_nos']);
    shuffle($main1);
    shuffle($energy1);

    $state = initGameState($roomId, [
        'id' => 'p1',
        'token' => $p1Token,
        'name' => tcgGetUserDisplayName($p1DiscordId),
        'deck_choice' => $deckChoiceLabel,
        'deck_label' => $deck1['name'],
        'main_deck' => $main1,
        'energy_deck' => $energy1,
        'discord_id' => $p1DiscordId,
        'sleeve_id' => tcgNormalizeSleeveId($deck1['sleeve_id'] ?? ''),
        'playmat_id' => tcgNormalizePlaymatId($deck1['playmat_id'] ?? ''),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($deck1['playmat_brightness'] ?? 1.0),
        'deck_snapshot' => ['main_nos' => $deck1['main_nos'], 'energy_nos' => $deck1['energy_nos']],
    ]);
    $state['mode'] = 'ranked';
    $state['game_mode'] = $gameMode;
    $state['ranked'] = [
        'p1_discord_id' => $p1DiscordId,
        'p2_discord_id' => $p2DiscordId,
        'applied' => false,
        'game_mode' => $gameMode,
        'match_api' => 'overflow',
    ];

    $main2 = buildDeck($allCards, $deck2['main_nos']);
    $energy2 = buildDeck($allCards, $deck2['energy_nos']);
    shuffle($main2);
    shuffle($energy2);

    $state = addSecondPlayer($state, [
        'id' => 'p2',
        'token' => $p2Token,
        'name' => tcgGetUserDisplayName($p2DiscordId),
        'deck_choice' => $deckChoiceLabel,
        'deck_label' => $deck2['name'],
        'main_deck' => $main2,
        'energy_deck' => $energy2,
        'discord_id' => $p2DiscordId,
        'sleeve_id' => tcgNormalizeSleeveId($deck2['sleeve_id'] ?? ''),
        'playmat_id' => tcgNormalizePlaymatId($deck2['playmat_id'] ?? ''),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($deck2['playmat_brightness'] ?? 1.0),
        'deck_snapshot' => ['main_nos' => $deck2['main_nos'], 'energy_nos' => $deck2['energy_nos']],
    ], null);

    $state['phase_timer_cfg'] = ['enabled' => true, 'duration' => PHASE_TIMER_MAX];

    // Claim both queue seats before the slow VPS seed so a concurrent ranked_join
    // cannot pair the same player into a second room while seed is in flight.
    if (!tcgClaimRankedQueuePair($p1DiscordId, $p2DiscordId, $gameMode)) {
        return null;
    }

    // Authoritative room lives on VPS Redis — do not leave a Hostinger-only playable copy.
    if (!tcgSeedRankedRoomToVps($state)) {
        // Seats already claimed; do not re-queue (avoids racing another match).
        return null;
    }

    $matchId = tcgCreateRankedMatchRecord($roomId, $p1DiscordId, $p2DiscordId, $p1Token, $p2Token, $gameMode);

    return [
        'match_id' => $matchId,
        'room_id' => $roomId,
        'game_mode' => $gameMode,
        'match_api' => 'overflow',
        'p1' => ['discord_id' => $p1DiscordId, 'token' => $p1Token, 'player_id' => 'p1'],
        'p2' => ['discord_id' => $p2DiscordId, 'token' => $p2Token, 'player_id' => 'p2'],
    ];
}

function tcgOnGameFinished(array &$state): void {
    if (($state['mode'] ?? '') === 'tournament') {
        require_once __DIR__ . '/tournament.php';
        tcgOnTournamentGameFinished($state);
        return;
    }
    if (($state['mode'] ?? '') !== 'ranked') {
        return;
    }
    $ranked = $state['ranked'] ?? [];
    $remoteElo = (($ranked['match_api'] ?? '') === 'overflow') || tcgShouldApplyRankedEloRemotely();

    // Elo already applied on Hostinger — still retry PR if the VPS room never got a pack
    // (or stored a retryable skip as applied).
    if (!empty($ranked['applied'])) {
        require_once __DIR__ . '/ranked_pr_rewards.php';
        if ($remoteElo && tcgRankedPrRewardNeedsHostingerRetry($state)) {
            if (tcgPostRankedApplyResultToHostinger($state)) {
                // pr_reward / seq bump applied by reference in match_bridge.
            }
        }
        return;
    }
    $winnerPid = $state['winner'] ?? null;
    $p1Id = $ranked['p1_discord_id'] ?? null;
    $p2Id = $ranked['p2_discord_id'] ?? null;
    if (!$p1Id || !$p2Id) {
        return;
    }

    // VPS match API (or overflow-seeded rooms): Elo/PR live on Hostinger — signed webhook.
    if ($remoteElo) {
        if (!tcgPostRankedApplyResultToHostinger($state)) {
            // Leave applied=false so a later poll/recover can retry.
            return;
        }
        $state['ranked']['applied'] = true;
        return;
    }

    require_once __DIR__ . '/game_mode.php';
    $gameMode = tcgNormalizeGameMode($ranked['game_mode'] ?? TCG_GAME_MODE_STANDARD);
    $seasonChanges = null;
    if ($winnerPid === 'p1') {
        $seasonChanges = tcgApplyRankResult($p1Id, $p2Id, false, $gameMode);
    } elseif ($winnerPid === 'p2') {
        $seasonChanges = tcgApplyRankResult($p2Id, $p1Id, false, $gameMode);
    } else {
        tcgApplyRankResult($p1Id, $p2Id, true, $gameMode);
    }
    if (is_array($seasonChanges) && $seasonChanges !== []) {
        $bySeat = [];
        if (isset($seasonChanges[$p1Id])) {
            $bySeat['p1'] = $seasonChanges[$p1Id];
        }
        if (isset($seasonChanges[$p2Id])) {
            $bySeat['p2'] = $seasonChanges[$p2Id];
        }
        $state['ranked']['season_changes'] = $bySeat;
    }
    // Mark applied immediately after ELO so PR reward failures cannot leave rating unapplied.
    $state['ranked']['applied'] = true;
    $prOk = false;
    try {
        require_once __DIR__ . '/ranked_pr_rewards.php';
        tcgApplyRankedPrRewardOnFinish($state);
        $reward = $state['ranked']['pr_reward']['reward'] ?? null;
        $prOk = !empty($state['ranked']['pr_reward_applied']);
    } catch (Throwable $e) {
        // ELO already applied — PR reward is best-effort.
    }
    tcgCompleteRankedMatch(
        $state['room_id'] ?? '',
        in_array($winnerPid, ['p1', 'p2'], true) ? $winnerPid : null,
        $prOk ? true : null
    );
}
