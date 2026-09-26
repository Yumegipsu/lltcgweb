<?php

declare(strict_types=1);

namespace LLTCG\Game\Store;

/**
 * Unlocked writers (get_state polls) must not roll a room backward.
 * A lock holder may still replace a finished casual room on rematch.
 */
final class SaveGuard
{
    public static function isStaleOverwrite(array $existing, array $incoming, bool $holdsLock = false): bool
    {
        // A lock holder may replace a finished casual room only for an explicit rematch.
        // Phase-timer / live-show heals must not turn a concede back into a live match.
        $replacingFinished = ($existing['status'] ?? '') === 'finished'
            && ($incoming['status'] ?? '') !== 'finished'
            && empty($incoming['_rematch_generation']);
        if ($replacingFinished) {
            return true;
        }
        if ($holdsLock) {
            return false;
        }
        return intval($existing['seq'] ?? 0) > intval($incoming['seq'] ?? 0);
    }
}
