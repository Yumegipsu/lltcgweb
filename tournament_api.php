<?php
/**
 * Tournament Mode v1 — CRUD / register / check-in / moderation APIs.
 */
require_once __DIR__ . '/tournament_lib.php';
require_once __DIR__ . '/chat_moderation.php';

/** @param array<string,mixed> $body */
function tcgApiTournamentEnabled(array $body = []): array {
    // Touch DB so once-migrations (tournament tables) apply even before auth.
    try {
        tcgDb();
    } catch (Throwable $e) { /* ignore */ }
    $uid = null;
    try {
        $uid = tcgRequireAuthUser($body);
    } catch (Throwable $e) {
        $uid = null;
    }
    return ['success' => true, 'enabled' => tcgUserMayUseTournaments($uid)];
}

/**
 * Lightweight hub preview: upcoming events with whether the viewer entered.
 * Client picks joined countdown vs day-of promo.
 *
 * @param array<string,mixed> $body
 */
function tcgApiTournamentHub(array $body = []): array {
    $now = time();
    $uid = null;
    try {
        $uid = tcgRequireAuthUser($body);
    } catch (Throwable $e) {
        return ['success' => true, 'enabled' => false, 'upcoming' => [], 'server_now' => $now];
    }
    if (!tcgUserMayUseTournaments($uid)) {
        return ['success' => true, 'enabled' => false, 'upcoming' => [], 'server_now' => $now];
    }
    $stmt = tcgDb()->prepare(
        'SELECT t.id, t.title, t.status, t.start_at, t.prize_pool_coins, t.checkin_mins,
                (SELECT COUNT(*) FROM tcg_tournament_entrants e WHERE e.tournament_id = t.id) AS entrant_count,
                (SELECT 1 FROM tcg_tournament_entrants e2
                  WHERE e2.tournament_id = t.id AND e2.discord_id = ?
                    AND e2.status IN ("registered","checked_in","playing","eliminated","winner")
                  LIMIT 1) AS i_am_entrant
         FROM tcg_tournaments t
         WHERE t.status IN ("open","checkin","running")
         ORDER BY t.start_at ASC
         LIMIT 40'
    );
    $stmt->execute([$uid]);
    $upcoming = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $upcoming[] = [
            'id' => (string)$row['id'],
            'title' => (string)$row['title'],
            'status' => (string)$row['status'],
            'start_at' => (int)$row['start_at'],
            'checkin_mins' => (int)$row['checkin_mins'],
            'prize_pool_coins' => (int)$row['prize_pool_coins'],
            'entrant_count' => (int)($row['entrant_count'] ?? 0),
            'i_am_entrant' => !empty($row['i_am_entrant']),
        ];
    }
    return [
        'success' => true,
        'enabled' => true,
        'upcoming' => $upcoming,
        'server_now' => $now,
    ];
}

