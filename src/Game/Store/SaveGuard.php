<?php

declare(strict_types=1);

namespace LLTCG\Game\Store;

/**
 * Unlocked writers (get_state polls) must not roll a room backward.
 * A lock holder may still replace a finished casual room on rematch.
 */
final class SaveGuard
{
    public static function isStaleOverwrite(array $existing, array $incoming): bool
    {
        if (($existing['status'] ?? '') === 'finished' && ($incoming['status'] ?? '') !== 'finished') {
            return true;
        }
        return intval($existing['seq'] ?? 0) > intval($incoming['seq'] ?? 0);
    }
}
