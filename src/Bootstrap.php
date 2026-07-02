<?php

declare(strict_types=1);

namespace LLTCG;

final class Bootstrap
{
    public static function init(): void
    {
        $root = dirname(__DIR__);
        require_once $root . '/config/paths.php';
        tcgDefinePathConstants();
    }
}
