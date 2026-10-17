<?php
/**
 * Deck Experiment — save/load legal decks by short password or account preset.
 * Playable only in Free Mode (unranked). Password load stays open for sharing.
 */
require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/sleeves.php';
require_once __DIR__ . '/deck_validate.php';
require_once __DIR__ . '/game_mode.php';
require_once __DIR__ . '/decklog_import.php';
tcgDefinePathConstants();

define('EXPERIMENT_PASSWORD_LEN', 8);
define('EXPERIMENT_PASSWORD_CHARS', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
define('EXPERIMENT_PASSWORD_MAX', 16);
define('EXPERIMENT_DECK_MAX_AGE', 60 * 60 * 24 * 180); // 180 days
define('TCG_MAX_EXPERIMENT_PRESETS', 10);

function ensureExperimentDecksDir(): void {
    if (!is_dir(EXPERIMENT_DECKS_DIR)) {
        mkdir(EXPERIMENT_DECKS_DIR, 0755, true);
    }
}

function normalizeExperimentPassword(string $raw): string {
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw));
}

function generateExperimentPassword(): string {
    $chars = EXPERIMENT_PASSWORD_CHARS;
    $len = strlen($chars);
    $pw = '';
    for ($i = 0; $i < EXPERIMENT_PASSWORD_LEN; $i++) {
        $pw .= $chars[random_int(0, $len - 1)];
    }
    return $pw;
}

function experimentDeckPath(string $password): string {
    return EXPERIMENT_DECKS_DIR . $password . '.json';
}

/** Canonical card-number list so + / ＋ aliases compare equal. */
function experimentDeckListKey(array $cards): string {
    $norm = [];
    foreach ($cards as $no) {
        $norm[] = str_replace('＋', '+', trim((string) $no));
    }
    return json_encode($norm, JSON_UNESCAPED_UNICODE) ?: '[]';
}

function experimentStoredListsMatch(array $storedMain, array $storedEnergy, array $main, array $energy): bool {
    return experimentDeckListKey($storedMain) === experimentDeckListKey($main)
        && experimentDeckListKey($storedEnergy) === experimentDeckListKey($energy);
}

/**
 * A share password loads whatever deck file it points at. If that file is missing
 * or is another slot's list, drop it so this preset stops advertising slot 1's code.
 * Returns true when the stored password was cleared.
 */
