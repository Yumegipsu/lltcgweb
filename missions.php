<?php
/**
 * Star Gem missions — daily tasks and one-time milestones.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stamps.php';

const TCG_MISSION_DAILY_SUB_IDS = [
    'daily_open_all_boosters',
    'daily_ranked_match',
    'daily_use_stamp',
];

/** JST calendar day missions launched — one-day retroactive credit for daily_open_all_boosters. */
const TCG_MISSION_DAILY_BOOSTERS_BACKFILL_JST = '2026-07-08';

/** @return list<array{id: string, type: string, reward: int, sort: int, i18n_key: string, group?: string, threshold?: int}> */
function tcgMissionDefinitions(): array {
    return [
        ['id' => 'daily_open_all_boosters', 'type' => 'daily', 'reward' => 50, 'sort' => 10, 'i18n_key' => 'missions.daily.openAllBoosters'],
        ['id' => 'daily_ranked_match', 'type' => 'daily', 'reward' => 50, 'sort' => 20, 'i18n_key' => 'missions.daily.rankedMatch'],
        ['id' => 'daily_use_stamp', 'type' => 'daily', 'reward' => 50, 'sort' => 30, 'i18n_key' => 'missions.daily.useStamp'],
        ['id' => 'daily_complete_all', 'type' => 'daily', 'reward' => 100, 'sort' => 40, 'i18n_key' => 'missions.daily.completeAll'],

        ['id' => 'ms_profile_banner', 'type' => 'milestone', 'reward' => 100, 'sort' => 100, 'i18n_key' => 'missions.milestone.profileBanner'],
        ['id' => 'ms_profile_flag', 'type' => 'milestone', 'reward' => 100, 'sort' => 105, 'i18n_key' => 'missions.milestone.profileFlag'],
        ['id' => 'ms_profile_stamps', 'type' => 'milestone', 'reward' => 100, 'sort' => 110, 'i18n_key' => 'missions.milestone.profileStamps'],
        // 4 extra starters after the initial pick (5 total) — one every 400 cards.
        ['id' => 'ms_cards_400', 'type' => 'milestone', 'reward' => 0, 'reward_type' => 'starter_choice', 'reward_fallback' => 200, 'sort' => 150, 'i18n_key' => 'missions.milestone.cards400', 'threshold' => 400],
        ['id' => 'ms_cards_800', 'type' => 'milestone', 'reward' => 0, 'reward_type' => 'starter_choice', 'reward_fallback' => 200, 'sort' => 151, 'i18n_key' => 'missions.milestone.cards800', 'threshold' => 800],
        ['id' => 'ms_cards_1200', 'type' => 'milestone', 'reward' => 0, 'reward_type' => 'starter_choice', 'reward_fallback' => 200, 'sort' => 152, 'i18n_key' => 'missions.milestone.cards1200', 'threshold' => 1200],
        ['id' => 'ms_cards_1600', 'type' => 'milestone', 'reward' => 0, 'reward_type' => 'starter_choice', 'reward_fallback' => 200, 'sort' => 153, 'i18n_key' => 'missions.milestone.cards1600', 'threshold' => 1600],
        ['id' => 'ms_ranked_1', 'type' => 'milestone', 'reward' => 100, 'sort' => 200, 'i18n_key' => 'missions.milestone.ranked1', 'threshold' => 1],
        ['id' => 'ms_unranked_1', 'type' => 'milestone', 'reward' => 100, 'sort' => 210, 'i18n_key' => 'missions.milestone.unranked1', 'threshold' => 1],
        ['id' => 'ms_ranked_5', 'type' => 'milestone', 'reward' => 200, 'sort' => 220, 'i18n_key' => 'missions.milestone.ranked5', 'threshold' => 5],
        ['id' => 'ms_ranked_10', 'type' => 'milestone', 'reward' => 300, 'sort' => 230, 'i18n_key' => 'missions.milestone.ranked10', 'threshold' => 10],
        ['id' => 'ms_ranked_50', 'type' => 'milestone', 'reward' => 1000, 'sort' => 240, 'i18n_key' => 'missions.milestone.ranked50', 'threshold' => 50],
        ['id' => 'ms_ranked_100', 'type' => 'milestone', 'reward' => 3000, 'sort' => 250, 'i18n_key' => 'missions.milestone.ranked100', 'threshold' => 100],
        ['id' => 'ms_ranked_500', 'type' => 'milestone', 'reward' => 3000, 'sort' => 260, 'i18n_key' => 'missions.milestone.ranked500', 'threshold' => 500],
        ['id' => 'ms_ranked_1000', 'type' => 'milestone', 'reward' => 5000, 'sort' => 270, 'i18n_key' => 'missions.milestone.ranked1000', 'threshold' => 1000],
        ['id' => 'ms_sticker_1', 'type' => 'milestone', 'reward' => 200, 'sort' => 280, 'i18n_key' => 'missions.milestone.sticker1', 'threshold' => 1],
        ['id' => 'ms_sticker_10', 'type' => 'milestone', 'reward' => 200, 'sort' => 281, 'i18n_key' => 'missions.milestone.sticker10', 'threshold' => 10],
        ['id' => 'ms_sticker_50', 'type' => 'milestone', 'reward' => 500, 'sort' => 282, 'i18n_key' => 'missions.milestone.sticker50', 'threshold' => 50],
        ['id' => 'ms_sticker_100', 'type' => 'milestone', 'reward' => 1000, 'sort' => 283, 'i18n_key' => 'missions.milestone.sticker100', 'threshold' => 100],
        ['id' => 'ms_win_muse', 'type' => 'milestone', 'reward' => 1000, 'sort' => 300, 'i18n_key' => 'missions.milestone.winMuse', 'group' => "μ's"],
        ['id' => 'ms_win_aqours', 'type' => 'milestone', 'reward' => 1000, 'sort' => 310, 'i18n_key' => 'missions.milestone.winAqours', 'group' => 'Sunshine'],
        ['id' => 'ms_win_liella', 'type' => 'milestone', 'reward' => 1000, 'sort' => 320, 'i18n_key' => 'missions.milestone.winLiella', 'group' => 'Superstar'],
        ['id' => 'ms_win_hasunosora', 'type' => 'milestone', 'reward' => 1000, 'sort' => 330, 'i18n_key' => 'missions.milestone.winHasunosora', 'group' => 'Hasunosora'],
        ['id' => 'ms_win_nijigasaki', 'type' => 'milestone', 'reward' => 1000, 'sort' => 340, 'i18n_key' => 'missions.milestone.winNijigasaki', 'group' => 'Nijigasaki'],
    ];
}

