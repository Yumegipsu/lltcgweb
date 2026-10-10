<?php
/**
 * Birthday gacha banners: limited banners live only during the 24 hours (JST) of an idol's birthday.
 * Standard pool + every non-PR card of that idol from ALL packs (premium, DUO, newest booster...),
 * with a relative rate boost on all of the idol's cards.
 *
 * Birthdays + image colors mirror Chiichan (!!bday and get_idol_data).
 */

/** Relative weight of the birthday idol's cards versus the rest of their rarity band. */
const TCG_GACHA_BDAY_BOOST = 2.0;

/** Banner background = the idol color darkened by this factor. */
const TCG_GACHA_BDAY_DARKEN = 0.55;

/**
 * idol given-name key => [month, day, image color].
 *
 * @return array<string,array{0:int,1:int,2:string}>
 */
function tcgGachaBirthdayTable(): array {
    return [
    'dia' => [1, 1, '#f23b4c'],
    'aurora' => [1, 3, '#ff5b9d'],
    'sayaka' => [1, 13, '#5383c3'],
    'hanayo' => [1, 17, '#54ab48'],
    'wien' => [1, 20, '#d7bfe3'],
    'kasumi' => [1, 23, '#ffe41c'],
    'yu' => [1, 29, '#8a8a9e'],
    'emma' => [2, 5, '#8ec225'],
    'kanan' => [2, 10, '#13e8ae'],
    'mai' => [2, 13, '#4d93d9'],
    'lanzhu' => [2, 15, '#f8c8c4'],
    'chisato' => [2, 25, '#ff6d91'],
    'kosuzu' => [2, 28, '#fad764'],
    'ayumu' => [3, 1, '#ee879d'],
    'miracle' => [3, 2, '#ffb6c1'],
    'hanamaru' => [3, 4, '#e6d617'],
    'umi' => [3, 15, '#1660a5'],
    'shizuku' => [4, 3, '#73c9f3'],
    'noriko' => [4, 4, '#cc66ff'],
    'kinako' => [4, 10, '#fff442'],
    'you' => [4, 17, '#49b9f9'],
    'maki' => [4, 19, '#cc3554'],
    'kanon' => [5, 1, '#ff7f26'],
    'sarah' => [5, 4, '#87ceeb'],
    'midori' => [5, 7, '#3fbf7f'],
    'kaho' => [5, 22, '#f8b500'],
    'ai' => [5, 30, '#f18f3d'],
    'nozomi' => [6, 9, '#744791'],
    'hanabi' => [6, 11, '#ff4747'],
    'mari' => [6, 13, '#ae58eb'],
    'kozue' => [6, 15, '#68be8d'],
    'shiki' => [6, 17, '#b2ffdd'],
    'ceras' => [6, 26, '#f56455'],
    'karin' => [6, 29, '#565ea9'],
    'akira' => [7, 9, '#b5e6a2'],
    'yoshiko' => [7, 13, '#898989'],
    'keke' => [7, 17, '#a3fcfa'],
    'nico' => [7, 22, '#d54e8d'],
    'chika' => [8, 1, '#f0a20b'],
    'honoka' => [8, 3, '#f09b21'],
    'natsumi' => [8, 7, '#ff51c4'],
    'setsuna' => [8, 8, '#e94c53'],
    'yuna' => [8, 11, '#ffff00'],
    'polka' => [8, 18, '#ffff66'],
    'rurino' => [8, 31, '#e7609e'],
    'kotori' => [9, 12, '#8c9395'],
    'riko' => [9, 19, '#e9a9e8'],
    'ruby' => [9, 21, '#fb75e4'],
    'yukuri' => [9, 22, '#c0e6f5'],
    'hime' => [9, 24, '#9d8de2'],
    'sumire' => [9, 28, '#75f467'],
    'shioriko' => [10, 5, '#40d096'],
    'ginko' => [10, 20, '#a2d7dd'],
    'eli' => [10, 21, '#36b3dd'],
    'mei' => [10, 29, '#ff3535'],
    'rin' => [11, 1, '#f1c51f'],
    'shion' => [11, 11, '#f2f2f2'],
    'rina' => [11, 13, '#9aa3aa'],
    'tsuzuri' => [11, 17, '#c22d3b'],
    'ren' => [11, 24, '#0000a0'],
    'izumi' => [12, 1, '#1ebecd'],
    'mao' => [12, 2, '#ff00ff'],
    'mia' => [12, 6, '#d6d5ca'],
    'leah' => [12, 12, '#dde6ed'],
    'kanata' => [12, 16, '#b44e8f'],
    'megumi' => [12, 20, '#c8c2c6'],
    'tomari' => [12, 28, '#4dd2e1'],
    ];
}

