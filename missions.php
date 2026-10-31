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

/** Distinct JST login days needed in a week for `weekly_login_7` (id kept: it is stored on completed rows). */
const TCG_MISSION_WEEKLY_LOGIN_DAYS = 5;

/** Weekly / monthly "complete all" missions are credited when every one of these is completed. */
const TCG_MISSION_WEEKLY_SUB_IDS = [
    'weekly_ranked_random',
    'weekly_ranked_10',
    'weekly_ranked_win',
    'weekly_login_7',
];
const TCG_MISSION_MONTHLY_SUB_IDS = [
    'monthly_tournament',
    'monthly_ranked_30',
    'monthly_pink_c',
    'monthly_weekly_4',
];

/** JST calendar day missions launched — one-day retroactive credit for daily_open_all_boosters. */
const TCG_MISSION_DAILY_BOOSTERS_BACKFILL_JST = '2026-07-08';

/** A def with only_season exists only while that ranked season is the current one. */
function tcgMissionDefIsLive(array $def): bool {
    if (empty($def['only_season'])) {
        return true;
    }
    if (!function_exists('tcgSeasonClockInfo')) {
        require_once __DIR__ . '/season.php';
    }
    $clock = tcgSeasonClockInfo();
    return !empty($clock['active']) && (string)($clock['id'] ?? '') === (string)$def['only_season'];
}

/** @return list<array{id: string, type: string, reward: int, sort: int, i18n_key: string, group?: string, threshold?: int}> */
function tcgMissionDefinitions(): array {
    return array_values(array_filter(tcgMissionDefinitionsRaw(), 'tcgMissionDefIsLive'));
}

/** @return list<array<string,mixed>> */
function tcgMissionDefinitionsRaw(): array {
    return [
        ['id' => 'daily_open_all_boosters', 'type' => 'daily', 'reward' => 50, 'sort' => 10, 'i18n_key' => 'missions.daily.openAllBoosters'],
        ['id' => 'daily_ranked_match', 'type' => 'daily', 'reward' => 50, 'sort' => 20, 'i18n_key' => 'missions.daily.rankedMatch'],
        ['id' => 'daily_use_stamp', 'type' => 'daily', 'reward' => 50, 'sort' => 30, 'i18n_key' => 'missions.daily.useStamp'],
        ['id' => 'daily_complete_all', 'type' => 'daily', 'reward' => 100, 'sort' => 40, 'i18n_key' => 'missions.daily.completeAll'],

        // Weekly (JST Monday reset). `rewards` = list of {type: gems|coins|tickets|ticket_sr|ticket_ur|seal, amount, tier?}.
        ['id' => 'weekly_ranked_random', 'type' => 'weekly', 'reward' => 100, 'reward_type' => 'multi', 'rewards' => [['type' => 'gems', 'amount' => 100]], 'sort' => 10, 'i18n_key' => 'missions.weekly.rankedRandom'],
        ['id' => 'weekly_ranked_10', 'type' => 'weekly', 'reward' => 10, 'reward_type' => 'multi', 'rewards' => [['type' => 'seal', 'tier' => 'N', 'amount' => 10]], 'sort' => 20, 'i18n_key' => 'missions.weekly.ranked10', 'threshold' => 10],
        ['id' => 'weekly_ranked_win', 'type' => 'weekly', 'reward' => 100, 'reward_type' => 'multi', 'rewards' => [['type' => 'coins', 'amount' => 100], ['type' => 'seal', 'tier' => 'R', 'amount' => 1]], 'sort' => 30, 'i18n_key' => 'missions.weekly.rankedWin'],
        ['id' => 'weekly_login_7', 'type' => 'weekly', 'reward' => 10, 'reward_type' => 'multi', 'rewards' => [['type' => 'tickets', 'amount' => 10]], 'sort' => 40, 'i18n_key' => 'missions.weekly.login7', 'threshold' => TCG_MISSION_WEEKLY_LOGIN_DAYS],
        ['id' => 'weekly_complete_all', 'type' => 'weekly', 'reward' => 1, 'reward_type' => 'multi', 'rewards' => [['type' => 'ticket_sr', 'amount' => 1]], 'sort' => 50, 'i18n_key' => 'missions.weekly.completeAll', 'threshold' => 4],

        // Monthly (resets with the ranked season, 00:00 UTC on the 1st).
        ['id' => 'monthly_tournament', 'type' => 'monthly', 'reward' => 500, 'reward_type' => 'multi', 'rewards' => [['type' => 'ticket_sr', 'amount' => 1], ['type' => 'coins', 'amount' => 500]], 'sort' => 10, 'i18n_key' => 'missions.monthly.tournament'],
        ['id' => 'monthly_ranked_30', 'type' => 'monthly', 'reward' => 1, 'reward_type' => 'multi', 'rewards' => [['type' => 'ticket_sr', 'amount' => 1]], 'sort' => 20, 'i18n_key' => 'missions.monthly.ranked30', 'threshold' => 30],
        ['id' => 'monthly_pink_c', 'type' => 'monthly', 'reward' => 1000, 'reward_type' => 'multi', 'rewards' => [['type' => 'gems', 'amount' => 1000]], 'sort' => 30, 'i18n_key' => 'missions.monthly.pinkC'],
        ['id' => 'monthly_weekly_4', 'type' => 'monthly', 'reward' => 3, 'reward_type' => 'multi', 'rewards' => [['type' => 'ticket_sr', 'amount' => 3]], 'sort' => 40, 'i18n_key' => 'missions.monthly.weeklyAll4', 'threshold' => 4],
        ['id' => 'monthly_complete_all', 'type' => 'monthly', 'reward' => 1, 'reward_type' => 'multi', 'rewards' => [['type' => 'ticket_ur', 'amount' => 1]], 'sort' => 50, 'i18n_key' => 'missions.monthly.completeAll', 'threshold' => 4],

        // Temporary: only exists during ranked season 2026-10 (Season 1); gone once Season 2 starts. Pinned above the other milestones.
        ['id' => 'ms_season_feedback_2026_10', 'type' => 'milestone', 'reward' => 1, 'reward_type' => 'multi', 'rewards' => [['type' => 'ticket_sr', 'amount' => 1]], 'sort' => 1, 'pinned_top' => true, 'only_season' => '2026-10', 'i18n_key' => 'missions.milestone.seasonFeedback1'],
        ['id' => 'ms_profile_banner', 'type' => 'milestone', 'reward' => 100, 'sort' => 100, 'i18n_key' => 'missions.milestone.profileBanner'],
        ['id' => 'ms_profile_flag', 'type' => 'milestone', 'reward' => 100, 'sort' => 105, 'i18n_key' => 'missions.milestone.profileFlag'],
        ['id' => 'ms_profile_stamps', 'type' => 'milestone', 'reward' => 100, 'sort' => 110, 'i18n_key' => 'missions.milestone.profileStamps'],
        ['id' => 'ms_friend_1', 'type' => 'milestone', 'reward' => 100, 'coins_reward' => 100, 'reward_type' => 'star_gems_and_coins', 'sort' => 115, 'i18n_key' => 'missions.milestone.friend1', 'threshold' => 1],
        ['id' => 'ms_friend_10', 'type' => 'milestone', 'reward' => 500, 'coins_reward' => 500, 'reward_type' => 'star_gems_and_coins', 'sort' => 116, 'i18n_key' => 'missions.milestone.friend10', 'threshold' => 10],
        ['id' => 'ms_friend_20', 'type' => 'milestone', 'reward' => 500, 'reward_type' => 'coins', 'sort' => 117, 'i18n_key' => 'missions.milestone.friend20', 'threshold' => 20],
        ['id' => 'ms_sleeves_10', 'type' => 'milestone', 'reward' => 500, 'reward_type' => 'multi', 'rewards' => [['type' => 'gems', 'amount' => 500], ['type' => 'coins', 'amount' => 1000]], 'sort' => 118, 'i18n_key' => 'missions.milestone.sleeves10', 'threshold' => 10],
        ['id' => 'ms_playmats_10', 'type' => 'milestone', 'reward' => 1000, 'reward_type' => 'multi', 'rewards' => [['type' => 'gems', 'amount' => 1000], ['type' => 'coins', 'amount' => 3000]], 'sort' => 119, 'i18n_key' => 'missions.milestone.playmats10', 'threshold' => 10],
        ['id' => 'ms_gacha_scout_1', 'type' => 'milestone', 'reward' => 5, 'reward_type' => 'multi', 'rewards' => [['type' => 'tickets', 'amount' => 5]], 'sort' => 120, 'i18n_key' => 'missions.milestone.gachaScout1', 'threshold' => 1],
        ['id' => 'ms_gacha_scout_10', 'type' => 'milestone', 'reward' => 1000, 'reward_type' => 'multi', 'rewards' => [['type' => 'coins', 'amount' => 1000], ['type' => 'tickets', 'amount' => 10]], 'sort' => 121, 'i18n_key' => 'missions.milestone.gachaScout10', 'threshold' => 10],
        ['id' => 'ms_gacha_birthday', 'type' => 'milestone', 'reward' => 5, 'reward_type' => 'multi', 'rewards' => [['type' => 'tickets', 'amount' => 5], ['type' => 'gems', 'amount' => 50]], 'sort' => 122, 'i18n_key' => 'missions.milestone.gachaBirthday'],
        // 6 extra starters after the initial pick (7 total) — one every 400 cards.
        ['id' => 'ms_cards_400', 'type' => 'milestone', 'reward' => 0, 'reward_type' => 'starter_choice', 'reward_fallback' => 200, 'sort' => 150, 'i18n_key' => 'missions.milestone.cards400', 'threshold' => 400],
        ['id' => 'ms_cards_800', 'type' => 'milestone', 'reward' => 0, 'reward_type' => 'starter_choice', 'reward_fallback' => 200, 'sort' => 151, 'i18n_key' => 'missions.milestone.cards800', 'threshold' => 800],
        ['id' => 'ms_cards_1200', 'type' => 'milestone', 'reward' => 0, 'reward_type' => 'starter_choice', 'reward_fallback' => 200, 'sort' => 152, 'i18n_key' => 'missions.milestone.cards1200', 'threshold' => 1200],
        ['id' => 'ms_cards_1600', 'type' => 'milestone', 'reward' => 0, 'reward_type' => 'starter_choice', 'reward_fallback' => 200, 'sort' => 153, 'i18n_key' => 'missions.milestone.cards1600', 'threshold' => 1600],
        ['id' => 'ms_cards_2000', 'type' => 'milestone', 'reward' => 0, 'reward_type' => 'starter_choice', 'reward_fallback' => 200, 'sort' => 154, 'i18n_key' => 'missions.milestone.cards2000', 'threshold' => 2000],
        ['id' => 'ms_cards_2400', 'type' => 'milestone', 'reward' => 0, 'reward_type' => 'starter_choice', 'reward_fallback' => 200, 'sort' => 155, 'i18n_key' => 'missions.milestone.cards2400', 'threshold' => 2400],
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
        ['id' => 'ms_win_turn_3', 'type' => 'milestone', 'reward' => 800, 'reward_type' => 'coins_and_pr_pack', 'sort' => 290, 'i18n_key' => 'missions.milestone.winTurn3', 'threshold' => 3],
        ['id' => 'ms_yell_score_20', 'type' => 'milestone', 'reward' => 500, 'reward_type' => 'coins_and_pr_pack', 'sort' => 292, 'i18n_key' => 'missions.milestone.yellScore20', 'threshold' => 20],
        ['id' => 'ms_live_score_15', 'type' => 'milestone', 'reward' => 500, 'reward_type' => 'coins_and_pr_pack', 'sort' => 294, 'i18n_key' => 'missions.milestone.liveScore15', 'threshold' => 15],
        ['id' => 'ms_win_muse', 'type' => 'milestone', 'reward' => 1000, 'sort' => 300, 'i18n_key' => 'missions.milestone.winMuse', 'group' => "μ's"],
        ['id' => 'ms_win_aqours', 'type' => 'milestone', 'reward' => 1000, 'sort' => 310, 'i18n_key' => 'missions.milestone.winAqours', 'group' => 'Sunshine'],
        ['id' => 'ms_win_liella', 'type' => 'milestone', 'reward' => 1000, 'sort' => 320, 'i18n_key' => 'missions.milestone.winLiella', 'group' => 'Superstar'],
        ['id' => 'ms_win_hasunosora', 'type' => 'milestone', 'reward' => 1000, 'sort' => 330, 'i18n_key' => 'missions.milestone.winHasunosora', 'group' => 'Hasunosora'],
        ['id' => 'ms_win_nijigasaki', 'type' => 'milestone', 'reward' => 1000, 'sort' => 340, 'i18n_key' => 'missions.milestone.winNijigasaki', 'group' => 'Nijigasaki'],
        ['id' => 'ms_login_days_10', 'type' => 'milestone', 'reward' => 1, 'reward_type' => 'free_sleeve', 'sort' => 160, 'i18n_key' => 'missions.milestone.loginDays10', 'threshold' => 10],
    ];
}