function tcgMissionDefById(string $missionId): ?array {
    foreach (tcgMissionDefinitions() as $def) {
        if ($def['id'] === $missionId) {
            return $def;
        }
    }
    return null;
}

function tcgMissionPeriodKey(array $def): string {
    return ($def['type'] ?? '') === 'daily' ? tcgTodayJst() : '';
}

function tcgMissionProgressRow(string $discordId, string $missionId, string $periodKey): ?array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_mission_progress
        WHERE discord_id = ? AND mission_id = ? AND period_key = ?');
    $stmt->execute([$discordId, $missionId, $periodKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function tcgMissionIsCompleted(string $discordId, string $missionId, string $periodKey): bool {
    $row = tcgMissionProgressRow($discordId, $missionId, $periodKey);
    return $row && !empty($row['completed_at']);
}

function tcgMissionIsClaimed(string $discordId, string $missionId, string $periodKey): bool {
    $row = tcgMissionProgressRow($discordId, $missionId, $periodKey);
    return $row && !empty($row['claimed_at']);
}

/** @return list<array{id: string, i18n_key: string, reward: int}> */
function tcgMissionMarkCompleted(string $discordId, string $missionId, ?string $periodKey = null): array {
    $def = tcgMissionDefById($missionId);
    if (!$def) {
        return [];
    }
    $periodKey = $periodKey ?? tcgMissionPeriodKey($def);
    if (tcgMissionIsCompleted($discordId, $missionId, $periodKey)) {
        return [];
    }
    $now = time();
    $db = tcgDb();
    $db->prepare('INSERT OR IGNORE INTO tcg_mission_progress (discord_id, mission_id, period_key, completed_at, claimed_at)
        VALUES (?, ?, ?, ?, NULL)')
        ->execute([$discordId, $missionId, $periodKey, $now]);
    $db->prepare('UPDATE tcg_mission_progress SET completed_at = ?
        WHERE discord_id = ? AND mission_id = ? AND period_key = ? AND completed_at IS NULL')
        ->execute([$now, $discordId, $missionId, $periodKey]);
    if (!tcgMissionIsCompleted($discordId, $missionId, $periodKey)) {
        return [];
    }
    return [[
        'id' => $missionId,
        'i18n_key' => $def['i18n_key'],
        'reward' => intval($def['reward']),
    ]];
}

function tcgMissionMarkCompletedSilent(string $discordId, string $missionId, ?string $periodKey = null): void {
    tcgMissionMarkCompleted($discordId, $missionId, $periodKey);
}

/** @return list<array{id: string, i18n_key: string, reward: int}> */
function tcgMissionTryCompleteAllDaily(string $discordId): array {
    $period = tcgTodayJst();
    foreach (TCG_MISSION_DAILY_SUB_IDS as $subId) {
        if (!tcgMissionIsCompleted($discordId, $subId, $period)) {
            return [];
        }
    }
    return tcgMissionMarkCompleted($discordId, 'daily_complete_all', $period);
}

function tcgMissionDailySubsComplete(string $discordId, string $period): bool {
    foreach (TCG_MISSION_DAILY_SUB_IDS as $subId) {
        if (!tcgMissionIsCompleted($discordId, $subId, $period)) {
            return false;
        }
    }
    return true;
}

function tcgMissionStatusForDef(string $discordId, array $def): string {
    $periodKey = tcgMissionPeriodKey($def);
    if (tcgMissionIsClaimed($discordId, $def['id'], $periodKey)) {
        return 'claimed';
    }
    if (tcgMissionIsCompleted($discordId, $def['id'], $periodKey)) {
        return 'completed';
    }
    return 'active';
}

function tcgMissionSortRank(string $status, int $sort): int {
    return match ($status) {
        'completed' => 0,
        'active' => 1,
        'claimed' => 2,
        default => 3,
    };
}

function tcgMissionClaimableCount(string $discordId): int {
    $n = 0;
    foreach (tcgMissionDefinitions() as $def) {
        if (tcgMissionStatusForDef($discordId, $def) === 'completed') {
            $n++;
        }
    }
    return $n;
}

function tcgMissionListForUser(string $discordId): array {
    tcgMissionBackfillRetroactive($discordId);
    $totalCards = tcgCollectionTotalCards($discordId);
    $stickerExchanges = tcgGetStickerExchanges($discordId);
    $starterOptions = null;
    $missions = [];
    foreach (tcgMissionDefinitions() as $def) {
        $periodKey = tcgMissionPeriodKey($def);
        $row = tcgMissionProgressRow($discordId, $def['id'], $periodKey);
        $status = tcgMissionStatusForDef($discordId, $def);
        $rewardType = (string)($def['reward_type'] ?? 'star_gems');
        $entry = [
            'id' => $def['id'],
            'type' => $def['type'],
            'reward' => intval($def['reward']),
            'reward_type' => $rewardType,
            'sort' => intval($def['sort']),
            'i18n_key' => $def['i18n_key'],
            'status' => $status,
            'period_key' => $periodKey,
            'completed_at' => $row['completed_at'] ?? null,
            'claimed_at' => $row['claimed_at'] ?? null,
            'sort_rank' => tcgMissionSortRank($status, intval($def['sort'])),
        ];
        if (isset($def['threshold'])) {
            $entry['threshold'] = intval($def['threshold']);
        }
        if ($rewardType === 'starter_choice') {
            if ($starterOptions === null) {
                if (!function_exists('tcgStarterOptionsWithOwned')) {
                    require_once __DIR__ . '/booster.php';
                }
                $starterOptions = tcgStarterOptionsWithOwned($discordId);
            }
            $available = 0;
            foreach ($starterOptions as $opt) {
                if (empty($opt['owned'])) {
                    $available++;
                }
            }
            $entry['reward_fallback'] = intval($def['reward_fallback'] ?? 0);
            $entry['starter_options'] = $starterOptions;
            $entry['available_starter_count'] = $available;
            $entry['total_cards'] = $totalCards;
            if (isset($def['threshold'])) {
                $entry['progress'] = min($totalCards, intval($def['threshold']));
            }
        } elseif (str_starts_with($def['id'], 'ms_cards_') && isset($def['threshold'])) {
            $entry['total_cards'] = $totalCards;
            $entry['progress'] = min($totalCards, intval($def['threshold']));
        } elseif (str_starts_with($def['id'], 'ms_sticker_') && isset($def['threshold'])) {
            $entry['progress'] = min($stickerExchanges, intval($def['threshold']));
        }
        $missions[] = $entry;
    }
    usort($missions, static function (array $a, array $b): int {
        $cmp = ($a['sort_rank'] ?? 9) <=> ($b['sort_rank'] ?? 9);
        if ($cmp !== 0) {
            return $cmp;
        }
        return ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0);
    });
    foreach ($missions as &$m) {
        unset($m['sort_rank']);
    }
    unset($m);
    return $missions;
}

function tcgMissionClaim(string $discordId, string $missionId, ?string $starterKey = null): array {
    $def = tcgMissionDefById($missionId);
    if (!$def) {
        throw new Exception('Unknown mission');
    }
    $periodKey = tcgMissionPeriodKey($def);
    if ($def['id'] === 'daily_complete_all' && !tcgMissionDailySubsComplete($discordId, $periodKey)) {
        throw new Exception('Complete the other daily missions first');
    }
    if (!tcgMissionIsCompleted($discordId, $missionId, $periodKey)) {
        throw new Exception('Mission not completed yet');
    }
    if (tcgMissionIsClaimed($discordId, $missionId, $periodKey)) {
        throw new Exception('Mission reward already claimed');
    }

    $rewardType = (string)($def['reward_type'] ?? 'star_gems');
    $now = time();
    $db = tcgDb();

    if ($rewardType === 'starter_choice') {
        if (!function_exists('tcgGrantAdditionalStarterDeck')) {
            require_once __DIR__ . '/booster.php';
        }
        $unowned = tcgUnownedStarterKeys($discordId);
        if ($unowned === []) {
            $reward = max(0, intval($def['reward_fallback'] ?? $def['reward'] ?? 0));
            $db->prepare('UPDATE tcg_mission_progress SET claimed_at = ? WHERE discord_id = ? AND mission_id = ? AND period_key = ?')
                ->execute([$now, $discordId, $missionId, $periodKey]);
            $gems = $reward > 0 ? tcgAddStarGems($discordId, $reward) : tcgGetStarGems($discordId);
            return [
                'mission' => [
                    'id' => $missionId,
                    'i18n_key' => $def['i18n_key'],
                    'reward' => $reward,
                    'reward_type' => 'star_gems',
                    'status' => 'claimed',
                ],
                'star_gems' => $gems,
                'star_gems_gained' => $reward,
                'starter_granted' => null,
            ];
        }

        $starterKey = trim((string)($starterKey ?? ''));
        if ($starterKey === '') {
            throw new Exception('Choose a starter deck');
        }
        if (!in_array($starterKey, $unowned, true)) {
            throw new Exception('That starter deck is already owned or invalid');
        }

        if (!function_exists('tcgReadCardsDataFile')) {
            require_once __DIR__ . '/booster.php';
        }
        $cards = function_exists('tcgLoadCardsData') ? tcgLoadCardsData() : tcgReadCardsDataFile();
        $granted = tcgGrantAdditionalStarterDeck($discordId, $starterKey, $cards, $missionId);
        $db->prepare('UPDATE tcg_mission_progress SET claimed_at = ? WHERE discord_id = ? AND mission_id = ? AND period_key = ?')
            ->execute([$now, $discordId, $missionId, $periodKey]);
        // Collection grew from the grant — may unlock later card milestones.
        tcgMissionCheckCollectionThresholds($discordId);

        return [
            'mission' => [
                'id' => $missionId,
                'i18n_key' => $def['i18n_key'],
                'reward' => 0,
                'reward_type' => 'starter_choice',
                'status' => 'claimed',
            ],
            'star_gems' => tcgGetStarGems($discordId),
            'star_gems_gained' => 0,
            'starter_granted' => $granted,
        ];
    }

    $db->prepare('UPDATE tcg_mission_progress SET claimed_at = ? WHERE discord_id = ? AND mission_id = ? AND period_key = ?')
        ->execute([$now, $discordId, $missionId, $periodKey]);
    $reward = intval($def['reward']);
    $gems = tcgAddStarGems($discordId, $reward);
    return [
        'mission' => [
            'id' => $missionId,
            'i18n_key' => $def['i18n_key'],
            'reward' => $reward,
            'reward_type' => 'star_gems',
            'status' => 'claimed',
        ],
        'star_gems' => $gems,
        'star_gems_gained' => $reward,
    ];
}

function tcgMissionAttachCompletions(array $payload, array $completions): array {
    if (!empty($completions)) {
        $payload['mission_completions'] = $completions;
    }
    return $payload;
}

function tcgMissionMergeCompletions(array ...$lists): array {
    $out = [];
    $seen = [];
    foreach ($lists as $list) {
        foreach ($list as $item) {
            $id = $item['id'] ?? '';
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $item;
        }
    }
    return $out;
}

function tcgDeckMainIsSingleGroup(array $mainNos, string $targetGroup, array $cardMap): bool {
    if ($mainNos === []) {
        return false;
    }
    $targetGroup = trim($targetGroup);
    foreach ($mainNos as $no) {
        $no = trim((string)$no);
        if ($no === '') {
            continue;
        }
        $card = $cardMap[$no] ?? null;
        if (!is_array($card)) {
            return false;
        }
        $type = (string)($card['card_type_en'] ?? '');
        if ($type === 'Energy') {
            continue;
        }
        if ($type !== 'Member' && $type !== 'Live') {
            return false;
        }
        $group = trim((string)($card['group'] ?? ''));
        if ($group !== $targetGroup) {
            return false;
        }
    }
    return true;
}

function tcgMissionIdForGroup(string $group): ?string {
    return match ($group) {
        "μ's" => 'ms_win_muse',
        'Sunshine' => 'ms_win_aqours',
        'Superstar' => 'ms_win_liella',
        'Hasunosora' => 'ms_win_hasunosora',
        'Nijigasaki' => 'ms_win_nijigasaki',
        default => null,
    };
}

function tcgMissionCheckGroupWin(string $discordId, array $state, string $playerId): array {
    $player = $state['players'][$playerId] ?? null;
    if (!is_array($player)) {
        return [];
    }
    $snapshot = $player['deck_snapshot'] ?? null;
    $mainNos = is_array($snapshot) ? ($snapshot['main_nos'] ?? []) : [];
    if (!is_array($mainNos) || $mainNos === []) {
        return [];
    }
    if (!file_exists(TCG_CARDS_FILE)) {
        return [];
    }
    $cards = json_decode((string)file_get_contents(TCG_CARDS_FILE), true) ?: [];
    $cardMap = tcgBuildCardMap($cards);
    $completions = [];
    foreach (tcgMissionDefinitions() as $def) {
        $group = $def['group'] ?? null;
        if (!$group || ($def['type'] ?? '') !== 'milestone') {
            continue;
        }
        if (!tcgDeckMainIsSingleGroup($mainNos, $group, $cardMap)) {
            continue;
        }
        $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, $def['id']));
    }
    return $completions;
}

function tcgMissionCheckRankedThresholds(string $discordId): array {
    require_once __DIR__ . '/matchmaking.php';
    $rank = tcgRankRow($discordId);
    $games = intval($rank['games'] ?? 0);
    $completions = [];
    foreach (tcgMissionDefinitions() as $def) {
        if (($def['type'] ?? '') !== 'milestone') {
            continue;
        }
        $threshold = $def['threshold'] ?? null;
        if ($threshold === null) {
            continue;
        }
        if (str_starts_with($def['id'], 'ms_unranked_')) {
            $gamesCount = tcgGetUnrankedGames($discordId);
        } elseif (str_starts_with($def['id'], 'ms_ranked_')) {
            $gamesCount = $games;
        } else {
            continue;
        }
        if ($gamesCount >= intval($threshold)) {
            $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, $def['id']));
        }
    }
    return $completions;
}

