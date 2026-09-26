<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Second performer Live Start must not re-enter an unresolved Wait skill.
 * Room FF22CB: Karin (cost ≤9, one legal target) called resume from inside
 * resolveLiveStartAbilities and the request died on the memory limit.
 */
final class IssueKarinLiveStartReentryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        unset($GLOBALS['_lltcg_in_live_start_resolve']);
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
        $blank = [
            'id' => '',
            'name' => '',
            'hand' => [],
            'waiting_room' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'success_lives' => [],
            'live_zone' => [],
        ];
        $p1 = $blank;
        $p1['id'] = 'p1';
        $p1['name'] = 'P1';
        $p2 = $blank;
        $p2['id'] = 'p2';
        $p2['name'] = 'P2';
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 10,
            'turn' => 6,
            'first_player' => 'p1',
            'active_player' => 'p2',
            'live_attempt' => ['p1', 'p2'],
            '_live_start_perf_pid' => 'p2',
            '_live_start_done' => ['p1' => true],
            'live_start_mandatory_resolved' => [
                'p1:earlier:0' => true,
            ],
            'log' => [],
            'players' => ['p1' => $p1, 'p2' => $p2],
        ];
    }

    public function testSecondPerformerStillChoosesLiveStartOrder(): void
    {
        $karin = $this->cardByNo('PL!N-bp4-004-P', 'karin');
        $live = $this->cardByNo('PL!N-bp5-029-L', 'believer');
        $state = $this->baseState();
        $state['players']['p2']['stage']['center'] = $karin;
        $state['players']['p2']['live_zone'] = [$live];
        $state['players']['p2']['main_deck'] = [
            $this->cardByNo('PL!N-pb1-026-N', 'deck1'),
        ];
        $cheap = $this->cardByNo('PL!HS-bp6-012-R', 'cheap');
        $state['players']['p1']['stage']['center'] = $cheap;

        $state = \resolveLiveStartAbilities($state, 'p2');

        $this->assertSame('live_start_order_sources', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);
        $this->assertLessThan(20, count($state['log'] ?? []));
    }

    public function testSingleWaitTargetDoesNotReenterLiveStart(): void
    {
        $karin = $this->cardByNo('PL!N-bp4-004-P', 'karin');
        $live = $this->cardByNo('PL!N-bp5-029-L', 'believer');
        $state = $this->baseState();
        $state['players']['p2']['stage']['center'] = $karin;
        $state['players']['p2']['live_zone'] = [$live];
        $state['players']['p2']['main_deck'] = [
            $this->cardByNo('PL!N-pb1-026-N', 'deck1'),
        ];
        $cheap = $this->cardByNo('PL!HS-bp6-012-R', 'cheap');
        $state['players']['p1']['stage']['center'] = $cheap;
        $state['_live_start_order'] = ['p2' => ['karin', 'believer']];
        $state['_live_start_order_asked'] = ['p2' => true];

        $state = \resolveLiveStartAbilities($state, 'p2');

        $this->assertTrue(!empty($state['players']['p1']['stage']['center']['in_wait']));
        $this->assertLessThan(40, count($state['log'] ?? []));
        $this->assertArrayNotHasKey('_lltcg_in_live_start_resolve', $GLOBALS);
    }
}
