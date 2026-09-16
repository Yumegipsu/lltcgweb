<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** PL!S-bp6-003 Kanan — activated swap Stage Aqours for WR cost+2 after discarding 1. */
final class KananSBp6003SwapTest extends TestCase
{
    private function kananAbility(): array
    {
        $data = json_decode((string)file_get_contents((string)constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === 'PL!S-bp6-003-R') {
                $ab = $card['abilities'][0] ?? null;
                $this->assertNotNull($ab);
                $this->assertSame('activated_swap_stage_wr_member', $ab['type'] ?? null);
                $this->assertSame(2, intval($ab['energy_cost'] ?? 0));
                return $ab;
            }
        }
        $this->fail('Missing PL!S-bp6-003-R');
    }

    private function aqours(string $id, int $cost, string $name = 'Aqours Member'): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'name_en' => $name,
            'group' => 'Sunshine',
            'cost' => $cost,
            'active' => true,
            'abilities' => [],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function activeEnergy(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'instance_id' => 'e' . $i,
                'card_type' => 'エネルギー',
                'card_type_en' => 'Energy',
                'active' => true,
            ];
        }
        return $out;
    }

    private function baseState(array $kanan, array $other, array $hand, array $wr = [], bool $withResponder = true): array
    {
        $prompt = [
            'type' => 'sbp6_swap_stage_wr_member',
            'step' => 'confirm',
            'owner' => 'p1',
            'source_id' => $kanan['instance_id'],
            'source_name' => 'Kanan Matsuura',
            'ability' => $this->kananAbility(),
            'prompt' => 'Discard 1?',
            'choices' => ['yes', 'no'],
        ];
        if ($withResponder) {
            $prompt['responder'] = 'p1';
        }
        return [
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'log' => [],
            'pending_prompt' => $prompt,
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => $hand,
                    'waiting_room' => $wr,
                    'stage' => [
                        'left' => $other,
                        'center' => $kanan,
                        'right' => null,
                    ],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => $this->activeEnergy(2),
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testActivateOpensConfirmWithResponder(): void
    {
        $kanan = $this->aqours('kanan', 9, 'Kanan');
        $kanan['abilities'] = [$this->kananAbility()];
        $other = $this->aqours('you', 5, 'You');
        $state = $this->baseState($kanan, $other, [$this->aqours('hand1', 1)], [$this->aqours('wr7', 7)]);
        unset($state['pending_prompt']);

        $next = actionActivateAbility($state, 'p1', [
            'card_id' => 'kanan',
            'ability_index' => 0,
        ]);

        $pr = $next['pending_prompt'] ?? null;
        $this->assertNotNull($pr);
        $this->assertSame('sbp6_swap_stage_wr_member', $pr['type'] ?? null);
        $this->assertSame('p1', $pr['responder'] ?? null);
        $this->assertSame('p1', $pr['owner'] ?? null);
        $this->assertSame(['yes', 'no'], $pr['choices'] ?? null);
    }

    public function testLegacyMissingResponderStillResolvesViaDispatch(): void
    {
        $kanan = $this->aqours('kanan', 9, 'Kanan');
        $other = $this->aqours('you', 5, 'You');
        $handCard = $this->aqours('hand1', 1);
        $wr = [$this->aqours('wr7', 7)];
        $state = $this->baseState($kanan, $other, [$handCard], $wr, false);
        $this->assertArrayNotHasKey('responder', $state['pending_prompt']);

        $next = actionResolvePrompt($state, 'p1', [
            'choice' => 'yes',
            'discard_ids' => ['hand1'],
        ]);

        $this->assertArrayNotHasKey('_resolve_prompt_noop', $next);
        $this->assertSame('sbp6_swap_pick_stage_member', $next['pending_prompt']['type'] ?? null);
        $this->assertSame('p1', $next['pending_prompt']['responder'] ?? null);
    }

    public function testYesWithoutDiscardThrows(): void
    {
        $kanan = $this->aqours('kanan', 9, 'Kanan');
        $other = $this->aqours('you', 5, 'You');
        $hand = [$this->aqours('hand1', 1)];
        $wr = [$this->aqours('wr7', 7)];
        $state = $this->baseState($kanan, $other, $hand, $wr);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Discard exactly 1 card');
        sBp6ResolvePrompt($state, 'p1', $state['pending_prompt'], 'yes', []);
    }

    public function testYesWithoutEnergyThrows(): void
    {
        $kanan = $this->aqours('kanan', 9, 'Kanan');
        $other = $this->aqours('you', 5, 'You');
        $handCard = $this->aqours('hand1', 1);
        $wr = [$this->aqours('wr7', 7)];
        $state = $this->baseState($kanan, $other, [$handCard], $wr);
        $state['players']['p1']['energy_zone'] = [];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Need 2 active Energy');
        sBp6ResolvePrompt($state, 'p1', $state['pending_prompt'], 'yes', [
            'discard_ids' => ['hand1'],
        ]);
    }

    public function testYesWithDiscardOffersStagePick(): void
    {
        $kanan = $this->aqours('kanan', 9, 'Kanan');
        $other = $this->aqours('you', 5, 'You');
        $handCard = $this->aqours('hand1', 1);
        $wr = [$this->aqours('wr7', 7)];
        $state = $this->baseState($kanan, $other, [$handCard], $wr);

        $next = sBp6ResolvePrompt($state, 'p1', $state['pending_prompt'], 'yes', [
            'discard_ids' => ['hand1'],
        ]);

        $this->assertSame('sbp6_swap_pick_stage_member', $next['pending_prompt']['type'] ?? null);
        $this->assertSame('p1', $next['pending_prompt']['responder'] ?? null);
        $cands = $next['pending_prompt']['candidates'] ?? [];
        $this->assertCount(1, $cands);
        $this->assertSame('you', $cands[0]['instance_id'] ?? null);
        $this->assertCount(0, $next['players']['p1']['hand']);
        $this->assertCount(0, array_filter(
            $next['players']['p1']['energy_zone'] ?? [],
            fn($c) => !empty($c['active'])
        ));
        $this->assertTrue(
            (bool)array_filter(
                $next['players']['p1']['waiting_room'],
                fn($c) => ($c['instance_id'] ?? '') === 'hand1'
            )
        );
    }

    public function testFullSwapPlaysWrMember(): void
    {
        $kanan = $this->aqours('kanan', 9, 'Kanan');
        $other = $this->aqours('you', 5, 'You');
        $handCard = $this->aqours('hand1', 1);
        $wrMember = $this->aqours('wr7', 7, 'Dia');
        $state = $this->baseState($kanan, $other, [$handCard], [$wrMember]);

        $state = sBp6ResolvePrompt($state, 'p1', $state['pending_prompt'], 'yes', [
            'discard_ids' => ['hand1'],
        ]);
        $state = sBp6ResolvePrompt($state, 'p1', $state['pending_prompt'], 'you', [
            'card_id' => 'you',
        ]);
        $this->assertSame('sbp6_swap_pick_wr_member', $state['pending_prompt']['type'] ?? null);
        $state = sBp6ResolvePrompt($state, 'p1', $state['pending_prompt'], 'wr7', [
            'card_id' => 'wr7',
        ]);

        $this->assertArrayNotHasKey('pending_prompt', $state);
        $left = $state['players']['p1']['stage']['left'] ?? null;
        $this->assertNotNull($left);
        $this->assertSame('wr7', $left['instance_id'] ?? null);
        $this->assertSame('kanan', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $this->assertTrue(
            (bool)array_filter(
                $state['players']['p1']['waiting_room'],
                fn($c) => ($c['instance_id'] ?? '') === 'you'
            )
        );
    }
}
