<?php
/**
 * Key Event matches: the group-restricted match that pays Event Points.
 *
 * Flow:
 *  1. account host: events_match_start spends the keys and returns a signed ticket plus the player's decks
 *     (one per group, built from their event deck slots with starter-deck substitutes).
 *  2. match host (VPS under match-primary): event_match_join queues the ticket; two tickets for the same event pair
 *     up on the first player's group. After the wait, event_match_cpu starts a CPU game on the same group instead.
 *  3. Either room carries state['event_match']; when it finishes, tcgEventsMatchOnFinished scores it.
 *
 * The ticket is an HMAC over the event, player, group and a hash of the decks, so the match host (which cannot see
 * the account database) can trust both the key payment and the deck contents.
 */
require_once __DIR__ . '/events_keys.php';

const TCG_EVENT_MATCH_TICKET_TTL = 3600;

/**
 * The groups an event match can pick. Every group has a starter deck, so anyone can play every group.
 *
 * @return list<array{key:string,group:string,label:string,starters:list<string>}>
 */
function tcgEventsMatchGroups(): array {
    return [
        ['key' => 'muse', 'group' => "μ's", 'label' => "μ's", 'starters' => ['muse']],
        ['key' => 'sunshine', 'group' => 'Sunshine', 'label' => 'Aqours', 'starters' => ['sunshine']],
        ['key' => 'nijigasaki', 'group' => 'Nijigasaki', 'label' => 'Nijigasaki', 'starters' => ['nijigasaki', 'nijigasaki_cheer']],
        ['key' => 'superstar', 'group' => 'Superstar', 'label' => 'Liella!', 'starters' => ['liella', 'superstar_cheer']],
        ['key' => 'hasunosora', 'group' => 'Hasunosora', 'label' => 'Hasunosora', 'starters' => ['hasunosora']],
    ];
}

/** @return array{key:string,group:string,label:string,starters:list<string>}|null */
function tcgEventsMatchGroupByKey(string $key): ?array {
    foreach (tcgEventsMatchGroups() as $g) {
        if ($g['key'] === $key) {
            return $g;
        }
    }
    return null;
}

function tcgEventsMatchEnsureSchema(PDO $db): void {
    static $done = [];
    $id = spl_object_id($db);
    if (isset($done[$id])) {
        return;
    }
    $done[$id] = true;
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_event_decks (
        discord_id TEXT NOT NULL,
        group_key TEXT NOT NULL,
        preset_slot INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (discord_id, group_key)
    )');
    $cols = array_column($db->query('PRAGMA table_info(tcg_event_decks)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['main_deck', 'energy_deck'] as $col) {
        if (!in_array($col, $cols, true)) {
            $db->exec('ALTER TABLE tcg_event_decks ADD COLUMN ' . $col . ' TEXT');
        }
    }
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_event_tickets (
        id TEXT PRIMARY KEY,
        event_id TEXT NOT NULL,
        discord_id TEXT NOT NULL,
        group_key TEXT NOT NULL,
        keys_spent INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT \'issued\',
        created_at INTEGER NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_event_queue (
        ticket_id TEXT PRIMARY KEY,
        event_id TEXT NOT NULL,
        discord_id TEXT NOT NULL,
        player_name TEXT NOT NULL DEFAULT \'\',
        group_key TEXT NOT NULL,
        join_body TEXT NOT NULL,
        joined_at INTEGER NOT NULL,
        last_seen_at INTEGER NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_event_match_result (
        ticket_id TEXT PRIMARY KEY,
        room_id TEXT NOT NULL,
        player_token TEXT NOT NULL,
        player_id TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )');
}

// ------------------------------------------------------------------ tickets

function tcgEventsMatchSecret(): string {
    require_once __DIR__ . '/match_bridge.php';
    $s = tcgInternalMatchSecret();
    if ($s === '') {
        throw new Exception('Event matches are not configured on this host', 503);
    }
    return $s;
}

/** @param array<string,mixed> $decks */
function tcgEventsMatchDecksHash(array $decks): string {
    ksort($decks);
    foreach ($decks as $k => $d) {
        $decks[$k] = ['main' => array_values(array_map('strval', $d['main'] ?? [])), 'energy' => array_values(array_map('strval', $d['energy'] ?? []))];
    }
    return hash('sha256', json_encode($decks, JSON_UNESCAPED_UNICODE));
}

/** @param array<string,mixed> $payload */
function tcgEventsMatchSignTicket(array $payload): string {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    return $b64 . '.' . hash_hmac('sha256', $b64, tcgEventsMatchSecret());
}

/**
 * @param array<string,mixed> $decks the decks the client sent along with the ticket
 * @return array<string,mixed> ticket payload
 */
function tcgEventsMatchVerifyTicket(string $ticket, array $decks, ?string $expectUid = null): array {
    $parts = explode('.', $ticket);
    if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], tcgEventsMatchSecret()), $parts[1])) {
        throw new Exception('Invalid event match ticket', 403);
    }
    $payload = json_decode((string)base64_decode(strtr($parts[0], '-_', '+/')), true);
    if (!is_array($payload) || intval($payload['exp'] ?? 0) < time()) {
        throw new Exception('Event match ticket expired', 403);
    }
    if ($expectUid !== null && (string)($payload['uid'] ?? '') !== $expectUid) {
        throw new Exception('Ticket belongs to another player', 403);
    }
    if (!hash_equals((string)($payload['dh'] ?? ''), tcgEventsMatchDecksHash($decks))) {
        throw new Exception('Event decks do not match the ticket', 403);
    }
    return $payload;
}

