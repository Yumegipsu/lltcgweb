<?php
/**
 * Report text_ko coverage grouped by booster / starter patterns (batches 5a–5e).
 *
 * Usage: php scripts/card_text_ko_coverage.php
 */
$root = dirname(__DIR__);
$cardsPath = $root . '/cards.json';

if (!is_file($cardsPath)) {
    fwrite(STDERR, "MISSING cards.json\n");
    exit(1);
}

$data = json_decode(file_get_contents($cardsPath), true);
if (!is_array($data) || !is_array($data['cards'] ?? null)) {
    fwrite(STDERR, "INVALID cards.json\n");
    exit(1);
}

function hasNonEmptyText(array $card): bool
{
    return trim((string) ($card['text'] ?? '')) !== ''
        || trim((string) ($card['text_jp'] ?? '')) !== '';
}

function hasTextKo(array $card): bool
{
    return trim((string) ($card['text_ko'] ?? '')) !== '';
}

function batchBucket(array $card): ?string
{
    $no = (string) ($card['card_no'] ?? '');

    if (preg_match('/-SD1-/i', $no)) {
        return '5a:sd1';
    }
    if (preg_match('/-SD2-/i', $no)) {
        return '5a:sd2';
    }
    if (str_contains(strtoupper($no), 'STARTER DECK')) {
        return '5a:starter';
    }
    $bp = (string) ($card['booster_pack'] ?? '');
    if (str_contains($bp, 'スタートデッキ')) {
        return '5a:startdeck_bp';
    }

    $bp1Patterns = ['PL!N-bp1-', 'PL!HS-bp1-', 'PL!SP-bp1-', 'LL-bp1-', 'PL!-bp1-'];
    foreach ($bp1Patterns as $pat) {
        if (stripos($no, $pat) !== false) {
            return '5b:bp01';
        }
    }

    if (preg_match('/-bp2-/i', $no) || preg_match('/-bp3-/i', $no) || preg_match('/-bp4-/i', $no)) {
        return '5c:bp02-04';
    }

    if (preg_match('/-PR-/i', $no) || preg_match('/-pb\d+-/i', $no) || preg_match('/-CL\d+-/i', $no)) {
        return '5d:sp_premium';
    }

    return '5e:other';
}

function pct(int $done, int $total): string
{
    if ($total === 0) {
        return '—';
    }

    return sprintf('%.1f%%', 100.0 * $done / $total);
}

$labels = [
    '5a:sd1' => '5a SD1',
    '5a:sd2' => '5a SD2',
    '5a:starter' => '5a STARTER DECK',
    '5a:startdeck_bp' => '5a スタートデッキ',
    '5b:bp01' => '5b BP01 (PL!N/HS/SP/LL bp1)',
    '5c:bp02-04' => '5c BP02–BP04',
    '5d:sp_premium' => '5d PR / pb / CL premium',
    '5e:other' => '5e remaining',
];

$groups = [
    'All cards' => ['total' => 0, 'with_text' => 0, 'with_ko' => 0],
    '5a starters' => ['total' => 0, 'with_text' => 0, 'with_ko' => 0],
    '5b BP01' => ['total' => 0, 'with_text' => 0, 'with_ko' => 0],
    '5d premium' => ['total' => 0, 'with_text' => 0, 'with_ko' => 0],
    '5e remaining' => ['total' => 0, 'with_text' => 0, 'with_ko' => 0],
];

$sub = [];

foreach ($data['cards'] as $card) {
    $groups['All cards']['total']++;
    if (hasNonEmptyText($card)) {
        $groups['All cards']['with_text']++;
    }
    if (hasTextKo($card)) {
        $groups['All cards']['with_ko']++;
    }

    $bucket = batchBucket($card);
    if ($bucket === null) {
        continue;
    }

    if (!isset($sub[$bucket])) {
        $sub[$bucket] = ['total' => 0, 'with_text' => 0, 'with_ko' => 0];
    }
    $sub[$bucket]['total']++;
    if (hasNonEmptyText($card)) {
        $sub[$bucket]['with_text']++;
    }
    if (hasTextKo($card)) {
        $sub[$bucket]['with_ko']++;
    }

    if (str_starts_with($bucket, '5a:')) {
        $groups['5a starters']['total']++;
        if (hasNonEmptyText($card)) {
            $groups['5a starters']['with_text']++;
        }
        if (hasTextKo($card)) {
            $groups['5a starters']['with_ko']++;
        }
    }
    if ($bucket === '5b:bp01') {
        $groups['5b BP01']['total']++;
        if (hasNonEmptyText($card)) {
            $groups['5b BP01']['with_text']++;
        }
        if (hasTextKo($card)) {
            $groups['5b BP01']['with_ko']++;
        }
    }
    if ($bucket === '5d:sp_premium') {
        $groups['5d premium']['total']++;
        if (hasNonEmptyText($card)) {
            $groups['5d premium']['with_text']++;
        }
        if (hasTextKo($card)) {
            $groups['5d premium']['with_ko']++;
        }
    }
    if ($bucket === '5e:other') {
        $groups['5e remaining']['total']++;
        if (hasNonEmptyText($card)) {
            $groups['5e remaining']['with_text']++;
        }
        if (hasTextKo($card)) {
            $groups['5e remaining']['with_ko']++;
        }
    }
}

echo "Love Live TCG — text_ko coverage\n";
echo str_repeat('=', 72) . "\n";
printf("%-48s %6s %8s %8s %7s\n", 'Group', 'cards', 'w/ text', 'text_ko', '%');
echo str_repeat('-', 72) . "\n";

foreach ($groups as $label => $g) {
    printf(
        "%-48s %6d %8d %8d %7s\n",
        $label,
        $g['total'],
        $g['with_text'],
        $g['with_ko'],
        pct($g['with_ko'], $g['with_text'])
    );
}

echo "\nBy batch pattern:\n";
echo str_repeat('-', 72) . "\n";
foreach ($labels as $key => $label) {
    if (!isset($sub[$key])) {
        continue;
    }
    $g = $sub[$key];
    printf(
        "%-48s %6d %8d %8d %7s\n",
        $label,
        $g['total'],
        $g['with_text'],
        $g['with_ko'],
        pct($g['with_ko'], $g['with_text'])
    );
}

echo "\nRun: python scripts/translate_text_ko_batch.py --batch 5d\n";