/** Mark sticker-shop seal exchange milestones complete when thresholds are met. */
function tcgMissionCheckStickerExchangeThresholds(string $discordId): array {
    $exchanges = tcgGetStickerExchanges($discordId);
    $completions = [];
    foreach (tcgMissionDefinitions() as $def) {
        if (($def['type'] ?? '') !== 'milestone' || !str_starts_with($def['id'], 'ms_sticker_')) {
            continue;
        }
        $threshold = intval($def['threshold'] ?? 0);
        if ($threshold > 0 && $exchanges >= $threshold) {
            $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, $def['id']));
        }
    }
    return $completions;
}

/** Mark collection total-card milestones complete when thresholds are met. */
function tcgMissionCheckCollectionThresholds(string $discordId): array {
    $total = tcgCollectionTotalCards($discordId);
    $completions = [];
    foreach (tcgMissionDefinitions() as $def) {
        if (($def['type'] ?? '') !== 'milestone' || !str_starts_with($def['id'], 'ms_cards_')) {
            continue;
        }
        $threshold = intval($def['threshold'] ?? 0);
        if ($threshold > 0 && $total >= $threshold) {
            $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, $def['id']));
        }
    }
    return $completions;
}

/** Attach signed-in account id to a human seat when auth token is present but room creation missed it. */
function tcgMissionBackfillPlayerDiscordFromAuth(array &$state, string $playerId, array $requestBody = []): void {
    if (!isset($state['players'][$playerId]) || !is_array($state['players'][$playerId])) {
        return;
    }
    if (tcgMissionSeatIsCpu($state['players'][$playerId])) {
        return;
    }
    if (!empty($state['players'][$playerId]['discord_id'])) {
        return;
    }
    if (!function_exists('tcgOptionalAuthUserId')) {
        if (is_file(__DIR__ . '/llr_auth_load.php')) {
            require_once __DIR__ . '/llr_auth_load.php';
        }
    }
    $uid = function_exists('tcgOptionalAuthUserId') ? tcgOptionalAuthUserId($requestBody) : null;
    if ($uid) {
        $state['players'][$playerId]['discord_id'] = $uid;
    }
}

