<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Replay 8B4552: Ginko On Enter opens opponent Wait pick; playing Kaho before that
 * resolves used to skip Kaho's surveil On Enter (pending_prompt guard).
 */
final class KahoBp6001OnEnterAfterOppWaitTest extends TestCase
{
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
            'turn' => 3,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'Arc',
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
                    'name' => 'Opp',
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

    /** @return list<array<string, mixed>> */
    private function activeEnergy(int $count, string $prefix = 'kaho_en'): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'instance_id' => $prefix . $i,
                'card_type' => 'エネルギー',
                'active' => true,
            ];
        }
        return $out;
    }

    private function fillerMember(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!HS-sd1-001-SD',
            'name_en' => 'Filler',
            'card_type' => 'メンバー',
            'group' => 'Hasunosora',
            'cost' => 3,
            'active' => true,
        ];
    }

    public function testOppWaitPromptBlocksPlayUntilResolvedThenKahoSurveilFires(): void
    {
        $ginko = $this->cardByNo('PL!HS-bp6-004-P', 'ginko_stage');
        $kaho = $this->cardByNo('PL!HS-bp6-001-P', 'kaho_hand');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $ginko;
        $state['players']['p2']['stage']['left'] = $this->fillerMember('opp_left');
        $state['players']['p2']['stage']['center'] = $this->fillerMember('opp_center');

        $state = \resolveOnEnterAbilities($state, 'p1', $ginko, 'center');
        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p2', $state['pending_prompt']['responder'] ?? null);

        $state['players']['p1']['hand'] = [$kaho];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(4);
        $state['players']['p1']['main_deck'] = [
            $this->fillerMember('deck1'),
            $this->fillerMember('deck2'),
            $this->fillerMember('deck3'),
            $this->fillerMember('deck4'),
            $this->fillerMember('deck5'),
        ];

        try {
            \actionPlayMember($state, 'p1', [
                'card_id' => 'kaho_hand',
                'slot' => 'right',
            ]);
            $this->fail('Expected play_member to be blocked while opponent Wait prompt is open');
        } catch (\Exception $e) {
            $this->assertStringContainsString('pending skill prompt', $e->getMessage());
        }

        $this->assertSame('wait_opponent_stage_pick', $state['pending_prompt']['type'] ?? null);
        $this->assertNull($state['players']['p1']['stage']['right'] ?? null);

        $state = \actionResolvePrompt($state, 'p2', ['slot' => 'left']);
        $this->assertEmpty($state['pending_prompt'] ?? null);

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'kaho_hand',
            'slot' => 'right',
        ]);
        $this->assertSame('kaho_hand', $state['players']['p1']['stage']['right']['instance_id'] ?? null);
        $this->assertSame('surveil_pick_one_deck_top', $state['pending_prompt']['type'] ?? null);
        $this->assertSame('p1', $state['pending_prompt']['responder'] ?? null);
        $this->assertGreaterThanOrEqual(2, count($state['pending_prompt']['candidates'] ?? []));
    }
}