/** @param array<string,mixed> $body */
function tcgApiTournamentList(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $status = trim((string)($body['status'] ?? $_GET['status'] ?? ''));
    $gameMode = trim((string)($body['game_mode'] ?? $_GET['game_mode'] ?? ''));
    $now = time();
    $sql = 'SELECT t.*,
            (SELECT COUNT(*) FROM tcg_tournament_entrants e WHERE e.tournament_id = t.id) AS entrant_count,
            (SELECT COUNT(*) FROM tcg_tournament_entrants e
             WHERE e.tournament_id = t.id AND e.status IN ("checked_in","playing","eliminated","winner")) AS checked_in_count
            FROM tcg_tournaments t WHERE 1=1';
    $params = [];
    if ($status !== '') {
        $sql .= ' AND t.status = ?';
        $params[] = $status;
    } else {
        $sql .= ' AND t.status IN ("open","checkin","running")';
    }
    if ($gameMode !== '') {
        $sql .= ' AND t.game_mode = ?';
        $params[] = tcgNormalizeGameMode($gameMode);
    }
    $sql .= ' ORDER BY t.start_at ASC LIMIT 100';
    $stmt = tcgDb()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $list = [];
    foreach ($rows as $row) {
        $pub = tcgTournamentPublicRow($row, [
            'total' => (int)($row['entrant_count'] ?? 0),
            'checked_in' => (int)($row['checked_in_count'] ?? 0),
        ]);
        $pub['checkin_opens_at'] = (int)$row['start_at'] - ((int)$row['checkin_mins'] * 60);
        $pub['server_now'] = $now;
        $pub['spectator_count'] = tcgTournamentSpectatorCount((string)$row['id']);
        $list[] = $pub;
    }
    if (function_exists('tcgPushDispatchTournamentStartReminders')) {
        tcgPushDispatchTournamentStartReminders();
    }
    $offsetMap = function_exists('tcgPushTournamentStartOffsetsForUser')
        ? tcgPushTournamentStartOffsetsForUser($uid, array_map(static fn($r) => (string)($r['id'] ?? ''), $list))
        : [];
    foreach ($list as &$pub) {
        $tid = strtoupper((string)($pub['id'] ?? ''));
        $pub['start_reminder_offsets'] = $offsetMap[$tid] ?? [];
    }
    unset($pub);
    $featured = tcgTournamentPickBulletinFeatured($list);
    if ($featured !== null) {
        $fid = strtoupper((string)($featured['id'] ?? ''));
        foreach ($list as &$pub) {
            if (strtoupper((string)($pub['id'] ?? '')) === $fid) {
                tcgTournamentEnrichBulletinFeatured($pub);
                break;
            }
        }
        unset($pub);
    }
    $past = [];
    if ($status === '') {
        $pastSql = 'SELECT t.*,
            (SELECT COUNT(*) FROM tcg_tournament_entrants e WHERE e.tournament_id = t.id) AS entrant_count,
            (SELECT COUNT(*) FROM tcg_tournament_entrants e
             WHERE e.tournament_id = t.id AND e.status IN ("checked_in","playing","eliminated","winner")) AS checked_in_count
            FROM tcg_tournaments t WHERE t.status = "finished"';
        $pastParams = [];
        if ($gameMode !== '') {
            $pastSql .= ' AND t.game_mode = ?';
            $pastParams[] = tcgNormalizeGameMode($gameMode);
        }
        $pastSql .= ' ORDER BY t.updated_at DESC LIMIT 40';
        $pastStmt = tcgDb()->prepare($pastSql);
        $pastStmt->execute($pastParams);
        foreach ($pastStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $tidPast = (string)$row['id'];
            tcgTournamentRepairFinishedEntrantStatuses($tidPast, $row);
            $pub = tcgTournamentPublicRow($row, [
                'total' => (int)($row['entrant_count'] ?? 0),
                'checked_in' => (int)($row['checked_in_count'] ?? 0),
            ]);
            $pub['server_now'] = $now;
            $ents = tcgTournamentFetchEntrants($tidPast);
            $pub['entrants'] = array_map(
                static fn($e) => [
                    'discord_id' => (string)$e['discord_id'],
                    'username' => (string)($e['username'] ?? 'Player'),
                    'avatar_url' => $e['avatar_url'] ?? null,
                    'status' => (string)($e['status'] ?? ''),
                ],
                $ents
            );
            $past[] = $pub;
        }
    }
    return [
        'success' => true,
        'tournaments' => $list,
        'past_tournaments' => $past,
        'server_now' => $now,
    ];
}

