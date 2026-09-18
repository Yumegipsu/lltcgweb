<?php
/**
 * Assert locale parity vs English, and that dotted keys used in code exist in en.
 *
 * Exit 0 on success; exit 1 and print missing keys on failure.
 *
 * Checks:
 *  1) locales/{es,ko,zh,th}.json each contain every leaf key from locales/en_extracted.json
 *  2) Dotted keys referenced via t()/tt()/data-i18n/titleKey in client/js + index.html
 *     exist in en_extracted.json (except known runtime-hydrate-only prefixes).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$enPath = $root . '/locales/en_extracted.json';
$locales = [
    'es' => $root . '/locales/es.json',
    'ko' => $root . '/locales/ko.json',
    'zh' => $root . '/locales/zh.json',
    'th' => $root . '/locales/th.json',
    'pt' => $root . '/locales/pt.json',
    'fr' => $root . '/locales/fr.json',
];

/** Keys under these prefixes are created in i18n.js hydrate, not stored as JSON leaves. */
$hydrateOnlyPrefixes = [
    'auth.',
    'phaseMsg.',
    'tut.',
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

function lookupPath(array $node, string $key): mixed
{
    $cur = $node;
    foreach (explode('.', $key) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) {
            return null;
        }
        $cur = $cur[$part];
    }
    return is_array($cur) ? null : $cur;
}

/** @return list<string> */
function collectUsedKeys(string $root, array $hydrateOnlyPrefixes): array
{
    $files = [];
    $jsDir = $root . '/client/js';
    if (is_dir($jsDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($jsDir));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.js')) {
                $files[] = $file->getPathname();
            }
        }
    }
    $index = $root . '/index.html';
    if (is_readable($index)) {
        $files[] = $index;
    }

    $patterns = [
        "/(?:^|[^.\\w])t\\(\\s*['\"]([a-z][a-zA-Z0-9]*(?:\\.[a-zA-Z0-9_]+)+)['\"]/",
        "/(?:^|[^.\\w])tt\\(\\s*['\"]([a-z][a-zA-Z0-9]*(?:\\.[a-zA-Z0-9_]+)+)['\"]/",
        "/data-i18n=[\"']([a-z][a-zA-Z0-9]*(?:\\.[a-zA-Z0-9_]+)+)[\"']/",
        "/data-i18n-placeholder=[\"']([a-z][a-zA-Z0-9]*(?:\\.[a-zA-Z0-9_]+)+)[\"']/",
        "/data-i18n-title=[\"']([a-z][a-zA-Z0-9]*(?:\\.[a-zA-Z0-9_]+)+)[\"']/",
        "/(?:titleKey|subtitleKey|subKey)\\s*:\\s*['\"]([a-z][a-zA-Z0-9]*(?:\\.[a-zA-Z0-9_]+)+)['\"]/",
    ];

    $used = [];
    foreach ($files as $path) {
        $txt = (string) file_get_contents($path);
        foreach ($patterns as $re) {
            if (preg_match_all($re, $txt, $m)) {
                foreach ($m[1] as $key) {
                    $hydrate = false;
                    foreach ($hydrateOnlyPrefixes as $prefix) {
                        if (str_starts_with($key, $prefix)) {
                            $hydrate = true;
                            break;
                        }
                    }
                    if ($hydrate) {
                        continue;
                    }
                    $used[$key] = true;
                }
            }
        }
    }
    $keys = array_keys($used);
    sort($keys);
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

$usedKeys = collectUsedKeys($root, $hydrateOnlyPrefixes);
$missingInEn = [];
foreach ($usedKeys as $key) {
    if (lookupPath($en, $key) === null) {
        $missingInEn[] = $key;
    }
}
if ($missingInEn !== []) {
    $hadFailure = true;
    fwrite(STDERR, "en_extracted.json missing " . count($missingInEn) . " key(s) used in code:\n");
    foreach ($missingInEn as $key) {
        fwrite(STDERR, "  - {$key}\n");
    }
}

if ($hadFailure) {
    exit(1);
}

echo 'i18n OK: ' . count($enKeys) . ' en keys matched in ' . implode(', ', $summary)
    . '; ' . count($usedKeys) . " code keys present\n";
