<?php
/**
 * Zone movement placeholder module — future home for card zone helpers.
 *
 * Card move helpers currently remain in effects.php; this file establishes the
 * src/Game/ boundary for incremental extraction.
 */

namespace LLTCG\Game;

final class ZoneMovement
{
    public static function isReady(): bool
    {
        return true;
    }
}
