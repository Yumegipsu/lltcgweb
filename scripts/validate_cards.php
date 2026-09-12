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

require_once $root . '/loveca_points.php';
require_once $root . '/deck_validate.php';

$lovecaMap = tcgGetLovecaPointMap();
$cardNos = [];
foreach ($data['cards'] ?? [] as $card) {
    $no = trim((string) ($card['card_no'] ?? ''));
    if ($no !== '') {
        $cardNos[$no] = true;
    }
}
foreach ($lovecaMap as $no => $pts) {
    if (!isset($cardNos[$no])) {
        fwrite(STDERR, "ERROR loveca_points.json: unknown card_no $no\n");
        $errors++;
    }
}
foreach ($data['starter_decks'] ?? [] as $key => $starter) {
    if (!is_array($starter)) {
        continue;
    }
    $main = $starter['main_deck'] ?? [];
    if (!is_array($main)) {
        continue;
    }
    $pts = tcgSumMainDeckLovecaPoints($main);
    $limit = tcgLovecaPointLimit();
    if ($pts > $limit) {
        fwrite(STDERR, "ERROR starter_decks.$key: loveca points $pts exceed limit $limit\n");
        $errors++;
    }
}

// Protein-bar promo reprints must not use another card's face art (issue #179).
foreach ($data['cards'] ?? [] as $card) {
    $no = (string)($card['card_no'] ?? '');
    if (!str_ends_with($no, '-PRproteinbar')) {
        continue;
    }
    $identity = substr($no, 0, -strlen('-PRproteinbar'));
    $image = (string)($card['image'] ?? '');
    $base = basename(parse_url($image, PHP_URL_PATH) ?: $image);
    if ($base === '' || $identity === '') {
        fwrite(STDERR, "ERROR $no: missing proteinbar image\n");
        $errors++;
        continue;
    }
    if (!str_starts_with($base, $identity . '-') && !str_starts_with($base, $identity . '.')) {
        fwrite(STDERR, "ERROR $no: image basename '$base' does not match identity '$identity' (wrong promo art)\n");
        $errors++;
    }
}

if ($errors > 0) {
    fwrite(STDERR, "FAILED: $errors error(s), $warnings warning(s)\n");
    exit(1);
}
echo "OK ($warnings warning(s))\n";
