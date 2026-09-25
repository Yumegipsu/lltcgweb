<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
/**
 * Production-safe API error messages.
 *
 * Set TCG_DEBUG=1 for full exception text (local dev / PHPUnit).
 * Set TCG_PRODUCTION=1 to force sanitization; default is sanitized when TCG_DEBUG is unset.
 */

function tcgIsDebugErrors(): bool {
    $debug = getenv('TCG_DEBUG');
    return $debug === '1' || strtolower((string)$debug) === 'true';
}

function tcgIsProduction(): bool {
    if (tcgIsDebugErrors()) {
        return false;
    }
    $prod = getenv('TCG_PRODUCTION');
    if ($prod === '0' || strtolower((string)$prod) === 'false') {
        return false;
    }
    if ($prod === '1' || strtolower((string)$prod) === 'true') {
        return true;
    }
    return true;
}

/** True when the exception is a room flock / SQLite busy that clients may retry. */
function tcgIsRetryableBusyFault(Throwable $e): bool {
    $msg = $e->getMessage();
    if (preg_match('/^(Cannot acquire lock|Lock timeout)/', $msg)) {
        return true;
    }
    if (function_exists('tcgDbIsLockedException') && tcgDbIsLockedException($e)) {
        return true;
    }
    return str_contains($msg, 'database is locked')
        || str_contains($msg, 'SQLITE_BUSY')
        || str_contains($msg, 'SQLSTATE[HY000]: General error: 5');
}

/**
 * Map exceptions to HTTP status codes.
 *
 * Explicit Exception codes in 400–599 are kept. Lock/busy → 503.
 * Programming/PDO faults → 500. Other Exception → 400 (client/validation).
 */
function tcgHttpStatusForThrowable(Throwable $e): int {
    $code = intval($e->getCode());
    if ($code >= 400 && $code <= 599) {
        return $code;
    }
    if (tcgIsRetryableBusyFault($e)) {
        return 503;
    }
    if ($e instanceof PDOException || $e instanceof Error) {
        return 500;
    }
    if ($e instanceof Exception) {
        return 400;
    }
    return 500;
}

function tcgLogServerFault(string $source, Throwable $e, int $code): void {
    if ($code < 500 && $code !== 503) {
        return;
    }
    if (!function_exists('error_log')) {
        return;
    }
    error_log(sprintf(
        'tcg [%s] HTTP %d %s: %s in %s:%d',
        $source,
        $code,
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
}

function tcgPublicErrorMessage(Throwable $e, int $httpCode): string {
    if (tcgIsDebugErrors()) {
        return $e->getMessage();
    }
    if ($httpCode === 503 && tcgIsRetryableBusyFault($e)) {
        return 'Server busy';
    }
    if ($httpCode >= 500) {
        return 'Server error';
    }
    if ($e instanceof InvalidArgumentException) {
        return $e->getMessage();
    }
    $msg = trim($e->getMessage());
    if ($httpCode === 400 && $msg !== '') {
        return $msg;
    }
    return $msg !== '' ? $msg : 'Request failed';
}

/**
 * JSON payload for API error responses (game api.php style).
 *
 * @return array{error:string,retryable?:bool,code?:string}
 */
function tcgPublicErrorPayload(Throwable $e, int $httpCode): array {
    $payload = ['error' => tcgPublicErrorMessage($e, $httpCode)];
    if ($httpCode === 403 && str_contains(strtolower($e->getMessage()), 'banned')) {
        $payload['code'] = 'account_banned';
    }
    if ($httpCode === 503 && tcgIsRetryableBusyFault($e)) {
        $payload['retryable'] = true;
        $payload['code'] = preg_match('/^(Cannot acquire lock|Lock timeout)/', $e->getMessage())
            ? 'lock_timeout'
            : 'database_busy';
    }
    if ($httpCode === 503 && tcgIsRetryableReplayNotReady($e)) {
        $payload['retryable'] = true;
        $payload['code'] = 'replay_not_ready';
    }
    return $payload;
}

/** True when replay export/save should be retried (finish race / overflow lag). */
function tcgIsRetryableReplayNotReady(Throwable $e): bool {
    $msg = strtolower($e->getMessage());
    return str_contains($msg, 'not finished')
        || str_contains($msg, 'only be saved after the match finishes')
        || str_contains($msg, 'no recorded actions')
        || str_contains($msg, 'match export not ready')
        || str_contains($msg, 'export not ready')
        || str_contains($msg, 'host unreachable')
        || str_contains($msg, 'room not found');
}
