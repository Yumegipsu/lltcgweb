<?php
/**
 * Distributed gifts: staff-created gifts (audience = everyone or a list of players) that players
 * collect from the Gifts menu on the hub. Each player can claim a gift once.
 *
 * A gift carries its own copy (title/body, with `gifts.<key>.*` i18n for built-in gifts), an
 * optional image + external link, and a list of rewards:
 *   {"type":"muse_premium_ticket","amount":1}   — gift-only item (see gacha_muse.php)
 *   {"type":"coins|star_gems|scouting_ticket|scouting_ticket_sr|scouting_ticket_ur","amount":N}
 *   {"type":"card","card_no":"…","qty":N} / sleeve / playmat — same payloads as event rewards.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/events.php';

/** Built-in gift: everyone gets one μ's Premium 10-Pull Ticket (Honoka "Love Live Champions" celebration). */
const TCG_GIFT_MUSE_CHAMPIONS_ID = 'muse-champions-2026';
const TCG_GIFT_MUSE_CHAMPIONS_LINK = 'https://x.com/LoveLiveChamps/status/2114748892055326956?a=bFoY9HXw-Cav1vqfPv4&s=20';
/** Available to claim through 2026-11-30 (UTC); becomes hidden at this instant. */
const TCG_GIFT_MUSE_CHAMPIONS_EXPIRES_AT = 1796083200; // 2026-12-01 00:00:00 UTC

/** @return list<string> */
function tcgGiftRewardTypes(): array {
    return array_merge(['muse_premium_ticket'], TCG_EVENT_REWARD_TYPES);
}

function tcgGiftsEnsureSchema(?PDO $db = null): void {
    $db = $db ?? tcgDb();
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_gifts (
        id TEXT PRIMARY KEY,
        title_key TEXT NOT NULL DEFAULT \'\',
        title_en TEXT NOT NULL DEFAULT \'\',
        body_en TEXT NOT NULL DEFAULT \'\',
        link_url TEXT NOT NULL DEFAULT \'\',
        image TEXT NOT NULL DEFAULT \'\',
        rewards TEXT NOT NULL DEFAULT \'[]\',
        audience TEXT NOT NULL DEFAULT \'all\',
        created_at INTEGER NOT NULL,
        expires_at INTEGER
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_gift_claims (
        gift_id TEXT NOT NULL,
        discord_id TEXT NOT NULL,
        claimed_at INTEGER NOT NULL,
        PRIMARY KEY (gift_id, discord_id)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_gift_claims_user ON tcg_gift_claims(discord_id)');
}

/**
 * Insert the built-in gift(s) once. Audience is everyone (`all`), so any account —
 * including players who sign up later — can claim until expires_at.
 * Always keeps the Champions gift's expiry on the Nov 30 window (does not rewrite copy).
 */
function tcgGiftsSeedDefaults(?PDO $db = null): void {
    $db = $db ?? tcgDb();
    $stmt = $db->prepare(
        'INSERT OR IGNORE INTO tcg_gifts (id, title_key, title_en, body_en, link_url, image, rewards, audience, created_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, \'all\', ?, ?)'
    );
    $stmt->execute([
        TCG_GIFT_MUSE_CHAMPIONS_ID,
        'gifts.muse10',
        "μ's Premium 10-Pull Ticket",
        'In celebration of Honoka winning "[[Love Live Champions]]"! Scout in a μ\'s-only gacha with boosted Honoka rates and a guaranteed SR+ Honoka.',
        TCG_GIFT_MUSE_CHAMPIONS_LINK,
        'assets/Muse_Premium_Ticket.webp',
        json_encode([['type' => 'muse_premium_ticket', 'amount' => 1]]),
        time(),
        TCG_GIFT_MUSE_CHAMPIONS_EXPIRES_AT,
    ]);
    // Rows inserted before the expiry window existed still need the deadline.
    $db->prepare('UPDATE tcg_gifts SET expires_at = ? WHERE id = ?')
        ->execute([TCG_GIFT_MUSE_CHAMPIONS_EXPIRES_AT, TCG_GIFT_MUSE_CHAMPIONS_ID]);
}

/**
 * @param mixed $raw
 * @return list<array<string,mixed>>
 */
function tcgGiftNormalizeRewards($raw): array {
    if (!is_array($raw) || $raw === []) {
        throw new Exception('A gift needs at least one reward', 400);
    }
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            throw new Exception('Invalid gift reward', 400);
        }
        $type = trim((string)($row['type'] ?? ''));
        if (!in_array($type, tcgGiftRewardTypes(), true)) {
            throw new Exception('Invalid gift reward type', 400);
        }
        if ($type === 'muse_premium_ticket') {
            $out[] = ['type' => $type, 'amount' => max(1, min(100, intval($row['amount'] ?? 1)))];
            continue;
        }
        $out[] = ['type' => $type] + tcgEventsNormalizeRewardPayload($type, $row);
    }
    return $out;
}