/** @param array<string,mixed> $body */
function tcgApiTournamentGet(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? $_GET['tournament_id'] ?? '')));
    if ($id === '') {
        throw new Exception('tournament_id required', 400);
    }
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    if ((string)($row['status'] ?? '') === 'finished') {
        tcgTournamentRepairFinishedEntrantStatuses($id, $row);
        if (function_exists('tcgTournamentRetryMissingReplays')) {
            // Attach archive ids into match meta / public games for past-bracket Watch buttons.
            tcgTournamentRetryMissingReplays($id);
            $matches = tcgTournamentFetchMatches($id);
        }
    }
    $entrants = tcgTournamentFetchEntrants($id);
    if (!isset($matches)) {
        $matches = tcgTournamentFetchMatches($id);
    }
    $me = null;
    foreach ($entrants as $e) {
        if ((string)$e['discord_id'] === $uid) {
            $me = tcgTournamentPublicEntrant($e, true);
            break;
        }
    }
    $checked = 0;
    foreach ($entrants as $e) {
        if (in_array((string)$e['status'], ['checked_in', 'playing', 'eliminated', 'winner'], true)) {
            $checked++;
        }
    }
    $pub = tcgTournamentPublicRow($row, ['total' => count($entrants), 'checked_in' => $checked]);
    $pub['checkin_opens_at'] = (int)$row['start_at'] - ((int)$row['checkin_mins'] * 60);
    $pub['spectator_count'] = tcgTournamentSpectatorCount($id);
    if (function_exists('tcgPushTournamentStartOffsetsForUser')) {
        $off = tcgPushTournamentStartOffsetsForUser($uid, [$id]);
        $pub['start_reminder_offsets'] = $off[$id] ?? [];
    } else {
        $pub['start_reminder_offsets'] = [];
    }
    $stmt = tcgDb()->prepare('SELECT username, avatar_url FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([(string)$row['host_discord_id']]);
    $hostRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $hostName = (string)($hostRow['username'] ?? 'Host');
    $hostAvatar = $hostRow['avatar_url'] ?? null;

    $replayIndex = function_exists('tcgTournamentReplayIdsByRoom')
        ? tcgTournamentReplayIdsByRoom($id)
        : [];

    return [
        'success' => true,
        'tournament' => $pub,
        'host_username' => $hostName,
        'host_avatar_url' => $hostAvatar,
        'host_discord_id' => (string)$row['host_discord_id'],
        'is_host' => (string)$row['host_discord_id'] === $uid,
        'me' => $me,
        'entrants' => array_map(static fn($e) => tcgTournamentPublicEntrant($e, false), $entrants),
        'matches' => array_map(
            static fn(array $m) => tcgTournamentPublicMatch($m, $replayIndex),
            $matches
        ),
        'bracket_preview' => (count($matches) === 0)
            ? tcgTournamentBracketPreview(
                tcgTournamentPreviewPlayerCap($row, $entrants, $checked),
                (string)(tcgTournamentDecodeSettings($row['settings_json'] ?? '{}')['format'] ?? 'single_elim'),
                tcgTournamentPreviewPlayoffSize($row, $entrants, $checked)
            )
            : [],
        'standings' => tcgTournamentPublicStandings($entrants, $matches),
        'server_now' => time(),
        'pr_pack_reward' => tcgTournamentPrPackRewardForViewer($id, $uid, $pub),
    ];
}

/**
 * Estimate field size for empty-bracket preview (prefer live signups over max_players).
 *
 * @param array<string,mixed> $row
 * @param list<array<string,mixed>> $entrants
 */
function tcgTournamentPreviewPlayerCap(array $row, array $entrants, int $checkedIn): int {
    $settings = tcgTournamentDecodeSettings($row['settings_json'] ?? '{}');
    if (!empty($settings['showed_up'])) {
        return max(2, (int)$settings['showed_up']);
    }
    if ($checkedIn >= 2) {
        return $checkedIn;
    }
    $n = count($entrants);
    if ($n >= 2) {
        return $n;
    }
    return max(2, (int)($row['max_players'] ?? 2));
}

/**
 * @param array<string,mixed> $row
 * @param list<array<string,mixed>> $entrants
 */