const TCG_MISSION_PLAY_THRESHOLDS = [
    100 => 200,
    500 => 1000,
];

/**
 * Dynamic play-stat milestones (idol Stage / Live group / Live subunit).
 *
 * @return list<array<string,mixed>>
 */
function tcgMissionPlayDefinitions(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!function_exists('tcgPlayStatMilestoneKeysFromCatalog')) {
        require_once __DIR__ . '/play_stats.php';
    }
    $keys = tcgPlayStatMilestoneKeysFromCatalog();
    $out = [];
    $sortBase = 500;
    $i = 0;
    foreach ($keys['idols'] as $idol) {
        foreach (TCG_MISSION_PLAY_THRESHOLDS as $threshold => $coins) {
            $out[] = [
                'id' => 'ms_play_idol:' . $idol . ':' . $threshold,
                'type' => 'milestone',
                'reward' => $coins,
                'reward_type' => 'coins',
                'sort' => $sortBase + ($i++),
                'i18n_key' => 'missions.milestone.playIdol',
                'i18n_vars' => ['idol' => $idol, 'n' => $threshold],
                'threshold' => $threshold,
                'play_tracker' => TCG_PLAY_TRACKER_STAGE,
                'play_dim' => TCG_PLAY_DIM_IDOL,
                'play_key' => $idol,
                'play_family' => 'idol',
            ];
        }
    }
    foreach ($keys['units'] as $unit) {
        $display = tcgPlayStatUnitDisplayName($unit);
        foreach (TCG_MISSION_PLAY_THRESHOLDS as $threshold => $coins) {
            $out[] = [
                'id' => 'ms_play_unit:' . $unit . ':' . $threshold,
                'type' => 'milestone',
                'reward' => $coins,
                'reward_type' => 'coins',
                'sort' => $sortBase + ($i++),
                'i18n_key' => 'missions.milestone.playLiveGroup',
                'i18n_vars' => ['group' => $display, 'n' => $threshold],
                'threshold' => $threshold,
                'play_tracker' => TCG_PLAY_TRACKER_LIVE_SUCCESS,
                'play_dim' => TCG_PLAY_DIM_UNIT,
                'play_key' => $unit,
                'play_family' => 'unit',
            ];
        }
    }
    if (!function_exists('subunitDisplayEn')) {
        require_once __DIR__ . '/subunits.php';
    }
    foreach ($keys['subunits'] as $subunit) {
        $subunitLabel = subunitDisplayEn($subunit);
        foreach (TCG_MISSION_PLAY_THRESHOLDS as $threshold => $coins) {
            $out[] = [
                'id' => 'ms_play_subunit:' . $subunit . ':' . $threshold,
                'type' => 'milestone',
                'reward' => $coins,
                'reward_type' => 'coins',
                'sort' => $sortBase + ($i++),
                'i18n_key' => 'missions.milestone.playLiveSubunit',
                'i18n_vars' => ['subunit' => $subunitLabel, 'n' => $threshold],
                'threshold' => $threshold,
                'play_tracker' => TCG_PLAY_TRACKER_LIVE_SUCCESS,
                'play_dim' => TCG_PLAY_DIM_SUBUNIT,
                'play_key' => $subunit,
                'play_family' => 'subunit',
            ];
        }
    }
    $cache = $out;
    return $cache;
}