/**
 * Resolve Discord id for mission credit on an in-match action (stamps, etc.).
 * Prefer seat / ranked ids, then auth header / auth_token (not seat token).
 */
function tcgMissionResolveActingDiscordId(array &$state, string $playerId, array $requestBody = []): ?string {
    if (tcgMissionSeatIsCpu($state['players'][$playerId] ?? null)) {
        return null;
    }
    tcgMissionBackfillPlayerDiscordFromAuth($state, $playerId, $requestBody);
    $discordId = tcgPlayerDiscordId($state, $playerId);
    if ($discordId) {
        return $discordId;
    }
    if (!function_exists('tcgOptionalAuthUserId')) {
        if (is_file(__DIR__ . '/llr_auth_load.php')) {
            require_once __DIR__ . '/llr_auth_load.php';
        }
    }
    if (!function_exists('tcgOptionalAuthUserId')) {
        return null;
    }
    $uid = tcgOptionalAuthUserId($requestBody);
    if ($uid) {
        $state['players'][$playerId]['discord_id'] = $uid;
        return $uid;
    }
    return null;
}

function tcgPlayerDiscordId(array $state, string $playerId): ?string {
    $player = $state['players'][$playerId] ?? null;
    if (is_array($player) && !empty($player['discord_id'])) {
        return (string)$player['discord_id'];
    }
    $ranked = $state['ranked'] ?? [];
    if ($playerId === 'p1' && !empty($ranked['p1_discord_id'])) {
        return (string)$ranked['p1_discord_id'];
    }
    if ($playerId === 'p2' && !empty($ranked['p2_discord_id'])) {
        return (string)$ranked['p2_discord_id'];
    }
    return null;
}