function tcgDropStaleExperimentSharePassword(string $uid, int $slot, string $password, array $main, array $energy): bool {
    $password = normalizeExperimentPassword($password);
    if ($uid === '' || $slot < 1 || $password === '') {
        return false;
    }
    $stored = readExperimentDeckFile($password);
    if (is_array($stored) && experimentStoredListsMatch(
        $stored['main_deck'] ?? [],
        $stored['energy_deck'] ?? [],
        $main,
        $energy
    )) {
        return false;
    }
    tcgDb()->prepare('UPDATE tcg_experiment_presets SET share_password = NULL
        WHERE discord_id = ? AND slot = ? AND share_password = ?')
        ->execute([$uid, $slot, $password]);
    return true;
}

/** True when the join/create body selects a Deck Experiment deck (password or account preset). */
function tcgBodyUsesExperimentDeck(array $body): bool {
    $deck = trim((string)($body['deck'] ?? ''));
    if ($deck === 'experiment'
        || str_starts_with($deck, 'experiment:')
        || str_starts_with($deck, 'experiment_preset:')) {
        return true;
    }
    if (normalizeExperimentPassword((string)($body['experiment_password'] ?? '')) !== '') {
        return true;
    }
    $slot = intval($body['experiment_slot'] ?? 0);
    return $slot >= 1;
}

/** True when the join/create body selects a saved account deck preset. */
function tcgBodyUsesAccountPresetDeck(array $body): bool {
    $deck = trim((string)($body['deck'] ?? ''));
    return $deck === 'preset' || str_starts_with($deck, 'preset:');
}

/**
 * Experiment decks are Free Mode only. Free also allows saved account presets.
 * CPU seats are exempt. Call before resolving player decks for unranked create/join/casual.
 */
function tcgAssertUnrankedDeckForGameMode(array $body): void {
    $deck = trim((string)($body['deck'] ?? ''));
    if ($deck === 'cpu' || str_starts_with($deck, 'cpu:')) {
        return;
    }
    $mode = tcgNormalizeGameMode($body['game_mode'] ?? TCG_GAME_MODE_STANDARD);
    $usesExp = tcgBodyUsesExperimentDeck($body);
    $usesPreset = tcgBodyUsesAccountPresetDeck($body);
    if (tcgIsRandomizedGameMode($mode)) {
        if ($usesExp) {
            throw new Exception('Randomized Decks mode assigns a random legal deck automatically', 400);
        }
        // Empty or random is fine; callers rewrite other deck choices to random.
        return;
    }
    if (tcgIsFreeGameMode($mode)) {
        if (!$usesExp && !$usesPreset) {
            throw new Exception('Free requires a Deck Experiment deck or a saved account deck', 400);
        }
        return;
    }
    if ($usesExp) {
        throw new Exception('Deck Experiment decks can only be used in Free', 400);
    }
}

function assertExperimentAllowedForRoom(array $body): void {
    $mode = tcgNormalizeGameMode($body['game_mode'] ?? '');
    if (!tcgIsFreeGameMode($mode)) {
        throw new Exception('Deck Experiment decks can only be used in Free', 400);
    }
}

function validateExperimentDeckPayload(array $main, array $energy, array $cardsData, bool $allowIncomplete = false): array {
    $main = array_values(array_map('strval', $main));
    $energy = array_values(array_map('strval', $energy));
    $cardMap = tcgBuildCardMap($cardsData);
    $validation = tcgValidateDeckLists($main, $energy, $cardMap, null, $allowIncomplete);
    if (!$validation['valid']) {
        throw new Exception('Invalid deck: ' . implode('; ', $validation['errors']));
    }
    return ['main' => $main, 'energy' => $energy];
}

function normalizeExperimentDeckName(string $name): string {
    $name = trim($name);
    if ($name === '') {
        return 'Deck Experiment';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 40);
    }
    return substr($name, 0, 40);
}

function readExperimentDeckFile(string $password): ?array {
    $password = normalizeExperimentPassword($password);
    if ($password === '') {
        return null;
    }
    $path = experimentDeckPath($password);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        return null;
    }
    $savedAt = intval($data['saved_at'] ?? 0);
    if ($savedAt > 0 && (time() - $savedAt) > EXPERIMENT_DECK_MAX_AGE) {
        @unlink($path);
        return null;
    }
    $main = $data['main_deck'] ?? null;
    $energy = $data['energy_deck'] ?? null;
    if (!is_array($main) || !is_array($energy)) {
        return null;
    }
    return [
        'password'     => $password,
        'name'         => normalizeExperimentDeckName((string)($data['name'] ?? '')),
        'main_deck'    => array_values(array_map('strval', $main)),
        'energy_deck'  => array_values(array_map('strval', $energy)),
        'sleeve_id'    => tcgNormalizeSleeveId($data['sleeve_id'] ?? ''),
        'saved_at'     => $savedAt,
    ];
}

function writeExperimentDeckFile(string $password, string $name, array $main, array $energy, string $sleeveId = ''): void {
    ensureExperimentDecksDir();
    if (!is_dir(EXPERIMENT_DECKS_DIR) || !is_writable(EXPERIMENT_DECKS_DIR)) {
        throw new Exception('Experiment deck storage is unavailable');
    }
    $payload = [
        'password'     => $password,
        'name'         => normalizeExperimentDeckName($name),
        'main_deck'    => $main,
        'energy_deck'  => $energy,
        'sleeve_id'    => tcgNormalizeSleeveId($sleeveId),
        'saved_at'     => time(),
    ];
    $path = experimentDeckPath($password);
    $tmp = $path . '.tmp.' . getmypid();
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new Exception('Could not encode experiment deck');
    }
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new Exception('Could not save experiment deck');
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new Exception('Could not save experiment deck');
    }
}