function tcgMissionIsPlayDef(array $def): bool {
    return isset($def['play_tracker'], $def['play_dim'], $def['play_key']);
}

/** @return list<array<string,mixed>> */
function tcgMissionAllDefinitions(): array {
    return array_merge(tcgMissionDefinitions(), tcgMissionPlayDefinitions());
}

function tcgMissionParsePlayDefId(string $missionId): ?array {
    if (str_starts_with($missionId, 'ms_play_idol:')) {
        $rest = substr($missionId, strlen('ms_play_idol:'));
        $pos = strrpos($rest, ':');
        if ($pos === false) {
            return null;
        }
        $key = substr($rest, 0, $pos);
        $threshold = intval(substr($rest, $pos + 1));
        if ($key === '' || !isset(TCG_MISSION_PLAY_THRESHOLDS[$threshold])) {
            return null;
        }
        return [
            'id' => $missionId,
            'type' => 'milestone',
            'reward' => TCG_MISSION_PLAY_THRESHOLDS[$threshold],
            'reward_type' => 'coins',
            'sort' => 500,
            'i18n_key' => 'missions.milestone.playIdol',
            'i18n_vars' => ['idol' => $key, 'n' => $threshold],
            'threshold' => $threshold,
            'play_tracker' => TCG_PLAY_TRACKER_STAGE,
            'play_dim' => TCG_PLAY_DIM_IDOL,
            'play_key' => $key,
            'play_family' => 'idol',
        ];
    }
    if (str_starts_with($missionId, 'ms_play_unit:')) {
        $rest = substr($missionId, strlen('ms_play_unit:'));
        $pos = strrpos($rest, ':');
        if ($pos === false) {
            return null;
        }
        $key = substr($rest, 0, $pos);
        $threshold = intval(substr($rest, $pos + 1));
        if ($key === '' || !isset(TCG_MISSION_PLAY_THRESHOLDS[$threshold])) {
            return null;
        }
        if (!function_exists('tcgPlayStatUnitDisplayName')) {
            require_once __DIR__ . '/play_stats.php';
        }
        return [
            'id' => $missionId,
            'type' => 'milestone',
            'reward' => TCG_MISSION_PLAY_THRESHOLDS[$threshold],
            'reward_type' => 'coins',
            'sort' => 500,
            'i18n_key' => 'missions.milestone.playLiveGroup',
            'i18n_vars' => ['group' => tcgPlayStatUnitDisplayName($key), 'n' => $threshold],
            'threshold' => $threshold,
            'play_tracker' => TCG_PLAY_TRACKER_LIVE_SUCCESS,
            'play_dim' => TCG_PLAY_DIM_UNIT,
            'play_key' => $key,
            'play_family' => 'unit',
        ];
    }
    if (str_starts_with($missionId, 'ms_play_subunit:')) {
        $rest = substr($missionId, strlen('ms_play_subunit:'));
        $pos = strrpos($rest, ':');
        if ($pos === false) {
            return null;
        }
        $key = substr($rest, 0, $pos);
        $threshold = intval(substr($rest, $pos + 1));
        if ($key === '' || !isset(TCG_MISSION_PLAY_THRESHOLDS[$threshold])) {
            return null;
        }
        if (!function_exists('subunitDisplayEn')) {
            require_once __DIR__ . '/subunits.php';
        }
        return [
            'id' => $missionId,
            'type' => 'milestone',
            'reward' => TCG_MISSION_PLAY_THRESHOLDS[$threshold],
            'reward_type' => 'coins',
            'sort' => 500,
            'i18n_key' => 'missions.milestone.playLiveSubunit',
            'i18n_vars' => ['subunit' => subunitDisplayEn($key), 'n' => $threshold],
            'threshold' => $threshold,
            'play_tracker' => TCG_PLAY_TRACKER_LIVE_SUCCESS,
            'play_dim' => TCG_PLAY_DIM_SUBUNIT,
            'play_key' => $key,
            'play_family' => 'subunit',
        ];
    }
    return null;
}

function tcgMissionDefById(string $missionId): ?array {
    foreach (tcgMissionDefinitions() as $def) {
        if ($def['id'] === $missionId) {
            return $def;
        }
    }
    $parsed = tcgMissionParsePlayDefId($missionId);
    if ($parsed !== null) {
        return $parsed;
    }
    foreach (tcgMissionPlayDefinitions() as $def) {
        if ($def['id'] === $missionId) {
            return $def;
        }
    }
    return null;
}

/** Monday (JST) of the week containing $ts, as Y-m-d. */
function tcgMissionWeekKey(?int $ts = null): string {
    $tz = new DateTimeZone('Asia/Tokyo');
    $d = (new DateTimeImmutable('@' . ($ts ?? time())))->setTimezone($tz)->setTime(0, 0);
    $dow = (int)$d->format('N');
    return $d->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');
}

/** Unix time of the next Monday 00:00 JST. */
function tcgMissionWeekEndsAt(?int $ts = null): int {
    $tz = new DateTimeZone('Asia/Tokyo');
    $monday = new DateTimeImmutable(tcgMissionWeekKey($ts), $tz);
    return $monday->modify('+7 days')->getTimestamp();
}

function tcgMissionNow(): int {
    if (!function_exists('tcgSeasonNow')) {
        require_once __DIR__ . '/season.php';
    }
    return tcgSeasonNow();
}

/** Monthly missions reset with the ranked season (UTC month), e.g. 2026-10. */
function tcgMissionMonthKey(?int $ts = null): string {
    return gmdate('Y-m', $ts ?? tcgMissionNow());
}

function tcgMissionMonthEndsAt(?int $ts = null): int {
    $ts = $ts ?? tcgMissionNow();
    return gmmktime(0, 0, 0, (int)gmdate('n', $ts) + 1, 1, (int)gmdate('Y', $ts));
}

function tcgMissionPeriodKey(array $def): string {
    return match ($def['type'] ?? '') {
        'daily' => tcgTodayJst(),
        'weekly' => tcgMissionWeekKey(),
        'monthly' => tcgMissionMonthKey(),
        default => '',
    };
}

function tcgMissionPeriodEndsAt(array $def): ?int {
    return match ($def['type'] ?? '') {
        'weekly' => tcgMissionWeekEndsAt(),
        'monthly' => tcgMissionMonthEndsAt(),
        default => null,
    };
}

// ── Counters + one-shot events (weekly / monthly / lifetime progress) ─────────

function tcgMissionCounterGet(string $discordId, string $key, string $period = ''): int {
    $stmt = tcgDb()->prepare('SELECT count FROM tcg_mission_counters WHERE discord_id = ? AND counter_key = ? AND period_key = ?');
    $stmt->execute([$discordId, $key, $period]);
    return max(0, intval($stmt->fetchColumn() ?: 0));
}

