<?php
/**
 * Local cache of players' Discord avatars.
 *
 * tcg_users.avatar_url holds `avatar.php?u=<discord id>&h=<avatar hash>`. avatar.php downloads that exact
 * image from Discord's CDN once, keeps it under data/avatars/, and serves it from our own domain, so a
 * player's picture no longer depends on Discord's CDN still serving an old hash. When the hash changes
 * (new login, or a refresh from Discord) the new image is fetched and the previous file is deleted.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/paths.php';

const TCG_AVATAR_MAX_BYTES = 614400;

/** Directory for cached avatar images (HTTP-blocked with the rest of data/). */
function tcgAvatarDir(): string
{
    $dir = rtrim(tcgPath('data'), '/\\') . '/avatars/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function tcgAvatarValidId(string $id): bool
{
    return (bool)preg_match('/^\d{5,32}$/', $id);
}

function tcgAvatarValidHash(string $hash): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_]{1,64}$/', $hash);
}

/**
 * Public base of the tcg/ site. Avatar URLs are absolute because they are also shown by the Android app
 * and by pages on other origins. Override with TCG_PUBLIC_BASE_URL for a staging site.
 */
function tcgAvatarPublicBase(): string
{
    $env = getenv('TCG_PUBLIC_BASE_URL');
    $base = is_string($env) && trim($env) !== '' ? trim($env) : 'https://loveliveradio.ca/tcg/';
    return rtrim($base, '/') . '/';
}

/** Our own URL for a player's avatar. */
function tcgAvatarProxyUrl(string $discordId, string $hash): string
{
    return tcgAvatarPublicBase() . 'avatar.php?u=' . rawurlencode($discordId) . '&h=' . rawurlencode($hash);
}

/**
 * Split a stored avatar URL (our proxy URL or a Discord CDN URL) into [discord id, hash];
 * null for default/embed avatars and anything else.
 *
 * @return array{0:string,1:string}|null
 */
function tcgAvatarParseUrl(?string $url): ?array
{
    $url = trim((string)$url);
    if ($url === '') {
        return null;
    }
    if (preg_match('#avatar\.php\?(.*)$#', $url, $m)) {
        parse_str($m[1], $q);
        $id = (string)($q['u'] ?? '');
        $hash = (string)($q['h'] ?? '');
        return tcgAvatarValidId($id) && tcgAvatarValidHash($hash) ? [$id, $hash] : null;
    }
    if (preg_match('#cdn\.discordapp\.com/avatars/(\d+)/([A-Za-z0-9_]+)\.#', $url, $m)
        && tcgAvatarValidId($m[1])) {
        return [$m[1], $m[2]];
    }
    return null;
}

/** True when the URL is already our local-cache URL. */
function tcgAvatarIsProxyUrl(?string $url): bool
{
    return str_contains((string)$url, 'avatar.php?');
}

/** Store the proxy URL instead of a CDN URL whenever a real avatar hash is known. */
function tcgAvatarNormalizeForStorage(string $discordId, ?string $url): ?string
{
    $parsed = tcgAvatarParseUrl($url);
    if ($parsed === null || $parsed[0] !== $discordId) {
        return $url;
    }
    return tcgAvatarProxyUrl($discordId, $parsed[1]);
}

function tcgAvatarCacheFile(string $discordId, string $hash): string
{
    return tcgAvatarDir() . $discordId . '_' . $hash . '.png';
}

/** Newest cached file for a player (any hash), or null. */
function tcgAvatarNewestCached(string $discordId): ?string
{
    $best = null;
    $bestTime = -1;
    foreach (glob(tcgAvatarDir() . $discordId . '_*.png') ?: [] as $f) {
        $t = (int)@filemtime($f);
        if ($t > $bestTime) {
            $best = $f;
            $bestTime = $t;
        }
    }
    return $best;
}

/** Remove every cached avatar of this player except the one for $keepHash. */
function tcgAvatarPurgeOthers(string $discordId, string $keepHash): void
{
    $keep = tcgAvatarCacheFile($discordId, $keepHash);
    foreach (glob(tcgAvatarDir() . $discordId . '_*.png') ?: [] as $f) {
        if ($f !== $keep) {
            @unlink($f);
        }
    }
}

/**
 * Download one avatar from Discord's CDN into the cache and drop the player's older files.
 *
 * @return string|null path of the cached file, null when Discord does not serve this hash (any more)
 */