function apiExperimentDeckSave(array $body): array {
    $cards = json_decode(file_get_contents(CARDS_FILE), true);
    $main = $body['main_deck'] ?? null;
    $energy = $body['energy_deck'] ?? null;
    if (!is_array($main) || !is_array($energy)) {
        throw new Exception('main_deck and energy_deck required');
    }
    $validated = validateExperimentDeckPayload($main, $energy, $cards);
    $name = normalizeExperimentDeckName((string)($body['name'] ?? ''));

    $password = normalizeExperimentPassword((string)($body['password'] ?? ''));
    if ($password !== '') {
        if (strlen($password) < 4 || strlen($password) > EXPERIMENT_PASSWORD_MAX) {
            throw new Exception('Password must be 4–' . EXPERIMENT_PASSWORD_MAX . ' letters/numbers');
        }
    } else {
        $attempts = 0;
        do {
            $password = generateExperimentPassword();
            $attempts++;
        } while (is_file(experimentDeckPath($password)) && $attempts < 50);
        if (is_file(experimentDeckPath($password))) {
            throw new Exception('Could not generate a unique password — try again');
        }
    }

    $existing = ($password !== '' && is_file(experimentDeckPath($password)))
        ? readExperimentDeckFile($password)
        : null;
    $sleeveId = tcgSleeveIdFromBodyOrRow($body, $existing);
    writeExperimentDeckFile($password, $name, $validated['main'], $validated['energy'], $sleeveId);

    return [
        'success'  => true,
        'password' => $password,
        'name'     => $name,
        'sleeve_id' => $sleeveId,
        'main_count' => count($validated['main']),
        'energy_count' => count($validated['energy']),
    ];
}

function apiExperimentDeckLoad(array $body): array {
    $password = normalizeExperimentPassword((string)($body['password'] ?? $_GET['password'] ?? ''));
    if ($password === '') {
        throw new Exception('Password required');
    }
    $stored = readExperimentDeckFile($password);
    if (!$stored) {
        throw new Exception('No experiment deck found for that password');
    }

    $cards = json_decode(file_get_contents(CARDS_FILE), true);
    validateExperimentDeckPayload($stored['main_deck'], $stored['energy_deck'], $cards);

    return [
        'success'     => true,
        'password'    => $stored['password'],
        'name'        => $stored['name'],
        'main_deck'   => $stored['main_deck'],
        'energy_deck' => $stored['energy_deck'],
        'sleeve_id'   => tcgNormalizeSleeveId($stored['sleeve_id'] ?? ''),
    ];
}

/**
 * Import a deck log recipe into Deck Experiment lists.
 * Optionally persists as an experiment password (default on).
 */
function apiExperimentDecklogImport(array $body): array {
    if (function_exists('tcgRateLimitForAction')) {
        tcgRateLimitForAction('experiment_decklog_import', $body);
    }
    $raw = trim((string)($body['code'] ?? $body['url'] ?? $_GET['code'] ?? ''));
    // Pass raw input through so EN/JP view URLs keep preferred host (do not normalize first).
    $payload = tcgFetchDecklogView($raw);
    $code = tcgNormalizeDecklogCode($raw);
    $cards = json_decode((string)file_get_contents(CARDS_FILE), true);
    if (!is_array($cards)) {
        throw new Exception('Card database unavailable', 500);
    }
    $mapped = tcgMapDecklogPayloadToExperimentLists($payload, $cards);
    $validated = validateExperimentDeckPayload($mapped['main_deck'], $mapped['energy_deck'], $cards);
    $name = normalizeExperimentDeckName(
        $mapped['title'] !== '' ? $mapped['title'] : ('deck log ' . ($mapped['deck_id'] ?: $code))
    );
    $save = !isset($body['save']) || !in_array(
        strtolower((string)$body['save']),
        ['0', 'false', 'no', 'off'],
        true
    );
    $password = '';
    if ($save) {
        $attempts = 0;
        do {
            $password = generateExperimentPassword();
            $attempts++;
        } while (is_file(experimentDeckPath($password)) && $attempts < 50);
        if (is_file(experimentDeckPath($password))) {
            throw new Exception('Could not generate a unique password — try again');
        }
        writeExperimentDeckFile($password, $name, $validated['main'], $validated['energy']);
    }
    return [
        'success' => true,
        'decklog_code' => $mapped['deck_id'] !== '' ? $mapped['deck_id'] : $code,
        'name' => $name,
        'main_deck' => $validated['main'],
        'energy_deck' => $validated['energy'],
        'password' => $password,
        'saved' => $save && $password !== '',
    ];
}