function tcgMissionCounterBump(string $discordId, string $key, string $period = '', int $by = 1): int {
    tcgDb()->prepare('INSERT INTO tcg_mission_counters (discord_id, counter_key, period_key, count) VALUES (?, ?, ?, ?)
        ON CONFLICT(discord_id, counter_key, period_key) DO UPDATE SET count = count + excluded.count')
        ->execute([$discordId, $key, $period, $by]);
    return tcgMissionCounterGet($discordId, $key, $period);
}

/** True the first time this (user, event) is seen — retried match-finish hooks must not double count. */
function tcgMissionEventOnce(string $discordId, string $eventKey): bool {
    $stmt = tcgDb()->prepare('INSERT OR IGNORE INTO tcg_mission_events (discord_id, event_key, created_at) VALUES (?, ?, ?)');
    $stmt->execute([$discordId, $eventKey, time()]);
    return $stmt->rowCount() > 0;
}

/** Distinct JST login days in the current Monday-Sunday week. */
function tcgMissionLoginDaysThisWeek(string $discordId): int {
    $tz = new DateTimeZone('Asia/Tokyo');
    $start = tcgMissionWeekKey();
    $end = (new DateTimeImmutable($start, $tz))->modify('+6 days')->format('Y-m-d');
    $stmt = tcgDb()->prepare('SELECT COUNT(*) FROM tcg_login_days_log WHERE discord_id = ? AND day >= ? AND day <= ?');
    $stmt->execute([$discordId, $start, $end]);
    return intval($stmt->fetchColumn() ?: 0);
}

function tcgMissionOwnedCosmeticCount(string $discordId, string $table): int {
    if (!in_array($table, ['tcg_owned_sleeves', 'tcg_owned_playmats'], true)) {
        return 0;
    }
    try {
        $stmt = tcgDb()->prepare("SELECT COUNT(*) FROM {$table} WHERE discord_id = ?");
        $stmt->execute([$discordId]);
        return intval($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
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
    $out = [[
        'id' => $missionId,
        'i18n_key' => $def['i18n_key'],
        'reward' => intval($def['reward']),
        'reward_type' => (string)($def['reward_type'] ?? 'star_gems'),
        'i18n_vars' => is_array($def['i18n_vars'] ?? null) ? $def['i18n_vars'] : null,
    ]];
    // Weekly / monthly missions chain into their "complete all" parents.
    if (($def['type'] ?? '') === 'weekly') {
        $out = tcgMissionMergeCompletions($out, tcgMissionAfterWeeklyComplete($discordId, $missionId));
    } elseif (($def['type'] ?? '') === 'monthly') {
        $out = tcgMissionMergeCompletions($out, tcgMissionAfterMonthlyComplete($discordId, $missionId));
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function tcgMissionAfterWeeklyComplete(string $discordId, string $missionId): array {
    $week = tcgMissionWeekKey();
    if ($missionId === 'weekly_complete_all') {
        // One more "all weekly missions" week toward the monthly mission (counted once, when first completed).
        $month = tcgMissionMonthKey();
        $n = tcgMissionCounterBump($discordId, 'weekly_all', $month, 1);
        if ($n >= 4) {
            return tcgMissionMarkCompleted($discordId, 'monthly_weekly_4');
        }
        return [];
    }
    foreach (TCG_MISSION_WEEKLY_SUB_IDS as $subId) {
        if (!tcgMissionIsCompleted($discordId, $subId, $week)) {
            return [];
        }
    }
    return tcgMissionMarkCompleted($discordId, 'weekly_complete_all');
}

/** @return list<array<string,mixed>> */
function tcgMissionAfterMonthlyComplete(string $discordId, string $missionId): array {
    if ($missionId === 'monthly_complete_all') {
        return [];
    }
    $month = tcgMissionMonthKey();
    foreach (TCG_MISSION_MONTHLY_SUB_IDS as $subId) {
        if (!tcgMissionIsCompleted($discordId, $subId, $month)) {
            return [];
        }
    }
    return tcgMissionMarkCompleted($discordId, 'monthly_complete_all');
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

function tcgMissionSortRank(string $status, int $sort, bool $pinnedTop = false): int {
    if ($pinnedTop && $status !== 'claimed') {
        return -1;
    }
    return match ($status) {
        'completed' => 0,
        'active' => 1,
        'claimed' => 2,
        default => 3,
    };
}

function tcgMissionClaimableCount(string $discordId): int {
    $n = 0;
    foreach (tcgMissionAllDefinitions() as $def) {
        if (tcgMissionStatusForDef($discordId, $def) === 'completed') {
            $n++;
        }
    }
    return $n;
}

function tcgMissionListForUser(string $discordId): array {
    tcgMissionBackfillRetroactive($discordId);
    if (!function_exists('tcgGetPlayStat')) {
        require_once __DIR__ . '/play_stats.php';
    }
    tcgMissionCheckPlayStatThresholds($discordId);
    $totalCards = tcgCollectionTotalCards($discordId);
    $stickerExchanges = tcgGetStickerExchanges($discordId);
    require_once __DIR__ . '/matchmaking.php';
    $rankedGames = intval((tcgRankRow($discordId)['games'] ?? 0));
    $unrankedGames = tcgGetUnrankedGames($discordId);
    $starterOptions = null;
    $missions = [];
    foreach (tcgMissionAllDefinitions() as $def) {
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
            'sort_rank' => tcgMissionSortRank($status, intval($def['sort']), !empty($def['pinned_top'])),
        ];
        if (!empty($def['i18n_vars']) && is_array($def['i18n_vars'])) {
            $entry['i18n_vars'] = $def['i18n_vars'];
        }
        if (isset($def['threshold'])) {
            $entry['threshold'] = intval($def['threshold']);
        }
        if (isset($def['coins_reward'])) {
            $entry['coins_reward'] = intval($def['coins_reward']);
        }
        if (!empty($def['rewards']) && is_array($def['rewards'])) {
            $entry['rewards'] = $def['rewards'];
        }
        $endsAt = tcgMissionPeriodEndsAt($def);
        if ($endsAt !== null) {
            $entry['period_ends_at'] = $endsAt;
        }
        $periodicProgress = tcgMissionPeriodicProgress($discordId, $def);
        if ($periodicProgress !== null) {
            $entry['progress'] = $periodicProgress;
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
        } elseif (str_starts_with($def['id'], 'ms_ranked_') && isset($def['threshold'])) {
            $entry['progress'] = min($rankedGames, intval($def['threshold']));
            $entry['ranked_games'] = $rankedGames;
        } elseif (str_starts_with($def['id'], 'ms_unranked_') && isset($def['threshold'])) {
            $entry['progress'] = min($unrankedGames, intval($def['threshold']));
            $entry['unranked_games'] = $unrankedGames;
        } elseif (str_starts_with($def['id'], 'ms_friend_') && isset($def['threshold'])) {
            if (!function_exists('tcgSocialFriendCount')) {
                require_once __DIR__ . '/social.php';
            }
            $friends = tcgSocialFriendCount($discordId);
            $entry['progress'] = min($friends, intval($def['threshold']));
            $entry['friend_count'] = $friends;
        } elseif ($def['id'] === 'ms_login_days_10' && isset($def['threshold'])) {
            require_once __DIR__ . '/sleeve_shop.php';
            $loginDays = tcgGetLoginDays($discordId);
            $entry['progress'] = min($loginDays, intval($def['threshold']));
            $entry['login_days'] = $loginDays;
        } elseif (tcgMissionIsPlayDef($def) && isset($def['threshold'])) {
            $count = tcgGetPlayStat(
                $discordId,
                (string)$def['play_tracker'],
                (string)$def['play_dim'],
                (string)$def['play_key']
            );
            $threshold = intval($def['threshold']);
            $entry['progress'] = min($count, $threshold);
            $entry['play_count'] = $count;
            // Hide untouched play milestones until the player starts them.
            if ($status === 'active' && $count < 1) {
                continue;
            }
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

    if (!empty($def['rewards']) && is_array($def['rewards'])) {
        return tcgMissionClaimRewards($discordId, $def, $periodKey);
    }

    if ($rewardType === 'free_sleeve') {
        require_once __DIR__ . '/sleeve_shop.php';
        $db->prepare('UPDATE tcg_mission_progress SET claimed_at = ? WHERE discord_id = ? AND mission_id = ? AND period_key = ?')
            ->execute([$now, $discordId, $missionId, $periodKey]);
        $have = tcgGetFreeSleeveClaims($discordId);
        tcgSetFreeSleeveClaims($discordId, $have + max(1, intval($def['reward'] ?? 1)));
        return [
            'mission' => [
                'id' => $missionId,
                'i18n_key' => $def['i18n_key'],
                'reward' => 1,
                'reward_type' => 'free_sleeve',
                'status' => 'claimed',
            ],
            'star_gems' => tcgGetStarGems($discordId),
            'star_gems_gained' => 0,
            'free_sleeve_claims' => tcgGetFreeSleeveClaims($discordId),
        ];
    }

    if ($rewardType === 'coins_and_pr_pack') {
        require_once __DIR__ . '/coins.php';
        require_once __DIR__ . '/ranked_pr_rewards.php';
        $coinsGain = max(0, intval($def['reward'] ?? 0));
        $db->prepare('UPDATE tcg_mission_progress SET claimed_at = ? WHERE discord_id = ? AND mission_id = ? AND period_key = ?')
            ->execute([$now, $discordId, $missionId, $periodKey]);
        $coins = $coinsGain > 0 ? tcgAddCoins($discordId, $coinsGain) : tcgGetCoins($discordId);
        $prPack = tcgGrantPrPackCards($discordId);
        return [
            'mission' => [
                'id' => $missionId,
                'i18n_key' => $def['i18n_key'],
                'i18n_vars' => is_array($def['i18n_vars'] ?? null) ? $def['i18n_vars'] : null,
                'reward' => $coinsGain,
                'reward_type' => 'coins_and_pr_pack',
                'status' => 'claimed',
            ],
            'star_gems' => tcgGetStarGems($discordId),
            'star_gems_gained' => 0,
            'coins' => $coins,
            'coins_gained' => $coinsGain,
            'pr_pack' => $prPack,
        ];
    }

    if ($rewardType === 'star_gems_and_coins') {
        require_once __DIR__ . '/coins.php';
        $gemsGain = max(0, intval($def['reward'] ?? 0));
        $coinsGain = max(0, intval($def['coins_reward'] ?? $def['reward'] ?? 0));
        $db->prepare('UPDATE tcg_mission_progress SET claimed_at = ? WHERE discord_id = ? AND mission_id = ? AND period_key = ?')
            ->execute([$now, $discordId, $missionId, $periodKey]);
        $gems = $gemsGain > 0 ? tcgAddStarGems($discordId, $gemsGain) : tcgGetStarGems($discordId);
        $coins = $coinsGain > 0 ? tcgAddCoins($discordId, $coinsGain) : tcgGetCoins($discordId);
        return [
            'mission' => [
                'id' => $missionId,
                'i18n_key' => $def['i18n_key'],
                'i18n_vars' => is_array($def['i18n_vars'] ?? null) ? $def['i18n_vars'] : null,
                'reward' => $gemsGain,
                'coins_reward' => $coinsGain,
                'reward_type' => 'star_gems_and_coins',
                'status' => 'claimed',
            ],
            'star_gems' => $gems,
            'star_gems_gained' => $gemsGain,
            'coins' => $coins,
            'coins_gained' => $coinsGain,
        ];
    }

    if ($rewardType === 'coins') {
        require_once __DIR__ . '/coins.php';
        $coinsGain = max(0, intval($def['reward'] ?? 0));
        $db->prepare('UPDATE tcg_mission_progress SET claimed_at = ? WHERE discord_id = ? AND mission_id = ? AND period_key = ?')
            ->execute([$now, $discordId, $missionId, $periodKey]);
        $coins = $coinsGain > 0 ? tcgAddCoins($discordId, $coinsGain) : tcgGetCoins($discordId);
        return [
            'mission' => [
                'id' => $missionId,
                'i18n_key' => $def['i18n_key'],
                'i18n_vars' => is_array($def['i18n_vars'] ?? null) ? $def['i18n_vars'] : null,
                'reward' => $coinsGain,
                'reward_type' => 'coins',
                'status' => 'claimed',
            ],
            'star_gems' => tcgGetStarGems($discordId),
            'star_gems_gained' => 0,
            'coins' => $coins,
            'coins_gained' => $coinsGain,
        ];
    }

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

/** Progress toward a weekly / monthly / ownership / scouting mission, or null when it has none. */
function tcgMissionPeriodicProgress(string $discordId, array $def): ?int {
    $threshold = isset($def['threshold']) ? intval($def['threshold']) : null;
    $cap = static fn(int $n): int => $threshold !== null ? min($n, $threshold) : $n;
    switch ($def['id']) {
        case 'weekly_ranked_10':
            return $cap(tcgMissionCounterGet($discordId, 'ranked', tcgMissionWeekKey()));
        case 'monthly_ranked_30':
            return $cap(tcgMissionCounterGet($discordId, 'ranked', tcgMissionMonthKey()));
        case 'weekly_login_7':
            return $cap(tcgMissionLoginDaysThisWeek($discordId));
        case 'monthly_weekly_4':
            return $cap(tcgMissionCounterGet($discordId, 'weekly_all', tcgMissionMonthKey()));
        case 'weekly_complete_all':
            $week = tcgMissionWeekKey();
            $n = 0;
            foreach (TCG_MISSION_WEEKLY_SUB_IDS as $subId) {
                if (tcgMissionIsCompleted($discordId, $subId, $week)) {
                    $n++;
                }
            }
            return $cap($n);
        case 'monthly_complete_all':
            $month = tcgMissionMonthKey();
            $n = 0;
            foreach (TCG_MISSION_MONTHLY_SUB_IDS as $subId) {
                if (tcgMissionIsCompleted($discordId, $subId, $month)) {
                    $n++;
                }
            }
            return $cap($n);
        case 'ms_sleeves_10':
            return $cap(tcgMissionOwnedCosmeticCount($discordId, 'tcg_owned_sleeves'));
        case 'ms_playmats_10':
            return $cap(tcgMissionOwnedCosmeticCount($discordId, 'tcg_owned_playmats'));
        case 'ms_gacha_scout_1':
        case 'ms_gacha_scout_10':
            return $cap(tcgMissionCounterGet($discordId, 'gacha_scouts', ''));
    }
    return null;
}

/**
 * Grant every item of a multi-reward mission (gems / coins / Scouting Tickets / SR+UR tickets / seals).
 * The claim row is flipped first with an "unclaimed" guard so a double click cannot pay twice.
 *
 * @return array<string,mixed>
 */
function tcgMissionClaimRewards(string $discordId, array $def, string $periodKey): array {
    require_once __DIR__ . '/coins.php';
    require_once __DIR__ . '/seals.php';
    $db = tcgDb();
    $upd = $db->prepare('UPDATE tcg_mission_progress SET claimed_at = ?
        WHERE discord_id = ? AND mission_id = ? AND period_key = ? AND claimed_at IS NULL');
    $upd->execute([time(), $discordId, $def['id'], $periodKey]);
    if ($upd->rowCount() < 1) {
        throw new Exception('Mission reward already claimed');
    }
    $gemsGain = 0;
    $coinsGain = 0;
    $granted = [];
    foreach ($def['rewards'] as $item) {
        $type = (string)($item['type'] ?? '');
        $amount = max(0, intval($item['amount'] ?? 0));
        if ($amount < 1) {
            continue;
        }
        switch ($type) {
            case 'gems':
                tcgAddStarGems($discordId, $amount);
                $gemsGain += $amount;
                break;
            case 'coins':
                tcgAddCoins($discordId, $amount);
                $coinsGain += $amount;
                break;
            case 'tickets':
                tcgAddScoutingTickets($discordId, $amount);
                break;
            case 'ticket_sr':
                tcgAddScoutingTierTickets($discordId, 'sr', $amount);
                break;
            case 'ticket_ur':
                tcgAddScoutingTierTickets($discordId, 'ur', $amount);
                break;
            case 'seal':
                tcgAddSeals($discordId, (string)($item['tier'] ?? 'N'), $amount);
                break;
            default:
                continue 2;
        }
        $granted[] = ['type' => $type, 'amount' => $amount] + (isset($item['tier']) ? ['tier' => (string)$item['tier']] : []);
    }
    return [
        'mission' => [
            'id' => $def['id'],
            'i18n_key' => $def['i18n_key'],
            'i18n_vars' => is_array($def['i18n_vars'] ?? null) ? $def['i18n_vars'] : null,
            'reward' => intval($def['reward'] ?? 0),
            'reward_type' => 'multi',
            'rewards' => $granted,
            'status' => 'claimed',
        ],
        'rewards' => $granted,
        'star_gems' => tcgGetStarGems($discordId),
        'star_gems_gained' => $gemsGain,
        'coins' => tcgGetCoins($discordId),
        'coins_gained' => $coinsGain,
        'scouting_tickets' => tcgGetScoutingTickets($discordId),
        'scouting_tickets_sr' => tcgGetScoutingTierTickets($discordId, 'sr'),
        'scouting_tickets_ur' => tcgGetScoutingTierTickets($discordId, 'ur'),
        'seals' => tcgSealBalances($discordId),
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
    $matched = 0;
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
        $matched++;
    }
    return $matched > 0;
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

/** Mark friend-count milestones complete when thresholds are met. */
function tcgMissionCheckFriendCount(string $discordId): array {
    if (!function_exists('tcgSocialFriendCount')) {
        require_once __DIR__ . '/social.php';
    }
    $friends = tcgSocialFriendCount($discordId);
    $completions = [];
    foreach (tcgMissionDefinitions() as $def) {
        if (($def['type'] ?? '') !== 'milestone' || !str_starts_with($def['id'], 'ms_friend_')) {
            continue;
        }
        $threshold = intval($def['threshold'] ?? 0);
        if ($threshold > 0 && $friends >= $threshold) {
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

/** Best-effort diagnostics: the finish hook is best-effort, so failures used to vanish silently. */
function tcgMissionLogFailure(string $where, Throwable $e): void {
    $line = gmdate('c') . ' [missions] ' . $where . ': ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL;
    @error_log(trim($line));
    if (defined('TCG_DATA_DIR')) {
        $file = rtrim((string)TCG_DATA_DIR, '/\\') . '/mission_errors.log';
        if (!is_file($file) || (int)@filesize($file) < 200000) {
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        }
    }
}

/**
 * Credit finish missions for both seats.
 *
 * Each seat and each check is isolated: one failure (a locked database, a bad snapshot) used to
 * abort the whole loop, so the second seat lost its "Play a ranked match" credit while rank
 * points (applied earlier, elsewhere) still counted. The core ranked/unranked credit for both
 * seats runs first, with a lock retry, before any milestone check.
 *
 * @return list<array{id: string, i18n_key: string, reward: int}>
 */
function tcgMissionOnGameFinished(array $state): array {
    if (($state['status'] ?? '') !== 'finished') {
        return [];
    }
    $isRanked = ($state['mode'] ?? '') === 'ranked';
    $winner = $state['winner'] ?? null;
    $completions = [];
    $safe = static function (string $where, callable $fn) use (&$completions): void {
        try {
            $completions = tcgMissionMergeCompletions($completions, $fn());
        } catch (Throwable $e) {
            tcgMissionLogFailure($where, $e);
        }
    };
    if ($isRanked) {
        try {
            require_once __DIR__ . '/season_stats.php';
            tcgSeasonStatsRecordMatch($state);
        } catch (Throwable $e) {
            // Usage sheet is best-effort; never block mission credit.
        }
    }
    $seats = [];
    foreach (['p1', 'p2'] as $pid) {
        $player = $state['players'][$pid] ?? null;
        if (tcgMissionSeatIsCpu(is_array($player) ? $player : null)) {
            continue;
        }
        $discordId = tcgPlayerDiscordId($state, $pid);
        if ($discordId) {
            $seats[$pid] = $discordId;
        }
    }
    // Pass 1 — the credit every finished match owes each human seat.
    foreach ($seats as $pid => $discordId) {
        $safe("core $pid", static function () use ($isRanked, $discordId) {
            return tcgDbRetry(static function () use ($isRanked, $discordId) {
                if ($isRanked) {
                    return tcgMissionMergeCompletions(
                        tcgMissionMarkCompleted($discordId, 'daily_ranked_match'),
                        tcgMissionTryCompleteAllDaily($discordId)
                    );
                }
                tcgIncrementUnrankedGames($discordId);
                return [];
            });
        });
    }
    // Pass 1b — weekly / monthly credit (counters are per room, so retried hooks never double count).
    foreach ($seats as $pid => $discordId) {
        if ($isRanked) {
            $safe("ranked periodic $pid", static fn() => tcgMissionOnRankedFinished($discordId, $state));
        } elseif (($state['mode'] ?? '') === 'tournament') {
            $safe("tournament $pid", static fn() => tcgMissionMarkCompleted($discordId, 'monthly_tournament'));
        }
    }
    // Pass 2 — milestones, each check on its own.
    foreach ($seats as $pid => $discordId) {
        $safe("ranked thresholds $pid", static fn() => tcgMissionCheckRankedThresholds($discordId));
        $safe("score peaks $pid", static fn() => tcgMissionCheckScorePeaks($discordId, $state, $pid));
        $safe("play stats $pid", static fn() => tcgMissionCheckPlayStatThresholds($discordId));
        if ($winner === $pid) {
            $safe("group win $pid", static fn() => tcgMissionCheckGroupWin($discordId, $state, $pid));
            $safe("turn win $pid", static fn() => tcgMissionCheckTurnWin($discordId, $state));
        }
    }
    return $completions;
}

/** Mark play-stat milestones complete when idol / Live unit / subunit thresholds are met. */
function tcgMissionCheckPlayStatThresholds(string $discordId): array {
    if (!function_exists('tcgGetPlayStat')) {
        require_once __DIR__ . '/play_stats.php';
    }
    $counts = [];
    foreach ([TCG_PLAY_TRACKER_STAGE, TCG_PLAY_TRACKER_LIVE_SUCCESS] as $tracker) {
        foreach (tcgListPlayStats($discordId, $tracker) as $row) {
            $counts[$tracker . "\0" . $row['dim'] . "\0" . $row['key']] = intval($row['count']);
        }
    }
    if ($counts === []) {
        return [];
    }
    $completions = [];
    foreach (tcgMissionPlayDefinitions() as $def) {
        if (!tcgMissionIsPlayDef($def)) {
            continue;
        }
        $threshold = intval($def['threshold'] ?? 0);
        if ($threshold < 1) {
            continue;
        }
        $ck = (string)$def['play_tracker'] . "\0" . (string)$def['play_dim'] . "\0" . (string)$def['play_key'];
        $count = intval($counts[$ck] ?? 0);
        if ($count >= $threshold) {
            $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, $def['id']));
        }
    }
    return $completions;
}

/** Mark turn-number win milestones (e.g. win while game turn === 3). */
function tcgMissionCheckTurnWin(string $discordId, array $state): array {
    $turn = intval($state['turn'] ?? 0);
    $completions = [];
    foreach (tcgMissionDefinitions() as $def) {
        if (($def['type'] ?? '') !== 'milestone' || ($def['id'] ?? '') !== 'ms_win_turn_3') {
            continue;
        }
        $need = intval($def['threshold'] ?? 3);
        if ($turn === $need) {
            $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, $def['id']));
        }
    }
    return $completions;
}

/**
 * Yell-card / Live-score peak milestones from room `_mission_peaks` (or nested mission_peaks).
 * `yell` is the most Yell cards revealed in a single Live performance; `live` is peak Live score.
 *
 * @return list<array{id: string, i18n_key: string, reward: int}>
 */
function tcgMissionCheckScorePeaks(string $discordId, array $state, string $playerId): array {
    $peaks = $state['_mission_peaks'][$playerId] ?? ($state['mission_peaks'][$playerId] ?? null);
    if (!is_array($peaks)) {
        $peaks = [];
    }
    $yell = intval($peaks['yell'] ?? 0);
    $live = intval($peaks['live'] ?? 0);
    $completions = [];
    foreach (tcgMissionDefinitions() as $def) {
        if (($def['type'] ?? '') !== 'milestone') {
            continue;
        }
        $id = (string)($def['id'] ?? '');
        $need = intval($def['threshold'] ?? 0);
        if ($need <= 0) {
            continue;
        }
        if ($id === 'ms_yell_score_20' && $yell >= $need) {
            $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, $id));
        }
        if ($id === 'ms_live_score_15' && $live >= $need) {
            $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, $id));
        }
    }
    return $completions;
}

/**
 * Ranked finish: weekly Random-decks credit, weekly/monthly ranked match counters, Pink C check.
 *
 * @return list<array<string,mixed>>
 */
function tcgMissionOnRankedFinished(string $discordId, array $state): array {
    $roomId = (string)($state['room_id'] ?? '');
    $isNew = $roomId === '' ? true : tcgMissionEventOnce($discordId, 'ranked_room:' . $roomId);
    if ($isNew) {
        tcgMissionCounterBump($discordId, 'ranked', tcgMissionWeekKey(), 1);
        tcgMissionCounterBump($discordId, 'ranked', tcgMissionMonthKey(), 1);
    }
    if (!function_exists('tcgNormalizeGameMode')) {
        require_once __DIR__ . '/game_mode.php';
    }
    $gameMode = tcgNormalizeGameMode($state['ranked']['game_mode'] ?? ($state['game_mode'] ?? ''));
    $completions = [];
    if ($gameMode === TCG_GAME_MODE_RANDOMIZED) {
        $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, 'weekly_ranked_random'));
    }
    // Weekly: a win in any ranked mode (a resign or disconnect by the opponent is still a win).
    foreach (['p1', 'p2'] as $pid) {
        if (($state['winner'] ?? null) === $pid && tcgPlayerDiscordId($state, $pid) === $discordId) {
            $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, 'weekly_ranked_win'));
            break;
        }
    }
    $completions = tcgMissionMergeCompletions($completions, tcgMissionCheckRankedPeriodic($discordId));
    $completions = tcgMissionMergeCompletions($completions, tcgMissionCheckSeasonRank($discordId));
    return $completions;
}

/** Weekly 10 / monthly 30 ranked match missions from the period counters. */
function tcgMissionCheckRankedPeriodic(string $discordId): array {
    $completions = [];
    if (tcgMissionCounterGet($discordId, 'ranked', tcgMissionWeekKey()) >= 10) {
        $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, 'weekly_ranked_10'));
    }
    if (tcgMissionCounterGet($discordId, 'ranked', tcgMissionMonthKey()) >= 30) {
        $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, 'monthly_ranked_30'));
    }
    return $completions;
}