function tcgTournamentPreviewPlayoffSize(array $row, array $entrants, int $checkedIn): ?int {
    $settings = tcgTournamentDecodeSettings($row['settings_json'] ?? '{}');
    $format = (string)($settings['format'] ?? 'single_elim');
    if ($format !== 'swiss') {
        return null;
    }
    if (!empty($settings['playoff_size']) && in_array((int)$settings['playoff_size'], [2, 4], true)
        && !empty($settings['showed_up'])) {
        return (int)$settings['playoff_size'];
    }
    $cap = tcgTournamentPreviewPlayerCap($row, $entrants, $checkedIn);
    return tcgTournamentSwissPlayoffSize($cap);
}

/**
 * Champion-only PR pack reveal payload (cards from ledger after payout).
 *
 * @param array<string,mixed> $pub
 * @return array<string,mixed>|null
 */
function tcgTournamentPrPackRewardForViewer(string $tournamentId, string $viewerId, array $pub): ?array {
    if ((string)($pub['status'] ?? '') !== 'finished') {
        return null;
    }
    $winnerId = (string)(($pub['results']['winner']['discord_id'] ?? ''));
    if ($winnerId === '' || $winnerId !== $viewerId) {
        return null;
    }
    if (empty($pub['pr_pack']['awarded']) && empty($pub['results']['pr_pack']['awarded'])) {
        return null;
    }
    try {
        $stmt = tcgDb()->prepare(
            'SELECT meta_json FROM tcg_tournament_ledger
             WHERE tournament_id = ? AND discord_id = ? AND kind = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$tournamentId, $viewerId, 'pr_pack_payout']);
        $raw = $stmt->fetchColumn();
        if (!$raw) {
            return ['awarded' => true, 'pack_size' => TCG_TOURNAMENT_PR_PACK_SIZE, 'pending_reveal' => true];
        }
        $meta = json_decode((string)$raw, true);
        if (!is_array($meta) || empty($meta['cards']) || !is_array($meta['cards'])) {
            return ['awarded' => true, 'pack_size' => (int)($meta['pack_size'] ?? TCG_TOURNAMENT_PR_PACK_SIZE)];
        }
        $cards = $meta['cards'];
        $first = $cards[0] ?? [];
        return [
            'pack_size' => count($cards),
            'cards' => $cards,
            'card_no' => $first['card_no'] ?? null,
            'card' => $first,
            'converted' => !empty($first['converted']),
            'star_gems_earned' => (int)($meta['star_gems_earned'] ?? 0),
            'source' => 'tournament',
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @param list<array<string,mixed>> $entrants
 * @param list<array<string,mixed>> $matches
 * @return list<array{discord_id:string,username:string,wins:int,losses:int,status:string}>
 */
function tcgTournamentStandingsStatusRank(string $status): int {
    return match ($status) {
        'winner' => 0,
        'playing', 'checked_in', 'active' => 1,
        'eliminated' => 2,
        'registered' => 3,
        'no_show' => 4,
        default => 5,
    };
}

function tcgTournamentPublicStandings(array $entrants, array $matches): array {
    $swissMatches = array_values(array_filter(
        $matches,
        static fn($m) => (string)($m['bracket_side'] ?? '') === 'swiss'
    ));
    $useSwiss = $swissMatches !== [];
    $records = function_exists('tcgTournamentRecordsFromMatches')
        ? tcgTournamentRecordsFromMatches($useSwiss ? $swissMatches : $matches, $useSwiss ? 'swiss' : null)
        : [];
    $out = [];
    foreach ($entrants as $e) {
        $id = (string)$e['discord_id'];
        $row = [
            'discord_id' => $id,
            'username' => (string)($e['username'] ?? 'Player'),
            'wins' => (int)($records[$id]['wins'] ?? 0),
            'losses' => (int)($records[$id]['losses'] ?? 0),
            'status' => (string)($e['status'] ?? ''),
        ];
        $reason = trim((string)($e['elim_reason'] ?? ''));
        if ($reason !== '') {
            $row['elim_reason'] = $reason;
        }
        if ($useSwiss && function_exists('tcgTournamentOmwPercent')) {
            $row['omw'] = round(tcgTournamentOmwPercent($id, $records, $swissMatches, 'swiss'), 4);
        }
        $out[] = $row;
    }
    usort($out, static function ($a, $b) {
        $ra = tcgTournamentStandingsStatusRank((string)$a['status']);
        $rb = tcgTournamentStandingsStatusRank((string)$b['status']);
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        if ($a['wins'] !== $b['wins']) {
            return $b['wins'] <=> $a['wins'];
        }
        if ($a['losses'] !== $b['losses']) {
            return $a['losses'] <=> $b['losses'];
        }
        $oa = (float)($a['omw'] ?? 0.0);
        $ob = (float)($b['omw'] ?? 0.0);
        if (abs($oa - $ob) > 0.0000001) {
            return $ob <=> $oa;
        }
        return strcmp($a['username'], $b['username']);
    });
    return $out;
}

/**
 * Attach bulletin_featured, progress, and leaders onto a public list row.
 *
 * @param array<string,mixed> $pub
 */
function tcgTournamentEnrichBulletinFeatured(array &$pub): void {
    $id = strtoupper((string)($pub['id'] ?? ''));
    if ($id === '') {
        return;
    }
    $pub['bulletin_featured'] = true;
    $settings = is_array($pub['settings'] ?? null) ? $pub['settings'] : [];
    $status = (string)($pub['status'] ?? '');
    $entrants = tcgTournamentFetchEntrants($id);
    $matches = tcgTournamentFetchMatches($id);
    $pub['progress'] = tcgTournamentBulletinProgress($status, $settings, $matches);
    $avatars = [];
    foreach ($entrants as $e) {
        $avatars[(string)$e['discord_id']] = $e['avatar_url'] ?? null;
    }
    $leaders = [];
    if ($status === 'running' && $matches !== []) {
        $standings = tcgTournamentPublicStandings($entrants, $matches);
        foreach (array_slice($standings, 0, 3) as $row) {
            $did = (string)($row['discord_id'] ?? '');
            $leaders[] = [
                'discord_id' => $did,
                'username' => (string)($row['username'] ?? 'Player'),
                'avatar_url' => $avatars[$did] ?? null,
                'wins' => (int)($row['wins'] ?? 0),
                'losses' => (int)($row['losses'] ?? 0),
                'status' => (string)($row['status'] ?? ''),
            ];
        }
    } else {
        $sorted = $entrants;
        usort($sorted, static function ($a, $b) {
            $sa = (string)($a['status'] ?? '');
            $sb = (string)($b['status'] ?? '');
            $ra = in_array($sa, ['checked_in', 'playing', 'winner'], true) ? 0 : 1;
            $rb = in_array($sb, ['checked_in', 'playing', 'winner'], true) ? 0 : 1;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return ((int)($a['registered_at'] ?? 0)) <=> ((int)($b['registered_at'] ?? 0));
        });
        foreach (array_slice($sorted, 0, 3) as $e) {
            $leaders[] = [
                'discord_id' => (string)$e['discord_id'],
                'username' => (string)($e['username'] ?? 'Player'),
                'avatar_url' => $e['avatar_url'] ?? null,
                'wins' => 0,
                'losses' => 0,
                'status' => (string)($e['status'] ?? ''),
            ];
        }
    }
    $pub['leaders'] = $leaders;
}

/** @param array<string,mixed> $body */
function tcgApiTournamentCreate(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));

    $title = trim((string)($body['title'] ?? ''));
    tcgAssertTournamentTitleAllowed($title);
    $startAt = (int)($body['start_at'] ?? 0);
    if ($startAt < time() + 60) {
        throw new Exception('start_at must be at least 1 minute from now', 400);
    }
    $checkin = (int)($body['checkin_mins'] ?? TCG_TOURNAMENT_DEFAULT_CHECKIN_MINS);
    $checkin = max(TCG_TOURNAMENT_MIN_CHECKIN_MINS, min(TCG_TOURNAMENT_MAX_CHECKIN_MINS, $checkin));
    $minP = max(TCG_TOURNAMENT_MIN_PLAYERS, (int)($body['min_players'] ?? 4));
    $maxP = max($minP, (int)($body['max_players'] ?? 8));
    $maxP = min(TCG_TOURNAMENT_MAX_PLAYERS_CAP, $maxP);
    $fee = max(0, (int)($body['entry_fee_coins'] ?? 0));
    if ($fee > 100000) {
        throw new Exception('entry_fee_coins too high', 400);
    }
    $gameMode = tcgNormalizeGameMode($body['game_mode'] ?? TCG_GAME_MODE_STANDARD);
    $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];
    $settings = tcgTournamentNormalizeSettings($settings, $gameMode);
    $settings['connect_secs'] = TCG_TOURNAMENT_CONNECT_SECS;

    $wantPrPack = !empty($body['pr_pack_prize']) || !empty($body['pr_pack']);
    if ($wantPrPack) {
        if ($maxP < TCG_TOURNAMENT_PR_PACK_MIN_CHECKINS) {
            throw new Exception(
                'PR pack prize requires max players ≥ ' . TCG_TOURNAMENT_PR_PACK_MIN_CHECKINS,
                400
            );
        }
        $settings['pr_pack'] = 1;
        $settings['pr_pack_status'] = 'escrowed';
    }

    $id = tcgTournamentNewId();
    $now = time();
    return tcgDbRetry(function () use (
        $id, $uid, $title, $gameMode, $startAt, $checkin, $minP, $maxP, $fee, $settings, $now, $wantPrPack
    ) {
        $db = tcgDb();
        $db->beginTransaction();
        try {
            if ($wantPrPack) {
                tcgDeductCoins($uid, TCG_TOURNAMENT_PR_PACK_COST);
                if (!tcgTournamentLedgerWrite(
                    $id,
                    $uid,
                    'host_pr_pack_escrow',
                    TCG_TOURNAMENT_PR_PACK_COST,
                    'prpack:' . $id,
                    ['pack_size' => TCG_TOURNAMENT_PR_PACK_SIZE]
                )) {
                    throw new Exception('PR pack escrow conflict', 409);
                }
            }
            $db->prepare(
                'INSERT INTO tcg_tournaments
                 (id, host_discord_id, title, status, game_mode, start_at, checkin_mins,
                  min_players, max_players, entry_fee_coins, prize_pool_coins, settings_json, created_at, updated_at)
                 VALUES (?, ?, ?, "open", ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
            )->execute([
                $id, $uid, $title, $gameMode, $startAt, $checkin,
                $minP, $maxP, $fee, tcgTournamentEncodeSettings($settings), $now, $now,
            ]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $row = tcgTournamentFetch($id);
        return [
            'success' => true,
            'tournament' => tcgTournamentPublicRow($row ?: ['id' => $id], ['total' => 0, 'checked_in' => 0]),
            'coins' => tcgGetCoins($uid),
        ];
    });
}

/** @param array<string,mixed> $body */
function tcgApiTournamentUpdate(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = strtoupper(trim((string)($body['tournament_id'] ?? '')));
    $row = tcgTournamentFetch($id);
    if (!$row) {
        throw new Exception('Tournament not found', 404);
    }
    tcgTournamentAssertHost($row, $uid);
    if (!in_array((string)$row['status'], ['open', 'draft'], true)) {
        throw new Exception('Can only edit before check-in', 400);
    }

    $title = array_key_exists('title', $body) ? trim((string)$body['title']) : (string)$row['title'];
    tcgAssertTournamentTitleAllowed($title);
    $startAt = array_key_exists('start_at', $body) ? (int)$body['start_at'] : (int)$row['start_at'];
    if ($startAt < time() + 30) {
        throw new Exception('start_at too soon', 400);
    }
    $checkin = array_key_exists('checkin_mins', $body)
        ? (int)$body['checkin_mins'] : (int)$row['checkin_mins'];
    $checkin = max(TCG_TOURNAMENT_MIN_CHECKIN_MINS, min(TCG_TOURNAMENT_MAX_CHECKIN_MINS, $checkin));
    $minP = array_key_exists('min_players', $body) ? (int)$body['min_players'] : (int)$row['min_players'];
    $maxP = array_key_exists('max_players', $body) ? (int)$body['max_players'] : (int)$row['max_players'];
    $minP = max(TCG_TOURNAMENT_MIN_PLAYERS, $minP);
    $maxP = min(TCG_TOURNAMENT_MAX_PLAYERS_CAP, max($minP, $maxP));
    $fee = array_key_exists('entry_fee_coins', $body)
        ? max(0, (int)$body['entry_fee_coins']) : (int)$row['entry_fee_coins'];
    $gameMode = array_key_exists('game_mode', $body)
        ? tcgNormalizeGameMode($body['game_mode'])
        : tcgNormalizeGameMode($row['game_mode']);

    $settings = tcgTournamentDecodeSettings($row['settings_json'] ?? '{}');
    if (is_array($body['settings'] ?? null)) {
        $settings = array_merge($settings, $body['settings']);
    }
    $settings = tcgTournamentNormalizeSettings($settings, $gameMode);

    tcgDb()->prepare(
        'UPDATE tcg_tournaments SET title=?, start_at=?, checkin_mins=?, min_players=?, max_players=?,
         entry_fee_coins=?, game_mode=?, settings_json=?, updated_at=? WHERE id=?'
    )->execute([
        $title, $startAt, $checkin, $minP, $maxP, $fee, $gameMode,
        tcgTournamentEncodeSettings($settings), time(), $id,
    ]);

    return tcgApiTournamentGet(array_merge($body, ['tournament_id' => $id]));
}

/**
 * Load a public tournament game replay payload (any signed-in viewer).
 *
 * @param array<string,mixed> $body
 * @return array<string,mixed>
 */
function tcgApiTournamentReplayGet(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = (int)($body['replay_id'] ?? $_GET['replay_id'] ?? 0);
    $row = function_exists('tcgTournamentReplayLoad') ? tcgTournamentReplayLoad($id) : null;
    if (!$row) {
        throw new Exception('Replay not found', 404);
    }
    if (!function_exists('replayPayloadDecodeFromStorage') || !function_exists('validateReplayFile')) {
        throw new Exception('Replay helpers unavailable', 500);
    }
    $payload = replayPayloadDecodeFromStorage((string)($row['payload_json'] ?? ''));
    validateReplayFile($payload);
    $payload = ensureReplayPayloadV2($payload);
    return [
        'success' => true,
        'summary' => tcgTournamentReplayPublicSummary($row),
        'replay' => $payload,
    ];
}

/**
 * Start a replay_view room from a public tournament archive.
 *
 * @param array<string,mixed> $body
 * @return array<string,mixed>
 */
function tcgApiTournamentReplayStart(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgRequireTournamentsEnabled($uid);
    $id = (int)($body['replay_id'] ?? 0);
    $row = function_exists('tcgTournamentReplayLoad') ? tcgTournamentReplayLoad($id) : null;
    if (!$row) {
        throw new Exception('Replay not found', 404);
    }
    if (!function_exists('replayPayloadDecodeFromStorage') || !function_exists('apiReplayStart')) {
        throw new Exception('Replay helpers unavailable', 500);
    }
    $payload = replayPayloadDecodeFromStorage((string)($row['payload_json'] ?? ''));
    validateReplayFile($payload);
    $payload = ensureReplayPayloadV2($payload);
    $started = apiReplayStart(['replay' => $payload]);
    return [
        'success' => true,
        'summary' => tcgTournamentReplayPublicSummary($row),
        'replay' => $payload,
    ] + $started;
}

