<?php
/**
 * Loveca Point System — deck construction budget for restricted cards.
 * Data: config/loveca_points.json (official list, April 2026).
 */

function tcgLovecaPointsConfigPath(): string {
    return __DIR__ . '/config/loveca_points.json';
}

/** @var array<string, mixed>|null */
$GLOBALS['_tcg_loveca_config'] = null;

function tcgLoadLovecaPointsConfig(): array {
    if (is_array($GLOBALS['_tcg_loveca_config'])) {
        return $GLOBALS['_tcg_loveca_config'];
    }
    $path = tcgLovecaPointsConfigPath();
    if (!is_file($path)) {
        $GLOBALS['_tcg_loveca_config'] = ['limit' => 9, 'cards' => []];
        return $GLOBALS['_tcg_loveca_config'];
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($data)) {
        $GLOBALS['_tcg_loveca_config'] = ['limit' => 9, 'cards' => []];
        return $GLOBALS['_tcg_loveca_config'];
    }
    $GLOBALS['_tcg_loveca_config'] = $data;
    return $data;
}

function tcgLovecaPointLimit(): int {
    $cfg = tcgLoadLovecaPointsConfig();
    $limit = intval($cfg['limit'] ?? 9);
    return $limit > 0 ? $limit : 9;
}

/** @return array<string, int> */
function tcgGetLovecaPointMap(): array {
    $cfg = tcgLoadLovecaPointsConfig();
    $cards = $cfg['cards'] ?? [];
    if (!is_array($cards)) {
        return [];
    }
    $map = [];
    foreach ($cards as $no => $pts) {
        $no = trim((string) $no);
        if ($no === '') {
            continue;
        }
        $map[$no] = max(0, intval($pts));
    }
    return $map;
}

function tcgGetLovecaPointForCardNo(string $cardNo): int {
    $cardNo = trim($cardNo);
    if ($cardNo === '') {
        return 0;
    }
    $map = tcgGetLovecaPointMap();
    return $map[$cardNo] ?? 0;
}

function tcgSumMainDeckLovecaPoints(array $mainDeck): int {
    $total = 0;
    foreach ($mainDeck as $no) {
        $total += tcgGetLovecaPointForCardNo((string) $no);
    }
    return $total;
}

function tcgValidateLovecaPointBudget(array $mainDeck): ?string {
    $total = tcgSumMainDeckLovecaPoints($mainDeck);
    $limit = tcgLovecaPointLimit();
    if ($total > $limit) {
        return "Loveca point total is $total (max $limit)";
    }
    return null;
}
