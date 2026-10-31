<?php
/**
 * Discord profile resolution for TCG accounts (shared by llr_auth.php).
 * Reads session, wrapped/user_cache.json, iframe bearer rows, and snake_scores
 * before falling back to placeholder names.
 */
declare(strict_types=1);

/** True for empty / generic names that must not overwrite real Discord handles. */
function tcgIsPlaceholderUsername(?string $name): bool
{
    $t = trim((string)($name ?? ''));
    if ($t === '') {
        return true;
    }
    return strcasecmp($t, 'Player') === 0 || strcasecmp($t, 'User') === 0;
}

/** Site root (public_html) — parent of tcg/ on Hostinger. */
function tcgSiteRootPath(): string
{
    $override = getenv('TCG_SITE_ROOT');
    if (is_string($override) && trim($override) !== '') {
        return rtrim(trim($override), '/\\');
    }
    static $root = null;
    if ($root !== null) {
        return $root;
    }
    $here = __DIR__;
    if (function_exists('tcgRepoRoot')) {
        $repo = rtrim((string)tcgRepoRoot(), '/\\');
        if (basename($repo) === 'tcg') {
            $root = dirname($repo);
            return $root;
        }
        if (is_file($repo . '/account.php')) {
            $root = $repo;
            return $root;
        }
    }
    if (basename($here) === 'tcg') {
        $root = dirname($here);
        return $root;
    }
    // auth_profile.php at repo root beside account.php
    $root = $here;
    return $root;
}

function tcgWrappedUserCachePath(): string
{
    return tcgSiteRootPath() . '/wrapped/user_cache.json';
}

/** Read wrapped user_cache entry without deleting expired rows (auth fallback). */
function tcgReadWrappedUserCacheEntry(string $userId): ?array
{
    $cacheFile = tcgWrappedUserCachePath();
    if (!is_file($cacheFile)) {
        return null;
    }
    $cache = json_decode((string)file_get_contents($cacheFile), true);
    if (!is_array($cache)) {
        return null;
    }
    $entry = $cache[$userId] ?? null;
    return is_array($entry) ? $entry : null;
}