function tcgAvatarDownload(string $discordId, string $hash): ?string
{
    if (!tcgAvatarValidId($discordId) || !tcgAvatarValidHash($hash)) {
        return null;
    }
    $file = tcgAvatarCacheFile($discordId, $hash);
    if (is_file($file) && filesize($file) > 0) {
        return $file;
    }
    $url = 'https://cdn.discordapp.com/avatars/' . $discordId . '/' . $hash . '.png?size=128';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT => 'LovecaAvatarCache/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $code !== 200 || $body === '' || strlen($body) > TCG_AVATAR_MAX_BYTES) {
        return null;
    }
    // PNG / GIF / WebP / JPEG only — never store anything else under our domain.
    $magic = substr($body, 0, 12);
    $isImage = str_starts_with($magic, "\x89PNG") || str_starts_with($magic, 'GIF8')
        || (str_starts_with($magic, 'RIFF') && substr($magic, 8, 4) === 'WEBP')
        || str_starts_with($magic, "\xFF\xD8\xFF");
    if (!$isImage) {
        return null;
    }
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $body, LOCK_EX) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        return null;
    }
    tcgAvatarPurgeOthers($discordId, $hash);
    return $file;
}

/**
 * Newest avatar hash we can learn for a player without them logging in.
 * Order: the site's Discord login cache (refreshed at every OAuth login), then the Discord API via the
 * optional bot token. null = unknown, '' = the player has no custom avatar.
 */
function tcgAvatarLoginCache(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (!function_exists('tcgWrappedUserCachePath') && is_file(__DIR__ . '/auth_profile.php')) {
        require_once __DIR__ . '/auth_profile.php';
    }
    if (function_exists('tcgWrappedUserCachePath')) {
        $file = tcgWrappedUserCachePath();
        if (is_file($file)) {
            $decoded = json_decode((string)file_get_contents($file), true);
            if (is_array($decoded)) {
                $cache = $decoded;
            }
        }
    }
    return $cache;
}

function tcgAvatarFreshHash(string $discordId, bool $allowBot = true): ?string
{
    $entry = tcgAvatarLoginCache()[$discordId] ?? null;
    if (is_array($entry) && array_key_exists('avatar', $entry)) {
        $h = is_string($entry['avatar']) ? trim($entry['avatar']) : '';
        if ($h === '' || tcgAvatarValidHash($h)) {
            return $h;
        }
    }
    if ($allowBot) {
        if (!function_exists('tcgLookupDiscordBotProfile') && is_file(__DIR__ . '/auth_profile.php')) {
            require_once __DIR__ . '/auth_profile.php';
        }
        if (function_exists('tcgLookupDiscordBotProfile')) {
            $bot = tcgLookupDiscordBotProfile($discordId);
            if (is_array($bot)) {
                $h = isset($bot['avatar_hash']) && is_string($bot['avatar_hash']) ? trim($bot['avatar_hash']) : '';
                if ($h === '' || tcgAvatarValidHash($h)) {
                    return $h;
                }
            }
        }
    }
    return null;
}

/** Point a player's stored avatar at this hash's local-cache URL. */
function tcgAvatarStoreHash(string $discordId, string $hash): void
{
    if (!function_exists('tcgDb')) {
        return;
    }
    tcgDb()->prepare('UPDATE tcg_users SET avatar_url = ?, updated_at = ? WHERE discord_id = ?')
        ->execute([tcgAvatarProxyUrl($discordId, $hash), time(), $discordId]);
}

/**
 * One-time sweep over existing accounts: CDN URLs become local-cache URLs (no network needed), and
 * accounts stuck on a missing / default avatar get the real one from the login cache when it knows it.
 *
 * @return array{converted:int,recovered:int}
 */
function tcgAvatarSweepAllUsers(PDO $db): array
{
    $converted = 0;
    $recovered = 0;
    $upd = $db->prepare('UPDATE tcg_users SET avatar_url = ? WHERE discord_id = ?');
    $rows = $db->query('SELECT discord_id, avatar_url FROM tcg_users')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $id = (string)$row['discord_id'];
        if (!tcgAvatarValidId($id)) {
            continue;
        }
        $url = (string)($row['avatar_url'] ?? '');
        if (tcgAvatarIsProxyUrl($url)) {
            continue;
        }
        $parsed = tcgAvatarParseUrl($url);
        $fresh = tcgAvatarFreshHash($id, false);   // local file only, never the network
        if ($fresh !== null && $fresh !== '') {
            // The login cache is refreshed at every OAuth login: it wins over a possibly stale stored hash.
            $upd->execute([tcgAvatarProxyUrl($id, $fresh), $id]);
            if ($parsed !== null && $parsed[0] === $id) {
                $converted++;
            } else {
                $recovered++;
            }
            continue;
        }
        if ($parsed !== null && $parsed[0] === $id) {
            $upd->execute([tcgAvatarProxyUrl($id, $parsed[1]), $id]);
            $converted++;
        }
    }
    return ['converted' => $converted, 'recovered' => $recovered];
}
