<?php
/**
 * Offline / contributor fallback when llr_auth.php is not present.
 * Guest lobby, CPU, tutorial, and deck experiment work; account & ranked APIs return 401.
 */
if (!defined('TCG_TOKEN_SECRET')) {
    define('TCG_TOKEN_SECRET', '');
}

function tcgSessionStart(): void {
}

function tcgVerifyToken(string $token) {
    return false;
}

function tcgCurrentSessionUserId(): ?string {
    return null;
}

function tcgResolveAuthUserId(string $tokenMaybe = ''): ?string {
    return null;
}

function tcgReadAuthTokenFromRequest(array $body = []): string {
    $token = trim((string)($body['token'] ?? $_GET['token'] ?? ''));
    if ($token !== '') {
        return $token;
    }
    $hdr = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (is_string($hdr) && stripos($hdr, 'Bearer ') === 0) {
        return trim(substr($hdr, 7));
    }
    return '';
}

function tcgRequireAuthUser(array $body = []): string {
    throw new Exception('Authentication required', 401);
}

function tcgOptionalAuthUserId(array $body = []): ?string {
    return null;
}

function tcgAuthUserProfile(string $userId): array {
    return [
        'id' => (string)$userId,
        'username' => 'Player',
        'avatar_url' => 'https://cdn.discordapp.com/embed/avatars/0.png',
    ];
}
