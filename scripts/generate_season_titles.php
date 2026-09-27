<?php
/**
 * Composite a seasonal title PNG: pill template, rank icon, "2026 Season 1".
 *
 *   php scripts/generate_season_titles.php 2026-10
 *
 * Requires the GD extension. Rank icons live in client/img/ranks/.
 * Output is client/img/titles/season-{id}-{key}.png.
 */
require_once dirname(__DIR__) . '/season.php';

function tcgSeasonGenerateTitlePng(string $seasonId, int $step, string $dest): bool {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
        return false;
    }
    $step = tcgSeasonClampStep($step);
    $def = tcgSeasonStepDef($step);
    $basePath = dirname(__DIR__) . '/client/img/ranks/title-base.png';
    $iconPath = dirname(__DIR__) . '/' . tcgSeasonIconUrl($step);
    $font = dirname(__DIR__) . '/client/fonts/Nunito-Bold.ttf';
    if (!is_file($basePath) || !is_file($iconPath) || !is_file($font)) {
        return false;
    }
    $base = imagecreatefrompng($basePath);
    $icon = imagecreatefrompng($iconPath);
    if (!$base || !$icon) {
        return false;
    }
    imagealphablending($base, true);
    imagesavealpha($base, true);
    tcgSeasonRetintTitle($base, $def['tone'], $def['letter']);
    $bw = imagesx($base);
    $bh = imagesy($base);
    $target = (int)max(24, round($bh * 0.72));
    $scaled = imagescale($icon, $target, $target, IMG_BICUBIC);
    if ($scaled) {
        imagecopy($base, $scaled, (int)round($bh * 0.18), (int)round(($bh - $target) / 2), 0, 0, $target, $target);
        imagedestroy($scaled);
    }
    imagedestroy($icon);
    $text = tcgSeasonLabel($seasonId);
    $color = imagecolorallocate($base, 74, 44, 78);
    $left = (int)round($bh * 0.18) + $target + (int)round($bh * 0.16);
    $maxW = max(40, $bw - $left - 16);
    $size = max(11, (int)round($bh * 0.34));
    $textW = $maxW;
    while ($size > 11) {
        $box = imagettfbbox($size, 0, $font, $text);
        $textW = $box ? abs($box[2] - $box[0]) : $maxW;
        if ($textW <= $maxW) {
            break;
        }
        $size--;
    }
    $x = $left;
    $y = (int)round($bh * 0.64);
    imagettftext($base, $size, 0, max(8, $x), $y, $color, $font, $text);
    $dir = dirname($dest);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $ok = imagepng($base, $dest);
    imagedestroy($base);
    return (bool)$ok;
}

function tcgSeasonRetintTitle($img, string $tone, string $letter): void {
    $w = imagesx($img);
    $h = imagesy($img);
    $shift = $tone === 'green' ? 115 : 0;
    $satMul = 1.0;
    if ($tone === 'pink') {
        $satMul = $letter === 'S' ? 1.2 : ($letter === 'A' ? 1.08 : ($letter === 'B' ? 1.0 : 0.9));
    }
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($img, $x, $y);
            $a = ($rgba >> 24) & 0x7F;
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            $max = max($r, $g, $b) / 255;
            $min = min($r, $g, $b) / 255;
            $l = ($max + $min) / 2;
            $d = $max - $min;
            if ($d < 0.08 || $l > 0.92) {
                continue;
            }
            $s = $d / (1 - abs(2 * $l - 1));
            $hue = 0;
            if ($max === $r / 255) {
                $hue = 60 * fmod((($g - $b) / 255) / $d, 6);
            } elseif ($max === $g / 255) {
                $hue = 60 * ((($b - $r) / 255) / $d + 2);
            } else {
                $hue = 60 * ((($r - $g) / 255) / $d + 4);
            }
            if ($hue < 0) {
                $hue += 360;
            }
            $hue = fmod($hue + $shift, 360);
            $s = max(0, min(1, $s * $satMul));
            $c = (1 - abs(2 * $l - 1)) * $s;
            $hp = $hue / 60;
            $xx = $c * (1 - abs(fmod($hp, 2) - 1));
            $m = $l - $c / 2;
            if ($hp < 1) {
                [$rr, $gg, $bb] = [$c, $xx, 0];
            } elseif ($hp < 2) {
                [$rr, $gg, $bb] = [$xx, $c, 0];
            } elseif ($hp < 3) {
                [$rr, $gg, $bb] = [0, $c, $xx];
            } elseif ($hp < 4) {
                [$rr, $gg, $bb] = [0, $xx, $c];
            } elseif ($hp < 5) {
                [$rr, $gg, $bb] = [$xx, 0, $c];
            } else {
                [$rr, $gg, $bb] = [$c, 0, $xx];
            }
            $nr = (int)max(0, min(255, round(($rr + $m) * 255)));
            $ng = (int)max(0, min(255, round(($gg + $m) * 255)));
            $nb = (int)max(0, min(255, round(($bb + $m) * 255)));
            $col = imagecolorallocatealpha($img, $nr, $ng, $nb, $a);
            imagesetpixel($img, $x, $y, $col);
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $seasonId = $argv[1] ?? '2026-10';
    if (!preg_match('/^\d{4}-\d{2}$/', $seasonId)) {
        fwrite(STDERR, "Usage: php scripts/generate_season_titles.php YYYY-MM\n");
        exit(1);
    }
    $made = 0;
    foreach (array_keys(TCG_SEASON_STEPS) as $step) {
        $dest = dirname(__DIR__) . '/' . tcgSeasonTitleImageUrl(tcgSeasonTitleId($seasonId, (int)$step));
        if (tcgSeasonGenerateTitlePng($seasonId, (int)$step, $dest)) {
            $made++;
            echo $dest . PHP_EOL;
        }
    }
    if ($made === 0) {
        fwrite(STDERR, "No titles written (GD, font, or source art missing).\n");
        exit(1);
    }
}