/** True for CPU/COM seats — never credit missions to them (even if discord_id leaked onto the seat). */
function tcgMissionSeatIsCpu(?array $player): bool {
    if (!$player) {
        return false;
    }
    if (function_exists('isCpuPlayer')) {
        return isCpuPlayer($player);
    }
    $deckChoice = (string)($player['deck_choice'] ?? '');
    if ($deckChoice === 'cpu' || str_starts_with($deckChoice, 'cpu:')) {
        return true;
    }
    $name = (string)($player['name'] ?? '');
    if ($name === '') {
        return false;
    }
    if (str_contains($name, 'CPU') || str_contains($name, '🤖')) {
        return true;
    }
    return str_starts_with($name, 'COM') || str_starts_with($name, 'COM（');
}

/** @return list<array{id: string, i18n_key: string, reward: int}> */
function tcgMissionOnGameFinished(array $state): array {
    if (($state['status'] ?? '') !== 'finished') {
        return [];
    }
    $isRanked = ($state['mode'] ?? '') === 'ranked';
    $winner = $state['winner'] ?? null;
    $completions = [];
    foreach (['p1', 'p2'] as $pid) {
        $player = $state['players'][$pid] ?? null;
        if (tcgMissionSeatIsCpu(is_array($player) ? $player : null)) {
            continue;
        }
        $discordId = tcgPlayerDiscordId($state, $pid);
        if (!$discordId) {
            continue;
        }
        if ($isRanked) {
            $done = tcgMissionMarkCompleted($discordId, 'daily_ranked_match');
            $completions = tcgMissionMergeCompletions($completions, $done);
            $completions = tcgMissionMergeCompletions($completions, tcgMissionTryCompleteAllDaily($discordId));
        } else {
            tcgIncrementUnrankedGames($discordId);
        }
        $completions = tcgMissionMergeCompletions($completions, tcgMissionCheckRankedThresholds($discordId));
        if ($winner === $pid) {
            $completions = tcgMissionMergeCompletions($completions, tcgMissionCheckGroupWin($discordId, $state, $pid));
        }
    }
    return $completions;
}

/** @return list<array{id: string, i18n_key: string, reward: int}> */
function tcgMissionOnDailyBoostersExhausted(string $discordId): array {
    $allow = tcgDailyOpenAllowance($discordId);
    if (($allow['remaining'] ?? 1) > 0) {
        return [];
    }
    $completions = tcgMissionMarkCompleted($discordId, 'daily_open_all_boosters');
    $completions = tcgMissionMergeCompletions($completions, tcgMissionTryCompleteAllDaily($discordId));
    return $completions;
}

/** @return list<array{id: string, i18n_key: string, reward: int}> */
function tcgMissionOnStampSent(string $discordId): array {
    $completions = tcgMissionMarkCompleted($discordId, 'daily_use_stamp');
    return tcgMissionMergeCompletions($completions, tcgMissionTryCompleteAllDaily($discordId));
}

/** @return list<array{id: string, i18n_key: string, reward: int}> */
function tcgMissionOnProfileBannerSet(string $discordId): array {
    return tcgMissionMarkCompleted($discordId, 'ms_profile_banner');
}

