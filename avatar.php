<?php
/**
 * Serve a player's Discord avatar from our own cache.
 * GET ?u=<discord id>&h=<avatar hash>  → image/png (immutable: the hash is part of the URL)
 *
 * The image is downloaded from Discord once and kept under data/avatars/. If Discord no longer serves
 * that hash (the player changed it), the newest hash we can learn is fetched instead; failing that we
 * redirect to Discord's default avatar (never another hash's cached file — that looked like a stuck avatar).
 */
declare(strict_types=1);

require_once __DIR__ . '/avatar_cache.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/db.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    tcgSendCorsPreflight('GET, OPTIONS', 'Content-Type');
    http_response_code(200);
    exit;
}

function tcgAvatarSend(string $file, string $cache): never
{
    tcgSendCorsHeaders();
    header('Content-Type: ' . tcgAvatarContentType($file));
    header('Cache-Control: ' . $cache);
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

function tcgAvatarDefaultRedirect(string $id): never
{
    tcgSendCorsHeaders();
    header('Cache-Control: public, max-age=300');
    header('Location: ' . tcgDiscordAvatarUrl($id, null), true, 302);
    exit;
}

$id = (string)($_GET['u'] ?? '');
$hash = (string)($_GET['h'] ?? '');
if (!tcgAvatarValidId($id)) {
    tcgSendCorsHeaders();
    http_response_code(400);
    exit;
}
if (!tcgAvatarValidHash($hash)) {
    $cached = tcgAvatarNewestCached($id);
    if ($cached !== null) {
        tcgAvatarSend($cached, 'public, max-age=300');
    }
    tcgAvatarDefaultRedirect($id);
}

$immutable = 'public, max-age=31536000, immutable';
$file = tcgAvatarCacheFile($id, $hash);
if (is_file($file) && filesize($file) > 0) {
    tcgAvatarSend($file, $immutable);
}

// Only fetch hashes that belong to this player (stored on their account or in the login cache), so
// the endpoint cannot be used to make us download arbitrary Discord URLs.
$stored = null;
try {
    $st = tcgDb()->prepare('SELECT avatar_url FROM tcg_users WHERE discord_id = ?');
    $st->execute([$id]);
    $row = $st->fetchColumn();
    $stored = tcgAvatarParseUrl($row === false ? null : (string)$row);
} catch (Throwable $e) {
    $stored = null;
}
$loginHash = tcgAvatarFreshHash($id, false);
$allowed = ($stored !== null && $stored[0] === $id && $stored[1] === $hash) || $loginHash === $hash;

if ($allowed) {
    $got = tcgAvatarDownload($id, $hash);
    if ($got !== null) {
        tcgAvatarSend($got, $immutable);
    }
}

// Discord does not serve this hash: look for the player's current one (bot first, then login cache).
$fresh = null;
$looked = tcgAvatarFreshHash($id, true);
if ($looked !== null && $looked !== '' && $looked !== $hash) {
    $fresh = $looked;
} elseif ($loginHash !== null && $loginHash !== '' && $loginHash !== $hash) {
    $fresh = $loginHash;
}
if ($fresh !== null) {
    $got = tcgAvatarDownload($id, $fresh);
    if ($got !== null) {
        try {
            tcgAvatarStoreHash($id, $fresh);
        } catch (Throwable $e) {
            // serving the picture matters more than recording the new hash
        }
        // This URL still names the old hash, so keep the response short-lived.
        tcgAvatarSend($got, 'public, max-age=300');
    }
}

// Do not serve a different hash's file for a URL that named $hash — that made changed avatars look
// stuck on the previous picture. Fall through to Discord's default instead.
tcgAvatarDefaultRedirect($id);