function tcgEventsMatchLeaveProof(string $ticketId): string {
    return hash_hmac('sha256', 'left:' . $ticketId, tcgEventsMatchSecret());
}

// ------------------------------------------------------------------ event decks

/**
 * Build one playable deck for a group: the player's assigned deck (cards of that group they own), topped up from
 * the group's starter deck so it is always a legal 48 Members + 12 Lives + 12 Energy.
 *
 * @param array<string,mixed> $cardsData
 * @param array<string,array<string,mixed>> $cardMap
 * @param array<string,int>|null $owned collection map; null skips ownership (used for guests and tests)
 * @param array{main:list<string>,energy:list<string>}|null $saved the assigned preset
 * @param array<string,mixed> $groupDef
 * @return array{main:list<string>,energy:list<string>,source:string,own_count:int}
 */
function tcgEventsMatchAssembleDeck(array $cardsData, array $cardMap, ?array $owned, ?array $saved, array $groupDef): array {
    require_once __DIR__ . '/deck_validate.php';
    require_once __DIR__ . '/booster.php';
    require_once __DIR__ . '/deckgen.php';
    $starterMain = [];
    $starterEnergy = [];
    foreach ($groupDef['starters'] as $sk) {
        $lists = tcgGetStarterDeckLists($sk, $cardsData);
        $starterMain = array_merge($starterMain, $lists['main_deck'] ?? []);
        $starterEnergy = array_merge($starterEnergy, $lists['energy_deck'] ?? []);
    }
    $firstStarter = tcgGetStarterDeckLists($groupDef['starters'][0], $cardsData);

    $main = [];
    $energy = [];
    $counts = [];
    $ownedUsed = [];
    $members = 0;
    $lives = 0;
    $try = function (string $no, bool $checkOwned) use (&$main, &$counts, &$ownedUsed, &$members, &$lives, $cardMap, $owned, $groupDef): bool {
        $card = $cardMap[$no] ?? null;
        if (!$card || ($card['group'] ?? '') !== $groupDef['group']) {
            return false;
        }
        $type = $card['card_type'] ?? '';
        if ($type === 'メンバー' ? $members >= TCG_MEMBER_SLOTS : ($type === 'ライブ' ? $lives >= TCG_LIVE_SLOTS : true)) {
            return false;
        }
        $id = tcgDeckCopyIdentity($no);
        if (($counts[$id] ?? 0) >= tcgGetDeckMaxCopies($card, $no)) {
            return false;
        }
        if ($checkOwned && $owned !== null) {
            if (($ownedUsed[$no] ?? 0) >= tcgOwnedQtyForCardNo($owned, $no)) {
                return false;
            }
        }
        $candidate = $main;
        $candidate[] = $no;
        if (tcgValidateLovecaPointBudget($candidate) !== null) {
            return false;
        }
        $main[] = $no;
        $counts[$id] = ($counts[$id] ?? 0) + 1;
        if ($checkOwned) {
            $ownedUsed[$no] = ($ownedUsed[$no] ?? 0) + 1;
        }
        $type === 'メンバー' ? $members++ : $lives++;
        return true;
    };

    $ownCount = 0;
    foreach (($saved['main'] ?? []) as $no) {
        if ($try((string)$no, true)) {
            $ownCount++;
        }
    }
    foreach ($starterMain as $no) {
        if (count($main) >= TCG_MAIN_DECK_SIZE) {
            break;
        }
        $try((string)$no, false);
    }
    $energyUsed = [];
    foreach (($saved['energy'] ?? []) as $no) {
        $no = (string)$no;
        $card = $cardMap[$no] ?? null;
        if (!$card || ($card['card_type'] ?? '') !== 'エネルギー' || count($energy) >= TCG_ENERGY_SLOTS) {
            continue;
        }
        if ($owned !== null && ($energyUsed[$no] ?? 0) >= tcgOwnedQtyForCardNo($owned, $no)) {
            continue;
        }
        $id = tcgDeckCopyIdentity($no);
        if (count(array_filter($energy, fn($e) => tcgDeckCopyIdentity($e) === $id)) >= TCG_MAX_ENERGY_COPIES) {
            continue;
        }
        $energy[] = $no;
        $energyUsed[$no] = ($energyUsed[$no] ?? 0) + 1;
    }
    foreach ($starterEnergy as $no) {
        if (count($energy) >= TCG_ENERGY_SLOTS) {
            break;
        }
        $id = tcgDeckCopyIdentity((string)$no);
        if (count(array_filter($energy, fn($e) => tcgDeckCopyIdentity($e) === $id)) >= TCG_MAX_ENERGY_COPIES) {
            continue;
        }
        $energy[] = (string)$no;
    }

    $check = tcgValidateDeckLists($main, $energy, $cardMap, null);
    if (!$check['valid']) {
        return ['main' => array_values($firstStarter['main_deck']), 'energy' => array_values($firstStarter['energy_deck']), 'source' => 'starter', 'own_count' => 0];
    }
    $source = $ownCount === 0 ? 'starter' : ($ownCount >= TCG_MAIN_DECK_SIZE ? 'own' : 'mixed');
    return ['main' => $main, 'energy' => $energy, 'source' => $source, 'own_count' => $ownCount];
}