/** @param array<string,mixed> $reward */
function tcgGiftGrantReward(string $uid, array $reward): void {
    $type = (string)($reward['type'] ?? '');
    if ($type === 'muse_premium_ticket') {
        tcgAddMusePremiumTickets($uid, max(1, intval($reward['amount'] ?? 1)));
        return;
    }
    tcgEventsGrantReward($uid, $type, $reward);
}

/** @param array<string,mixed> $row */
function tcgGiftVisibleToUser(array $row, string $uid, ?int $now = null): bool {
    $now = $now ?? time();
    $exp = $row['expires_at'] ?? null;
    if ($exp !== null && $exp !== '' && intval($exp) > 0 && intval($exp) <= $now) {
        return false;
    }
    $aud = (string)($row['audience'] ?? 'all');
    if ($aud === 'all' || $aud === '') {
        return true;
    }
    $ids = json_decode($aud, true);
    return is_array($ids) && in_array($uid, array_map('strval', $ids), true);
}

/**
 * Gifts this player can see (claimed ones included, so the menu keeps its history), newest first.
 *
 * @return list<array<string,mixed>>
 */
function tcgGiftsForUser(string $uid, ?int $now = null): array {
    $db = tcgDb();
    $claims = [];
    $stmt = $db->prepare('SELECT gift_id, claimed_at FROM tcg_gift_claims WHERE discord_id = ?');
    $stmt->execute([$uid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $claims[(string)$c['gift_id']] = intval($c['claimed_at']);
    }
    $out = [];
    foreach ($db->query('SELECT * FROM tcg_gifts ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!tcgGiftVisibleToUser($row, $uid, $now) && !isset($claims[(string)$row['id']])) {
            continue;
        }
        $id = (string)$row['id'];
        $expiresAt = $row['expires_at'] ?? null;
        $expiresAt = ($expiresAt !== null && $expiresAt !== '' && intval($expiresAt) > 0)
            ? intval($expiresAt)
            : null;
        $out[] = [
            'id' => $id,
            'title_key' => (string)$row['title_key'],
            'title_en' => (string)$row['title_en'],
            'body_en' => (string)$row['body_en'],
            'link_url' => (string)$row['link_url'],
            'image' => (string)$row['image'],
            'rewards' => json_decode((string)$row['rewards'], true) ?: [],
            'created_at' => intval($row['created_at']),
            'expires_at' => $expiresAt,
            'claimed' => isset($claims[$id]),
            'claimed_at' => $claims[$id] ?? null,
        ];
    }
    return $out;
}

function tcgGiftsBalances(string $uid): array {
    return [
        'star_gems' => tcgGetStarGems($uid),
        'coins' => tcgGetCoins($uid),
        'scouting_tickets' => tcgGetScoutingTickets($uid),
        'scouting_tickets_sr' => tcgGetScoutingTierTickets($uid, 'sr'),
        'scouting_tickets_ur' => tcgGetScoutingTierTickets($uid, 'ur'),
        'muse_premium_tickets' => tcgGetMusePremiumTickets($uid),
    ];
}

function tcgApiGiftsList(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $gifts = tcgGiftsForUser($uid);
    $unclaimed = 0;
    foreach ($gifts as $g) {
        if (empty($g['claimed'])) {
            $unclaimed++;
        }
    }
    return ['success' => true, 'gifts' => $gifts, 'unclaimed_count' => $unclaimed] + tcgGiftsBalances($uid);
}

function tcgApiGiftClaim(array $body): array {
    tcgRateLimitForAction('gift_claim', $body);
    $uid = tcgRequireAuthUser($body);
    tcgEnsureUser($uid, tcgAuthUserProfile($uid));
    $giftId = trim((string)($body['gift_id'] ?? $body['id'] ?? ''));
    if ($giftId === '') {
        throw new Exception('gift_id required', 400);
    }
    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_gifts WHERE id = ?');
    $stmt->execute([$giftId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !tcgGiftVisibleToUser($row, $uid)) {
        throw new Exception('Gift not found', 404);
    }
    $rewards = json_decode((string)$row['rewards'], true);
    if (!is_array($rewards) || $rewards === []) {
        throw new Exception('Gift has no rewards', 500);
    }
    // The claim row is the once-only guard: the primary key makes a second (or concurrent) claim a no-op.
    $ins = $db->prepare('INSERT OR IGNORE INTO tcg_gift_claims (gift_id, discord_id, claimed_at) VALUES (?, ?, ?)');
    $ins->execute([$giftId, $uid, time()]);
    if ($ins->rowCount() < 1) {
        throw new Exception('Gift already claimed', 409);
    }
    try {
        foreach ($rewards as $reward) {
            if (is_array($reward)) {
                tcgGiftGrantReward($uid, $reward);
            }
        }
    } catch (Throwable $e) {
        // Never leave a player with a spent claim and no reward.
        $db->prepare('DELETE FROM tcg_gift_claims WHERE gift_id = ? AND discord_id = ?')->execute([$giftId, $uid]);
        throw $e;
    }
    return [
        'success' => true,
        'gift_id' => $giftId,
        'rewards' => $rewards,
    ] + tcgGiftsBalances($uid);
}

/**
 * Owner-only: publish a gift. Body: id?, title_en, body_en, link_url?, image?, rewards[], audience ('all' | [discord ids]),
 * expires_at? (unix). Re-using an id that already exists is refused so claims stay meaningful.
 */
function tcgApiGiftsAdminCreate(array $body): array {
    $uid = tcgRequireAuthUser($body);
    if (!function_exists('tcgSocialIsOwner')) {
        require_once __DIR__ . '/social.php';
    }
    if (!tcgSocialIsOwner($uid)) {
        throw new Exception('Admin only', 403);
    }
    $title = trim((string)($body['title_en'] ?? ''));
    $text = trim((string)($body['body_en'] ?? ''));
    if ($title === '' || $text === '') {
        throw new Exception('title_en and body_en required', 400);
    }
    $link = trim((string)($body['link_url'] ?? ''));
    if ($link !== '' && !preg_match('#^https://#i', $link)) {
        throw new Exception('link_url must be https', 400);
    }
    $rewards = tcgGiftNormalizeRewards($body['rewards'] ?? null);
    $audience = $body['audience'] ?? 'all';
    if ($audience !== 'all') {
        if (!is_array($audience) || $audience === []) {
            throw new Exception('audience must be "all" or a list of player ids', 400);
        }
        $audience = json_encode(array_values(array_map('strval', $audience)));
    }
    $id = trim((string)($body['id'] ?? ''));
    if ($id === '') {
        $id = 'gift-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2));
    }
    if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $id)) {
        throw new Exception('Invalid gift id', 400);
    }
    $expires = isset($body['expires_at']) ? max(0, intval($body['expires_at'])) : 0;
    $stmt = tcgDb()->prepare(
        'INSERT OR IGNORE INTO tcg_gifts (id, title_key, title_en, body_en, link_url, image, rewards, audience, created_at, expires_at)
         VALUES (?, \'\', ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id, $title, $text, $link, trim((string)($body['image'] ?? '')),
        json_encode($rewards), (string)$audience, time(), $expires > 0 ? $expires : null,
    ]);
    if ($stmt->rowCount() < 1) {
        throw new Exception('A gift with that id already exists', 409);
    }
    return ['success' => true, 'gift_id' => $id];
}
