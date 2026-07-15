<?php
/**
 * Assert STRINGS.es, STRINGS.ko, and STRINGS.zh each have every leaf key present in
 * STRINGS.en (locales/*.json source).
 * Exit 0 on success; exit 1 and print missing keys on failure.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$enPath = $root . '/locales/en_extracted.json';
$locales = [
    'es' => $root . '/locales/es.json',
    'ko' => $root . '/locales/ko.json',
    'zh' => $root . '/locales/zh.json',
];

if (!is_readable($enPath)) {
    fwrite(STDERR, "Missing locales/en_extracted.json\n");
    exit(1);
}

foreach ($locales as $code => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "Missing locales/{$code}.json\n");
        exit(1);
    }
}

/** @return list<string> */
function leafKeys(array $node, string $prefix = ''): array
{
    $keys = [];
    foreach ($node as $k => $v) {
        $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            $keys = array_merge($keys, leafKeys($v, $path));
        } else {
            $keys[] = $path;
        }
    }
    return $keys;
}

$en = json_decode((string) file_get_contents($enPath), true, 512, JSON_THROW_ON_ERROR);
$enKeys = leafKeys($en);

$hadFailure = false;
$summary = [];

foreach ($locales as $code => $path) {
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $localeKeys = array_flip(leafKeys($data));

    $missing = [];
    foreach ($enKeys as $key) {
        if (!isset($localeKeys[$key])) {
            $missing[] = $key;
        }
    }

    if ($missing !== []) {
        $hadFailure = true;
        fwrite(STDERR, "STRINGS.{$code} missing " . count($missing) . " key(s) from STRINGS.en:\n");
        foreach ($missing as $key) {
            fwrite(STDERR, "  - {$key}\n");
        }
    } else {
        $summary[] = "{$code}";
    }
}

if ($hadFailure) {
    exit(1);
}

echo 'i18n OK: ' . count($enKeys) . ' en keys matched in ' . implode(', ', $summary) . "\n";
