#!/usr/bin/env php
<?php
/** Report text_zh coverage for cards with rules text. */
declare(strict_types=1);
$root = dirname(__DIR__);
$data = json_decode((string) file_get_contents($root . '/cards.json'), true, 512, JSON_THROW_ON_ERROR);
$need = 0;
$have = 0;
foreach ($data['cards'] as $c) {
    $en = trim((string) ($c['text'] ?? ''));
    $jp = trim((string) ($c['text_jp'] ?? ''));
    if ($en === '' && $jp === '') {
        continue;
    }
    $need++;
    if (trim((string) ($c['text_zh'] ?? '')) !== '') {
        $have++;
    }
}
printf("text_zh coverage: %d / %d (%.1f%%)\n", $have, $need, $need ? 100.0 * $have / $need : 100.0);
exit($have === $need ? 0 : 1);
