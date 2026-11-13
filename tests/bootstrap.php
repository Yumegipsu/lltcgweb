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
putenv('TCG_DEBUG=1');

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
putenv('TCG_DEBUG=1');
// create_room() loads llr_auth via tcgRequireAuthLoader(); set local fake auth before api.php
// so replay/account tests can mint tokens without a production llr_auth.php checkout.
putenv('TCG_LOCAL_FAKE_AUTH=1');
putenv('TCG_LLR_AUTH_FILE=' . $root . '/llr_auth_local.php');

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

define('TCG_API_LIB_ONLY', true);
require_once $root . '/api.php';

/**
 * Card picks always open a prompt, even with a single legal choice (player input / immersion).
 * Tests that used to rely on the old auto-pick call this to answer such prompts the way a player
 * would: choose the only option. Stops at any prompt with zero or several choices.
 */
function tcgTestPickOnlyOption(array $state, int $max = 8): array {
    for ($i = 0; $i < $max && !empty($state['pending_prompt']); $i++) {
        $pr = $state['pending_prompt'];
        $list = null;
        foreach (['candidates', 'stage_members', 'members', 'eligible', 'wr_candidates', 'wr_cards'] as $k) {
            if (is_array($pr[$k] ?? null) && $pr[$k] !== []) {
                $list = array_values($pr[$k]);
                break;
            }
        }
        if ($list === null || count($list) !== 1) {
            break;
        }
        $c = is_array($list[0]['card'] ?? null) ? array_merge($list[0]['card'], $list[0]) : $list[0];
        $id = (string)($c['instance_id'] ?? '');
        $slot = (string)($c['slot'] ?? '');
        $payload = array_filter([
            'card_id' => $id, 'card_ids' => $id !== '' ? [$id] : null, 'member_id' => $id,
            'pick_id' => $id, 'target_id' => $id, 'discard_ids' => null,
            'slot' => $slot, 'slots' => $slot !== '' ? [$slot] : null, 'from_slot' => $slot,
        ], static fn($v) => $v !== null && $v !== '' && $v !== []);
        $seq = $state['seq'] ?? null;
        $before = $pr;
        $state = actionResolvePrompt($state, (string)($pr['responder'] ?? $pr['owner'] ?? 'p1'), $payload);
        if (($state['pending_prompt'] ?? null) === $before && ($state['seq'] ?? null) === $seq) {
            break;
        }
    }
    return $state;
}
