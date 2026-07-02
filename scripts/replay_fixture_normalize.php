#!/usr/bin/env php
<?php
/**
 * Normalize a debug replay export for tests/fixtures (strip tokens, validate schema).
 * Usage: php scripts/replay_fixture_normalize.php path/to/replay.json [output.json]
 */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/replay_fixture_normalize.php <input.json> [output.json]\n");
    exit(1);
}

$root = dirname(__DIR__);
define('TCG_API_LIB_ONLY', true);
require_once $root . '/replay.php';

$in = $argv[1];
$out = $argv[2] ?? $in;
$replay = json_decode((string)file_get_contents($in), true);
if (!is_array($replay)) {
    fwrite(STDERR, "Invalid JSON: $in\n");
    exit(1);
}

if (isset($replay['baseline']) && is_array($replay['baseline'])) {
    $replay['baseline']['room_id'] = 'FIXTURE';
    unset($replay['baseline']['action_log'], $replay['baseline']['replay_baseline']);
    foreach (['p1', 'p2'] as $pid) {
        if (isset($replay['baseline']['players'][$pid]['token'])) {
            unset($replay['baseline']['players'][$pid]['token']);
        }
    }
}
if (isset($replay['meta']) && is_array($replay['meta'])) {
    $replay['meta']['room_id'] = 'FIXTURE';
}

validateReplayFile($replay);
file_put_contents($out, json_encode($replay, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
echo "Normalized replay written to $out\n";
