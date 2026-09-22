<?php

declare(strict_types=1);

namespace LLTCG\Tests\Replay;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #199: Retrofuture Live Start WR play must not soft-skip in replay.
 * replayEnsureCardInHand used to yank the WR target into hand before resolve,
 * then "Card not in Waiting Room" desynced every later action.
 */
final class Issue199RetrofutureReplayWrPlayTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents(CARDS_FILE), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function emptyPlayer(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'hand' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'waiting_room' => [],
            'energy_zone' => [],
            'main_deck' => array_fill(0, 10, [
                'instance_id' => $id . '_deck',
                'card_type' => 'エネルギー',
            ]),
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
            'token' => $id . '-tok',
        ];
    }

    public function testRetrofutureWrPlaySurvivesReplaySeek(): void
    {
        $retro = $this->cardByNo('PL!HS-bp5-022-L', 'retro');
        $wr = $this->cardByNo('PL!HS-bp5-008-R', 'wr_edel');
        $center = $this->cardByNo('PL!HS-pb1-007-R', 'center11');

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage']['center'] = $center;
        $p1['waiting_room'] = [$wr];
        $p1['live_zone'] = [$retro];
        $p1['energy_zone'] = array_map(
            static fn(int $i): array => ['instance_id' => "e$i", 'active' => true],
            range(0, 5)
        );

        $p2 = $this->emptyPlayer('p2', 'CPU Bot');
        $p2['deck_choice'] = 'cpu';

        $base = [
            'room_id' => 'ISSUE199',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_set',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_ready' => ['p1' => true, 'p2' => true],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];

        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $live = beginLiveStartEffectPhase(
                json_decode(json_encode($base), true),
                true,
                false
            );
            $this->assertSame('optional_live_start', $live['pending_prompt']['type'] ?? null);

            $actions = [
                ['player_id' => 'p1', 'type' => 'resolve_prompt', 'data' => ['choice' => 'yes', 'pay' => true]],
                ['player_id' => 'p1', 'type' => 'resolve_prompt', 'data' => ['choice' => 'play']],
                ['player_id' => 'p1', 'type' => 'resolve_prompt', 'data' => ['card_id' => 'wr_edel']],
            ];

            $state = beginLiveStartEffectPhase(
                json_decode(json_encode($base), true),
                true,
                false
            );
            foreach ($actions as $i => $a) {
                $state = replayApplyRecordedAction(
                    $state,
                    $a['player_id'],
                    $a['type'],
                    $a['data'],
                    $i + 1
                );
            }

            $played = $state['players']['p1']['stage']['left']['instance_id']
                ?? $state['players']['p1']['stage']['right']['instance_id']
                ?? null;
            $this->assertSame(
                'wr_edel',
                $played,
                'Retrofuture WR Member must be played onto Stage during replay seek'
            );

            $skipLogs = array_filter(
                $state['log'] ?? [],
                static fn(array $e): bool => str_contains(
                    (string)($e['msg'] ?? ''),
                    'skipped unresolved prompt'
                )
            );
            $this->assertSame([], array_values($skipLogs), 'Must not soft-skip the WR play resolve');
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testEnsureCardInHandDoesNotYankWaitingRoomTargets(): void
    {
        $member = $this->cardByNo('PL!HS-bp5-008-R', 'wr_keep');
        $state = [
            'players' => [
                'p1' => [
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [$member],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
        replayEnsureCardInHand($state, 'p1', 'wr_keep');
        $this->assertSame(
            ['wr_keep'],
            array_column($state['players']['p1']['waiting_room'], 'instance_id')
        );
        $this->assertSame([], $state['players']['p1']['hand']);
    }
}