/** Monthly: reach Pink C (or higher) on the Standard ladder in the current season. */
function tcgMissionCheckSeasonRank(string $discordId): array {
    if (!function_exists('tcgSeasonLoadRow')) {
        require_once __DIR__ . '/season.php';
    }
    $clock = tcgSeasonClockInfo();
    if (empty($clock['active'])) {
        return [];
    }
    $row = tcgSeasonLoadRow($discordId, TCG_GAME_MODE_STANDARD);
    if (!$row || (string)($row['season_id'] ?? '') !== (string)$clock['id']) {
        return [];
    }
    if (max(intval($row['step'] ?? 0), intval($row['peak_step'] ?? 0)) < 1) {
        return [];
    }
    return tcgMissionMarkCompleted($discordId, 'monthly_pink_c');
}

/** Weekly login mission: record today's JST day and check the week. */
function tcgMissionOnLoginDay(string $discordId): array {
    tcgDb()->prepare('INSERT OR IGNORE INTO tcg_login_days_log (discord_id, day) VALUES (?, ?)')
        ->execute([$discordId, tcgTodayJst()]);
    if (tcgMissionLoginDaysThisWeek($discordId) >= TCG_MISSION_WEEKLY_LOGIN_DAYS) {
        return tcgMissionMarkCompleted($discordId, 'weekly_login_7');
    }
    return [];
}

