#!/usr/bin/env php
<?php
/**
 * Generate golden replay fixtures under tests/fixtures/replays/.
 * Run from repo root: php scripts/build_replay_fixtures.php
 */
$root = dirname(__DIR__);
putenv('TCG_DATA_DIR=' . sys_get_temp_dir() . '/lltcg_fixtures_' . getmypid() . '/data');
putenv('TCG_GAMES_DIR=' . sys_get_temp_dir() . '/lltcg_fixtures_' . getmypid() . '/games');
putenv('TCG_SYNC_ENABLED=0');

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

define('TCG_API_LIB_ONLY', true);
require_once $root . '/api.php';

function fixturePath(string $name): string {
    return dirname(__DIR__) . '/tests/fixtures/replays/' . $name;
}

function writeFixture(string $name, array $payload): void {
    $path = fixturePath($name);
    validateReplayFile($payload);
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo "Wrote $path\n";
}

function replayPayload(array $baseline, array $actions, array $expected, string $roomId = 'FIXTURE'): array {
    return [
        'schema_version' => REPLAY_SCHEMA_VERSION,
        'meta' => [
            'saver_player_id' => 'p1',
            'saver_name' => 'Fixture P1',
            'cpu_difficulty' => 'easy',
            'room_id' => $roomId,
        ],
        'baseline' => $baseline,
        'actions' => $actions,
        'expected' => $expected,
    ];
}

function replayAction(string $player, string $type, array $data = []): array {
    return ['player' => $player, 'type' => $type, 'data' => $data];
}

function makeJoinedGame(): array {
    $created = createRoom(['name' => 'Fixture P1', 'deck' => 'nijigasaki']);
    $roomId = $created['room_id'];
    joinRoom([
        'room_id' => $roomId,
        'name' => 'Fixture P2',
        'deck' => 'cpu',
        'cpu_difficulty' => 'easy',
        'first_player' => 'p1',
    ]);
    $state = loadGame($roomId);
    if (!$state) {
        throw new RuntimeException('Failed to load game after join');
    }
    return $state;
}

function memberFromDeck(array $state, string $pid): array {
    foreach ($state['players'][$pid]['main_deck'] as $c) {
        if (($c['card_type'] ?? '') === 'メンバー') {
            return $c;
        }
    }
    throw new RuntimeException('No member card in deck');
}

// empty_main_phase.json — update expected block
$empty = json_decode((string)file_get_contents(fixturePath('empty_main_phase.json')), true);
$empty['expected'] = [
    'final_phase' => 'main_p1',
    'status' => 'playing',
    'winner' => null,
    'pending_prompt' => null,
];
writeFixture('empty_main_phase.json', $empty);

// mulligan_keep.json
$mullBase = makeJoinedGame();
$mullBaseline = cloneStateForReplayBaseline($mullBase);
writeFixture('mulligan_keep.json', replayPayload(
    $mullBaseline,
    [
        replayAction('p1', 'mulligan', ['card_ids' => []]),
        replayAction('p2', 'mulligan', ['card_ids' => []]),
    ],
    [
        'final_phase' => 'main_first',
        'status' => 'setup',
        'winner' => null,
        'pending_prompt' => null,
    ]
));

// game_end_resign.json
$mainState = replayApplyActionsThrough(
    replayRestoreFromBaseline($mullBaseline, 'FIXRESIGN', 't1', 't2'),
    [
        replayAction('p1', 'mulligan', ['card_ids' => []]),
        replayAction('p2', 'mulligan', ['card_ids' => []]),
    ],
    2
);
$resignBaseline = cloneStateForReplayBaseline($mainState);
writeFixture('game_end_resign.json', replayPayload(
    $resignBaseline,
    [replayAction('p1', 'resign')],
    [
        'final_phase' => 'main_first',
        'status' => 'finished',
        'winner' => 'p2',
        'pending_prompt' => null,
    ],
    'FIXRESIGN'
));

// live_round_spectacle.json — empty live round (both lock 0 cards)
$liveState = $mainState;
$liveState = applyAction($liveState, 'p1', 'end_main', []);
$liveState = applyAction($liveState, 'p2', 'end_main', []);
$liveBaseline = cloneStateForReplayBaseline($liveState);
writeFixture('live_round_spectacle.json', replayPayload(
    $liveBaseline,
    [
        replayAction('p1', 'end_live_set'),
        replayAction('p2', 'end_live_set'),
    ],
    [
        'final_phase' => 'main_first',
        'status' => 'setup',
        'winner' => null,
        'pending_prompt' => null,
    ],
    'FIXLIVE'
));

// prompt_branch_yes_no.json
$promptBase = cloneStateForReplayBaseline($mainState);
$topCard = $promptBase['players']['p1']['main_deck'][0] ?? null;
if (!$topCard) {
    throw new RuntimeException('Missing deck top for prompt fixture');
}
$promptBase['pending_prompt'] = [
    'type' => 'look_top_optional_wr',
    'owner' => 'p1',
    'responder' => 'p1',
    'target' => 'p1',
    'source_name' => 'Fixture',
    'top_card' => $topCard,
    'prompt' => 'Put top card into Waiting Room?',
    'choices' => ['yes', 'no'],
    'choice_labels' => ['Yes — Put in WR', 'No — Leave on top'],
];
writeFixture('prompt_branch_yes_no.json', replayPayload(
    $promptBase,
    [replayAction('p1', 'resolve_prompt', ['choice' => 'no'])],
    [
        'final_phase' => 'main_first',
        'status' => 'setup',
        'winner' => null,
        'pending_prompt' => null,
    ],
    'FIXPROMPT'
));

// wr_pick_member.json
$wrCard = memberFromDeck($mainState, 'p1');
$wrCard['instance_id'] = 'wr-fixture-member-1';
$wrBase = cloneStateForReplayBaseline($mainState);
$wrBase['players']['p1']['waiting_room'] = [$wrCard];
$wrBase['pending_prompt'] = [
    'type' => 'pick_wr_to_hand',
    'owner' => 'p1',
    'responder' => 'p1',
    'source_name' => 'Fixture',
    'wr_pick_cfg' => ['filter' => 'member'],
];
writeFixture('wr_pick_member.json', replayPayload(
    $wrBase,
    [replayAction('p1', 'resolve_prompt', ['card_id' => 'wr-fixture-member-1'])],
    [
        'final_phase' => 'main_first',
        'status' => 'setup',
        'winner' => null,
        'pending_prompt' => null,
        'hand_contains' => 'wr-fixture-member-1',
    ],
    'FIXWR'
));

echo "All replay fixtures generated.\n";
