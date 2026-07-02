#!/usr/bin/env php
<?php
/**
 * Validate cards.json ability schema against effects.php handlers.
 */
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
} else {
    require_once $root . '/src/Game/EffectRegistry.php';
}

use LLTCG\Game\EffectRegistry;

$path = $root . '/cards.json';
if (!is_file($path)) {
    fwrite(STDERR, "MISSING cards.json\n");
    exit(1);
}
$data = json_decode((string)file_get_contents($path), true);
if (!is_array($data)) {
    fwrite(STDERR, "INVALID cards.json\n");
    exit(1);
}

$known = array_fill_keys(EffectRegistry::knownAbilityTypes(), true);
$errors = 0;
$warnings = 0;
$seenTypes = [];

foreach ($data['cards'] ?? [] as $card) {
    $cardNo = (string)($card['card_no'] ?? '');
    foreach ($card['abilities'] ?? [] as $i => $ab) {
        if (!is_array($ab)) {
            fwrite(STDERR, "ERROR $cardNo ability #$i: not an object\n");
            $errors++;
            continue;
        }
        $type = trim((string)($ab['type'] ?? ''));
        if ($type === '') {
            fwrite(STDERR, "ERROR $cardNo ability #$i: missing type\n");
            $errors++;
            continue;
        }
        $seenTypes[$type] = true;
        if (!isset($known[$type])) {
            fwrite(STDERR, "WARN $cardNo: unknown ability type '$type'\n");
            $warnings++;
        }
        $trigger = trim((string)($ab['trigger'] ?? ''));
        if ($trigger === '') {
            fwrite(STDERR, "WARN $cardNo ($type): missing trigger\n");
            $warnings++;
        }
    }
}

echo 'Checked ' . count($data['cards'] ?? []) . ' cards, ' . count($seenTypes) . " ability types.\n";
if ($errors > 0) {
    fwrite(STDERR, "FAILED: $errors error(s), $warnings warning(s)\n");
    exit(1);
}
echo "OK ($warnings warning(s))\n";