/** Sleeve / playmat ownership milestones. */
function tcgMissionCheckOwnedCosmetics(string $discordId): array {
    $completions = [];
    if (tcgMissionOwnedCosmeticCount($discordId, 'tcg_owned_sleeves') >= 10) {
        $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, 'ms_sleeves_10'));
    }
    if (tcgMissionOwnedCosmeticCount($discordId, 'tcg_owned_playmats') >= 10) {
        $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, 'ms_playmats_10'));
    }
    return $completions;
}

/** Bought a sleeve or playmat in the shop: ownership milestones. */
function tcgMissionOnShopPurchase(string $discordId): array {
    return tcgMissionCheckOwnedCosmetics($discordId);
}

/** Exchanged seals for a card in the Sticker Shop: the lifetime exchange milestones. */
function tcgMissionOnStickerExchange(string $discordId): array {
    return tcgMissionCheckStickerExchangeThresholds($discordId);
}

/** One Gacha Scouting action (any banner); $birthday = it was a limited birthday banner. */
function tcgMissionOnGachaScout(string $discordId, bool $birthday): array {
    $n = tcgMissionCounterBump($discordId, 'gacha_scouts', '', 1);
    $completions = tcgMissionMarkCompleted($discordId, 'ms_gacha_scout_1');
    if ($n >= 10) {
        $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, 'ms_gacha_scout_10'));
    }
    if ($birthday) {
        $completions = tcgMissionMergeCompletions($completions, tcgMissionMarkCompleted($discordId, 'ms_gacha_birthday'));
    }
    return $completions;
}

