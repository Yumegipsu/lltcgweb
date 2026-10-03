<?php
/**
 * Official English subunit names (from tcg/cards.json energy cards + app catalog).
 * Internal game logic keeps JP keys on cards; use subunitDisplayEn() for player-facing copy.
 */

const SUBUNIT_EN = [
    'スリーズブーケ' => 'Cerise Bouquet',
    'Cerise Bouquet' => 'Cerise Bouquet',
    'みらくらぱーく！' => 'Mira-Cra Park!',
    'みらくらぱーく!' => 'Mira-Cra Park!',
    'Mirakuru Park!' => 'Mira-Cra Park!',
    'Mira-Cra Park!' => 'Mira-Cra Park!',
    'DOLLCHESTRA' => 'DOLLCHESTRA',
    'Edel Note' => 'Edel Note',
    'E del Note' => 'Edel Note',
    '5yncri5e!' => '5yncri5e!',
    'KALEIDOSCORE' => 'KALEIDOSCORE',
    'プリパラ' => 'Printemps',
    'Printemps' => 'Printemps',
    'リリホワ' => 'lily white',
    'lily white' => 'lily white',
    'バイバイ' => 'BiBi',
    'BiBi' => 'BiBi',
    'CYaRon！' => 'CYaRon!',
    'CYaRon!' => 'CYaRon!',
    'シャロン' => 'CYaRon!',
    'アゼリア' => 'AZALEA',
    'AZALEA' => 'AZALEA',
    'ギルキス' => 'Guilty Kiss',
    'Guilty Kiss' => 'Guilty Kiss',
    'セイントスノー' => 'Saint Snow',
    'Saint Snow' => 'Saint Snow',
    'Sunny Passion' => 'Sunny Passion',
    'Aqours/Saint Snow' => 'Aqours/Saint Snow',
    'A・ZU・NA' => 'A・ZU・NA',
    'QU4RTZ' => 'QU4RTZ',
    'R3BIRTH' => 'R3BIRTH',
    'ダイバーディーバ' => 'DiverDiva',
    'DiverDiva' => 'DiverDiva',
    'キャチュー' => 'CatChu!',
    'CatChu!' => 'CatChu!',
    'A-RISE' => 'A-RISE',
];

function subunitDisplayEn(string $subunit): string
{
    if ($subunit === '') {
        return $subunit;
    }
    if (isset(SUBUNIT_EN[$subunit])) {
        return SUBUNIT_EN[$subunit];
    }
    return str_replace('！', '!', $subunit);
}

/**
 * Infer subunit from a Member's character name when catalog subunit is missing.
 * Single-idol μ's / Aqours / etc. only — multi-name (&) cards are left alone. Refs #230.
 */
function inferMemberSubunitFromName(array $card): string
{
    $typeEn = (string)($card['card_type_en'] ?? '');
    $typeJp = (string)($card['card_type'] ?? '');
    $isMember = ($typeEn === 'Member' || $typeJp === 'メンバー'
        || (function_exists('isMemberCard') && isMemberCard($card)));
    if (!$isMember) {
        return '';
    }

    $nameEn = trim((string)($card['name_en'] ?? ''));
    $nameJp = trim((string)($card['name'] ?? ''));
    if ($nameEn === '' && $nameJp === '') {
        return '';
    }
    // Duo / trio prints must not guess a single subunit.
    foreach ([$nameEn, $nameJp] as $label) {
        if ($label !== '' && (str_contains($label, ' & ') || str_contains($label, '&')
            || str_contains($label, '＆') || str_contains($label, '／'))) {
            return '';
        }
    }

    static $map = null;
    if ($map === null) {
        $map = [
            // μ's — Printemps
            'Honoka Kosaka' => 'Printemps',
            'Kotori Minami' => 'Printemps',
            'Hanayo Koizumi' => 'Printemps',
            '高坂穂乃果' => 'Printemps',
            '南ことり' => 'Printemps',
            '小泉花陽' => 'Printemps',
            // μ's — lily white
            'Umi Sonoda' => 'lily white',
            'Rin Hoshizora' => 'lily white',
            'Nozomi Tojo' => 'lily white',
            '園田海未' => 'lily white',
            '星空凛' => 'lily white',
            '星空 凛' => 'lily white',
            '東條希' => 'lily white',
            '東條 希' => 'lily white',
            // μ's — BiBi
            'Eli Ayase' => 'BiBi',
            'Maki Nishikino' => 'BiBi',
            'Nico Yazawa' => 'BiBi',
            '絢瀬絵里' => 'BiBi',
            '西木野真姫' => 'BiBi',
            '矢澤にこ' => 'BiBi',
            // Aqours — CYaRon!
            'Chika Takami' => 'CYaRon!',
            'You Watanabe' => 'CYaRon!',
            'Ruby Kurosawa' => 'CYaRon!',
            '高海千歌' => 'CYaRon!',
            '渡辺曜' => 'CYaRon!',
            '黒澤ルビィ' => 'CYaRon!',
            // Aqours — AZALEA
            'Kanan Matsuura' => 'AZALEA',
            'Dia Kurosawa' => 'AZALEA',
            'Hanamaru Kunikida' => 'AZALEA',
            '松浦果南' => 'AZALEA',
            '黒澤ダイヤ' => 'AZALEA',
            '国木田花丸' => 'AZALEA',
            // Aqours — Guilty Kiss
            'Riko Sakurauchi' => 'Guilty Kiss',
            'Yoshiko Tsushima' => 'Guilty Kiss',
            'Mari Ohara' => 'Guilty Kiss',
            '桜内梨子' => 'Guilty Kiss',
            '津島善子' => 'Guilty Kiss',
            '小原鞠莉' => 'Guilty Kiss',
        ];
    }

    if ($nameEn !== '' && isset($map[$nameEn])) {
        return $map[$nameEn];
    }
    if ($nameJp !== '' && isset($map[$nameJp])) {
        return $map[$nameJp];
    }
    $nameJpCompact = preg_replace('/\s+/u', '', $nameJp) ?? $nameJp;
    if ($nameJpCompact !== '' && isset($map[$nameJpCompact])) {
        return $map[$nameJpCompact];
    }
    return '';
}

function localizeSubunitText(?string $text): string
{
    if ($text === null || $text === '') {
        return (string)$text;
    }
    $out = str_replace('Mirakuru Park!', 'Mira-Cra Park!', $text);
    $keys = array_keys(SUBUNIT_EN);
    usort($keys, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    foreach ($keys as $jp) {
        $en = SUBUNIT_EN[$jp];
        if ($jp !== $en && str_contains($out, $jp)) {
            $out = str_replace($jp, $en, $out);
        }
    }
    return $out;
}