/** Whole-word given name match on single-idol Member / Energy cards (no Lives, no group cards). */
function tcgGachaCardMatchesIdol(array $card, string $idolKey): bool {
    if (($card['card_type_en'] ?? '') === 'Live' || ($card['card_type'] ?? '') === 'ライブ') {
        return false;
    }
    $name = (string)($card['name_en'] ?? '');
    if ($name === '' || preg_match('/[&＆]/u', $name)) {
        return false;
    }
    $tokens = preg_split('/[\s\x{3000}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return in_array($idolKey, $tokens, true);
}

/** Non-PR, real-pack card that may be added to a birthday pool from any product. */
function tcgGachaBirthdayExtraEligible(array $card): bool {
    $no = trim((string)($card['card_no'] ?? ''));
    if ($no === '') {
        return false;
    }
    if (function_exists('tcgIsStarterBasicEnergyCard') && tcgIsStarterBasicEnergyCard($no)) {
        return false;
    }
    if (function_exists('tcgCardEligibleForPrBoosterPool') && tcgCardEligibleForPrBoosterPool($card)) {
        return false;
    }
    $r = tcgNormalizePoolRarity((string)($card['rarity'] ?? 'N'), $no);
    if ($r === 'PR' || $r === 'PR+' || str_starts_with($r, 'PR')) {
        return false;
    }
    return (string)($card['booster_pack'] ?? '') !== 'PRカード';
}

/** @return array{0:int,1:int,2:int} month, day, unix time of the NEXT JST midnight */
function tcgGachaJstDayInfo(?int $now = null): array {
    $ts = $now ?? tcgGachaNow();
    $tz = new DateTimeZone('Asia/Tokyo');
    $jst = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    $next = $jst->setTime(0, 0, 0)->modify('+1 day');
    return [(int)$jst->format('n'), (int)$jst->format('j'), $next->getTimestamp()];
}

function tcgGachaHexToRgb(string $hex): array {
    $h = ltrim($hex, '#');
    if (strlen($h) !== 6) {
        $h = '8a8a9e';
    }
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}

function tcgGachaRelativeLuminance(array $rgb): float {
    $lin = static function (int $c): float {
        $s = $c / 255;
        return $s <= 0.03928 ? $s / 12.92 : pow(($s + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * $lin((int)$rgb[0]) + 0.7152 * $lin((int)$rgb[1]) + 0.0722 * $lin((int)$rgb[2]);
}

/** @return array{bg:string,fg:string} darkened banner color + black or white, whichever reads better */
function tcgGachaBirthdayColors(string $baseHex): array {
    [$r, $g, $b] = tcgGachaHexToRgb($baseHex);
    $f = TCG_GACHA_BDAY_DARKEN;
    $dark = [(int)round($r * $f), (int)round($g * $f), (int)round($b * $f)];
    $lum = tcgGachaRelativeLuminance($dark);
    $contrastWhite = 1.05 / ($lum + 0.05);
    $contrastBlack = ($lum + 0.05) / 0.05;
    return [
        'bg' => sprintf('#%02x%02x%02x', $dark[0], $dark[1], $dark[2]),
        'fg' => $contrastBlack > $contrastWhite ? '#000000' : '#ffffff',
    ];
}

/**
 * Banners live right now (JST date == an idol's birthday). An idol with no eligible card
 * in the game has no banner.
 *
 * @return list<array<string,mixed>>
 */
function tcgGachaBirthdayBanners(array $cardsData, ?int $now = null): array {
    [$month, $day, $endsAt] = tcgGachaJstDayInfo($now);
    $out = [];
    foreach (tcgGachaBirthdayTable() as $key => $row) {
        if ($row[0] !== $month || $row[1] !== $day) {
            continue;
        }
        $names = [];
        $count = 0;
        $artCard = '';
        foreach ($cardsData['cards'] ?? [] as $card) {
            if (!is_array($card) || !tcgGachaCardMatchesIdol($card, (string)$key)) {
                continue;
            }
            if (!tcgGachaBirthdayExtraEligible($card)) {
                continue;
            }
            $count++;
            if ($artCard === '' && ($card['card_type_en'] ?? '') === 'Member') {
                $artCard = (string)($card['card_no'] ?? '');
            }
            $n = trim((string)($card['name_en'] ?? ''));
            if ($n !== '' && $n !== mb_strtoupper($n)) {
                $names[$n] = ($names[$n] ?? 0) + 1;
            }
        }
        if ($count === 0) {
            continue;
        }
        arsort($names);
        $full = $names ? (string)array_key_first($names) : ucfirst((string)$key);
        $colors = tcgGachaBirthdayColors($row[2]);
        $out[] = [
            'id' => 'birthday:' . $key,
            'type' => 'birthday',
            'idol_key' => (string)$key,
            'name_en' => $full,
            'month' => $month,
            'day' => $day,
            'date_label' => $month . '.' . $day,
            'color' => $row[2],
            'bg' => $colors['bg'],
            'fg' => $colors['fg'],
            'ends_at' => $endsAt,
            'card_count' => $count,
            // Fallback art when assets/gacha/renders/<idol>.png is missing (e.g. Wien, Yu).
            'art_card_no' => $artCard,
        ];
    }
    return $out;
}

/** The active birthday banner for $idolKey, or null (expired / not their birthday). */
function tcgGachaActiveBirthdayBanner(array $cardsData, string $idolKey, ?int $now = null): ?array {
    foreach (tcgGachaBirthdayBanners($cardsData, $now) as $banner) {
        if ($banner['idol_key'] === $idolKey) {
            return $banner;
        }
    }
    return null;
}

/**
 * Standard pools + the idol's non-PR cards from every pack, with the rate boost recorded in
 * `_w` (card_no => weight) for every card of that idol.
 *
 * @return array{n:list<string>,sr:list<string>,ur:list<string>,all:list<string>,_w:array<string,float>}
 */
function tcgGachaBuildBirthdayPools(array $cardsData, string $idolKey, ?int $now = null): array {
    $pools = tcgGachaBuildPools($cardsData, $now);
    $inStandard = array_fill_keys($pools['all'], true);
    $weights = [];
    foreach ($cardsData['cards'] ?? [] as $card) {
        if (!is_array($card) || !tcgGachaCardMatchesIdol($card, $idolKey)) {
            continue;
        }
        $no = trim((string)($card['card_no'] ?? ''));
        if ($no === '') {
            continue;
        }
        if (!isset($inStandard[$no])) {
            if (!tcgGachaBirthdayExtraEligible($card)) {
                continue;
            }
            $r = tcgNormalizePoolRarity((string)($card['rarity'] ?? 'N'), $no);
            $tier = tcgGachaTierForRarity($r);
            $pools[$tier][] = $no;
            $pools['all'][] = $no;
        }
        $weights[$no] = TCG_GACHA_BDAY_BOOST;
    }
    foreach (['n', 'sr', 'ur', 'all'] as $k) {
        $pools[$k] = array_values(array_unique($pools[$k]));
    }
    $pools['_w'] = $weights;
    return $pools;
}
