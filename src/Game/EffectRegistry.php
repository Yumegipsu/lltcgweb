<?php

declare(strict_types=1);

namespace LLTCG\Game;

final class EffectRegistry
{
    /** @return list<string> */
    public static function knownAbilityTypes(): array
    {
        static $types = null;
        if ($types !== null) {
            return $types;
        }
        $root = dirname(__DIR__, 2);
        $src = (string)file_get_contents($root . '/effects.php');
        if (!preg_match_all("/case '([a-z0-9_]+)':/", $src, $m)) {
            $types = [];
            return $types;
        }
        $types = array_values(array_unique($m[1]));
        sort($types);
        return $types;
    }

    public static function isKnownType(string $type): bool
    {
        return in_array($type, self::knownAbilityTypes(), true);
    }
}
