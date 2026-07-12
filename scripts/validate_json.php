#!/usr/bin/env php
<?php
/**
 * Validate JSON data files required by the TCG app.
 */
$root = dirname(__DIR__);
$files = [
    'cards.json',
    'tutorial.json',
    'tutorial_guide.json',
    'tutorial_ja.json',
    'tutorial_es.json',
    'tutorial_ko.json',
    'pack_listings.json',
    'sfx_manifest.web.json',
    'playmat_zones.json',
    'news.json',
];

$errors = 0;
foreach ($files as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        if ($rel === 'tutorial.json' || $rel === 'tutorial_guide.json') {
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
    if ($rel === 'news.json') {
        $data = json_decode($raw, true);
        if (!is_array($data['posts'] ?? null)) {
            fwrite(STDERR, "INVALID news.json: missing posts array\n");
            $errors++;
            continue;
        }
        foreach ($data['posts'] as $i => $post) {
            if (empty($post['id'])) {
                fwrite(STDERR, "INVALID news.json: post $i missing id\n");
                $errors++;
            }
            foreach (['title', 'body'] as $field) {
                foreach (['en', 'ja'] as $loc) {
                    if (!isset($post[$field][$loc]) || trim((string) $post[$field][$loc]) === '') {
                        fwrite(STDERR, "INVALID news.json: post $i missing $field.$loc\n");
                        $errors++;
                    }
                }
            }
            if (isset($post['bannerStyle'])) {
                $bs = strtolower(trim((string) $post['bannerStyle']));
                if (!in_array($bs, ['crop', 'full', 'wide'], true)) {
                    fwrite(STDERR, "INVALID news.json: post $i bannerStyle must be crop, full, or wide\n");
                    $errors++;
                }
            }
        }
    }
    echo "OK $rel\n";
}

if ($errors > 0) {
    exit(1);
}
echo "All JSON files valid.\n";