/** @return list<array{id: string, i18n_key: string, reward: int}> */
function tcgMissionOnProfileFlagSet(string $discordId): array {
    return tcgMissionMarkCompleted($discordId, 'ms_profile_flag');
}

/** @return list<array{id: string, i18n_key: string, reward: int}> */
function tcgMissionOnStampFavoritesSet(string $discordId, array $favorites): array {
    $profile = $favorites['profile'] ?? [];
    if (!is_array($profile) || $profile === []) {
        return [];
    }
    return tcgMissionMarkCompleted($discordId, 'ms_profile_stamps');
}

function tcgMissionBackfillRetroactive(string $discordId): void {
    static $ran = [];
    if (isset($ran[$discordId])) {
        return;
    }
    $ran[$discordId] = true;

    require_once __DIR__ . '/matchmaking.php';
    $db = tcgDb();
    $stmt = $db->prepare('SELECT banner_card_no, equipped_flag, stamp_favorites, unranked_games, sticker_exchanges FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    if (!empty($user['banner_card_no'])) {
        tcgMissionMarkCompletedSilent($discordId, 'ms_profile_banner');
    }
    require_once __DIR__ . '/flags.php';
    if (tcgNormalizeEquippedFlag($user['equipped_flag'] ?? null) !== '') {
        tcgMissionMarkCompletedSilent($discordId, 'ms_profile_flag');
    }
    $fav = tcgParseStampFavorites($user['stamp_favorites'] ?? null);
    if (!empty($fav['profile'])) {
        tcgMissionMarkCompletedSilent($discordId, 'ms_profile_stamps');
    }

    $rank = tcgRankRow($discordId);
    $rankedGames = intval($rank['games'] ?? 0);
    foreach (tcgMissionDefinitions() as $def) {
        if (($def['type'] ?? '') !== 'milestone' || !str_starts_with($def['id'], 'ms_ranked_')) {
            continue;
        }
        $threshold = intval($def['threshold'] ?? 0);
        if ($threshold > 0 && $rankedGames >= $threshold) {
            tcgMissionMarkCompletedSilent($discordId, $def['id']);
        }
    }
    if (intval($user['unranked_games'] ?? 0) >= 1) {
        tcgMissionMarkCompletedSilent($discordId, 'ms_unranked_1');
    }

    $stickerExchanges = intval($user['sticker_exchanges'] ?? 0);
    foreach (tcgMissionDefinitions() as $def) {
        if (($def['type'] ?? '') !== 'milestone' || !str_starts_with($def['id'], 'ms_sticker_')) {
            continue;
        }
        $threshold = intval($def['threshold'] ?? 0);
        if ($threshold > 0 && $stickerExchanges >= $threshold) {
            tcgMissionMarkCompletedSilent($discordId, $def['id']);
        }
    }

    $totalCards = tcgCollectionTotalCards($discordId);
    foreach (tcgMissionDefinitions() as $def) {
        if (($def['type'] ?? '') !== 'milestone' || !str_starts_with($def['id'], 'ms_cards_')) {
            continue;
        }
        $threshold = intval($def['threshold'] ?? 0);
        if ($threshold > 0 && $totalCards >= $threshold) {
            tcgMissionMarkCompletedSilent($discordId, $def['id']);
        }
    }

    if (!function_exists('tcgEnsureStarterOwnedSeeded')) {
        require_once __DIR__ . '/booster.php';
    }
    tcgEnsureStarterOwnedSeeded($discordId);

    tcgMissionBackfillGroupWinsFromReplays($discordId);
    tcgMissionBackfillDailyBoostersLaunchDay($discordId);
}

/**
 * Launch-day only: if the player already exhausted today's free daily packs before missions
 * existed, mark daily_open_all_boosters complete (claim still manual).
 */
function tcgMissionBackfillDailyBoostersLaunchDay(string $discordId, ?string $todayJst = null): void {
    $todayJst = $todayJst ?? tcgTodayJst();
    if ($todayJst !== TCG_MISSION_DAILY_BOOSTERS_BACKFILL_JST) {
        return;
    }
    if (!function_exists('tcgDailyOpenAllowance')) {
        require_once __DIR__ . '/booster.php';
    }

    // Judge exhaustion from the launch-day row itself (not live "today" allowance), so this
    // still works in tests and if a profile loads after the launch JST date.
    $db = tcgDb();
    $stmt = $db->prepare('SELECT last_open_date, packs_opened_today, first_day_bonus_used FROM tcg_daily_state WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || ($row['last_open_date'] ?? '') !== $todayJst) {
        return;
    }

    $opened = max(0, intval($row['packs_opened_today'] ?? 0));
    if ($opened <= 0) {
        return;
    }

    $flagUsed = intval($row['first_day_bonus_used'] ?? 0) === 1;
    $limit = $flagUsed ? TCG_DAILY_PACK_LIMIT : TCG_WELCOME_DAY_PACK_LIMIT;
    if ($opened < $limit) {
        return;
    }

    tcgMissionMarkCompletedSilent($discordId, 'daily_open_all_boosters', $todayJst);
    tcgMissionTryCompleteAllDaily($discordId);
}

