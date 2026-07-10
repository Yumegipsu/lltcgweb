<?php
/**
 * Ranked room creation and post-game ELO updates.
 *
 * Loads api.php as a library (TCG_API_LIB_ONLY). tcgCreateRankedRoom pairs equipped
 * deck presets; tcgOnGameFinished adjusts tcg_rank when a ranked match ends.
 */
define('TCG_API_LIB_ONLY', true);
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/matchmaking.php';
require_once __DIR__ . '/deck_validate.php';
require_once __DIR__ . '/booster.php';

function tcgGetEquippedDeckLists(string $discordId): ?array {
    $row = tcgGetEquippedDeckRow($discordId);
    if (!$row) {
        return null;
    }
    return [
        'name' => tcgNormalizeDeckPresetName($row['name'] ?? 'Ranked Deck'),
        'main_nos' => json_decode($row['main_deck'], true) ?: [],
        'energy_nos' => json_decode($row['energy_deck'], true) ?: [],
    ];
}

function tcgGetUserDisplayName(string $discordId): string {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT username FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['username'] ?? 'Player';
}

function tcgCreateRankedRoomPair(string $p1DiscordId, string $p2DiscordId): ?array {
    $deck1 = tcgGetEquippedDeckLists($p1DiscordId);
    $deck2 = tcgGetEquippedDeckLists($p2DiscordId);
    if (!$deck1 || !$deck2) {
        return null;
    }

    $cards = json_decode(file_get_contents(CARDS_FILE), true);
    $allCards = $cards['cards'] ?? [];
    $cardMap = tcgBuildCardMap($cards);

    foreach ([$p1DiscordId => $deck1, $p2DiscordId => $deck2] as $uid => $deck) {
        $v = tcgValidateDeckLists($deck['main_nos'], $deck['energy_nos'], $cardMap, tcgGetCollectionMap($uid));
        if (!$v['valid']) {
            tcgQueueLeave($uid);
            return null;
        }
    }

    $roomId = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
    $p1Token = generateToken();
    $p2Token = generateToken();

    $main1 = buildDeck($allCards, $deck1['main_nos']);
    $energy1 = buildDeck($allCards, $deck1['energy_nos']);
    shuffle($main1);
    shuffle($energy1);

    $state = initGameState($roomId, [
        'id' => 'p1',
        'token' => $p1Token,
        'name' => tcgGetUserDisplayName($p1DiscordId),
        'deck_choice' => 'ranked',
        'deck_label' => $deck1['name'],
        'main_deck' => $main1,
        'energy_deck' => $energy1,
        'discord_id' => $p1DiscordId,
        'deck_snapshot' => ['main_nos' => $deck1['main_nos'], 'energy_nos' => $deck1['energy_nos']],
    ]);
    $state['mode'] = 'ranked';
    $state['ranked'] = [
        'p1_discord_id' => $p1DiscordId,
        'p2_discord_id' => $p2DiscordId,
        'applied' => false,
    ];

    $main2 = buildDeck($allCards, $deck2['main_nos']);
    $energy2 = buildDeck($allCards, $deck2['energy_nos']);
    shuffle($main2);
    shuffle($energy2);

    $state = addSecondPlayer($state, [
        'id' => 'p2',
        'token' => $p2Token,
        'name' => tcgGetUserDisplayName($p2DiscordId),
        'deck_choice' => 'ranked',
        'deck_label' => $deck2['name'],
        'main_deck' => $main2,
        'energy_deck' => $energy2,
        'discord_id' => $p2DiscordId,
        'deck_snapshot' => ['main_nos' => $deck2['main_nos'], 'energy_nos' => $deck2['energy_nos']],
    ], null);

    $state['phase_timer_cfg'] = ['enabled' => true, 'duration' => PHASE_TIMER_MAX];

    saveGame($roomId, $state);
    $matchId = tcgCreateRankedMatchRecord($roomId, $p1DiscordId, $p2DiscordId, $p1Token, $p2Token);

    return [
        'match_id' => $matchId,
        'room_id' => $roomId,
        'p1' => ['discord_id' => $p1DiscordId, 'token' => $p1Token, 'player_id' => 'p1'],
        'p2' => ['discord_id' => $p2DiscordId, 'token' => $p2Token, 'player_id' => 'p2'],
    ];
}

function tcgOnGameFinished(array &$state): void {
    if (($state['mode'] ?? '') !== 'ranked') {
        return;
    }
    $ranked = $state['ranked'] ?? [];
    if (!empty($ranked['applied'])) {
        return;
    }
    $winnerPid = $state['winner'] ?? null;
    $p1Id = $ranked['p1_discord_id'] ?? null;
    $p2Id = $ranked['p2_discord_id'] ?? null;
    if (!$p1Id || !$p2Id) {
        return;
    }
    if ($winnerPid === 'p1') {
        tcgApplyRankResult($p1Id, $p2Id, false);
    } elseif ($winnerPid === 'p2') {
        tcgApplyRankResult($p2Id, $p1Id, false);
    } else {
        tcgApplyRankResult($p1Id, $p2Id, true);
    }
    // Mark applied immediately after ELO so PR reward failures cannot leave rating unapplied.
    $state['ranked']['applied'] = true;
    tcgCompleteRankedMatch($state['room_id'] ?? '');
    try {
        require_once __DIR__ . '/ranked_pr_rewards.php';
        tcgApplyRankedPrRewardOnFinish($state);
    } catch (Throwable $e) {
        // ELO already applied — PR reward is best-effort.
    }
}
