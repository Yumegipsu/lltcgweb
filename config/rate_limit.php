<?php
/**
 * Simple file-based rate limiting for unauthenticated endpoints.
 */
require_once __DIR__ . '/paths.php';

const TCG_RATE_WINDOW_SEC = 600;

function tcgRateLimitDir(): string {
    return tcgPath('rate_limits');
}

function tcgRateLimitCheck(string $bucket, string $key, int $maxHits, int $windowSec): void {
    $dir = tcgRateLimitDir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $safeKey = preg_replace('/[^a-zA-Z0-9._-]/', '_', $key);
    $file = $dir . $bucket . '_' . $safeKey . '.json';
    $now = time();
    $state = ['hits' => [], 'updated' => $now];
    if (is_file($file)) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (is_array($decoded) && isset($decoded['hits']) && is_array($decoded['hits'])) {
            $state = $decoded;
        }
    }
    $cutoff = $now - $windowSec;
    $state['hits'] = array_values(array_filter(
        $state['hits'],
        static fn($ts) => is_int($ts) ? $ts >= $cutoff : (is_numeric($ts) && (int)$ts >= $cutoff)
    ));
    if (count($state['hits']) >= $maxHits) {
        throw new Exception('Rate limit exceeded. Try again shortly.');
    }
    $state['hits'][] = $now;
    $state['updated'] = $now;
    file_put_contents($file, json_encode($state), LOCK_EX);
}

function tcgRateLimitClientKey(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return is_string($ip) ? $ip : 'unknown';
}

function tcgRateLimitAuthKey(array $body, ?string $authToken = null): string {
    $token = $authToken ?? trim((string)($body['token'] ?? ''));
    if ($token !== '') {
        return $token;
    }
    $header = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (is_string($header) && trim($header) !== '') {
        return trim($header);
    }
    return tcgRateLimitClientKey();
}

/**
 * Apply per-action rate limits (see docs/SECURITY.md for bucket table).
 */
function tcgRateLimitForAction(string $action, array $body = [], ?string $authToken = null): void {
    $ip = tcgRateLimitClientKey();
    switch ($action) {
        case 'create_room':
            tcgRateLimitCheck('create_room', $ip, 30, TCG_RATE_WINDOW_SEC);
            break;
        case 'cache_card_image':
            tcgRateLimitCheck('cache_card_image', $ip, 120, TCG_RATE_WINDOW_SEC);
            break;
        case 'join_room':
            tcgRateLimitCheck('join_room', $ip, 60, TCG_RATE_WINDOW_SEC);
            break;
        case 'action':
            $roomId = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($body['room_id'] ?? ''))));
            tcgRateLimitCheck('action', $ip . '_' . ($roomId !== '' ? $roomId : 'none'), 1200, TCG_RATE_WINDOW_SEC);
            break;
        case 'dry_run_actions':
            $roomId = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($body['room_id'] ?? ''))));
            // Expert search batches many sequences; allow more than live actions.
            tcgRateLimitCheck('dry_run_actions', $ip . '_' . ($roomId !== '' ? $roomId : 'none'), 600, TCG_RATE_WINDOW_SEC);
            break;
        case 'get_state':
            $roomId = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($body['room_id'] ?? ''))));
            $key = $roomId !== '' ? $ip . '_' . $roomId : $ip;
            $max = $roomId !== '' ? 4000 : 60;
            tcgRateLimitCheck('get_state', $key, $max, TCG_RATE_WINDOW_SEC);
            break;
        case 'casual_join':
            tcgRateLimitCheck('casual_join', $ip, 30, TCG_RATE_WINDOW_SEC);
            break;
        case 'open_booster':
            tcgRateLimitCheck('open_booster', tcgRateLimitAuthKey($body, $authToken), 20, TCG_RATE_WINDOW_SEC);
            break;
        case 'ranked_join':
            tcgRateLimitCheck('ranked_join', tcgRateLimitAuthKey($body, $authToken), 20, TCG_RATE_WINDOW_SEC);
            break;
    }
}