/** @return array<string,int> group key => assigned preset slot (0 = starter only) */
function tcgEventsMatchSlots(string $uid): array {
    $db = tcgDb();
    tcgEventsMatchEnsureSchema($db);
    $stmt = $db->prepare('SELECT group_key, preset_slot FROM tcg_event_decks WHERE discord_id = ?');
    $stmt->execute([$uid]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string)$r['group_key']] = intval($r['preset_slot']);
    }
    return $out;
}

/** @return array{main:list<string>,energy:list<string>}|null */
function tcgEventsMatchPresetLists(string $uid, int $slot): ?array {
    if ($slot < 1) {
        return null;
    }
    $stmt = tcgDb()->prepare('SELECT main_deck, energy_deck FROM tcg_deck_presets WHERE discord_id = ? AND slot = ?');
    $stmt->execute([$uid, $slot]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return ['main' => json_decode((string)$row['main_deck'], true) ?: [], 'energy' => json_decode((string)$row['energy_deck'], true) ?: []];
}

/**
 * The deck a player built for one group in the event deck editor, falling back to a linked saved deck.
 *
 * @return array{main:list<string>,energy:list<string>}|null
 */
function tcgEventsMatchSavedLists(string $uid, string $groupKey, int $linkedSlot): ?array {
    $stmt = tcgDb()->prepare('SELECT main_deck, energy_deck FROM tcg_event_decks WHERE discord_id = ? AND group_key = ?');
    $stmt->execute([$uid, $groupKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['main_deck'] !== null) {
        return [
            'main' => array_values(array_map('strval', json_decode((string)$row['main_deck'], true) ?: [])),
            'energy' => array_values(array_map('strval', json_decode((string)($row['energy_deck'] ?? '[]'), true) ?: [])),
        ];
    }
    return tcgEventsMatchPresetLists($uid, $linkedSlot);
}

/**
 * All five groups' decks for a player, keyed by group key.
 *
 * @return array<string,array{main:list<string>,energy:list<string>,source:string,own_count:int}>
 */
function tcgEventsMatchBuildAllDecks(string $uid): array {
    require_once __DIR__ . '/deck_validate.php';
    $cardsData = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cardsData);
    $owned = tcgGetCollectionMap($uid);
    $slots = tcgEventsMatchSlots($uid);
    $out = [];
    foreach (tcgEventsMatchGroups() as $g) {
        $saved = tcgEventsMatchSavedLists($uid, $g['key'], intval($slots[$g['key']] ?? 0));
        $out[$g['key']] = tcgEventsMatchAssembleDeck($cardsData, $cardMap, $owned, $saved, $g);
    }
    return $out;
}

// ------------------------------------------------------------------ account-host API

/** @param array<string,mixed> $body */
function tcgApiEventsMatchDecks(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEventsMatchEnsureSchema(tcgDb());
    $slots = tcgEventsMatchSlots($uid);
    $decks = tcgEventsMatchBuildAllDecks($uid);
    $cardsData = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cardsData);
    $presets = [];
    $stmt = tcgDb()->prepare('SELECT slot, name, main_deck FROM tcg_deck_presets WHERE discord_id = ? ORDER BY slot ASC');
    $stmt->execute([$uid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $nos = json_decode((string)$r['main_deck'], true) ?: [];
        $byGroup = [];
        foreach (tcgEventsMatchGroups() as $g) {
            $byGroup[$g['key']] = count(array_filter($nos, fn($n) => ($cardMap[$n]['group'] ?? '') === $g['group']));
        }
        $presets[] = ['slot' => intval($r['slot']), 'name' => tcgNormalizeDeckPresetName((string)$r['name']), 'cards' => count($nos), 'by_group' => $byGroup];
    }
    $groups = [];
    foreach (tcgEventsMatchGroups() as $g) {
        $d = $decks[$g['key']];
        $groups[] = ['key' => $g['key'], 'label' => $g['label'], 'slot' => intval($slots[$g['key']] ?? 0), 'source' => $d['source'], 'own_count' => $d['own_count']];
    }
    return ['success' => true, 'groups' => $groups, 'presets' => $presets];
}

/** @param array<string,mixed> $body */
function tcgApiEventsMatchSetDeck(array $body): array {
    $uid = tcgRequireAuthUser($body);
    $db = tcgDb();
    tcgEventsMatchEnsureSchema($db);
    $g = tcgEventsMatchGroupByKey((string)($body['group'] ?? ''));
    if (!$g) {
        throw new Exception('Unknown group', 400);
    }
    $slot = max(0, intval($body['slot'] ?? 0));
    if ($slot > 0 && tcgEventsMatchPresetLists($uid, $slot) === null) {
        throw new Exception('Deck not found', 404);
    }
    $db->prepare('INSERT INTO tcg_event_decks (discord_id, group_key, preset_slot, main_deck, energy_deck, updated_at) VALUES (?, ?, ?, NULL, NULL, ?)
        ON CONFLICT(discord_id, group_key) DO UPDATE SET preset_slot = excluded.preset_slot, main_deck = NULL, energy_deck = NULL, updated_at = excluded.updated_at')
        ->execute([$uid, $g['key'], $slot, time()]);
    return tcgApiEventsMatchDecks($body);
}

/**
 * Everything the editor needs for one group: the current deck lists, the group's starter deck, and the player's
 * saved decks that contain cards of the group (to import from).
 *
 * @param array<string,mixed> $body
 */
function tcgApiEventsMatchDeckGet(array $body): array {
    require_once __DIR__ . '/booster.php';
    require_once __DIR__ . '/deckgen.php';
    $uid = tcgRequireAuthUser($body);
    tcgEventsMatchEnsureSchema(tcgDb());
    $g = tcgEventsMatchGroupByKey((string)($body['group'] ?? ''));
    if (!$g) {
        throw new Exception('Unknown group', 400);
    }
    $cardsData = tcgLoadCardsData();
    $cardMap = tcgBuildCardMap($cardsData);
    $owned = tcgGetCollectionMap($uid);
    $slots = tcgEventsMatchSlots($uid);
    $saved = tcgEventsMatchSavedLists($uid, $g['key'], intval($slots[$g['key']] ?? 0)) ?? ['main' => [], 'energy' => []];
    $assembled = tcgEventsMatchAssembleDeck($cardsData, $cardMap, $owned, $saved, $g);
    $inGroup = fn($no): bool => ($cardMap[(string)$no]['group'] ?? '') === $g['group'];

    $starter = ['main' => [], 'energy' => []];
    foreach ($g['starters'] as $sk) {
        $lists = tcgGetStarterDeckLists($sk, $cardsData);
        $starter['main'] = array_merge($starter['main'], $lists['main_deck'] ?? []);
        $starter['energy'] = array_merge($starter['energy'], $lists['energy_deck'] ?? []);
    }

    $presets = [];
    $stmt = tcgDb()->prepare('SELECT slot, name, main_deck, energy_deck FROM tcg_deck_presets WHERE discord_id = ? ORDER BY slot ASC');
    $stmt->execute([$uid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $nos = array_values(array_filter(json_decode((string)$r['main_deck'], true) ?: [], $inGroup));
        if ($nos) {
            $presets[] = [
                'slot' => intval($r['slot']),
                'name' => tcgNormalizeDeckPresetName((string)$r['name']),
                'main' => $nos,
                'energy' => json_decode((string)$r['energy_deck'], true) ?: [],
            ];
        }
    }
    return [
        'success' => true,
        'group' => ['key' => $g['key'], 'label' => $g['label'], 'name' => $g['group']],
        'main' => array_values(array_filter($saved['main'], $inGroup)),
        'energy' => array_values(array_filter($saved['energy'], fn($n) => ($cardMap[(string)$n]['card_type'] ?? '') === 'エネルギー')),
        'source' => $assembled['source'],
        'starter' => $starter,
        'presets' => $presets,
        'limits' => ['main' => TCG_MAIN_DECK_SIZE, 'members' => TCG_MEMBER_SLOTS, 'lives' => TCG_LIVE_SLOTS, 'energy' => TCG_ENERGY_SLOTS],
    ];
}

/**
 * Save the deck built in the editor. It may be incomplete (the rest is filled from the group's starter deck at
 * match time) but every card in it must be in the group, owned, and within the deck rules.
 *
 * @param array<string,mixed> $body
 */
function tcgApiEventsMatchDeckSave(array $body): array {
    require_once __DIR__ . '/deck_validate.php';
    $uid = tcgRequireAuthUser($body);
    $db = tcgDb();
    tcgEventsMatchEnsureSchema($db);
    $g = tcgEventsMatchGroupByKey((string)($body['group'] ?? ''));
    if (!$g) {
        throw new Exception('Unknown group', 400);
    }
    $main = is_array($body['main'] ?? null) ? array_values(array_map('strval', $body['main'])) : [];
    $energy = is_array($body['energy'] ?? null) ? array_values(array_map('strval', $body['energy'])) : [];
    $cardMap = tcgBuildCardMap(tcgLoadCardsData());
    $owned = tcgGetCollectionMap($uid);

    $members = 0;
    $lives = 0;
    $identity = [];
    $ownedUsed = [];
    foreach ($main as $no) {
        $card = $cardMap[$no] ?? null;
        if (!$card || ($card['group'] ?? '') !== $g['group']) {
            throw new Exception('Only ' . $g['label'] . ' cards can go in this deck', 400);
        }
        $type = $card['card_type'] ?? '';
        if ($type === 'メンバー') {
            $members++;
        } elseif ($type === 'ライブ') {
            $lives++;
        } else {
            throw new Exception('Only Members and Lives go in the main deck', 400);
        }
        $id = tcgDeckCopyIdentity($no);
        $identity[$id] = ($identity[$id] ?? 0) + 1;
        if ($identity[$id] > tcgGetDeckMaxCopies($card, $no)) {
            throw new Exception('Too many copies of ' . $no, 400);
        }
        $ownedUsed[$no] = ($ownedUsed[$no] ?? 0) + 1;
        if ($ownedUsed[$no] > tcgOwnedQtyForCardNo($owned, $no)) {
            throw new Exception('You do not own enough copies of ' . $no, 400);
        }
    }
    if ($members > TCG_MEMBER_SLOTS || $lives > TCG_LIVE_SLOTS) {
        throw new Exception('Too many Members or Lives', 400);
    }
    if (($err = tcgValidateLovecaPointBudget($main)) !== null) {
        throw new Exception($err, 400);
    }
    if (count($energy) > TCG_ENERGY_SLOTS) {
        throw new Exception('Too many Energy cards', 400);
    }
    $energyIdentity = [];
    $energyUsed = [];
    foreach ($energy as $no) {
        if (($cardMap[$no]['card_type'] ?? '') !== 'エネルギー') {
            throw new Exception('Only Energy cards go in the Energy deck', 400);
        }
        $id = tcgDeckCopyIdentity($no);
        $energyIdentity[$id] = ($energyIdentity[$id] ?? 0) + 1;
        $energyUsed[$no] = ($energyUsed[$no] ?? 0) + 1;
        if ($energyIdentity[$id] > TCG_MAX_ENERGY_COPIES || $energyUsed[$no] > tcgOwnedQtyForCardNo($owned, $no)) {
            throw new Exception('Too many copies of ' . $no, 400);
        }
    }

    $db->prepare('INSERT INTO tcg_event_decks (discord_id, group_key, preset_slot, main_deck, energy_deck, updated_at) VALUES (?, ?, 0, ?, ?, ?)
        ON CONFLICT(discord_id, group_key) DO UPDATE SET preset_slot = 0, main_deck = excluded.main_deck, energy_deck = excluded.energy_deck, updated_at = excluded.updated_at')
        ->execute([$uid, $g['key'], json_encode($main), json_encode($energy), time()]);
    return tcgApiEventsMatchDeckGet($body);
}

/**
 * Spend the keys for one event match and hand back the signed ticket and decks.
 *
 * @param array<string,mixed> $body
 */
function tcgApiEventsMatchStart(array $body): array {
    $uid = tcgRequireAuthUser($body);
    tcgEventsTickStatuses();
    $event = tcgEventsAssertVisible(tcgEventsGetRow(trim((string)($body['event_id'] ?? ''))), $uid);
    return tcgEventsMatchStart($event, $uid);
}

/** @param array<string,mixed> $event */
function tcgEventsMatchStart(array $event, string $uid): array {
    if (!tcgEventsIsKeyType($event) || (string)$event['status'] !== 'active') {
        throw new Exception('This event is not running', 400);
    }
    $db = tcgDb();
    tcgEventsMatchEnsureSchema($db);
    $cfg = tcgEventsConfig($event)['event_match'];
    $cost = max(0, intval($cfg['key_cost'] ?? 0));
    if ($cost > 0 && !tcgEventsWalletAdd((string)$event['id'], $uid, 'keys', -$cost)) {
        throw new Exception('Not enough Event Keys', 400);
    }
    try {
        $groups = tcgEventsMatchGroups();
        $group = $groups[random_int(0, count($groups) - 1)];
        $decks = tcgEventsMatchBuildAllDecks($uid);
        $slim = [];
        foreach ($decks as $k => $d) {
            $slim[$k] = ['main' => $d['main'], 'energy' => $d['energy']];
        }
        $tid = bin2hex(random_bytes(8));
        $ticket = tcgEventsMatchSignTicket([
            'v' => 1, 'tid' => $tid, 'eid' => (string)$event['id'], 'uid' => $uid, 'grp' => $group['key'],
            'exp' => time() + TCG_EVENT_MATCH_TICKET_TTL, 'dh' => tcgEventsMatchDecksHash($slim),
        ]);
        $db->prepare('INSERT INTO tcg_event_tickets (id, event_id, discord_id, group_key, keys_spent, status, created_at) VALUES (?, ?, ?, ?, ?, \'issued\', ?)')
            ->execute([$tid, (string)$event['id'], $uid, $group['key'], $cost, time()]);
    } catch (Throwable $e) {
        if ($cost > 0) {
            tcgEventsWalletAdd((string)$event['id'], $uid, 'keys', $cost);
        }
        throw $e;
    }
    return [
        'success' => true,
        'ticket' => $ticket,
        'ticket_id' => $tid,
        'group' => $group['key'],
        'group_label' => $group['label'],
        'decks' => $slim,
        'keys_spent' => $cost,
        'wallet' => tcgEventsWalletGet((string)$event['id'], $uid),
        'wait_sec' => max(5, intval($cfg['queue_wait_sec'] ?? 30)),
    ];
}

/**
 * Give the keys back for a match that never started. The match host proves the ticket left its queue unused.
 *
 * @param array<string,mixed> $body
 */
function tcgApiEventsMatchCancel(array $body): array {
    return tcgEventsMatchCancel(tcgRequireAuthUser($body), trim((string)($body['ticket_id'] ?? '')), (string)($body['proof'] ?? ''));
}

function tcgEventsMatchCancel(string $uid, string $tid, string $proof): array {
    $db = tcgDb();
    tcgEventsMatchEnsureSchema($db);
    if ($tid === '' || !hash_equals(tcgEventsMatchLeaveProof($tid), $proof)) {
        throw new Exception('Cannot refund this match', 403);
    }
    $stmt = $db->prepare('SELECT * FROM tcg_event_tickets WHERE id = ? AND discord_id = ?');
    $stmt->execute([$tid, $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Ticket not found', 404);
    }
    $upd = $db->prepare("UPDATE tcg_event_tickets SET status = 'refunded' WHERE id = ? AND status = 'issued'");
    $upd->execute([$tid]);
    $refunded = 0;
    if ($upd->rowCount() > 0 && intval($row['keys_spent']) > 0) {
        $refunded = intval($row['keys_spent']);
        tcgEventsWalletAdd((string)$row['event_id'], $uid, 'keys', $refunded);
    }
    return ['success' => true, 'refunded' => $refunded, 'wallet' => tcgEventsWalletGet((string)$row['event_id'], $uid)];
}

// ------------------------------------------------------------------ match-host API

function tcgEventsMatchLoadApi(): void {
    if (!defined('TCG_API_LIB_ONLY')) {
        define('TCG_API_LIB_ONLY', true);
    }
    require_once __DIR__ . '/api.php';
    tcgRequireAuthLoader();
}

/** @param array<string,mixed> $body */
function tcgEventsMatchReadTicket(array $body): array {
    $decks = is_array($body['decks'] ?? null) ? $body['decks'] : [];
    $uid = tcgRequireAuthUser($body);
    $ticket = tcgEventsMatchVerifyTicket((string)($body['ticket'] ?? ''), $decks, $uid);
    $group = tcgEventsMatchGroupByKey((string)($ticket['grp'] ?? ''));
    if (!$group || !isset($decks[$group['key']])) {
        throw new Exception('Event decks are incomplete', 400);
    }
    return ['uid' => $uid, 'ticket' => $ticket, 'decks' => $decks, 'group' => $group];
}

/** @param array<string,mixed> $body */
function apiEventMatchJoin(array $body): array {
    tcgRateLimitForAction('casual_join', $body);
    tcgEventsMatchLoadApi();
    $t = tcgEventsMatchReadTicket($body);
    $tid = (string)$t['ticket']['tid'];
    $name = trim((string)($body['name'] ?? 'Player')) ?: 'Player';
    $joinBody = json_encode([
        'name' => $name, 'decks' => $t['decks'], 'ticket' => $body['ticket'],
        'sleeve_id' => $body['sleeve_id'] ?? '', 'playmat_id' => $body['playmat_id'] ?? '',
        'playmat_brightness' => $body['playmat_brightness'] ?? 1.0,
        'phase_timer_enabled' => $body['phase_timer_enabled'] ?? null, 'phase_timer_seconds' => $body['phase_timer_seconds'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    require_once __DIR__ . '/casual_matchmaking.php';
    return tcgWithCasualQueueLock(function () use ($t, $tid, $name, $joinBody): array {
        $db = tcgDb();
        tcgEventsMatchEnsureSchema($db);
        $now = time();
        $db->prepare('DELETE FROM tcg_event_queue WHERE discord_id = ? OR last_seen_at < ?')->execute([$t['uid'], $now - 120]);
        $db->prepare('INSERT INTO tcg_event_queue (ticket_id, event_id, discord_id, player_name, group_key, join_body, joined_at, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$tid, (string)$t['ticket']['eid'], $t['uid'], $name, $t['group']['key'], $joinBody, $now, $now]);
        return ['success' => true] + (tcgEventsMatchTryPair($tid) ?? ['status' => 'searching', 'wait_seconds' => 0]);
    });
}

/** @param array<string,mixed> $body */
function apiEventMatchStatus(array $body): array {
    tcgEventsMatchLoadApi();
    $uid = tcgRequireAuthUser($body);
    $tid = preg_replace('/[^a-f0-9]/', '', (string)($body['ticket_id'] ?? ''));
    require_once __DIR__ . '/casual_matchmaking.php';
    return tcgWithCasualQueueLock(function () use ($uid, $tid): array {
        $db = tcgDb();
        tcgEventsMatchEnsureSchema($db);
        $stmt = $db->prepare('SELECT * FROM tcg_event_match_result WHERE ticket_id = ?');
        $stmt->execute([$tid]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $db->prepare('DELETE FROM tcg_event_match_result WHERE ticket_id = ?')->execute([$tid]);
            return ['success' => true, 'status' => 'matched', 'room_id' => $res['room_id'], 'player_token' => $res['player_token'], 'player_id' => $res['player_id']];
        }
        $q = $db->prepare('SELECT joined_at FROM tcg_event_queue WHERE ticket_id = ? AND discord_id = ?');
        $q->execute([$tid, $uid]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => true, 'status' => 'idle'];
        }
        $db->prepare('UPDATE tcg_event_queue SET last_seen_at = ? WHERE ticket_id = ?')->execute([time(), $tid]);
        return ['success' => true] + (tcgEventsMatchTryPair($tid) ?? ['status' => 'searching', 'wait_seconds' => time() - intval($row['joined_at'])]);
    });
}

/** @param array<string,mixed> $body */
function apiEventMatchLeave(array $body): array {
    tcgEventsMatchLoadApi();
    $uid = tcgRequireAuthUser($body);
    $tid = preg_replace('/[^a-f0-9]/', '', (string)($body['ticket_id'] ?? ''));
    require_once __DIR__ . '/casual_matchmaking.php';
    return tcgWithCasualQueueLock(function () use ($uid, $tid): array {
        $db = tcgDb();
        tcgEventsMatchEnsureSchema($db);
        $del = $db->prepare('DELETE FROM tcg_event_queue WHERE ticket_id = ? AND discord_id = ?');
        $del->execute([$tid, $uid]);
        if ($del->rowCount() > 0) {
            return ['success' => true, 'left' => true, 'proof' => tcgEventsMatchLeaveProof($tid)];
        }
        // Already paired: hand the room over instead of a refund.
        $stmt = $db->prepare('SELECT * FROM tcg_event_match_result WHERE ticket_id = ?');
        $stmt->execute([$tid]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $db->prepare('DELETE FROM tcg_event_match_result WHERE ticket_id = ?')->execute([$tid]);
            return ['success' => true, 'left' => false, 'match' => ['status' => 'matched', 'room_id' => $res['room_id'], 'player_token' => $res['player_token'], 'player_id' => $res['player_id']]];
        }
        return ['success' => true, 'left' => false];
    });
}

/**
 * Pair the waiting player `$tid` with the longest-waiting other player of the same event. Caller holds the queue lock.
 *
 * @return array<string,mixed>|null
 */
function tcgEventsMatchTryPair(string $tid): ?array {
    $db = tcgDb();
    $stmt = $db->prepare('SELECT * FROM tcg_event_queue WHERE ticket_id = ?');
    $stmt->execute([$tid]);
    $self = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$self) {
        return null;
    }
    $o = $db->prepare('SELECT * FROM tcg_event_queue WHERE event_id = ? AND discord_id != ? AND last_seen_at >= ? ORDER BY joined_at ASC LIMIT 1');
    $o->execute([$self['event_id'], $self['discord_id'], time() - 45]);
    $opp = $o->fetch(PDO::FETCH_ASSOC);
    if (!$opp) {
        return null;
    }
    $p1 = intval($self['joined_at']) <= intval($opp['joined_at']) ? $self : $opp;
    $p2 = $p1['ticket_id'] === $self['ticket_id'] ? $opp : $self;
    $room = tcgEventsMatchCreatePvpRoom($p1, $p2);
    $db->prepare('DELETE FROM tcg_event_queue WHERE ticket_id IN (?, ?)')->execute([$p1['ticket_id'], $p2['ticket_id']]);
    $now = time();
    $ins = $db->prepare('INSERT OR REPLACE INTO tcg_event_match_result (ticket_id, room_id, player_token, player_id, created_at) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([$p1['ticket_id'], $room['room_id'], $room['p1_token'], 'p1', $now]);
    $ins->execute([$p2['ticket_id'], $room['room_id'], $room['p2_token'], 'p2', $now]);
    $meIsP1 = $p1['ticket_id'] === $self['ticket_id'];
    $db->prepare('DELETE FROM tcg_event_match_result WHERE ticket_id = ?')->execute([$self['ticket_id']]);
    return ['status' => 'matched', 'room_id' => $room['room_id'], 'player_token' => $meIsP1 ? $room['p1_token'] : $room['p2_token'], 'player_id' => $meIsP1 ? 'p1' : 'p2'];
}

/**
 * @param array<string,mixed> $seat queue row
 * @param array<string,mixed> $cards cards.json data
 * @return array<string,mixed> player payload for initGameState / addSecondPlayer
 */
function tcgEventsMatchSeat(array $seat, string $pid, string $token, array $cards, string $group): array {
    $body = json_decode((string)$seat['join_body'], true) ?: [];
    $deck = $body['decks'][$group] ?? null;
    if (!is_array($deck)) {
        throw new Exception('Event deck missing for ' . $group);
    }
    $main = buildDeckForRoom($cards['cards'], array_values($deck['main']), [], 'main_order');
    $energy = buildDeckForRoom($cards['cards'], array_values($deck['energy']), [], 'energy_order');
    shuffle($main);
    shuffle($energy);
    return [
        'id' => $pid, 'token' => $token,
        'name' => htmlspecialchars(trim((string)($body['name'] ?? $seat['player_name'] ?? 'Player')), ENT_QUOTES),
        'deck_choice' => 'event:' . $group, 'deck_label' => 'Event deck',
        'main_deck' => $main, 'energy_deck' => $energy,
        'sleeve_id' => tcgNormalizeSleeveId($body['sleeve_id'] ?? ''),
        'playmat_id' => tcgNormalizePlaymatId($body['playmat_id'] ?? ''),
        'playmat_brightness' => tcgNormalizePlaymatBrightness($body['playmat_brightness'] ?? 1.0),
        'deck_snapshot' => ['main_nos' => array_values($deck['main']), 'energy_nos' => array_values($deck['energy'])],
        'discord_id' => (string)$seat['discord_id'],
    ];
}

/**
 * @param array<string,mixed> $p1Row
 * @param array<string,mixed> $p2Row
 * @return array{room_id:string,p1_token:string,p2_token:string}
 */
function tcgEventsMatchCreatePvpRoom(array $p1Row, array $p2Row): array {
    tcgEventsMatchLoadApi();
    $cards = tcgLoadCardsData();
    $group = (string)$p1Row['group_key'];
    $roomId = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
    $t1 = generateToken();
    $t2 = generateToken();
    $b1 = json_decode((string)$p1Row['join_body'], true) ?: [];
    $state = initGameState($roomId, tcgEventsMatchSeat($p1Row, 'p1', $t1, $cards, $group));
    $state['phase_timer_cfg'] = parsePhaseTimerConfigFromBody($b1);
    $state = addSecondPlayer($state, tcgEventsMatchSeat($p2Row, 'p2', $t2, $cards, $group), null, null);
    require_once __DIR__ . '/game_mode.php';
    $state['game_mode'] = TCG_GAME_MODE_STANDARD;
    $state['event_match'] = ['event_id' => (string)$p1Row['event_id'], 'group' => $group, 'vs_cpu' => false];
    saveGame($roomId, $state);
    return ['room_id' => $roomId, 'p1_token' => $t1, 'p2_token' => $t2];
}

/**
 * No opponent turned up: start a CPU game on the ticket's group. The CPU plays a starter deck of that group.
 *
 * @param array<string,mixed> $body
 */
function apiEventMatchCpu(array $body): array {
    tcgRateLimitForAction('casual_join', $body);
    tcgEventsMatchLoadApi();
    return tcgEventsMatchCpuRoom(tcgEventsMatchReadTicket($body), $body);
}

/**
 * @param array<string,mixed> $t verified ticket (see tcgEventsMatchReadTicket)
 * @param array<string,mixed> $body
 */
function tcgEventsMatchCpuRoom(array $t, array $body): array {
    require_once __DIR__ . '/casual_matchmaking.php';
    $paired = tcgWithCasualQueueLock(function () use ($t): ?array {
        $db = tcgDb();
        tcgEventsMatchEnsureSchema($db);
        $tid = (string)$t['ticket']['tid'];
        // Someone paired with this player a moment ago: take that room instead of starting a second one.
        $stmt = $db->prepare('SELECT * FROM tcg_event_match_result WHERE ticket_id = ?');
        $stmt->execute([$tid]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $db->prepare('DELETE FROM tcg_event_match_result WHERE ticket_id = ?')->execute([$tid]);
            return ['status' => 'matched', 'room_id' => $res['room_id'], 'player_token' => $res['player_token'], 'player_id' => $res['player_id']];
        }
        $db->prepare('DELETE FROM tcg_event_queue WHERE ticket_id = ?')->execute([$tid]);
        return null;
    });
    if ($paired) {
        return ['success' => true, 'matched' => $paired];
    }
    $cards = tcgLoadCardsData();
    $group = $t['group'];
    $roomId = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
    $humanToken = generateToken();
    $cpuToken = generateToken();
    $seat = ['join_body' => json_encode($body + ['decks' => $t['decks']], JSON_UNESCAPED_UNICODE), 'discord_id' => $t['uid'], 'player_name' => (string)($body['name'] ?? 'Player')];
    $state = initGameState($roomId, tcgEventsMatchSeat($seat, 'p1', $humanToken, $cards, $group['key']));
    $state['phase_timer_cfg'] = parsePhaseTimerConfigFromBody($body);
    // The CPU plays one of the group's starter decks: pure group cards, always legal.
    require_once __DIR__ . '/booster.php';
    $starterKey = $group['starters'][random_int(0, count($group['starters']) - 1)];
    $starter = tcgGetStarterDeckLists($starterKey, $cards);
    $gen = ['main_deck' => array_values($starter['main_deck']), 'energy_deck' => array_values($starter['energy_deck']), 'name_en' => tcgStarterLabel($starterKey)];
    $diff = normalizeCpuDifficulty($body['cpu_difficulty'] ?? 'normal');
    $cpuMain = buildDeckForRoom($cards['cards'], $gen['main_deck'], [], 'main_order');
    $cpuEnergy = buildDeckForRoom($cards['cards'], $gen['energy_deck'], [], 'energy_order');
    shuffle($cpuMain);
    shuffle($cpuEnergy);
    $state = addSecondPlayer($state, [
        'id' => 'p2', 'token' => $cpuToken, 'name' => 'CPU (' . ucfirst($diff) . ')',
        'deck_choice' => 'cpu:' . $diff, 'deck_label' => $gen['name_en'],
        'main_deck' => $cpuMain, 'energy_deck' => $cpuEnergy,
        'sleeve_id' => '', 'playmat_id' => '', 'playmat_brightness' => 1.0,
        'deck_snapshot' => ['main_nos' => $gen['main_deck'], 'energy_nos' => $gen['energy_deck']],
    ], null, null);
    $state['cpu_difficulty'] = $diff;
    $state['cpu_solo'] = true;
    unset($state['players']['p2']['discord_id']);
    require_once __DIR__ . '/game_mode.php';
    $state['game_mode'] = TCG_GAME_MODE_STANDARD;
    $state['event_match'] = ['event_id' => (string)$t['ticket']['eid'], 'group' => $group['key'], 'vs_cpu' => true];
    saveGame($roomId, $state);
    $out = [
        'success' => true, 'room_id' => $roomId, 'player_token' => $humanToken, 'player_id' => 'p1',
        'cpu_token' => $cpuToken, 'cpu_difficulty' => $diff,
    ];
    return function_exists('tcgSyncAttachMeta') ? tcgSyncAttachMeta($out, $roomId, $humanToken) : $out;
}

// ------------------------------------------------------------------ scoring

/**
 * Score a finished event match for each human seat: Event Points only (keys were paid when the ticket was issued).
 *
 * @param array<string,mixed> $state
 * @return list<array<string,mixed>>
 */
function tcgEventsMatchOnFinished(array $state): array {
    $em = $state['event_match'] ?? null;
    if (!is_array($em) || empty($em['event_id'])) {
        return [];
    }
    $roomId = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($state['room_id'] ?? '')) ?? '');
    $event = tcgEventsGetRow((string)$em['event_id']);
    if ($roomId === '' || !$event || !tcgEventsIsKeyType($event)) {
        return [];
    }
    // A match that started before the event ended still counts for a few minutes after it.
    if (tcgEventsNowTs($event) > intval($event['ends_at']) + 900) {
        return [];
    }
    if (!function_exists('tcgPlayerDiscordId')) {
        require_once __DIR__ . '/missions.php';
    }
    $out = [];
    foreach (['p1', 'p2'] as $pid) {
        $discordId = tcgPlayerDiscordId($state, $pid);
        if (!$discordId) {
            continue;
        }
        try {
            $r = tcgEventsRecordEventMatch($event, $discordId, ($state['winner'] ?? null) === $pid, !empty($em['vs_cpu']), false, $roomId);
        } catch (Throwable $e) {
            continue; // already scored for this room
        }
        $out[] = [
            'pid' => $pid, 'discord_id' => $discordId, 'event_id' => (string)$event['id'], 'amount' => $r['ep'],
            'balance' => $r['balance'], 'milestones' => $r['milestones'], 'event_match' => true,
        ];
    }
    return $out;
}
