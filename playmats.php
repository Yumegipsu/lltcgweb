<?php
/**
 * Playmat ids stored on deck presets / match seats.
 * Catalog: playmats_catalog.json; server sanitizes id + brightness.
 */
/** Retired playmat ids → the entry that replaced them (same art; ownership is merged by a db.php migration). */
if (!defined('TCG_PLAYMAT_ID_ALIASES')) {
    define('TCG_PLAYMAT_ID_ALIASES', [
        'md-10736756' => 'br-rmv2-328',
    ]);
}

if (!function_exists('tcgNormalizePlaymatId')) {
    function tcgNormalizePlaymatId(mixed $raw): string {
        $id = strtolower(trim((string)$raw));
        if ($id === '' || $id === 'none' || $id === 'default') {
            return '';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $id)) {
            return '';
        }
        return TCG_PLAYMAT_ID_ALIASES[$id] ?? $id;
    }
}

if (!function_exists('tcgNormalizePlaymatBrightness')) {
    /** Clamp brightness to [0.35, 1.0]; invalid → 1.0. */
    function tcgNormalizePlaymatBrightness(mixed $raw): float {
        if ($raw === null || $raw === '') {
            return 1.0;
        }
        if (!is_numeric($raw)) {
            return 1.0;
        }
        $v = floatval($raw);
        if (!is_finite($v)) {
            return 1.0;
        }
        if ($v >= 35.0 && $v <= 100.0) {
            // Allow 35–100 style percentages from older clients.
            $v = $v / 100.0;
        }
        if ($v < 0.35) {
            return 0.35;
        }
        if ($v > 1.0) {
            return 1.0;
        }
        return round($v, 3);
    }
}

if (!function_exists('tcgPlaymatIdFromBodyOrRow')) {
    function tcgPlaymatIdFromBodyOrRow(array $body, mixed $stored = null): string {
        if (array_key_exists('playmat_id', $body)) {
            return tcgNormalizePlaymatId($body['playmat_id']);
        }
        if (is_array($stored) && array_key_exists('playmat_id', $stored)) {
            return tcgNormalizePlaymatId($stored['playmat_id']);
        }
        return tcgNormalizePlaymatId($stored);
    }
}

if (!function_exists('tcgPlaymatBrightnessFromBodyOrRow')) {
    function tcgPlaymatBrightnessFromBodyOrRow(array $body, mixed $stored = null): float {
        if (array_key_exists('playmat_brightness', $body)) {
            return tcgNormalizePlaymatBrightness($body['playmat_brightness']);
        }
        if (is_array($stored) && array_key_exists('playmat_brightness', $stored)) {
            return tcgNormalizePlaymatBrightness($stored['playmat_brightness']);
        }
        if ($stored !== null && !is_array($stored)) {
            return tcgNormalizePlaymatBrightness($stored);
        }
        return 1.0;
    }
}
