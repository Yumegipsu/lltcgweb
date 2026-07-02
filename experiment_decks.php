<?php
/**
 * Guest Deck Experiment — save/load legal decks by short password (unranked only).
 */
require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/deck_validate.php';
tcgDefinePathConstants();

define('EXPERIMENT_PASSWORD_LEN', 8);
define('EXPERIMENT_PASSWORD_CHARS', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
define('EXPERIMENT_PASSWORD_MAX', 16);
define('EXPERIMENT_DECK_MAX_AGE', 60 * 60 * 24 * 180); // 180 days

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

function assertExperimentGuestOnly(array $body): void {
    require_once __DIR__ . '/llr_auth_load.php';
    $uid = tcgResolveAuthUserId(tcgReadAuthTokenFromRequest($body));
    if ($uid) {
        throw new Exception('Deck Experiment is only available when signed out');
    }
}

function validateExperimentDeckPayload(array $main, array $energy, array $cardsData): array {
    $main = array_values(array_map('strval', $main));
    $energy = array_values(array_map('strval', $energy));
    $cardMap = tcgBuildCardMap($cardsData);
    $validation = tcgValidateDeckLists($main, $energy, $cardMap, null);
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
        'saved_at'     => $savedAt,
    ];
}

function writeExperimentDeckFile(string $password, string $name, array $main, array $energy): void {
    ensureExperimentDecksDir();
    $payload = [
        'password'     => $password,
        'name'         => normalizeExperimentDeckName($name),
        'main_deck'    => $main,
        'energy_deck'  => $energy,
        'saved_at'     => time(),
    ];
    $path = experimentDeckPath($password);
    $tmp = $path . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, $path);
}

function apiExperimentDeckSave(array $body): array {
    assertExperimentGuestOnly($body);
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

    writeExperimentDeckFile($password, $name, $validated['main'], $validated['energy']);

    return [
        'success'  => true,
        'password' => $password,
        'name'     => $name,
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
    ];
}

function apiExperimentRandomDeck(array $body): array {
    assertExperimentGuestOnly($body);
    require_once __DIR__ . '/deckgen.php';
    $data = json_decode(file_get_contents(CARDS_FILE), true);
    $cards = $data['cards'] ?? [];
    $tier = in_array((string)($body['tier'] ?? ''), ['easy', 'normal', 'hard'], true)
        ? (string)$body['tier']
        : 'normal';
    $gen = generateEnhancedCpuDeckLists($cards, $tier);
    validateExperimentDeckPayload($gen['main_deck'], $gen['energy_deck'], $data);
    return [
        'success'     => true,
        'name'        => 'Random Deck',
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
    ];
}