function tcgMissionBackfillGroupWinsFromReplays(string $discordId): void {
    if (!file_exists(TCG_CARDS_FILE)) {
        return;
    }
    $cards = json_decode((string)file_get_contents(TCG_CARDS_FILE), true) ?: [];
    $cardMap = tcgBuildCardMap($cards);
    $db = tcgDb();
    $stmt = $db->prepare('SELECT payload_json, saver_player_id, winner FROM tcg_replays WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (($row['winner'] ?? '') !== ($row['saver_player_id'] ?? '')) {
            continue;
        }
        $payload = json_decode($row['payload_json'] ?? '', true);
        if (!is_array($payload)) {
            continue;
        }
        $pid = (string)($row['saver_player_id'] ?? '');
        $player = $payload['players'][$pid] ?? null;
        if (!is_array($player)) {
            continue;
        }
        $mainNos = $player['deck_snapshot']['main_nos'] ?? [];
        if (!is_array($mainNos)) {
            continue;
        }
        foreach (tcgMissionDefinitions() as $def) {
            $group = $def['group'] ?? null;
            if (!$group) {
                continue;
            }
            if (tcgDeckMainIsSingleGroup($mainNos, $group, $cardMap)) {
                tcgMissionMarkCompletedSilent($discordId, $def['id']);
            }
        }
    }
}

function tcgApiMissionsList(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $missions = tcgMissionListForUser($uid);
    return [
        'success' => true,
        'missions' => $missions,
        'claimable_count' => tcgMissionClaimableCount($uid),
        'daily_period' => tcgTodayJst(),
    ];
}

function tcgApiMissionsClaim(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $missionId = trim((string)($body['mission_id'] ?? ''));
    if ($missionId === '') {
        throw new Exception('mission_id required');
    }
    $starter = isset($body['starter']) ? trim((string)$body['starter']) : null;
    if ($starter === '') {
        $starter = null;
    }
    $result = tcgMissionClaim($uid, $missionId, $starter);
    return array_merge(['success' => true], $result, [
        'claimable_count' => tcgMissionClaimableCount($uid),
    ]);
}

function tcgMissionSummaryForUser(string $discordId): array {
    tcgMissionBackfillRetroactive($discordId);
    return [
        'claimable_count' => tcgMissionClaimableCount($discordId),
        'daily_period' => tcgTodayJst(),
    ];
}

/**
 * Delete a mission progress row. If it was claimed, soft-clawback the reward (floor 0).
 *
 * @return array{retracted: bool, was_claimed: bool, gems_clawed: int, mission_id: string, discord_id: string}
 */
function tcgMissionRetract(string $discordId, string $missionId, ?string $periodKey = null): array {
    $def = tcgMissionDefById($missionId);
    if (!$def) {
        return [
            'retracted' => false,
            'was_claimed' => false,
            'gems_clawed' => 0,
            'mission_id' => $missionId,
            'discord_id' => $discordId,
        ];
    }
    $periodKey = $periodKey ?? tcgMissionPeriodKey($def);
    $row = tcgMissionProgressRow($discordId, $missionId, $periodKey);
    if (!$row || empty($row['completed_at'])) {
        return [
            'retracted' => false,
            'was_claimed' => false,
            'gems_clawed' => 0,
            'mission_id' => $missionId,
            'discord_id' => $discordId,
        ];
    }
    $wasClaimed = !empty($row['claimed_at']);
    $reward = max(0, intval($def['reward'] ?? 0));
    $gemsClawed = 0;
    if ($wasClaimed && $reward > 0) {
        $before = tcgGetStarGems($discordId);
        tcgSoftClawbackStarGems($discordId, $reward);
        $gemsClawed = max(0, $before - tcgGetStarGems($discordId));
    }
    tcgDb()->prepare('DELETE FROM tcg_mission_progress WHERE discord_id = ? AND mission_id = ? AND period_key = ?')
        ->execute([$discordId, $missionId, $periodKey]);
    return [
        'retracted' => true,
        'was_claimed' => $wasClaimed,
        'gems_clawed' => $gemsClawed,
        'mission_id' => $missionId,
        'discord_id' => $discordId,
    ];
}

/**
 * Inspect a finished match: return map mission_id => 'false_cpu'|'legit' for the given account.
 *
 * False CPU path: CPU seat won while carrying this discord_id (solo auth leak).
 * Legit path: non-CPU seat with this discord_id won using a pure group deck.
 *
 * @return array<string, 'false_cpu'|'legit'>
 */
function tcgMissionGroupWinEvidenceFromState(array $state, string $discordId, array $cardMap): array {
    if (($state['status'] ?? '') !== 'finished') {
        return [];
    }
    $winner = (string)($state['winner'] ?? '');
    if ($winner !== 'p1' && $winner !== 'p2') {
        return [];
    }
    $wp = $state['players'][$winner] ?? null;
    if (!is_array($wp)) {
        return [];
    }
    $winnerDiscord = trim((string)($wp['discord_id'] ?? ''));
    if ($winnerDiscord === '' || $winnerDiscord !== $discordId) {
        return [];
    }
    $mainNos = $wp['deck_snapshot']['main_nos'] ?? [];
    if (!is_array($mainNos) || $mainNos === []) {
        return [];
    }
    $out = [];
    $kind = tcgMissionSeatIsCpu($wp) ? 'false_cpu' : 'legit';
    foreach (tcgMissionDefinitions() as $def) {
        $group = $def['group'] ?? null;
        if (!$group || ($def['type'] ?? '') !== 'milestone') {
            continue;
        }
        if (!tcgDeckMainIsSingleGroup($mainNos, $group, $cardMap)) {
            continue;
        }
        $out[$def['id']] = $kind;
    }
    return $out;
}

/**
 * Merge evidence maps; legit wins beat false_cpu for the same mission.
 *
 * @param array<string, 'false_cpu'|'legit'> ...$maps
 * @return array<string, 'false_cpu'|'legit'>
 */