/**
 * Credit what the existing history already proves for the CURRENT week / month, once per user per month:
 * completed ranked matches (counters, Random-decks credit), a tournament played this month, and the last
 * recorded login day. Every match is recorded with the
 * same per-room marker as the live hook, so a retried finish can never count the same room twice.
 * Not recoverable (no history is stored): sticker-shop exchanges, gacha scouts and earlier login days.
 */
function tcgMissionBackfillPeriodHistory(string $discordId): void {
    $month = tcgMissionMonthKey();
    $marker = 'retro_period:' . $month;
    if (!tcgMissionEventOnce($discordId, $marker)) {
        return;
    }
    try {
        $db = tcgDb();
        $tz = new DateTimeZone('Asia/Tokyo');
        $now = tcgMissionNow();
        $monthStart = gmmktime(0, 0, 0, (int)gmdate('n', $now), 1, (int)gmdate('Y', $now));
        $monthEnd = tcgMissionMonthEndsAt($now);
        $weekKey = tcgMissionWeekKey();
        $weekStart = (new DateTimeImmutable($weekKey, $tz))->getTimestamp();
        $weekEnd = tcgMissionWeekEndsAt();

        // Ranked matches played this month (this week's share feeds the weekly counter).
        $stmt = $db->prepare('SELECT room_id, game_mode, created_at FROM tcg_ranked_matches
            WHERE status = "done" AND (p1_id = ? OR p2_id = ?) AND created_at >= ? AND created_at < ?');
        $stmt->execute([$discordId, $discordId, $monthStart, $monthEnd]);
        $randomThisWeek = false;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $room = (string)($row['room_id'] ?? '');
            $at = intval($row['created_at'] ?? 0);
            $inWeek = $at >= $weekStart && $at < $weekEnd;
            if ($room !== '' && tcgMissionEventOnce($discordId, 'ranked_room:' . $room)) {
                tcgMissionCounterBump($discordId, 'ranked', $month, 1);
                if ($inWeek) {
                    tcgMissionCounterBump($discordId, 'ranked', $weekKey, 1);
                }
            }
            if ($inWeek && strtolower((string)($row['game_mode'] ?? '')) === 'randomized') {
                $randomThisWeek = true;
            }
        }
        if ($randomThisWeek) {
            tcgMissionMarkCompleted($discordId, 'weekly_ranked_random');
        }

        // Tournament played this month (registered-only and no-show entrants do not count).
        try {
            $t = $db->prepare('SELECT 1 FROM tcg_tournament_entrants e
                JOIN tcg_tournaments tt ON tt.id = e.tournament_id
                WHERE e.discord_id = ? AND tt.start_at >= ? AND tt.start_at < ?
                  AND e.status NOT IN ("registered", "dq")
                  AND COALESCE(e.elim_reason, "") != "afk_forfeit" LIMIT 1');
            $t->execute([$discordId, $monthStart, $monthEnd]);
            if ($t->fetchColumn()) {
                tcgMissionMarkCompleted($discordId, 'monthly_tournament');
            }
        } catch (Throwable $e) {
            // Tournament tables / elim_reason may not exist on every database.
        }

        // Last recorded login day, when it falls inside this week.
        $l = $db->prepare('SELECT login_days_last_date FROM tcg_users WHERE discord_id = ?');
        $l->execute([$discordId]);
        $last = (string)($l->fetchColumn() ?: '');
        $weekEndDay = (new DateTimeImmutable($weekKey, $tz))->modify('+6 days')->format('Y-m-d');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $last) && $last >= $weekKey && $last <= $weekEndDay) {
            $db->prepare('INSERT OR IGNORE INTO tcg_login_days_log (discord_id, day) VALUES (?, ?)')
                ->execute([$discordId, $last]);
        }
    } catch (Throwable $e) {
        // Let the next call retry the whole pass.
        try {
            tcgDb()->prepare('DELETE FROM tcg_mission_events WHERE discord_id = ? AND event_key = ?')
                ->execute([$discordId, $marker]);
        } catch (Throwable $e2) {
            // ignore
        }
        tcgMissionLogFailure('period history backfill', $e);
    }
}