function apiExperimentRandomDeck(array $body): array {
    require_once __DIR__ . '/deckgen.php';
    $data = json_decode(file_get_contents(CARDS_FILE), true);
    $cards = $data['cards'] ?? [];
    $tier = in_array((string)($body['tier'] ?? ''), ['easy', 'normal', 'hard'], true)
        ? (string)$body['tier']
        : 'normal';
    $group = trim((string)($body['group'] ?? ''));
    $subunit = trim((string)($body['subunit'] ?? ''));
    $gen = generateEnhancedCpuDeckLists(
        $cards,
        $tier,
        $group !== '' ? $group : null,
        $subunit !== '' ? $subunit : null
    );
    validateExperimentDeckPayload($gen['main_deck'], $gen['energy_deck'], $data);
    return [
        'success'     => true,
        'name'        => 'Random Deck',
        'group'       => $gen['group'] ?? $group,
        'subunit'     => $gen['subunit'] ?? $subunit,
        'main_deck'   => array_values($gen['main_deck'] ?? []),
        'energy_deck' => array_values($gen['energy_deck'] ?? []),
    ];
}

function resolveExperimentDeckFromPassword(string $password, array $cardsData): array {
    $stored = readExperimentDeckFile($password);
    if (!$stored) {
        throw new Exception('Experiment deck not found for that password');
    }
    validateExperimentDeckPayload($stored['main_deck'], $stored['energy_deck'], $cardsData);
    return [
        'deck_choice' => 'experiment:' . $stored['password'],
        'deck_label'  => $stored['name'],
        'main_nos'    => $stored['main_deck'],
        'energy_nos'  => $stored['energy_deck'],
        'sleeve_id'   => tcgNormalizeSleeveId($stored['sleeve_id'] ?? ''),
    ];
}

function resolveExperimentPresetDeckLists(array $body, array $cardsData, int $slot): array {
    if ($slot < 1 || $slot > TCG_MAX_EXPERIMENT_PRESETS) {
        throw new Exception('Experiment deck slot must be 1–' . TCG_MAX_EXPERIMENT_PRESETS, 400);
    }
    require_once __DIR__ . '/llr_auth_load.php';
    require_once __DIR__ . '/db.php';
    $uid = tcgRequireAuthUser($body);
    $db = tcgDb();
    $stmt = $db->prepare('SELECT name, main_deck, energy_deck, sleeve_id FROM tcg_experiment_presets WHERE discord_id = ? AND slot = ?');
    $stmt->execute([$uid, $slot]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Experiment deck #' . $slot . ' not found', 400);
    }
    $main = json_decode((string)$row['main_deck'], true) ?: [];
    $energy = json_decode((string)$row['energy_deck'], true) ?: [];
    $validated = validateExperimentDeckPayload($main, $energy, $cardsData);
    return [
        'deck_choice' => 'experiment_preset:' . $slot,
        'deck_label'  => normalizeExperimentDeckName((string)($row['name'] ?? ('Experiment ' . $slot))),
        'main_nos'    => $validated['main'],
        'energy_nos'  => $validated['energy'],
        'sleeve_id'   => tcgNormalizeSleeveId($row['sleeve_id'] ?? ''),
    ];
}
