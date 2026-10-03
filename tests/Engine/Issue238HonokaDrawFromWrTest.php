<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-001 Honoka — Live Start Draw-icon → add μ's from WR (#238).
 * Previously emitted pending_prompt type add_from_wr, which has no client UI
 * or PromptResolver path and softlocked after Success Draw-icon bonuses.
 */
final class Issue238HonokaDrawFromWrTest extends TestCase
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
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                $card['active'] = true;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function drawIconSuccessLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-TEST-DRAW-' . $id,
            'name_en' => 'Draw Icon Live',
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => "μ's",
            'score' => 1,
            'yell_draw_icon' => true,
            'special_heart' => 'icon_draw.png',
        ];
    }

    private function musCardInWr(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-TEST-WR-' . $id,
            'name_en' => 'WR μ\'s Member',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'group' => "μ's",
            'cost' => 3,
        ];
    }

    private function baseState(array $honoka, array $successLives, array $wr): array
    {
        return [
            'room_id' => 'ISSUE238',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $honoka,
                        'right' => null,
                    ],
                    'waiting_room' => $wr,
                    'energy_zone' => array_fill(0, 12, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => $successLives,
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testDrawIconOpensPickWrToHandNotAddFromWr(): void
    {
        $honoka = $this->cardByNo('PL!-pb2-001-R', 'honoka001');
        $wrCard = $this->musCardInWr('wr1');
        $state = $this->baseState($honoka, [$this->drawIconSuccessLive('sl1')], [$wrCard]);

        $state = resolveAbilityEffect($state, 'p1', $honoka, $honoka['abilities'][0], [
            'phase' => 'live_start',
            'slot' => 'center',
            'ability_index' => 0,
        ]);

        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pick_wr_to_hand', $pr['type'] ?? null);
        $this->assertNotSame('add_from_wr', $pr['type'] ?? null);
        $ids = array_column($pr['candidates'] ?? [], 'instance_id');
        $this->assertContains('wr1', $ids);
        $this->assertSame("μ's", $pr['wr_pick_cfg']['group'] ?? null);
        $this->assertSame('', $pr['wr_pick_cfg']['filter'] ?? 'missing');
    }

    public function testResolvingPickAddsCardAndResumesLiveStart(): void
    {
        $honoka = $this->cardByNo('PL!-pb2-001-R', 'honoka001');
        $wrCard = $this->musCardInWr('wr1');
        $state = $this->baseState($honoka, [$this->drawIconSuccessLive('sl1')], [$wrCard]);
        $state['_live_start_perf_pid'] = 'p1';
        $state['live_start_optional_queue'] = [];

        $state = resolveAbilityEffect($state, 'p1', $honoka, $honoka['abilities'][0], [
            'phase' => 'live_start',
            'slot' => 'center',
            'ability_index' => 0,
        ]);
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);

        $state = actionResolvePrompt($state, 'p1', [
            'card_id' => 'wr1',
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertContains('wr1', $handIds);
        $wrIds = array_column($state['players']['p1']['waiting_room'], 'instance_id');
        $this->assertNotContains('wr1', $wrIds);
    }

    public function testNoWrCandidatesSkipsPrompt(): void
    {
        $honoka = $this->cardByNo('PL!-pb2-001-R', 'honoka001');
        $state = $this->baseState($honoka, [$this->drawIconSuccessLive('sl1')], []);

        $state = resolveAbilityEffect($state, 'p1', $honoka, $honoka['abilities'][0], [
            'phase' => 'live_start',
            'slot' => 'center',
            'ability_index' => 0,
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
    }
}
