<?php
/**
 * Simple file-based rate limiting for unauthenticated endpoints.
 */
require_once __DIR__ . '/paths.php';

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
