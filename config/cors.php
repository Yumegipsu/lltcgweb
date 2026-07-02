<?php
/**
 * CORS allowlist for TCG HTTP endpoints.
 *
 * Set TCG_CORS_ORIGINS (comma-separated) in env or Docker. Defaults cover production
 * and local dev.
 */

function tcgCorsAllowedOrigins(): array {
    static $origins = null;
    if ($origins !== null) {
        return $origins;
    }
    $raw = getenv('TCG_CORS_ORIGINS');
    if (is_string($raw) && trim($raw) !== '') {
        $origins = array_values(array_filter(array_map('trim', explode(',', $raw))));
        return $origins;
    }
    $origins = [
        'https://loveliveradio.ca',
        'http://localhost:8080',
        'http://127.0.0.1:8080',
    ];
    return $origins;
}

function tcgCorsRequestOrigin(): string {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    return is_string($origin) ? trim($origin) : '';
}

function tcgCorsIsAllowed(string $origin): bool {
    if ($origin === '') {
        return false;
    }
    return in_array($origin, tcgCorsAllowedOrigins(), true);
}

/** Emit CORS headers when Origin is on the allowlist. */
function tcgSendCorsHeaders(): void {
    $origin = tcgCorsRequestOrigin();
    if ($origin !== '' && tcgCorsIsAllowed($origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}

function tcgSendCorsPreflight(string $methods, string $headers): void {
    tcgSendCorsHeaders();
    header('Access-Control-Allow-Methods: ' . $methods);
    header('Access-Control-Allow-Headers: ' . $headers);
}
