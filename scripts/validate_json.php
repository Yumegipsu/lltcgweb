#!/usr/bin/env php
<?php
/**
 * Validate JSON data files required by the TCG app.
 */
$root = dirname(__DIR__);
$files = [
    'cards.json',
    'tutorial.json',
    'tutorial_ja.json',
    'pack_listings.json',
    'sfx_manifest.web.json',
    'playmat_zones.json',
];

$errors = 0;
foreach ($files as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        if ($rel === 'tutorial.json') {
            fwrite(STDERR, "SKIP missing optional: $rel\n");
            continue;
        }
        fwrite(STDERR, "MISSING: $rel\n");
        $errors++;
        continue;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, "READ FAIL: $rel\n");
        $errors++;
        continue;
    }
    json_decode($raw);
    if (json_last_error() !== JSON_ERROR_NONE) {
        fwrite(STDERR, "INVALID JSON ($rel): " . json_last_error_msg() . "\n");
        $errors++;
        continue;
    }
    echo "OK $rel\n";
}

if ($errors > 0) {
    exit(1);
}
echo "All JSON files valid.\n";
