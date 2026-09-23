<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!SP-pb2-018 Mei Yoneme [Live Start]: activate 1 Energy for each other
 * differently named CatChu! Member. Must not offer Distortion's "up to 6".
 */
final class MeiPb2018LiveStartEnergyTest extends TestCase
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

    private function member(string $nameEn, string $subunit, string $iid): array
    {
        return [
            'instance_id' => $iid,
            'card_no' => 'TEST-' . $iid,
            'name' => $nameEn,
            'name_en' => $nameEn,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => 'Superstar',
            'subunit' => $subunit,
            'cost' => 2,
            'active' => true,
            'hearts' => [['color' => 'red', 'count' => 1]],
            'abilities' => [],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function restingEnergy(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['instance_id' => 'er' . $i, 'card_type' => 'エネルギー', 'active' => false];
        }
        return $out;
    }

    private function activeCount(array $state): int
    {
        $n = 0;
        foreach ($state['players']['p1']['energy_zone'] as $e) {
            if (!empty($e['active'])) {
                $n++;
            }
        }
        return $n;
    }

    private function base(array $mei, array $stage, array $energy): array
    {
        return [
            'room_id' => 'MEI018',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 3,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_attempt' => ['p1'],
            '_live_start_perf_pid' => 'p1',
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => $stage,
                    'energy_zone' => $energy,
                    'main_deck' => [],
                    'live_zone' => [[
                        'instance_id' => 'live0',
                        'card_no' => 'TEST-LIVE',
                        'name_en' => 'Test Live',
                        'card_type' => 'ライブ',
                        'abilities' => [],
                    ]],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testPrintingsUsePerOtherMemberEnergy(): void
    {
        foreach (['PL!SP-pb2-018-P＋', 'PL!SP-pb2-018-R'] as $no) {
            $card = $this->cardByNo($no, 'mei');
            $this->assertSame(
                'activate_energy_per_other_named_subunit',
                $card['abilities'][0]['type'] ?? null,
                $no
            );
        }
    }

    public function testOneOtherCatchuActivatesOneEnergy(): void
    {
        $mei = $this->cardByNo('PL!SP-pb2-018-R', 'mei');
        $state = $this->base($mei, [
            'left' => $mei,
            'center' => $this->member('Kanon Shibuya', 'CatChu!', 'kanon'),
            'right' => null,
        ], $this->restingEnergy(6));

        $state = \resolveLiveStartAbilities($state, 'p1');

        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertSame(1, $this->activeCount($state));
    }

    public function testTwoOtherCatchuActivateTwoEvenIfSixEnergyRests(): void
    {
        $mei = $this->cardByNo('PL!SP-pb2-018-P＋', 'mei');
        $state = $this->base($mei, [
            'left' => $this->member('Kanon Shibuya', 'CatChu!', 'kanon'),
            'center' => $mei,
            'right' => $this->member('Keke Tang', 'CatChu!', 'keke'),
        ], $this->restingEnergy(6));

        $state = \resolveLiveStartAbilities($state, 'p1');

        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertSame(2, $this->activeCount($state));
    }

    public function testSameNameAndNonCatchuDoNotCount(): void
    {
        $mei = $this->cardByNo('PL!SP-pb2-018-R', 'mei');
        $state = $this->base($mei, [
            'left' => $mei,
            'center' => $this->member('Mei Yoneme', 'CatChu!', 'mei2'),
            'right' => $this->member('Kinako Sakurakoji', '5yncri5e!', 'kina'),
        ], $this->restingEnergy(6));

        $state = \resolveLiveStartAbilities($state, 'p1');

        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertSame(0, $this->activeCount($state));
    }

    public function testMeiAloneDoesNotOpenEnergyPrompt(): void
    {
        $mei = $this->cardByNo('PL!SP-pb2-018-R', 'mei');
        $state = $this->base($mei, [
            'left' => null,
            'center' => $mei,
            'right' => null,
        ], $this->restingEnergy(6));

        $state = \resolveLiveStartAbilities($state, 'p1');

        $this->assertEmpty($state['pending_prompt'] ?? null);
        $this->assertSame(0, $this->activeCount($state));
    }
}
