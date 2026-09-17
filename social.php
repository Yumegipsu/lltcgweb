<?php
/**
 * Profile, friends, featured deck, and owner moderation (Hostinger account API).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/account_ban.php';
require_once __DIR__ . '/chat_moderation.php';
// Cards / collection / equipped-deck helpers are already loaded by account.php
// (play_stats.php, cards_data.php, deck_validate.php, booster.php). Do not
// re-require those with mismatched casings — Linux Hostinger would 500 /me.

const TCG_SOCIAL_OWNER_ID = '213038604975472640';
const TCG_SOCIAL_FRIEND_CAP = 25;
const TCG_SOCIAL_BIO_MAX = 100;
const TCG_SOCIAL_DECK_DESC_MAX = 200;
const TCG_SOCIAL_CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

function tcgSocialIsOwner(string $discordId): bool {
    return $discordId === TCG_SOCIAL_OWNER_ID;
}

/** Open profile-report count for the mod inbox badge. */
function tcgSocialOpenReportCount(): int {
    tcgSocialEnsureSchema();
    $n = tcgDb()->query("SELECT COUNT(*) FROM tcg_profile_reports WHERE status = 'open'")->fetchColumn();
    return max(0, (int)$n);
}

function tcgSocialEnsureSchema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = tcgDb();
    try {
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tcg_users_friend_code ON tcg_users(friend_code) WHERE friend_code IS NOT NULL AND friend_code != \'\'' );
    } catch (Throwable $e) {
        // Unique index may fail if duplicate empties exist; friend-code assign still retries.
    }
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_profile_showcase (
        discord_id TEXT NOT NULL,
        slot INTEGER NOT NULL,
        card_no TEXT NOT NULL DEFAULT \'\',
        PRIMARY KEY (discord_id, slot)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_friends (
        user_lo TEXT NOT NULL,
        user_hi TEXT NOT NULL,
        requester_id TEXT NOT NULL,
        status TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,
        PRIMARY KEY (user_lo, user_hi)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_friends_status ON tcg_friends(status, updated_at)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_pvp_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id TEXT NOT NULL UNIQUE,
        mode TEXT NOT NULL,
        p1_id TEXT NOT NULL,
        p2_id TEXT NOT NULL,
        winner_id TEXT,
        ended_at INTEGER NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_pvp_p1 ON tcg_pvp_results(p1_id, ended_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_pvp_p2 ON tcg_pvp_results(p2_id, ended_at)');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_profile_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        reporter_id TEXT NOT NULL,
        target_id TEXT NOT NULL,
        field TEXT NOT NULL,
        snippet TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL DEFAULT \'open\',
        created_at INTEGER NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_profile_mod_actions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        target_id TEXT NOT NULL,
        actor_id TEXT NOT NULL,
        action TEXT NOT NULL,
        note TEXT NOT NULL DEFAULT \'\',
        created_at INTEGER NOT NULL
    )');
    tcgBanEnsureSchema();
}

function tcgSocialRandomFriendCode(): string {
    $alpha = TCG_SOCIAL_CROCKFORD;
    $code = 'LC';
    $max = strlen($alpha) - 1;
    for ($i = 0; $i < 6; $i++) {
        $code .= $alpha[random_int(0, $max)];
    }
    return $code;
}

function tcgSocialNormalizeFriendCode(string $raw): string {
    $s = preg_replace('/[^0-9A-Z]/', '', strtoupper(trim($raw))) ?? '';
    // Crockford I/L/O/U only applies to the 6-character payload — never the LC prefix
    // (L→1 would turn LCXXXXXX into 1CXXXXXX and reject every real code).
    if (str_starts_with($s, 'LC')) {
        $rest = substr($s, 2);
    } elseif (str_starts_with($s, '1C') && strlen($s) >= 8) {
        $rest = substr($s, 2);
    } else {
        $rest = $s;
    }
    $rest = strtr($rest, ['I' => '1', 'L' => '1', 'O' => '0', 'U' => 'V']);
    return 'LC' . $rest;
}

function tcgSocialEnsureFriendCode(string $discordId): string {
    tcgSocialEnsureSchema();
    $db = tcgDb();
    try {
        $stmt = $db->prepare('SELECT friend_code FROM tcg_users WHERE discord_id = ?');
        $stmt->execute([$discordId]);
        $existing = trim((string)$stmt->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
    if ($existing !== '' && preg_match('/^LC[0-9A-HJKMNP-TV-Z]{6}$/', $existing)) {
        return $existing;
    }
    for ($i = 0; $i < 24; $i++) {
        $code = tcgSocialRandomFriendCode();
        try {
            $db->prepare(
                'UPDATE tcg_users SET friend_code = ? WHERE discord_id = ? AND (friend_code IS NULL OR friend_code = \'\')'
            )->execute([$code, $discordId]);
            $stmt->execute([$discordId]);
            $got = trim((string)$stmt->fetchColumn());
            if ($got !== '') {
                return $got;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    return $existing;
}

function tcgSocialPair(string $a, string $b): array {
    if ($a === $b) {
        throw new Exception('Cannot friend yourself', 400);
    }
    return $a < $b ? [$a, $b] : [$b, $a];
}

function tcgSocialFriendStatusFromRow(?array $row, string $viewer): string {
    if (!$row) {
        return 'none';
    }
    $status = (string)($row['status'] ?? '');
    if ($status === 'accepted') {
        return 'friends';
    }
    if ($status !== 'pending') {
        return 'none';
    }
    return ((string)($row['requester_id'] ?? '') === $viewer) ? 'outgoing' : 'incoming';
}

function tcgSocialFriendStatus(string $viewer, string $target): string {
    if ($viewer === '' || $target === '' || $viewer === $target) {
        return 'none';
    }
    [$lo, $hi] = tcgSocialPair($viewer, $target);
    $stmt = tcgDb()->prepare('SELECT status, requester_id FROM tcg_friends WHERE user_lo = ? AND user_hi = ?');
    $stmt->execute([$lo, $hi]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return tcgSocialFriendStatusFromRow($row, $viewer);
}

function tcgSocialAreFriends(string $a, string $b): bool {
    return tcgSocialFriendStatus($a, $b) === 'friends';
}

function tcgSocialFriendCount(string $discordId): int {
    tcgSocialEnsureSchema();
    $stmt = tcgDb()->prepare(
        'SELECT COUNT(*) FROM tcg_friends WHERE status = \'accepted\' AND (user_lo = ? OR user_hi = ?)'
    );
    $stmt->execute([$discordId, $discordId]);
    return intval($stmt->fetchColumn());
}

/** @return list<string> */
function tcgSocialAcceptedFriendIds(string $discordId): array {
    tcgSocialEnsureSchema();
    $stmt = tcgDb()->prepare(
        'SELECT user_lo, user_hi FROM tcg_friends WHERE status = \'accepted\' AND (user_lo = ? OR user_hi = ?)'
    );
    $stmt->execute([$discordId, $discordId]);
    $ids = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $lo = (string)($row['user_lo'] ?? '');
        $hi = (string)($row['user_hi'] ?? '');
        $other = $lo === $discordId ? $hi : $lo;
        if ($other !== '') {
            $ids[] = $other;
        }
    }
    return $ids;
}

function tcgSocialAttachFriendMissions(array $payload, string $a, string $b): array {
    if (!function_exists('tcgMissionCheckFriendCount')) {
        require_once __DIR__ . '/missions.php';
    }
    $done = tcgMissionMergeCompletions(
        tcgMissionCheckFriendCount($a),
        tcgMissionCheckFriendCount($b)
    );
    return tcgMissionAttachCompletions($payload, $done);
}

function tcgSocialUserStub(string $discordId): array {
    $stmt = tcgDb()->prepare('SELECT discord_id, username, avatar_url, friend_code FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $name = trim((string)($row['username'] ?? ''));
    return [
        'id' => $discordId,
        'username' => $name !== '' ? $name : 'Player',
        'avatar_url' => $row['avatar_url'] ?? null,
        'friend_code' => (string)($row['friend_code'] ?? ''),
        'known' => $row !== [],
    ];
}

function tcgSocialMatchHasWinner(array $row): bool {
    $w = trim((string)($row['winner_id'] ?? ''));
    return $w !== '';
}

function tcgRecordPvpResult(string $roomId, string $mode, string $p1Id, string $p2Id, ?string $winnerId): void {
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId) ?? '');
    $p1Id = trim($p1Id);
    $p2Id = trim($p2Id);
    if ($roomId === '' || $p1Id === '' || $p2Id === '' || $p1Id === $p2Id) {
        return;
    }
    if (strcasecmp($p1Id, 'cpu') === 0 || strcasecmp($p2Id, 'cpu') === 0) {
        return;
    }
    $mode = strtolower(trim($mode));
    if ($mode === '' || $mode === 'cpu' || str_contains($mode, 'cpu')) {
        return;
    }
    tcgSocialEnsureSchema();
    $win = $winnerId !== null ? trim($winnerId) : '';
    tcgDb()->prepare(
        'INSERT OR IGNORE INTO tcg_pvp_results (room_id, mode, p1_id, p2_id, winner_id, ended_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$roomId, $mode, $p1Id, $p2Id, $win === '' ? null : $win, time()]);
}

function tcgSocialCostBucket(int $cost): string {
    if ($cost <= 3) {
        return '1-3';
    }
    if ($cost === 4) {
        return '4';
    }
    if ($cost <= 8) {
        return '5-8';
    }
    if ($cost === 9) {
        return '9';
    }
    if ($cost <= 11) {
        return '10-11';
    }
    if ($cost <= 15) {
        return '12-15';
    }
    return '16+';
}

function tcgSocialBladeColor(string $color): string {
    $c = strtolower(trim($color));
    return match ($c) {
        'pink', 'rose', '桃' => 'pink',
        'red', '赤' => 'red',
        'yellow', 'gold', '黄' => 'yellow',
        'green', '緑' => 'green',
        'blue', '青' => 'blue',
        'purple', '紫' => 'purple',
        'all', '全' => 'all',
        'any', 'gray', 'grey', 'colourless', 'colorless', '無' => 'any',
        default => $c,
    };
}

function tcgSocialAddHeartCounts($raw, array &$dest): void {
    if (!is_array($raw)) {
        return;
    }
    foreach ($raw as $h) {
        $color = is_array($h) ? (string)($h['color'] ?? $h['colour'] ?? '') : (string)$h;
        $color = tcgSocialBladeColor($color);
        if ($color === '') {
            continue;
        }
        $n = is_array($h) ? max(1, intval($h['count'] ?? $h['n'] ?? 1)) : 1;
        $dest[$color] = ($dest[$color] ?? 0) + $n;
    }
}

function tcgSocialDeckComposition(array $main, array $energy, array $cardMap): array {
    $buckets = ['1-3' => 0, '4' => 0, '5-8' => 0, '9' => 0, '10-11' => 0, '12-15' => 0, '16+' => 0];
    $blades = [];
    $hearts = [];
    $types = ['member' => 0, 'live' => 0, 'energy' => 0];
    $countCard = static function (string $no) use (&$buckets, &$blades, &$hearts, &$types, $cardMap): void {
        $card = $cardMap[$no] ?? null;
        if (!is_array($card)) {
            return;
        }
        $en = strtolower((string)($card['card_type_en'] ?? $card['card_type'] ?? ''));
        $jp = (string)($card['card_type'] ?? '');
        $isMember = $en === 'member' || $jp === 'メンバー';
        $isLive = $en === 'live' || $jp === 'ライブ';
        $isEnergy = $en === 'energy' || $jp === 'エネルギー';
        if ($isMember) {
            $types['member']++;
        } elseif ($isLive) {
            $types['live']++;
        } elseif ($isEnergy) {
            $types['energy']++;
        }
        $cost = intval($card['cost'] ?? 0);
        if ($cost > 0) {
            $b = tcgSocialCostBucket($cost);
            $buckets[$b] = ($buckets[$b] ?? 0) + 1;
        }
        tcgSocialAddHeartCounts($card['blade_hearts'] ?? [], $blades);
        // Printed member hearts only — live `hearts` are requirements, not supply.
        if ($isMember) {
            tcgSocialAddHeartCounts($card['hearts'] ?? [], $hearts);
        }
    };
    foreach ($main as $no) {
        $countCard((string)$no);
    }
    foreach ($energy as $no) {
        $countCard((string)$no);
    }
    $heartTotal = 0;
    foreach ($blades as $n) {
        $heartTotal += intval($n);
    }
    $regularTotal = 0;
    foreach ($hearts as $n) {
        $regularTotal += intval($n);
    }
    return [
        'cost_buckets' => $buckets,
        'blade_hearts' => $blades,
        'hearts' => $hearts,
        'types' => $types,
        'blade_heart_total' => $heartTotal,
        'heart_total' => $regularTotal,
    ];
}

function tcgSocialFeaturedDeckPayload(array $user, string $viewerId, bool $areFriends): array {
    $vis = (string)($user['featured_deck_visibility'] ?? 'private');
    if (!in_array($vis, ['private', 'friends', 'public'], true)) {
        $vis = 'private';
    }
    $ownerId = (string)$user['discord_id'];
    $isOwner = $viewerId === $ownerId;
    $canSee = $isOwner || $vis === 'public' || ($vis === 'friends' && $areFriends);
    $meta = [
        'visibility' => $vis,
        'name' => null,
        'desc' => ($isOwner || $canSee) ? (string)($user['featured_deck_desc'] ?? '') : '',
        'visible' => $canSee,
        'composition' => null,
        'cards' => null,
        'decks' => null,
        'featured_deck_id' => intval($user['featured_deck_id'] ?? 0) ?: null,
    ];
    if ($isOwner) {
        $stmt = tcgDb()->prepare('SELECT id, slot, name FROM tcg_deck_presets WHERE discord_id = ? ORDER BY slot ASC');
        $stmt->execute([$ownerId]);
        $meta['decks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$canSee) {
        return $meta;
    }
    $deckId = intval($user['featured_deck_id'] ?? 0);
    $row = null;
    if ($deckId > 0) {
        $st = tcgDb()->prepare('SELECT * FROM tcg_deck_presets WHERE id = ? AND discord_id = ?');
        $st->execute([$deckId, $ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$row) {
        $row = tcgGetEquippedDeckRow($ownerId);
    }
    if (!$row) {
        return $meta;
    }
    $main = is_string($row['main_deck'] ?? null) ? (json_decode((string)$row['main_deck'], true) ?: []) : ($row['main_deck'] ?? []);
    $energy = is_string($row['energy_deck'] ?? null) ? (json_decode((string)$row['energy_deck'], true) ?: []) : ($row['energy_deck'] ?? []);
    $cardMap = tcgBuildCardMap(tcgLoadCardsData());
    $cards = [];
    foreach (array_merge($main, $energy) as $no) {
        $no = (string)$no;
        $c = $cardMap[$no] ?? null;
        $cards[] = [
            'card_no' => $no,
            'name' => is_array($c) ? (string)($c['name_en'] ?? $c['name'] ?? $no) : $no,
            'card_type_en' => is_array($c) ? (string)($c['card_type_en'] ?? '') : '',
        ];
    }
    $meta['name'] = tcgNormalizeDeckPresetName((string)($row['name'] ?? 'Deck'));
    $meta['composition'] = tcgSocialDeckComposition($main, $energy, $cardMap);
    $meta['preview'] = tcgSocialDeckPreview($main, $cardMap);
    $meta['cards'] = $cards;
    $meta['featured_deck_id'] = intval($row['id'] ?? $deckId) ?: null;
    return $meta;
}

/** Most-copied Member / Live cards for the featured-deck thumbnail (3 + 3). */
function tcgSocialDeckPreview(array $main, array $cardMap): array {
    $members = [];
    $lives = [];
    foreach ($main as $no) {
        $no = (string)$no;
        $card = $cardMap[$no] ?? null;
        $en = strtolower((string)($card['card_type_en'] ?? $card['type_en'] ?? ''));
        $jp = (string)($card['card_type'] ?? '');
        if ($en === 'member' || $jp === 'メンバー') {
            $members[$no] = ($members[$no] ?? 0) + 1;
        } elseif ($en === 'live' || $jp === 'ライブ') {
            $lives[$no] = ($lives[$no] ?? 0) + 1;
        }
    }
    arsort($members);
    arsort($lives);
    $pick = function (array $counts) use ($cardMap): array {
        $out = [];
        foreach (array_slice($counts, 0, 3, true) as $no => $n) {
            $c = $cardMap[(string)$no] ?? null;
            $out[] = [
                'card_no' => (string)$no,
                'count' => intval($n),
                'card_type_en' => is_array($c) ? (string)($c['card_type_en'] ?? '') : '',
            ];
        }
        return $out;
    };
    return ['members' => $pick($members), 'lives' => $pick($lives)];
}

function tcgSocialUnitLogoUrl(string $unit): string {
    $label = function_exists('tcgPlayStatUnitDisplayName')
        ? tcgPlayStatUnitDisplayName($unit)
        : $unit;
    $aliases = [
        "μ's" => "µ's",
        "Μ's" => "µ's",
        'Muse' => "µ's",
        "Mu's" => "µ's",
        'Sunshine' => 'Aqours',
        'Superstar' => 'Liella!',
        'Niji' => 'Nijigasaki',
        'Hasu' => 'Hasunosora',
        'Hasunosora Girls High School Idol Club' => 'Hasunosora',
    ];
    $key = $aliases[$label] ?? $label;
    if (function_exists('tcgSleeveShopUnitIconUrl')) {
        return tcgSleeveShopUnitIconUrl($key);
    }
    return match ($key) {
        "µ's" => 'https://i.idol.st/static/img/i_unit/%CE%BC-s.png',
        'Aqours' => 'https://i.idol.st/static/img/i_unit/Aqours.png',
        'Nijigasaki' => 'https://i.idol.st/static/img/i_unit/Nijigasaki-High-School.png',
        'Liella!', 'Liella' => 'https://i.idol.st/static/img/i_unit/Liella.png',
        'Hasunosora' => 'https://i.idol.st/static/img/i_unit/Hasunosora-Girls-High-School-Idol-Club.png',
        default => 'https://i.idol.st/static/img/i_unit/Other.png',
    };
}

/**
 * All Live printings for a song title, catalog order (first = previous default art).
 *
 * @return list<string>
 */
function tcgSocialLivePrintingNos(string $liveName): array {
    static $byName = null;
    $q = strtolower(trim($liveName));
    if ($q === '') {
        return [];
    }
    if ($byName === null) {
        $byName = [];
        if (!function_exists('tcgLoadCardsData') || !function_exists('tcgBuildCardMap')) {
            return [];
        }
        foreach (tcgBuildCardMap(tcgLoadCardsData()) as $no => $card) {
            if (!is_array($card)) {
                continue;
            }
            $isLive = function_exists('tcgCardIsLive')
                ? tcgCardIsLive($card)
                : (strtolower((string)($card['card_type_en'] ?? '')) === 'live'
                    || ($card['card_type'] ?? '') === 'ライブ');
            if (!$isLive) {
                continue;
            }
            $names = [];
            if (function_exists('cardPlayTrackTags')) {
                foreach (cardPlayTrackTags($card)['live_names'] ?? [] as $ln) {
                    $names[] = strtolower(trim((string)$ln));
                }
            }
            $names[] = strtolower(trim((string)($card['name_en'] ?? '')));
            $names[] = strtolower(trim((string)($card['name'] ?? '')));
            $noStr = (string)$no;
            foreach (array_unique($names) as $n) {
                if ($n === '') {
                    continue;
                }
                if (!isset($byName[$n])) {
                    $byName[$n] = [];
                }
                if (!in_array($noStr, $byName[$n], true)) {
                    $byName[$n][] = $noStr;
                }
            }
        }
    }
    return $byName[$q] ?? [];
}

/** First Live card_no whose live_name / English name matches. */
function tcgSocialLiveCardNo(string $liveName): string {
    $nos = tcgSocialLivePrintingNos($liveName);
    return $nos[0] ?? '';
}

/**
 * Prefer the printing this player used (and owns) over a random higher-rarity catalog hit.
 *
 * @param list<string> $printings
 * @param array<string,int> $useCounts card_no => live_success count
 * @param array<string,int> $ownedQty card_no => collection qty
 */
function tcgSocialPickOwnedOrUsedPrinting(array $printings, array $useCounts, array $ownedQty): string {
    $bestOwnedUsed = '';
    $bestOwnedUsedN = -1;
    $bestUsed = '';
    $bestUsedN = -1;
    $bestOwned = '';
    $bestOwnedN = -1;
    foreach ($printings as $no) {
        $no = trim((string)$no);
        if ($no === '') {
            continue;
        }
        $used = intval($useCounts[$no] ?? 0);
        $have = intval($ownedQty[$no] ?? 0);
        if ($used > $bestUsedN) {
            $bestUsedN = $used;
            $bestUsed = $no;
        }
        if ($have > 0 && $used > $bestOwnedUsedN) {
            $bestOwnedUsedN = $used;
            $bestOwnedUsed = $no;
        }
        if ($have > $bestOwnedN) {
            $bestOwnedN = $have;
            $bestOwned = $no;
        }
    }
    if ($bestOwnedUsedN > 0) {
        return $bestOwnedUsed;
    }
    if ($bestOwnedN > 0) {
        return $bestOwned;
    }
    if ($bestUsedN > 0) {
        return $bestUsed;
    }
    return $printings[0] ?? '';
}

function tcgSocialLiveCardNoForUser(string $discordId, string $liveName): string {
    $printings = tcgSocialLivePrintingNos($liveName);
    if ($printings === []) {
        return '';
    }
    $useCounts = [];
    foreach (tcgListPlayStats($discordId, TCG_PLAY_TRACKER_LIVE_SUCCESS, TCG_PLAY_DIM_CARD) as $row) {
        $useCounts[(string)$row['key']] = intval($row['count']);
    }
    $owned = function_exists('tcgGetCollectionMap') ? tcgGetCollectionMap($discordId) : [];
    return tcgSocialPickOwnedOrUsedPrinting($printings, $useCounts, is_array($owned) ? $owned : []);
}

/** Split "Honoka Kosaka" / "Kosaka Honoka" into comparable name tokens. */
function tcgSocialIdolNameTokens(string $s): array {
    $parts = preg_split('/[\s・･.\'’\-]+/u', strtolower(trim($s))) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

function tcgSocialIdolPortraitUrl(string $idolName): string {
    $q = strtolower(trim($idolName));
    if ($q === '') {
        return '';
    }
    $rows = function_exists('tcgLoadIdolPortraits') ? tcgLoadIdolPortraits() : [];
    if ($rows === []) {
        $path = __DIR__ . '/idol_portraits.json';
        if (is_file($path)) {
            $raw = json_decode((string)file_get_contents($path), true);
            $rows = is_array($raw['items'] ?? null) ? $raw['items'] : (is_array($raw) ? $raw : []);
        }
    }
    $qTokens = tcgSocialIdolNameTokens($q);
    $tokenUrl = '';
    $tokenLen = 0;
    foreach ($rows as $p) {
        if (!is_array($p)) {
            continue;
        }
        $url = (string)($p['portrait'] ?? '');
        if ($url === '') {
            continue;
        }
        $id = strtolower((string)($p['id'] ?? ''));
        $name = strtolower((string)($p['name'] ?? ''));
        $keys = [];
        foreach ([$id, $name] as $k) {
            if ($k !== '') {
                $keys[$k] = true;
            }
        }
        if (isset($keys[$q])) {
            return $url;
        }
        foreach (array_keys($keys) as $k) {
            if (in_array($k, $qTokens, true) && strlen($k) >= $tokenLen) {
                $tokenUrl = $url;
                $tokenLen = strlen($k);
            }
        }
    }
    return $tokenUrl;
}

function tcgSocialShowcase(string $discordId): array {
    $owned = tcgGetCollectionMap($discordId);
    $stmt = tcgDb()->prepare('SELECT slot, card_no FROM tcg_profile_showcase WHERE discord_id = ? ORDER BY slot ASC');
    $stmt->execute([$discordId]);
    $slots = [1 => '', 2 => '', 3 => ''];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $slot = intval($row['slot']);
        $no = (string)($row['card_no'] ?? '');
        if ($slot >= 1 && $slot <= 3 && $no !== '' && intval($owned[$no] ?? 0) > 0) {
            $slots[$slot] = $no;
        }
    }
    $cardMap = tcgBuildCardMap(tcgLoadCardsData());
    $out = [];
    for ($i = 1; $i <= 3; $i++) {
        $no = $slots[$i];
        $c = $no !== '' ? ($cardMap[$no] ?? null) : null;
        $out[] = [
            'slot' => $i,
            'card_no' => $no,
            'name' => is_array($c) ? (string)($c['name_en'] ?? $c['name'] ?? $no) : '',
        ];
    }
    return $out;
}

/**
 * Ranked W–L for the same board as hub / leaderboard (one game_mode, not a SUM
 * across standard + starters + randomized).
 *
 * @return array{wins:int,losses:int,games:int,draws:int,game_mode:string}
 */
function tcgSocialRankedWl(string $discordId, mixed $gameMode = null): array {
    require_once __DIR__ . '/game_mode.php';
    $mode = tcgNormalizeRankedGameMode($gameMode ?? TCG_GAME_MODE_STANDARD);
    $stmt = tcgDb()->prepare(
        'SELECT COALESCE(wins,0), COALESCE(losses,0), COALESCE(games,0), COALESCE(draws,0)
         FROM tcg_rank WHERE discord_id = ? AND game_mode = ?'
    );
    $stmt->execute([$discordId, $mode]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    if (!$row) {
        return ['wins' => 0, 'losses' => 0, 'games' => 0, 'draws' => 0, 'game_mode' => $mode];
    }
    return [
        'wins' => intval($row[0]),
        'losses' => intval($row[1]),
        'games' => intval($row[2]),
        'draws' => intval($row[3]),
        'game_mode' => $mode,
    ];
}

function tcgSocialRoomKey(string $roomId): string {
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId) ?? '');
}

/**
 * PvP rows already stored for the social overlay, plus ranked seats the Elo
 * path finished even when tcg_pvp_results was skipped.
 *
 * @return list<array{room_id:string,mode:string,p1_id:string,p2_id:string,winner_id:?string,ended_at:int}>
 */
function tcgSocialCollectMatchRows(string $discordId): array {
    tcgSocialEnsureSchema();
    $byRoom = [];
    $stmt = tcgDb()->prepare(
        'SELECT room_id, mode, p1_id, p2_id, winner_id, ended_at
         FROM tcg_pvp_results WHERE p1_id = ? OR p2_id = ?'
    );
    $stmt->execute([$discordId, $discordId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rid = tcgSocialRoomKey((string)$row['room_id']);
        if ($rid === '') {
            $rid = 'pvp-' . md5((string)$row['room_id'] . (string)($row['ended_at'] ?? ''));
        }
        $win = trim((string)($row['winner_id'] ?? ''));
        $mode = strtolower(trim((string)$row['mode']));
        $byRoom[$rid] = [
            'room_id' => $rid,
            'mode' => $mode !== '' ? $mode : 'casual',
            'p1_id' => (string)$row['p1_id'],
            'p2_id' => (string)$row['p2_id'],
            'winner_id' => $win === '' ? null : $win,
            'ended_at' => intval($row['ended_at'] ?? 0),
        ];
    }
    try {
        $stmt = tcgDb()->prepare(
            'SELECT room_id, p1_id, p2_id, winner_pid, created_at, game_mode, status
             FROM tcg_ranked_matches
             WHERE (p1_id = ? OR p2_id = ?) AND status = \'done\''
        );
        $stmt->execute([$discordId, $discordId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rid = tcgSocialRoomKey((string)$row['room_id']);
            if ($rid === '' || isset($byRoom[$rid])) {
                continue;
            }
            $p1 = (string)$row['p1_id'];
            $p2 = (string)$row['p2_id'];
            $wp = (string)($row['winner_pid'] ?? '');
            if ($wp !== 'p1' && $wp !== 'p2') {
                continue;
            }
            $winner = $wp === 'p1' ? $p1 : $p2;
            $byRoom[$rid] = [
                'room_id' => $rid,
                'mode' => 'ranked',
                'p1_id' => $p1,
                'p2_id' => $p2,
                'winner_id' => $winner,
                'ended_at' => intval($row['created_at'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        // Hostinger DBs that predate winner_pid still serve tcg_pvp_results / tcg_rank.
    }
    return array_values($byRoom);
}

function tcgSocialModeEntry(string $mode, int $games, int $wins, int $losses, bool $wlKnown): array {
    $pct = $games > 0 && $wlKnown ? round(100 * $wins / $games, 1) : 0.0;
    return [
        'mode' => $mode,
        'games' => $games,
        'wins' => $wins,
        'losses' => $losses,
        'win_pct' => $pct,
        'winPct' => $pct,
        'wl_known' => $wlKnown,
    ];
}

function tcgSocialModeStats(string $discordId): array {
    $bucket = [];
    foreach (tcgSocialCollectMatchRows($discordId) as $row) {
        $mode = (string)$row['mode'];
        if (!isset($bucket[$mode])) {
            $bucket[$mode] = ['games' => 0, 'wins' => 0, 'losses' => 0];
        }
        $bucket[$mode]['games']++;
        if ($row['winner_id'] === $discordId) {
            $bucket[$mode]['wins']++;
        } elseif ($row['winner_id'] !== null) {
            $bucket[$mode]['losses']++;
        }
    }
    $ranked = tcgSocialRankedWl($discordId);
    if ($ranked['games'] > 0) {
        $bucket['ranked'] = [
            'games' => $ranked['games'],
            'wins' => $ranked['wins'],
            'losses' => $ranked['losses'],
        ];
    }
    $casualPlayed = function_exists('tcgGetUnrankedGames') ? tcgGetUnrankedGames($discordId) : 0;
    $casualFromMatches = $bucket['casual']['games'] ?? 0;
    if ($casualPlayed > $casualFromMatches) {
        $bucket['casual'] = [
            'games' => $casualPlayed,
            'wins' => $bucket['casual']['wins'] ?? 0,
            'losses' => $bucket['casual']['losses'] ?? 0,
            'wl_known' => (($bucket['casual']['wins'] ?? 0) + ($bucket['casual']['losses'] ?? 0)) > 0,
        ];
    }
    $out = [];
    foreach ($bucket as $mode => $n) {
        $w = intval($n['wins'] ?? 0);
        $l = intval($n['losses'] ?? 0);
        $g = intval($n['games'] ?? 0);
        $known = array_key_exists('wl_known', $n) ? (bool)$n['wl_known'] : ($w + $l) > 0 || $g > 0;
        if ($mode === 'casual' && $w === 0 && $l === 0 && $g > 0 && ($casualPlayed > $casualFromMatches)) {
            $known = false;
        }
        $out[] = tcgSocialModeEntry((string)$mode, $g, $w, $l, $known);
    }
    usort($out, static fn ($a, $b) => $b['games'] <=> $a['games']);
    return $out;
}

function tcgSocialOpponents(string $discordId, int $limit = 8): array {
    $limit = max(1, min(25, $limit));
    $bucket = [];
    foreach (tcgSocialCollectMatchRows($discordId) as $row) {
        $oppId = $row['p1_id'] === $discordId ? $row['p2_id'] : $row['p1_id'];
        $oppId = trim((string)$oppId);
        if ($oppId === '' || strcasecmp($oppId, 'cpu') === 0 || $oppId === $discordId) {
            continue;
        }
        if (function_exists('tcgBanIsActive') && tcgBanIsActive($oppId)) {
            continue;
        }
        if (!tcgSocialMatchHasWinner($row)) {
            continue;
        }
        if (!isset($bucket[$oppId])) {
            $bucket[$oppId] = ['wins' => 0, 'losses' => 0, 'games' => 0];
        }
        $bucket[$oppId]['games']++;
        if ($row['winner_id'] === $discordId) {
            $bucket[$oppId]['wins']++;
        } else {
            $bucket[$oppId]['losses']++;
        }
    }
    uasort($bucket, static fn ($a, $b) => $b['games'] <=> $a['games']);
    $out = [];
    foreach (array_slice($bucket, 0, $limit, true) as $oppId => $n) {
        if (intval($n['games']) < 1 || (intval($n['wins']) + intval($n['losses'])) < 1) {
            continue;
        }
        $stub = tcgSocialUserStub((string)$oppId);
        if (empty($stub['known'])) {
            continue;
        }
        unset($stub['known']);
        $stub['wins'] = intval($n['wins']);
        $stub['losses'] = intval($n['losses']);
        $stub['games'] = intval($n['games']);
        $out[] = $stub;
    }
    return $out;
}

function tcgSocialMatchHistory(string $discordId, int $offset = 0, int $limit = 20): array {
    $limit = max(1, min(50, $limit));
    $offset = max(0, $offset);
    $rows = tcgSocialCollectMatchRows($discordId);
    usort($rows, static fn ($a, $b) => $b['ended_at'] <=> $a['ended_at']);
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $seen[$row['room_id']] = true;
        $oppId = $row['p1_id'] === $discordId ? $row['p2_id'] : $row['p1_id'];
        $oppId = trim((string)$oppId);
        if ($oppId === '' || strcasecmp($oppId, 'cpu') === 0) {
            continue;
        }
        if (function_exists('tcgBanIsActive') && tcgBanIsActive($oppId)) {
            continue;
        }
        if (!tcgSocialMatchHasWinner($row)) {
            continue;
        }
        $win = $row['winner_id'] === $discordId ? 'win' : 'loss';
        $out[] = [
            'room_id' => $row['room_id'],
            'mode' => $row['mode'],
            'result' => $win,
            'ended_at' => intval($row['ended_at']),
            'opponent' => tcgSocialUserStub((string)$oppId),
        ];
    }
    if (count($out) < $offset + $limit) {
        try {
            $stmt = tcgDb()->prepare(
                'SELECT room_id, opponent_name, winner, saver_player_id, saved_at
                 FROM tcg_replays WHERE discord_id = ? ORDER BY saved_at DESC LIMIT 40'
            );
            $stmt->execute([$discordId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rid = tcgSocialRoomKey((string)$row['room_id']);
                if ($rid !== '' && isset($seen[$rid])) {
                    continue;
                }
                $oppName = trim((string)($row['opponent_name'] ?? ''));
                if ($oppName === '' || preg_match('/cpu/i', $oppName)) {
                    continue;
                }
                $seen[$rid !== '' ? $rid : ('replay-' . md5($oppName . (string)$row['saved_at']))] = true;
                $winner = (string)($row['winner'] ?? '');
                $saver = (string)($row['saver_player_id'] ?? '');
                $result = 'draw';
                if ($winner === 'p1' || $winner === 'p2') {
                    $result = ($winner === $saver) ? 'win' : 'loss';
                } elseif ($winner !== '') {
                    $result = (strcasecmp($winner, $discordId) === 0 || strcasecmp($winner, $saver) === 0) ? 'win' : 'loss';
                } else {
                    continue;
                }
                $out[] = [
                    'room_id' => $rid,
                    'mode' => 'match',
                    'result' => $result,
                    'ended_at' => intval($row['saved_at'] ?? 0),
                    'opponent' => [
                        'id' => '',
                        'username' => $oppName,
                        'avatar_url' => null,
                        'friend_code' => '',
                    ],
                ];
            }
        } catch (Throwable $e) {
            // Replays are a display fallback only.
        }
        usort($out, static fn ($a, $b) => $b['ended_at'] <=> $a['ended_at']);
    }
    return array_slice($out, $offset, $limit);
}

function tcgSocialMergedPlayDim(string $discordId, string $dim): array {
    if (!function_exists('tcgListPlayStats')) {
        require_once __DIR__ . '/play_stats.php';
    }
    $map = [];
    foreach ([TCG_PLAY_TRACKER_STAGE, TCG_PLAY_TRACKER_LIVE_SUCCESS] as $tracker) {
        foreach (tcgListPlayStats($discordId, $tracker, $dim) as $row) {
            $key = trim((string)$row['key']);
            if ($key === '') {
                continue;
            }
            $map[$key] = ($map[$key] ?? 0) + intval($row['count']);
        }
    }
    arsort($map);
    return $map;
}

function tcgSocialIdolUsage(string $discordId): array {
    $out = [];
    $n = 0;
    foreach (tcgSocialMergedPlayDim($discordId, TCG_PLAY_DIM_IDOL) as $name => $count) {
        if ($n++ >= 12) {
            break;
        }
        $portrait = tcgSocialIdolPortraitUrl($name);
        $out[] = [
            'idol' => $name,
            'count' => intval($count),
            'portrait' => $portrait,
            'portrait' => $portrait,
        ];
    }
    return $out;
}

function tcgSocialUnitUsage(string $discordId): array {
    $out = [];
    $n = 0;
    foreach (tcgSocialMergedPlayDim($discordId, TCG_PLAY_DIM_UNIT) as $name => $count) {
        if ($n++ >= 8) {
            break;
        }
        $label = function_exists('tcgPlayStatUnitDisplayName')
            ? tcgPlayStatUnitDisplayName((string)$name)
            : (string)$name;
        $out[] = [
            'unit' => $label,
            'count' => intval($count),
            'logo' => tcgSocialUnitLogoUrl((string)$name),
            'portrait' => tcgSocialUnitLogoUrl((string)$name),
        ];
    }
    return $out;
}

function tcgSocialLiveUsage(string $discordId): array {
    if (!function_exists('tcgListPlayStats')) {
        require_once __DIR__ . '/play_stats.php';
    }
    $out = [];
    $n = 0;
    foreach (tcgListPlayStats($discordId, TCG_PLAY_TRACKER_LIVE_SUCCESS, TCG_PLAY_DIM_LIVE_NAME) as $row) {
        if ($n++ >= 8) {
            break;
        }
        $name = (string)$row['key'];
        $cardNo = tcgSocialLiveCardNoForUser($discordId, $name);
        $out[] = [
            'live' => $name,
            'count' => intval($row['count']),
            'card_no' => $cardNo,
        ];
    }
    return $out;
}

function tcgApiSocialGetProfile(array $body): array {
    $viewer = tcgRequireAuthUser($body);
    tcgEnsureUser($viewer, tcgAuthUserProfile($viewer));
    tcgSocialEnsureSchema();
    tcgSocialEnsureFriendCode($viewer);
    if (!function_exists('tcgFormatEquippedTitle')) {
        require_once __DIR__ . '/titles.php';
    }
    $target = trim((string)($body['user_id'] ?? $body['discord_id'] ?? $viewer));
    if ($target === '') {
        $target = $viewer;
    }
    $stmt = tcgDb()->prepare('SELECT * FROM tcg_users WHERE discord_id = ?');
    $stmt->execute([$target]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new Exception('Player not found', 404);
    }
    $isSelf = $viewer === $target;
    $friendStatus = $isSelf ? 'self' : tcgSocialFriendStatus($viewer, $target);
    $friends = $friendStatus === 'friends';
    $tournamentSummary = null;
    try {
        require_once __DIR__ . '/tournament_lib.php';
        if (!function_exists('tcgTournamentStatsSummaryForUser')) {
            require_once __DIR__ . '/tournament_stats.php';
        }
        $tournamentSummary = tcgTournamentProfileSummary($target, 8);
    } catch (Throwable $e) {
        $tournamentSummary = null;
    }
    return [
        'success' => true,
        'is_self' => $isSelf,
        'is_friend' => $friends,
        'friend_status' => $friendStatus,
        'is_mod' => tcgSocialIsOwner($viewer),
        'profile' => [
            'id' => $target,
            'username' => (string)($user['username'] ?? 'Player'),
            'avatar_url' => $user['avatar_url'] ?? null,
            'friend_code' => (string)($user['friend_code'] ?? ''),
            'bio' => (string)($user['bio'] ?? ''),
            'bio_locked' => intval($user['bio_locked'] ?? 0) === 1,
            'title_id' => $user['title_id'] ?? null,
            'title' => function_exists('tcgFormatEquippedTitle')
                ? tcgFormatEquippedTitle($user['title_id'] ?? null)
                : null,
            'profile_warnings' => tcgSocialIsOwner($viewer) ? intval($user['profile_warnings'] ?? 0) : null,
            'ranked' => tcgSocialRankedWl($target, $body['game_mode'] ?? null),
            'unranked_games' => intval($user['unranked_games'] ?? 0),
            'showcase' => tcgSocialShowcase($target),
            'featured_deck' => tcgSocialFeaturedDeckPayload($user, $viewer, $friends),
            'tournament' => $tournamentSummary,
        ],
    ];
}

function tcgApiSocialGetStats(array $body): array {
    $viewer = tcgRequireAuthUser($body);
    $target = trim((string)($body['user_id'] ?? $body['userId'] ?? $viewer));
    if ($target === '') {
        $target = $viewer;
    }
    tcgSocialEnsureSchema();
    return [
        'success' => true,
        'modes' => tcgSocialModeStats($target),
        'opponents' => tcgSocialOpponents($target),
        'idols' => tcgSocialIdolUsage($target),
        'units' => tcgSocialUnitUsage($target),
        'lives' => tcgSocialLiveUsage($target),
        'history' => tcgSocialMatchHistory($target, intval($body['offset'] ?? 0), intval($body['limit'] ?? 20)),
    ];
}

function tcgApiSocialSaveProfile(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgSocialEnsureSchema();
    $row = tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    if (intval($row['bio_locked'] ?? 0) === 1 && array_key_exists('bio', $body)) {
        throw new Exception('Bio is locked', 403);
    }
    $sets = [];
    $params = [];
    if (array_key_exists('bio', $body)) {
        $bio = trim((string)$body['bio']);
        tcgAssertProfileTextAllowed($bio, 'bio', TCG_SOCIAL_BIO_MAX);
        $sets[] = 'bio = ?';
        $params[] = $bio;
    }
    if (isset($body['featured_deck_visibility'])) {
        $vis = (string)$body['featured_deck_visibility'];
        if (!in_array($vis, ['private', 'friends', 'public'], true)) {
            throw new Exception('Invalid visibility', 400);
        }
        $sets[] = 'featured_deck_visibility = ?';
        $params[] = $vis;
    }
    if (array_key_exists('featured_deck_desc', $body)) {
        $desc = trim((string)$body['featured_deck_desc']);
        tcgAssertProfileTextAllowed($desc, 'deck description', TCG_SOCIAL_DECK_DESC_MAX);
        $sets[] = 'featured_deck_desc = ?';
        $params[] = $desc;
    }
    if (array_key_exists('featured_deck_id', $body)) {
        $did = intval($body['featured_deck_id']);
        if ($did > 0) {
            $chk = tcgDb()->prepare('SELECT 1 FROM tcg_deck_presets WHERE id = ? AND discord_id = ?');
            $chk->execute([$did, $uid]);
            if (!$chk->fetchColumn()) {
                throw new Exception('Deck not found', 400);
            }
        }
        $sets[] = 'featured_deck_id = ?';
        $params[] = $did > 0 ? $did : null;
    }
    if ($sets) {
        $params[] = time();
        $params[] = $uid;
        tcgDb()->prepare('UPDATE tcg_users SET ' . implode(', ', $sets) . ', updated_at = ? WHERE discord_id = ?')
            ->execute($params);
    }
    if (isset($body['showcase']) && is_array($body['showcase'])) {
        $owned = tcgGetCollectionMap($uid);
        $up = tcgDb()->prepare(
            'INSERT INTO tcg_profile_showcase (discord_id, slot, card_no) VALUES (?, ?, ?)
             ON CONFLICT(discord_id, slot) DO UPDATE SET card_no = excluded.card_no'
        );
        foreach ($body['showcase'] as $slot => $no) {
            $s = intval(is_array($no) ? ($no['slot'] ?? $slot) : $slot);
            $cardNo = trim((string)(is_array($no) ? ($no['card_no'] ?? '') : $no));
            if ($s < 1 || $s > 3) {
                continue;
            }
            if ($cardNo !== '' && intval($owned[$cardNo] ?? 0) < 1) {
                throw new Exception('You do not own that card', 400);
            }
            $up->execute([$uid, $s, $cardNo]);
        }
    }
    return tcgApiSocialGetProfile(['user_id' => $uid] + $body);
}

function tcgApiSocialFriends(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgSocialEnsureSchema();
    $code = tcgSocialEnsureFriendCode($uid);
    $friends = [];
    $incoming = [];
    $outgoing = [];
    $st = tcgDb()->prepare('SELECT * FROM tcg_friends WHERE user_lo = ? OR user_hi = ?');
    $st->execute([$uid, $uid]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $other = $row['user_lo'] === $uid ? $row['user_hi'] : $row['user_lo'];
        $stub = tcgSocialUserStub($other);
        $stub['status'] = $row['status'];
        if ($row['status'] === 'accepted') {
            $friends[] = $stub;
        } elseif ($row['requester_id'] === $uid) {
            $outgoing[] = $stub;
        } else {
            $incoming[] = $stub;
        }
    }
    $recent = [];
    $seen = [];
    foreach (tcgSocialMatchHistory($uid, 0, 40) as $h) {
        $oid = $h['opponent']['id'] ?? '';
        if ($oid === '' || isset($seen[$oid])) {
            continue;
        }
        $seen[$oid] = true;
        $recent[] = $h['opponent'];
        if (count($recent) >= 10) {
            break;
        }
    }
    return [
        'success' => true,
        'friend_code' => $code,
        'cap' => TCG_SOCIAL_FRIEND_CAP,
        'count' => count($friends),
        'friends' => $friends,
        'incoming' => $incoming,
        'outgoing' => $outgoing,
        'recent' => $recent,
        'is_mod' => tcgSocialIsOwner($uid),
    ];
}

/** Accepted friend Discord ids for ephemeral match-chat friends room (VPS hub). */
function tcgApiMatchChatFriendIds(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgSocialEnsureSchema();
    $ids = [];
    $st = tcgDb()->prepare(
        "SELECT user_lo, user_hi FROM tcg_friends
         WHERE status = 'accepted' AND (user_lo = ? OR user_hi = ?)"
    );
    $st->execute([$uid, $uid]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $other = $row['user_lo'] === $uid ? $row['user_hi'] : $row['user_lo'];
        $other = trim((string)$other);
        if ($other !== '') {
            $ids[] = $other;
        }
    }
    return [
        'success' => true,
        'friend_ids' => array_values(array_unique($ids)),
    ];
}

function tcgApiSocialFriendAdd(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgSocialEnsureSchema();
    $other = trim((string)($body['user_id'] ?? $body['target_id'] ?? ''));
    if ($other !== '') {
        $st = tcgDb()->prepare('SELECT 1 FROM tcg_users WHERE discord_id = ?');
        $st->execute([$other]);
        if (!$st->fetchColumn()) {
            throw new Exception('Player not found', 404);
        }
    } else {
        $code = tcgSocialNormalizeFriendCode((string)($body['friend_code'] ?? $body['code'] ?? ''));
        if (!preg_match('/^LC[0-9A-HJKMNP-TV-Z]{6}$/', $code)) {
            throw new Exception('Invalid friend code', 400);
        }
        $st = tcgDb()->prepare('SELECT discord_id FROM tcg_users WHERE upper(friend_code) = ?');
        $st->execute([$code]);
        $other = (string)$st->fetchColumn();
        if ($other === '') {
            throw new Exception('No player with that code', 404);
        }
    }
    if ($other === $uid) {
        throw new Exception('Cannot friend yourself', 400);
    }
    if (tcgSocialFriendCount($uid) >= TCG_SOCIAL_FRIEND_CAP) {
        throw new Exception('Friend list is full (25)', 400);
    }
    [$lo, $hi] = tcgSocialPair($uid, $other);
    $now = time();
    $exist = tcgDb()->prepare('SELECT * FROM tcg_friends WHERE user_lo = ? AND user_hi = ?');
    $exist->execute([$lo, $hi]);
    $row = $exist->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if ($row['status'] === 'accepted') {
            throw new Exception('Already friends', 400);
        }
        if ($row['requester_id'] !== $uid) {
            tcgDb()->prepare('UPDATE tcg_friends SET status = ?, updated_at = ? WHERE user_lo = ? AND user_hi = ?')
                ->execute(['accepted', $now, $lo, $hi]);
            return tcgSocialAttachFriendMissions(['success' => true, 'accepted' => true], $uid, $other);
        }
        return ['success' => true, 'pending' => true];
    }
    tcgDb()->prepare(
        'INSERT INTO tcg_friends (user_lo, user_hi, requester_id, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$lo, $hi, $uid, 'pending', $now, $now]);
    return ['success' => true, 'pending' => true];
}

function tcgApiSocialFriendRespond(array $body, bool $accept): array {
    $uid = tcgRequireAuthUser($body);
    $other = trim((string)($body['user_id'] ?? $body['target_id'] ?? $body['id'] ?? ''));
    if ($other === '') {
        throw new Exception('user_id required', 400);
    }
    [$lo, $hi] = tcgSocialPair($uid, $other);
    $st = tcgDb()->prepare('SELECT * FROM tcg_friends WHERE user_lo = ? AND user_hi = ?');
    $st->execute([$lo, $hi]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status'] !== 'pending') {
        throw new Exception('No pending request', 400);
    }
    if ($accept) {
        if ($row['requester_id'] === $uid) {
            throw new Exception('Waiting for them to accept', 400);
        }
        if (tcgSocialFriendCount($uid) >= TCG_SOCIAL_FRIEND_CAP || tcgSocialFriendCount($other) >= TCG_SOCIAL_FRIEND_CAP) {
            throw new Exception('Friend list is full (25)', 400);
        }
        tcgDb()->prepare('UPDATE tcg_friends SET status = ?, updated_at = ? WHERE user_lo = ? AND user_hi = ?')
            ->execute(['accepted', time(), $lo, $hi]);
        return tcgSocialAttachFriendMissions(['success' => true], $uid, $other);
    } else {
        tcgDb()->prepare('DELETE FROM tcg_friends WHERE user_lo = ? AND user_hi = ?')->execute([$lo, $hi]);
    }
    return ['success' => true];
}

function tcgApiSocialFriendRemove(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $other = trim((string)($body['user_id'] ?? ''));
    [$lo, $hi] = tcgSocialPair($uid, $other);
    tcgDb()->prepare('DELETE FROM tcg_friends WHERE user_lo = ? AND user_hi = ?')->execute([$lo, $hi]);
    return ['success' => true];
}

function tcgApiSocialReport(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $target = trim((string)($body['user_id'] ?? ''));
    $field = (string)($body['reason'] ?? $body['field'] ?? 'bio');
    $chatFields = ['chat_spam', 'chat_rules', 'chat_harassment', 'chat_other'];
    if (in_array($field, ['bio', 'deck_desc', 'profile_bio'], true)) {
        $field = 'profile_bio';
    } elseif (in_array($field, ['alt_abuse', 'leaderboard_alt'], true)) {
        $field = 'alt_abuse';
    } elseif (in_array($field, $chatFields, true)) {
        // keep chat_* as-is
    } else {
        $field = 'profile_bio';
    }
    if ($target === '' || $target === $uid) {
        throw new Exception('Invalid report target', 400);
    }
    tcgSocialEnsureSchema();
    $snippetMax = in_array($field, $chatFields, true) ? 500 : 200;
    $snippet = mb_substr(trim((string)($body['snippet'] ?? '')), 0, $snippetMax);
    tcgDb()->prepare(
        'INSERT INTO tcg_profile_reports (reporter_id, target_id, field, snippet, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$uid, $target, $field, $snippet, 'open', time()]);
    return ['success' => true];
}

function tcgApiSocialModInbox(array $body): array {
    $uid = tcgRequireAuthUser($body);
    if (!tcgSocialIsOwner($uid)) {
        throw new Exception('Forbidden', 403);
    }
    tcgSocialEnsureSchema();
    $st = tcgDb()->query(
        'SELECT r.*, u.username, u.bio, u.profile_warnings, u.bio_locked
         FROM tcg_profile_reports r
         LEFT JOIN tcg_users u ON u.discord_id = r.target_id
         WHERE r.status = \'open\'
         ORDER BY r.created_at DESC
         LIMIT 80'
    );
    return [
        'success' => true,
        'open_count' => tcgSocialOpenReportCount(),
        'reports' => $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [],
    ];
}

function tcgApiSocialModAction(array $body): array {
    $uid = tcgRequireAuthUser($body);
    if (!tcgSocialIsOwner($uid)) {
        throw new Exception('Forbidden', 403);
    }
    $target = trim((string)($body['user_id'] ?? ''));
    $action = (string)($body['mod_action'] ?? '');
    if ($target === '' || !in_array($action, ['clear_bio', 'warn', 'lock_bio', 'unlock_bio', 'dismiss', 'ban'], true)) {
        throw new Exception('Invalid action', 400);
    }
    tcgSocialEnsureSchema();
    $db = tcgDb();
    $now = time();
    $rid = intval($body['report_id'] ?? 0);
    $reportField = trim((string)($body['reason'] ?? $body['note'] ?? ''));
    if ($rid > 0 && $reportField === '') {
        $rf = $db->prepare('SELECT field FROM tcg_profile_reports WHERE id = ?');
        $rf->execute([$rid]);
        $reportField = (string)($rf->fetchColumn() ?: '');
    }
    if ($action === 'clear_bio') {
        $db->prepare('UPDATE tcg_users SET bio = ?, updated_at = ? WHERE discord_id = ?')->execute(['', $now, $target]);
    } elseif ($action === 'warn') {
        $db->prepare('UPDATE tcg_users SET profile_warnings = COALESCE(profile_warnings,0)+1, updated_at = ? WHERE discord_id = ?')
            ->execute([$now, $target]);
        $warnReason = $reportField !== '' ? $reportField : 'profile_bio';
        tcgBanInsertNotice($target, 'warn', $warnReason);
    } elseif ($action === 'lock_bio') {
        $db->prepare('UPDATE tcg_users SET bio_locked = 1, updated_at = ? WHERE discord_id = ?')->execute([$now, $target]);
    } elseif ($action === 'unlock_bio') {
        $db->prepare('UPDATE tcg_users SET bio_locked = 0, updated_at = ? WHERE discord_id = ?')->execute([$now, $target]);
    } elseif ($action === 'ban') {
        tcgBanAccount($target, $uid, $reportField !== '' ? $reportField : 'moderation');
    }
    if ($rid > 0) {
        $db->prepare('UPDATE tcg_profile_reports SET status = ? WHERE id = ?')->execute(['closed', $rid]);
    } elseif (in_array($action, ['dismiss', 'ban'], true)) {
        $db->prepare('UPDATE tcg_profile_reports SET status = ? WHERE target_id = ? AND status = ?')
            ->execute(['closed', $target, 'open']);
    }
    $db->prepare('INSERT INTO tcg_profile_mod_actions (target_id, actor_id, action, note, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$target, $uid, $action, $reportField, $now]);
    return ['success' => true];
}

function tcgApiSocialBanList(array $body): array {
    $uid = tcgRequireAuthUser($body);
    if (!tcgSocialIsOwner($uid)) {
        throw new Exception('Forbidden', 403);
    }
    tcgSocialEnsureSchema();
    return ['success' => true, 'bans' => tcgBanListActive()];
}

function tcgApiSocialBanUnban(array $body): array {
    $uid = tcgRequireAuthUser($body);
    if (!tcgSocialIsOwner($uid)) {
        throw new Exception('Forbidden', 403);
    }
    $target = trim((string)($body['user_id'] ?? ''));
    if ($target === '') {
        throw new Exception('Invalid action', 400);
    }
    return tcgUnbanAccount($target, $uid);
}

function tcgApiSocialNoticeAck(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgBanAckNotice($uid, intval($body['notice_id'] ?? 0));
    return ['success' => true];
}
