<?php
/**
 * TCG multiplayer sync — VPS notify + HMAC subscribe tickets.
 *
 * Production secrets live in gitignored tcg_sync.local.php (see tcg_sync.local.php.example).
 */

function tcgSyncLoadConfig(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $local = __DIR__ . '/tcg_sync.local.php';
    if (is_file($local)) {
        require_once $local;
    }
    $loaded = true;
}

function tcgSyncSharedSecret(): string {
    tcgSyncLoadConfig();
    if (defined('TCG_SYNC_SHARED_SECRET')) {
        return trim((string)TCG_SYNC_SHARED_SECRET);
    }
    $env = getenv('TCG_SYNC_SHARED_SECRET');
    return is_string($env) ? trim($env) : '';
}

function tcgSyncPublishUrl(): string {
    tcgSyncLoadConfig();
    if (defined('TCG_SYNC_PUBLISH_URL')) {
        return trim((string)TCG_SYNC_PUBLISH_URL);
    }
    $env = getenv('TCG_SYNC_PUBLISH_URL');
    return is_string($env) ? trim($env) : '';
}

function tcgSyncInternalToken(): string {
    tcgSyncLoadConfig();
    if (defined('TCG_SYNC_INTERNAL_TOKEN')) {
        return trim((string)TCG_SYNC_INTERNAL_TOKEN);
    }
    $env = getenv('TCG_SYNC_INTERNAL_TOKEN');
    return is_string($env) ? trim($env) : '';
}

function tcgSyncEnabled(): bool {
    tcgSyncLoadConfig();
    $env = getenv('TCG_SYNC_ENABLED');
    if ($env === '0' || $env === 'false') {
        return false;
    }
    if (defined('TCG_SYNC_ENABLED') && !TCG_SYNC_ENABLED) {
        return false;
    }
    return tcgSyncSharedSecret() !== ''
        && tcgSyncPublishUrl() !== ''
        && tcgSyncInternalToken() !== '';
}

function tcgSyncNormalizeRoomId(string $roomId): string {
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', $roomId));
}

function tcgSyncIssueTicket(string $roomId, string $token, int $ttlSec = 86400): string {
    $secret = tcgSyncSharedSecret();
    if ($secret === '') {
        return '';
    }
    $roomId = tcgSyncNormalizeRoomId($roomId);
    $token = trim($token);
    if ($roomId === '' || $token === '') {
        return '';
    }
    $exp = time() + max(60, $ttlSec);
    $payload = $roomId . '|' . $token . '|' . $exp;
    $sig = hash_hmac('sha256', $payload, $secret);
    $raw = $payload . '|' . $sig;
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function tcgSyncAttachMeta(array $resp, string $roomId, string $token): array {
    if (!tcgSyncEnabled()) {
        $resp['sync_enabled'] = false;
        return $resp;
    }
    $state = loadGame($roomId);
    if (!$state || !isPvpMatch($state)) {
        $resp['sync_enabled'] = false;
        return $resp;
    }
    $resp['sync_enabled'] = true;
    $resp['sync_ticket'] = tcgSyncIssueTicket($roomId, $token);
    return $resp;
}

function tcgSyncNotify(string $roomId, int $seq, ?string $phase = null): void {
    if (!tcgSyncEnabled() || $seq <= 0) {
        return;
    }
    $roomId = tcgSyncNormalizeRoomId($roomId);
    if ($roomId === '') {
        return;
    }
    $url = tcgSyncPublishUrl();
    if ($url === '') {
        return;
    }
    $body = ['room_id' => $roomId, 'seq' => $seq];
    if ($phase !== null && $phase !== '') {
        $body['phase'] = $phase;
    }
    $json = json_encode($body);
    if ($json === false) {
        return;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-LLR-Site-Internal-Token: ' . tcgSyncInternalToken(),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function tcgSyncValidateTokenForRoom(string $roomId, string $token): bool {
    $roomId = tcgSyncNormalizeRoomId($roomId);
    $token = trim($token);
    if ($roomId === '' || $token === '') {
        return false;
    }
    if (function_exists('tcgIsSpectatorToken') && tcgIsSpectatorToken($token)) {
        if (!function_exists('tcgSpectatorTokenValid')) {
            return false;
        }
        return tcgSpectatorTokenValid($roomId, $token);
    }
    $state = loadGame($roomId);
    if (!$state) {
        return false;
    }
    return getPlayerIdByToken($state, $token) !== null;
}

function apiSyncTicket(array $body): array {
    if (!tcgSyncEnabled()) {
        return ['sync_enabled' => false];
    }
    $roomId = tcgSyncNormalizeRoomId((string)($body['room_id'] ?? ''));
    $token = trim((string)($body['token'] ?? ''));
    if ($roomId === '' || $token === '') {
        throw new Exception('room_id and token required');
    }
    if (!tcgSyncValidateTokenForRoom($roomId, $token)) {
        throw new Exception('Invalid session');
    }
    return tcgSyncAttachMeta(['ok' => true], $roomId, $token);
}
