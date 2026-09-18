<?php
/**
 * Shared cards.json loader for room creation / matchmaking.
 *
 * Full multi-locale cards.json is ~4MB; decoding it on every create_room /
 * casual_join held PHP workers long enough to stack SQLite "database is locked"
 * errors and client timeouts. This helper:
 *  - caches decoded data for the life of the request
 *  - serves a disk-cached "play" catalog with oracle text stripped (much smaller)
 *  - rebuilds that cache only when cards.json changes
 */

function tcgCardsPlayCachePath(): string {
    return rtrim(tcgPath('data'), '/\\') . DIRECTORY_SEPARATOR . 'cards_play_cache.json';
}

/**
 * @return array{cards?: list<array>, starter_decks?: array}
 */
function tcgLoadCardsData(bool $includeOracleText = false): array {
    static $full = null;
    static $fullMtime = null;
    static $play = null;
    static $playMtime = null;

    $cardsFile = defined('CARDS_FILE') ? CARDS_FILE : tcgPath('cards');
    if (!is_file($cardsFile)) {
        return ['cards' => [], 'starter_decks' => []];
    }
    $mtime = (int)filemtime($cardsFile);

    if ($includeOracleText) {
        if (is_array($full) && $fullMtime === $mtime) {
            return $full;
        }
        $raw = file_get_contents($cardsFile);
        $data = json_decode(is_string($raw) ? $raw : '', true);
        $full = is_array($data) ? $data : ['cards' => [], 'starter_decks' => []];
        $fullMtime = $mtime;
        return $full;
    }

    if (is_array($play) && $playMtime === $mtime) {
        return $play;
    }

    $cacheFile = tcgCardsPlayCachePath();
    if (is_file($cacheFile) && (int)filemtime($cacheFile) >= $mtime) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['cards']) && is_array($cached['cards'])) {
            $play = $cached;
            $playMtime = $mtime;
            return $play;
        }
    }

    $data = tcgLoadCardsData(true);
    $drop = ['text', 'text_jp', 'text_es', 'text_ko', 'text_zh', 'text_th', 'text_pt', 'text_fr'];
    if (isset($data['cards']) && is_array($data['cards'])) {
        foreach ($data['cards'] as &$card) {
            if (!is_array($card)) {
                continue;
            }
            foreach ($drop as $k) {
                unset($card[$k]);
            }
        }
        unset($card);
    }

    $play = $data;
    $playMtime = $mtime;

    $dir = dirname($cacheFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $out = json_encode($play, JSON_UNESCAPED_UNICODE);
    if ($out !== false) {
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $out, LOCK_EX) !== false) {
            @rename($tmp, $cacheFile);
        } else {
            @unlink($tmp);
        }
    }

    return $play;
}