function tcgLookupSnakeProfile(string $userId): ?array
{
    $dbPath = tcgSiteRootPath() . '/database.db';
    if (!is_file($dbPath)) {
        return null;
    }
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->prepare('SELECT username, avatar_url FROM snake_scores WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $username = trim((string)($row['username'] ?? ''));
        if (tcgIsPlaceholderUsername($username)) {
            return null;
        }
        $avatarUrl = isset($row['avatar_url']) ? (string)$row['avatar_url'] : null;
        $avatarHash = null;
        if ($avatarUrl !== '' && preg_match('#/avatars/\d+/([a-zA-Z0-9_]+)\.#', $avatarUrl, $m)) {
            $avatarHash = $m[1];
        }
        return [
            'username' => $username,
            'avatar_hash' => $avatarHash,
            'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function tcgLookupIframeBearerProfile(string $userId): ?array
{
    if (!function_exists('llrResolveIframeBearerTokenRow') || !function_exists('llrReadIframeBearerRawFromRequest')) {
        return null;
    }
    $raw = llrReadIframeBearerRawFromRequest();
    if ($raw === '') {
        return null;
    }
    $row = llrResolveIframeBearerTokenRow($raw);
    if (!is_array($row) || (string)($row['user_id'] ?? '') !== (string)$userId) {
        return null;
    }
    $username = trim((string)($row['username'] ?? ''));
    if (tcgIsPlaceholderUsername($username)) {
        return null;
    }
    $avatar = $row['avatar'] ?? null;
    return [
        'username' => $username,
        'avatar_hash' => is_string($avatar) && $avatar !== '' ? $avatar : null,
    ];
}

function tcgLookupStoredTcgUserProfile(string $userId): ?array
{
    if (!function_exists('tcgDb')) {
        return null;
    }
    try {
        $db = tcgDb();
        $stmt = $db->prepare('SELECT username, avatar_url FROM tcg_users WHERE discord_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $username = trim((string)($row['username'] ?? ''));
        if (tcgIsPlaceholderUsername($username)) {
            return null;
        }
        return [
            'username' => $username,
            'avatar_url' => $row['avatar_url'] ?? null,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function tcgDiscordBotToken(): string
{
    $token = '';
    if (defined('TCG_DISCORD_BOT_TOKEN')) {
        $token = trim((string)TCG_DISCORD_BOT_TOKEN);
    }
    if ($token === '') {
        $env = getenv('TCG_DISCORD_BOT_TOKEN');
        if (is_string($env) && trim($env) !== '') {
            $token = trim($env);
        }
    }
    return $token;
}

function tcgLookupDiscordBotProfile(string $userId): ?array
{
    $token = tcgDiscordBotToken();
    if ($token === '' || !preg_match('/^\d{5,32}$/', $userId)) {
        return null;
    }
    $url = 'https://discord.com/api/v10/users/' . rawurlencode($userId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bot ' . $token],
        CURLOPT_TIMEOUT => 8,
    ]);
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($response) || $response === '' || $code < 200 || $code >= 300) {
        return null;
    }
    $data = json_decode($response, true);
    if (!is_array($data)) {
        return null;
    }
    $global = trim((string)($data['global_name'] ?? ''));
    $handle = trim((string)($data['username'] ?? ''));
    $username = !tcgIsPlaceholderUsername($handle)
        ? $handle
        : (!tcgIsPlaceholderUsername($global) ? $global : '');
    if ($username === '') {
        return null;
    }
    $avatarHash = isset($data['avatar']) && is_string($data['avatar']) ? $data['avatar'] : null;
    return [
        'username' => $username,
        'avatar_hash' => $avatarHash,
    ];
}

/**
 * Resolve Discord display + handle for a signed-in user id.
 *
 * @return array{id:string,username:string,display_name:string,avatar_url:?string}
 */
function tcgBuildAuthUserProfile(string $userId): array
{
    $userId = (string)$userId;
    $displayName = null;
    $uniqueUsername = null;
    $avatarHash = null;
    $avatarUrl = null;

    if (function_exists('tcgSessionStart')) {
        tcgSessionStart();
        if ((string)($_SESSION['user_id'] ?? '') === $userId) {
            $sessionName = trim((string)($_SESSION['username'] ?? ''));
            if (!tcgIsPlaceholderUsername($sessionName)) {
                $displayName = $sessionName;
            }
            if (!empty($_SESSION['avatar'])) {
                $avatarHash = (string)$_SESSION['avatar'];
            }
        }
    }

    $cacheEntry = tcgReadWrappedUserCacheEntry($userId);
    if (is_array($cacheEntry)) {
        $cachedHandle = trim((string)($cacheEntry['username'] ?? ''));
        if (!tcgIsPlaceholderUsername($cachedHandle)) {
            $uniqueUsername = $cachedHandle;
        }
        $cachedGlobal = trim((string)($cacheEntry['global_name'] ?? ''));
        if (!$displayName && !tcgIsPlaceholderUsername($cachedGlobal)) {
            $displayName = $cachedGlobal;
        }
        if (!$displayName && $uniqueUsername) {
            $displayName = $uniqueUsername;
        }
        if (!$avatarHash && !empty($cacheEntry['avatar'])) {
            $avatarHash = (string)$cacheEntry['avatar'];
        }
    }

    if ((!$displayName || !$uniqueUsername) && function_exists('tcgLookupIframeBearerProfile')) {
        $bearer = tcgLookupIframeBearerProfile($userId);
        if (is_array($bearer)) {
            if (!$displayName && !empty($bearer['username'])) {
                $displayName = (string)$bearer['username'];
                if (!$uniqueUsername) {
                    $uniqueUsername = $displayName;
                }
            }
            if (!$avatarHash && !empty($bearer['avatar_hash'])) {
                $avatarHash = (string)$bearer['avatar_hash'];
            }
        }
    }

    if ((!$displayName || !$uniqueUsername) && function_exists('tcgLookupSnakeProfile')) {
        $snake = tcgLookupSnakeProfile($userId);
        if (is_array($snake)) {
            if (!$displayName) {
                $displayName = (string)$snake['username'];
            }
            if (!$uniqueUsername) {
                $uniqueUsername = (string)$snake['username'];
            }
            if (!$avatarHash && !empty($snake['avatar_hash'])) {
                $avatarHash = (string)$snake['avatar_hash'];
            }
            if (!$avatarUrl && !empty($snake['avatar_url'])) {
                $avatarUrl = (string)$snake['avatar_url'];
            }
        }
    }

    if ((!$displayName || !$uniqueUsername) && function_exists('tcgLookupStoredTcgUserProfile')) {
        $stored = tcgLookupStoredTcgUserProfile($userId);
        if (is_array($stored)) {
            if (!$displayName) {
                $displayName = (string)$stored['username'];
            }
            if (!$uniqueUsername) {
                $uniqueUsername = (string)$stored['username'];
            }
            if (!$avatarUrl && !empty($stored['avatar_url'])) {
                $avatarUrl = (string)$stored['avatar_url'];
            }
        }
    }

    if ((!$displayName || !$uniqueUsername) && function_exists('tcgLookupDiscordBotProfile')) {
        $bot = tcgLookupDiscordBotProfile($userId);
        if (is_array($bot)) {
            if (!$displayName && !empty($bot['username'])) {
                $displayName = (string)$bot['username'];
            }
            if (!$uniqueUsername && !empty($bot['username'])) {
                $uniqueUsername = (string)$bot['username'];
            }
            if (!$avatarHash && !empty($bot['avatar_hash'])) {
                $avatarHash = (string)$bot['avatar_hash'];
            }
        }
    }

    $username = !tcgIsPlaceholderUsername($uniqueUsername)
        ? $uniqueUsername
        : (!tcgIsPlaceholderUsername($displayName) ? $displayName : 'Player');

    // A real hash (session / user cache / bearer / bot) is fresher than any URL stored in snake_scores or
    // tcg_users, so it wins; a stored URL is only the fallback. Without either the CDN default embed is
    // used, and `avatar_known` stays false so callers never overwrite a stored custom avatar with it.
    $avatarKnown = is_string($avatarHash) && $avatarHash !== '';
    if ($avatarKnown && function_exists('tcgDiscordAvatarUrl')) {
        $avatarUrl = tcgDiscordAvatarUrl($userId, $avatarHash);
    } elseif ($avatarUrl) {
        $avatarKnown = !str_contains((string)$avatarUrl, '/embed/avatars/');
    } elseif (function_exists('tcgDiscordAvatarUrl')) {
        $avatarUrl = tcgDiscordAvatarUrl($userId, null);
    } else {
        $avatarUrl = 'https://cdn.discordapp.com/embed/avatars/0.png';
    }

    return [
        'id' => $userId,
        'username' => $username,
        'display_name' => !tcgIsPlaceholderUsername($displayName) ? $displayName : $username,
        'avatar_url' => $avatarUrl,
        'avatar_known' => $avatarKnown,
    ];
}

/** How long a player's Discord avatar is trusted before the next refresh from Discord. */
const TCG_AVATAR_REFRESH_TTL = 1800;

/**
 * Prefer a previously stored proxy avatar over a stale session/OAuth hash so ensureUser cannot undo a
 * successful bot freshen on the next /me inside the TTL window.
 */
function tcgFreshenPreferStoredAvatar(string $userId, array $profile, ?string $storedUrl): array
{
    $storedUrl = trim((string)$storedUrl);
    if ($storedUrl === '' || !function_exists('tcgAvatarParseUrl')) {
        return $profile;
    }
    $parsed = tcgAvatarParseUrl($storedUrl);
    if ($parsed === null || $parsed[0] !== $userId) {
        // Keep default/embed stored URLs as known too (player removed their custom avatar).
        if (str_contains($storedUrl, '/embed/avatars/')) {
            $profile['avatar_url'] = $storedUrl;
            $profile['avatar_known'] = true;
        }
        return $profile;
    }
    $profile['avatar_url'] = function_exists('tcgAvatarProxyUrl')
        ? tcgAvatarProxyUrl($userId, $parsed[1])
        : $storedUrl;
    $profile['avatar_known'] = true;
    return $profile;
}

/**
 * Re-read the player's CURRENT Discord avatar (the session / cache only know the avatar from their last
 * OAuth login, and Discord's CDN 404s a hash once it is changed) at most once per TTL, and fold it into
 * $profile. Needs TCG_DISCORD_BOT_TOKEN for a live Discord lookup; without it, prefers the stored URL /
 * login-cache hash so a stale $_SESSION['avatar'] cannot overwrite a fresher value.
 */
function tcgFreshenDiscordAvatar(string $userId, array $profile): array
{
    if (!function_exists('tcgDb') || !function_exists('tcgDiscordAvatarUrl')) {
        return $profile;
    }
    try {
        if (!function_exists('tcgAvatarParseUrl') && is_file(__DIR__ . '/avatar_cache.php')) {
            require_once __DIR__ . '/avatar_cache.php';
        }
        $db = tcgDb();
        $st = $db->prepare('SELECT avatar_url, avatar_checked_at FROM tcg_users WHERE discord_id = ?');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $storedUrl = is_array($row) ? (string)($row['avatar_url'] ?? '') : '';
        $last = is_array($row) ? (int)($row['avatar_checked_at'] ?? 0) : 0;
        $now = time();

        if (tcgDiscordBotToken() === '') {
            // No live Discord lookup: OAuth login-cache wins when present (refreshed on Discord login);
            // otherwise keep a stored proxy URL so a stale $_SESSION['avatar'] cannot undo it.
            if (function_exists('tcgAvatarHashFromLoginCache')) {
                $cacheHash = tcgAvatarHashFromLoginCache($userId);
                if ($cacheHash !== null) {
                    $profile['avatar_url'] = tcgDiscordAvatarUrl($userId, $cacheHash !== '' ? $cacheHash : null);
                    $profile['avatar_known'] = true;
                    return $profile;
                }
            }
            if ($storedUrl !== '' && function_exists('tcgAvatarIsProxyUrl') && tcgAvatarIsProxyUrl($storedUrl)) {
                return tcgFreshenPreferStoredAvatar($userId, $profile, $storedUrl);
            }
            return $profile;
        }

        if ($last > 0 && ($now - $last) < TCG_AVATAR_REFRESH_TTL) {
            // Critical: do NOT return the session-built profile unchanged — that hash is often from the
            // last OAuth login and would make ensureUser overwrite a newer bot-freshened avatar_url.
            return tcgFreshenPreferStoredAvatar($userId, $profile, $storedUrl);
        }

        $bot = tcgLookupDiscordBotProfile($userId);
        // Success: trust it for a full TTL. Failure (rate limit / outage): retry in ~15 minutes, not every request.
        $stamp = is_array($bot) ? $now : $now - TCG_AVATAR_REFRESH_TTL + 900;
        $db->prepare('UPDATE tcg_users SET avatar_checked_at = ? WHERE discord_id = ?')->execute([$stamp, $userId]);
        if (!is_array($bot)) {
            return tcgFreshenPreferStoredAvatar($userId, $profile, $storedUrl);
        }
        $hash = isset($bot['avatar_hash']) && is_string($bot['avatar_hash']) ? $bot['avatar_hash'] : null;
        $profile['avatar_url'] = tcgDiscordAvatarUrl($userId, $hash);
        $profile['avatar_known'] = true;
        if (function_exists('tcgSessionStart')) {
            tcgSessionStart();
            if ((string)($_SESSION['user_id'] ?? '') === $userId) {
                $_SESSION['avatar'] = $hash;
            }
        }
        if ($hash !== null && $hash !== '' && function_exists('tcgAvatarDownload')) {
            try {
                tcgAvatarDownload($userId, $hash);
            } catch (Throwable $e) {
                // Serving can retry the download; never fail /me over a warm-cache miss.
            }
        }
    } catch (Throwable $e) {
        // Never fail login / me over an avatar refresh.
    }
    return $profile;
}

/**
 * Batch-repair tcg_users rows stuck on placeholder names (CLI / operator script).
 *
 * @return array{scanned:int,repaired:int,still_placeholder:int,samples:list<array{discord_id:string,username:string}>}
 */
function tcgRepairPlaceholderUsernames(int $sampleLimit = 20): array
{
    if (!function_exists('tcgDb')) {
        throw new RuntimeException('tcgDb unavailable');
    }
    $db = tcgDb();
    $stmt = $db->query("SELECT discord_id, username FROM tcg_users WHERE username = 'Player' OR username = 'User' OR TRIM(username) = ''");
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $repaired = 0;
    $still = 0;
    $samples = [];
    $now = time();
    foreach ($rows as $row) {
        $uid = (string)($row['discord_id'] ?? '');
        if ($uid === '') {
            continue;
        }
        $profile = tcgBuildAuthUserProfile($uid);
        $newName = trim((string)($profile['username'] ?? ''));
        if (tcgIsPlaceholderUsername($newName)) {
            $still++;
            if (count($samples) < $sampleLimit) {
                $samples[] = ['discord_id' => $uid, 'username' => $newName];
            }
            continue;
        }
        $db->prepare('UPDATE tcg_users SET username = ?, avatar_url = ?, updated_at = ? WHERE discord_id = ?')
            ->execute([
                $newName,
                function_exists('tcgAvatarNormalizeForStorage')
                    ? tcgAvatarNormalizeForStorage($uid, isset($profile['avatar_url']) ? (string)$profile['avatar_url'] : null)
                    : ($profile['avatar_url'] ?? null),
                $now,
                $uid,
            ]);
        $repaired++;
    }
    return [
        'scanned' => count($rows),
        'repaired' => $repaired,
        'still_placeholder' => $still,
        'samples' => $samples,
    ];
}
