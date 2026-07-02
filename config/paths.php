<?php
/**
 * Runtime path configuration — env overrides with repo-root defaults.
 *
 * Keys: data, games, experiment_decks, cardimg, exports, cards, rate_limits
 */

function tcgRepoRoot(): string {
    return dirname(__DIR__);
}

function tcgPathDefaults(): array {
    $root = tcgRepoRoot();
    return [
        'data'             => $root . '/data/',
        'games'            => $root . '/games/',
        'experiment_decks' => $root . '/experiment_decks/',
        'cardimg'          => $root . '/cardimg/',
        'exports'          => $root . '/exports/',
        'cards'            => $root . '/cards.json',
        'rate_limits'      => $root . '/data/rate_limits/',
    ];
}

function tcgPathEnvKey(string $key): string {
    return match ($key) {
        'data'             => 'TCG_DATA_DIR',
        'games'            => 'TCG_GAMES_DIR',
        'experiment_decks' => 'TCG_EXPERIMENT_DECKS_DIR',
        'cardimg'          => 'TCG_CARDIMG_DIR',
        'exports'          => 'TCG_EXPORTS_DIR',
        'cards'            => 'TCG_CARDS_FILE',
        'rate_limits'      => 'TCG_RATE_LIMIT_DIR',
        default            => '',
    };
}

function tcgPath(string $key): string {
    static $cache = [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $defaults = tcgPathDefaults();
    if (!isset($defaults[$key])) {
        throw new InvalidArgumentException("Unknown tcg path key: $key");
    }
    $envKey = tcgPathEnvKey($key);
    $env = $envKey !== '' ? getenv($envKey) : false;
    if (is_string($env) && $env !== '') {
        $path = $env;
        if (in_array($key, ['data', 'games', 'experiment_decks', 'cardimg', 'exports', 'rate_limits'], true)) {
            $path = rtrim($path, '/\\') . '/';
        }
        $cache[$key] = $path;
        return $path;
    }
    $cache[$key] = $defaults[$key];
    return $defaults[$key];
}

function tcgEnsureDir(string $key): string {
    $path = tcgPath($key);
    if (substr($path, -1) === '/' && !is_dir($path)) {
        mkdir($path, 0755, true);
    }
    return $path;
}

/** Back-compat constants for legacy define() consumers. */
function tcgDefinePathConstants(): void {
    if (!defined('TCG_DATA_DIR')) {
        define('TCG_DATA_DIR', tcgPath('data'));
    }
    if (!defined('GAMES_DIR')) {
        define('GAMES_DIR', tcgPath('games'));
    }
    if (!defined('CARDIMG_DIR')) {
        define('CARDIMG_DIR', tcgPath('cardimg'));
    }
    if (!defined('EXPERIMENT_DECKS_DIR')) {
        define('EXPERIMENT_DECKS_DIR', tcgPath('experiment_decks'));
    }
    if (!defined('CARDS_FILE')) {
        define('CARDS_FILE', tcgPath('cards'));
    }
    if (!defined('TCG_CARDS_FILE')) {
        define('TCG_CARDS_FILE', CARDS_FILE);
    }
}
