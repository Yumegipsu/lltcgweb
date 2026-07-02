<?php
/**
 * PHPUnit bootstrap — isolated temp runtime dirs for tests.
 */
$root = dirname(__DIR__);

if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        if (!str_starts_with($class, 'LLTCG\\')) {
            return;
        }
        $rel = str_replace('\\', '/', substr($class, 6)) . '.php';
        $path = $root . '/src/' . $rel;
        if (is_file($path)) {
            require_once $path;
        }
    });
}

$testBase = sys_get_temp_dir() . '/lltcgweb_test_' . getmypid();
@mkdir($testBase, 0755, true);
@mkdir($testBase . '/data', 0755, true);
@mkdir($testBase . '/games', 0755, true);
@mkdir($testBase . '/experiment_decks', 0755, true);
@mkdir($testBase . '/cardimg', 0755, true);
@mkdir($testBase . '/data/rate_limits', 0755, true);

putenv('TCG_DATA_DIR=' . $testBase . '/data');
putenv('TCG_GAMES_DIR=' . $testBase . '/games');
putenv('TCG_EXPERIMENT_DECKS_DIR=' . $testBase . '/experiment_decks');
putenv('TCG_CARDIMG_DIR=' . $testBase . '/cardimg');
putenv('TCG_RATE_LIMIT_DIR=' . $testBase . '/data/rate_limits/');
putenv('TCG_CARDS_FILE=' . $root . '/cards.json');
putenv('TCG_SYNC_ENABLED=0');

require_once $root . '/config/paths.php';
tcgDefinePathConstants();

register_shutdown_function(static function () use ($testBase): void {
    $rm = static function (string $dir) use (&$rm): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $rm($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    };
    $rm($testBase);
});

putenv('TCG_SYNC_ENABLED=0');

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

define('TCG_API_LIB_ONLY', true);
require_once $root . '/api.php';