function tcgMissionMergeGroupWinEvidence(array ...$maps): array {
    $out = [];
    foreach ($maps as $map) {
        foreach ($map as $missionId => $kind) {
            if (($out[$missionId] ?? null) === 'legit') {
                continue;
            }
            if ($kind === 'legit' || !isset($out[$missionId])) {
                $out[$missionId] = $kind;
            }
        }
    }
    return $out;
}

/** @return array<string, array<string, 'false_cpu'|'legit'>> discord_id => mission_id => kind */
function tcgMissionCollectGroupWinEvidence(?array $cardMap = null): array {
    if ($cardMap === null) {
        if (!file_exists(TCG_CARDS_FILE)) {
            return [];
        }
        $cards = json_decode((string)file_get_contents(TCG_CARDS_FILE), true) ?: [];
        $cardMap = tcgBuildCardMap($cards);
    }
    /** @var array<string, array<string, 'false_cpu'|'legit'>> $byUser */
    $byUser = [];

    $addState = function (array $state) use (&$byUser, $cardMap): void {
        if (($state['status'] ?? '') !== 'finished') {
            return;
        }
        $ids = [];
        foreach (['p1', 'p2'] as $pid) {
            $did = trim((string)(($state['players'][$pid]['discord_id'] ?? '')));
            if ($did !== '') {
                $ids[$did] = true;
            }
        }
        foreach (array_keys($ids) as $discordId) {
            $ev = tcgMissionGroupWinEvidenceFromState($state, $discordId, $cardMap);
            if ($ev === []) {
                continue;
            }
            $byUser[$discordId] = tcgMissionMergeGroupWinEvidence($byUser[$discordId] ?? [], $ev);
        }
    };

    $gamesDir = function_exists('tcgPath') ? tcgPath('games') : (defined('GAMES_DIR') ? GAMES_DIR : '');
    if (is_string($gamesDir) && $gamesDir !== '' && is_dir($gamesDir)) {
        foreach (glob(rtrim($gamesDir, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $base = basename((string)$file);
            if (str_starts_with($base, 'presence_') || str_starts_with($base, 'lock_') || str_starts_with($base, 'spectators_')) {
                continue;
            }
            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') {
                continue;
            }
            $state = json_decode($raw, true);
            if (is_array($state)) {
                $addState($state);
            }
        }
    }

    $db = tcgDb();
    $stmt = $db->query('SELECT discord_id, winner, payload_json FROM tcg_replays');
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $discordId = trim((string)($row['discord_id'] ?? ''));
            if ($discordId === '') {
                continue;
            }
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            $baseline = $payload['baseline'] ?? null;
            if (!is_array($baseline)) {
                continue;
            }
            $state = $baseline;
            $state['status'] = 'finished';
            $state['winner'] = $row['winner'] ?? ($payload['meta']['winner'] ?? ($baseline['winner'] ?? null));
            // Prefer final seat snapshots when export embedded them.
            if (isset($payload['players']) && is_array($payload['players'])) {
                $state['players'] = $payload['players'];
            }
            $ev = tcgMissionGroupWinEvidenceFromState($state, $discordId, $cardMap);
            if ($ev === []) {
                continue;
            }
            $byUser[$discordId] = tcgMissionMergeGroupWinEvidence($byUser[$discordId] ?? [], $ev);
        }
    }

    return $byUser;
}

/**
 * Retract group-win milestones that lack a legitimate human-win record.
 *
 * Preferentially targets the CPU auth-leak path (CPU seat won while carrying the
 * player's discord_id). Also retracts completions with no saved legit win evidence
 * at all — those can be re-earned; replay backfill restores real wins automatically.
 *
 * @return list<array{retracted: bool, was_claimed: bool, gems_clawed: int, mission_id: string, discord_id: string, reason: string}>
 */
function tcgMissionClawbackFalseCpuGroupWins(): array {
    $evidence = tcgMissionCollectGroupWinEvidence();
    $results = [];
    $db = tcgDb();
    $stmt = $db->query("SELECT discord_id, mission_id, period_key FROM tcg_mission_progress
        WHERE completed_at IS NOT NULL AND mission_id LIKE 'ms_win_%'");
    if (!$stmt) {
        return [];
    }
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $discordId = (string)($row['discord_id'] ?? '');
        $missionId = (string)($row['mission_id'] ?? '');
        $periodKey = (string)($row['period_key'] ?? '');
        $kind = $evidence[$discordId][$missionId] ?? null;
        if ($kind === 'legit') {
            continue;
        }
        $reason = $kind === 'false_cpu' ? 'false_cpu_auth_leak' : 'no_legit_win_evidence';
        $result = tcgMissionRetract($discordId, $missionId, $periodKey);
        $result['reason'] = $reason;
        $results[] = $result;
    }
    return $results;
}

// One-shot Hostinger clawback after CPU seats stopped inheriting auth.
tcgDbRunMigrationOnce(tcgDb(), 'clawback_false_cpu_group_wins_20260711', static function (PDO $db): void {
    $results = tcgMissionClawbackFalseCpuGroupWins();
    $summary = [
        'at' => time(),
        'retracted' => count(array_filter($results, static fn(array $r): bool => !empty($r['retracted']))),
        'gems_clawed_total' => array_sum(array_map(static fn(array $r): int => intval($r['gems_clawed'] ?? 0), $results)),
        'details' => $results,
    ];
    $db->prepare('INSERT INTO tcg_schema_meta (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([
            'clawback_false_cpu_group_wins_20260711_report',
            json_encode($summary, JSON_UNESCAPED_SLASHES),
        ]);
});