/** Catch-up for anything that may have been earned outside a hooked action. */
function tcgMissionCheckPeriodic(string $discordId): void {
    tcgMissionBackfillPeriodHistory($discordId);
    try {
        tcgMissionCheckRankedPeriodic($discordId);
        tcgMissionCheckSeasonRank($discordId);
        tcgMissionCheckOwnedCosmetics($discordId);
        if (tcgMissionLoginDaysThisWeek($discordId) >= TCG_MISSION_WEEKLY_LOGIN_DAYS) {
            tcgMissionMarkCompleted($discordId, 'weekly_login_7');
        }
        // A ranked win earlier this week (any mode) that the finish hook never saw.
        if (!tcgMissionIsCompleted($discordId, 'weekly_ranked_win', tcgMissionWeekKey())) {
            $weekStart = (new DateTimeImmutable(tcgMissionWeekKey(), new DateTimeZone('Asia/Tokyo')))->getTimestamp();
            $w = tcgDb()->prepare('SELECT 1 FROM tcg_ranked_matches WHERE status = "done" AND created_at >= ? AND created_at < ?
                AND ((p1_id = ? AND winner_pid = "p1") OR (p2_id = ? AND winner_pid = "p2")) LIMIT 1');
            $w->execute([$weekStart, tcgMissionWeekEndsAt(), $discordId, $discordId]);
            if ($w->fetchColumn()) {
                tcgMissionMarkCompleted($discordId, 'weekly_ranked_win');
            }
        }
        // The weekly set changed: someone who already finished all of them gets the bonus: someone who already finished all of them gets the bonus.
        $week = tcgMissionWeekKey();
        $allWeekly = true;
        foreach (TCG_MISSION_WEEKLY_SUB_IDS as $subId) {
            if (!tcgMissionIsCompleted($discordId, $subId, $week)) {
                $allWeekly = false;
                break;
            }
        }
        if ($allWeekly) {
            tcgMissionMarkCompleted($discordId, 'weekly_complete_all');
        }
        if (tcgMissionCounterGet($discordId, 'gacha_scouts', '') >= 10) {
            tcgMissionMarkCompleted($discordId, 'ms_gacha_scout_10');
        }
        if (tcgMissionCounterGet($discordId, 'gacha_scouts', '') >= 1) {
            tcgMissionMarkCompleted($discordId, 'ms_gacha_scout_1');
        }
    } catch (Throwable $e) {
        tcgMissionLogFailure('periodic', $e);
    }
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

/** Season 1 feedback form submitted: completes the temporary Season 1 milestone. */
function tcgMissionOnSeasonFeedback(string $discordId): array {
    return tcgMissionMarkCompleted($discordId, 'ms_season_feedback_2026_10');
}

function tcgMissionBackfillSeasonFeedback(string $discordId): void {
    if (!tcgMissionDefById('ms_season_feedback_2026_10')) {
        return;
    }
    if (!function_exists('tcgSeasonStatsEnsureSchema')) {
        require_once __DIR__ . '/season_stats.php';
    }
    $db = tcgDb();
    tcgSeasonStatsEnsureSchema($db);
    $q = $db->prepare('SELECT 1 FROM tcg_season_feedback WHERE season_id = ? AND discord_id = ?');
    $q->execute(['2026-10', $discordId]);
    if ($q->fetchColumn()) {
        tcgMissionOnSeasonFeedback($discordId);
    }
}

function tcgMissionBackfillRetroactive(string $discordId): void {
    static $ran = [];
    if (isset($ran[$discordId])) {
        return;
    }
    $ran[$discordId] = true;
    tcgMissionBackfillSeasonFeedback($discordId);

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

    if (!function_exists('tcgSocialFriendCount')) {
        require_once __DIR__ . '/social.php';
    }
    $friendCount = tcgSocialFriendCount($discordId);
    foreach (tcgMissionDefinitions() as $def) {
        if (($def['type'] ?? '') !== 'milestone' || !str_starts_with($def['id'], 'ms_friend_')) {
            continue;
        }
        $threshold = intval($def['threshold'] ?? 0);
        if ($threshold > 0 && $friendCount >= $threshold) {
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

    require_once __DIR__ . '/sleeve_shop.php';
    tcgTouchLoginDays($discordId);
    tcgMissionCheckLoginDays($discordId);
    tcgMissionCheckPeriodic($discordId);
}

/** Mark login-days milestone when threshold met. */
function tcgMissionCheckLoginDays(string $discordId): array {
    require_once __DIR__ . '/sleeve_shop.php';
    $days = tcgGetLoginDays($discordId);
    if ($days < 10) {
        return [];
    }
    return tcgMissionMarkCompleted($discordId, 'ms_login_days_10');
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
        'weekly_period' => tcgMissionWeekKey(),
        'weekly_ends_at' => tcgMissionWeekEndsAt(),
        'monthly_period' => tcgMissionMonthKey(),
        'monthly_ends_at' => tcgMissionMonthEndsAt(),
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
    $rewardType = (string)($def['reward_type'] ?? 'star_gems');
    $gemsClawed = 0;
    if ($wasClaimed && $reward > 0 && $rewardType === 'star_gems') {
        $before = tcgGetStarGems($discordId);
        tcgSoftClawbackStarGems($discordId, $reward);
        $gemsClawed = max(0, $before - tcgGetStarGems($discordId));
    } elseif ($wasClaimed && $reward > 0 && $rewardType === 'coins_and_pr_pack') {
        require_once __DIR__ . '/coins.php';
        try {
            tcgDeductCoins($discordId, min($reward, tcgGetCoins($discordId)));
        } catch (Throwable $e) {
            // Soft clawback: leave balance alone if deduct fails.
        }
    } elseif ($wasClaimed && $rewardType === 'multi') {
        $gems = 0;
        $coinsBack = 0;
        foreach ($def['rewards'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'gems') {
                $gems += max(0, intval($item['amount'] ?? 0));
            } elseif (($item['type'] ?? '') === 'coins') {
                $coinsBack += max(0, intval($item['amount'] ?? 0));
            }
        }
        if ($gems > 0) {
            $before = tcgGetStarGems($discordId);
            tcgSoftClawbackStarGems($discordId, $gems);
            $gemsClawed = max(0, $before - tcgGetStarGems($discordId));
        }
        if ($coinsBack > 0) {
            require_once __DIR__ . '/coins.php';
            try {
                tcgDeductCoins($discordId, min($coinsBack, tcgGetCoins($discordId)));
            } catch (Throwable $e) {
                // Soft clawback: leave balance alone if deduct fails.
            }
        }
    } elseif ($wasClaimed && $rewardType === 'star_gems_and_coins') {
        if ($reward > 0) {
            $before = tcgGetStarGems($discordId);
            tcgSoftClawbackStarGems($discordId, $reward);
            $gemsClawed = max(0, $before - tcgGetStarGems($discordId));
        }
        require_once __DIR__ . '/coins.php';
        $coinsGain = max(0, intval($def['coins_reward'] ?? $reward));
        try {
            tcgDeductCoins($discordId, min($coinsGain, tcgGetCoins($discordId)));
        } catch (Throwable $e) {
            // Soft clawback: leave balance alone if deduct fails.
        }
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

