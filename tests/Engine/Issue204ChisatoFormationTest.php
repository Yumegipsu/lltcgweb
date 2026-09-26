<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!SP-pb2-014 (11) Formation Change is own Stage only.
 * PL!SP-pb2-003 (13) Live Success +1 marks every Member a Liella effect moved,
 * not only the first area in the batch.
 * PL!SP-pb1-003 (9) still rotates both Stages.
 */
final class Issue204ChisatoFormationTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
    }

    private function cardByNo(string $cardNo, string $instanceId): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
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

    private function baseState(): array
    {
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];
    }

    public function testElevenCostFormationChangeDoesNotMoveOpponent(): void
    {
        $eleven = $this->cardByNo('PL!SP-pb2-014-P＋', 'chi11');
        $thirteen = $this->cardByNo('PL!SP-pb2-003-PP', 'chi13');
        $opp = $this->cardByNo('PL!SP-sd2-005-SD2', 'opp');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $eleven;
        $state['players']['p1']['stage']['left'] = $thirteen;
        $state['players']['p2']['stage']['center'] = $opp;

        $state = \resolveOnEnterAbilities($state, 'p1', $eleven, 'center');

        $this->assertSame('optional_formation_change_group', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('opp', $state['players']['p2']['stage']['center']['instance_id'] ?? null);
        $this->assertNull($state['players']['p2']['stage']['left']);

        $state = \actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'assignments' => [
                'center' => 'chi13',
                'left' => 'chi11',
            ],
        ]);

        $this->assertSame('main_first', $state['phase'] ?? null);
        $this->assertSame('chi13', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $this->assertSame('Superstar', $state['players']['p1']['stage']['center']['moved_by_group_effect'] ?? '');
        $this->assertTrue(!empty($state['players']['p1']['stage']['center']['moved_this_turn']));
        $this->assertSame('chi11', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertSame('Superstar', $state['players']['p1']['stage']['left']['moved_by_group_effect'] ?? '');
        $this->assertSame('opp', $state['players']['p2']['stage']['center']['instance_id'] ?? null);
        $this->assertTrue(empty($state['players']['p2']['stage']['center']['moved_this_turn']));

        $state = \resolveLiveSuccessAbilities($state, 'p1', [], 0, [], []);
        $this->assertSame(1, intval($state['live_modifiers']['p1']['score_bonus'] ?? 0));
    }

    public function testElevenCostDoesNotFireWhenStageIsNotOnlySubunit(): void
    {
        $eleven = $this->cardByNo('PL!SP-pb2-014-R', 'chi11');
        $other = $this->cardByNo('PL!SP-sd2-005-SD2', 'ren');
        $opp = $this->cardByNo('PL!SP-pb2-003-PP', 'opp');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $eleven;
        $state['players']['p1']['stage']['left'] = $other;
        $state['players']['p2']['stage']['center'] = $opp;

        $state = \resolveOnEnterAbilities($state, 'p1', $eleven, 'center');

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('opp', $state['players']['p2']['stage']['center']['instance_id'] ?? null);
        $this->assertSame('ren', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
    }

    public function testNineCostStillRotatesBothStagesAndMarksLaterMember(): void
    {
        $nine = $this->cardByNo('PL!SP-pb1-003-P＋', 'chi9');
        $ally = $this->cardByNo('PL!SP-PR-005-PR', 'ally');
        $thirteen = $this->cardByNo('PL!SP-pb2-003-PP', 'chi13');
        $opp = $this->cardByNo('PL!SP-sd2-005-SD2', 'opp');
        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $nine;
        $state['players']['p1']['stage']['left'] = $ally;
        $state['players']['p1']['stage']['right'] = $thirteen;
        $state['players']['p2']['stage']['center'] = $opp;

        $state = \resolveOnEnterAbilities($state, 'p1', $nine, 'center');

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('chi13', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $this->assertSame('Superstar', $state['players']['p1']['stage']['center']['moved_by_group_effect'] ?? '');
        $this->assertSame('chi9', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertSame('ally', $state['players']['p1']['stage']['right']['instance_id'] ?? null);
        $this->assertNull($state['players']['p2']['stage']['center']);
        $this->assertSame('opp', $state['players']['p2']['stage']['left']['instance_id'] ?? null);
        $this->assertSame('Superstar', $state['players']['p2']['stage']['left']['moved_by_group_effect'] ?? '');
    }
}
