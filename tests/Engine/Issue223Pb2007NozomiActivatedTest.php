<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-007 Nozomi — Activated leave Stage → add μ's Live from WR →
 * activate 1 Energy per μ's card in Success Live (#223).
 * ActivateAbility previously threw "Ability type not implemented".
 */
final class Issue223Pb2007NozomiActivatedTest extends TestCase
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

    private function live(string $id, string $group = "μ's"): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-TEST-LIVE-' . $id,
            'name_en' => 'Test Live ' . $id,
            'name' => 'Test Live ' . $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => $group,
            'score' => 1,
            'required_hearts' => [['color' => 'pink', 'count' => 1]],
        ];
    }

    private function energyZone(int $active, int $inactive): array
    {
        $out = [];
        for ($i = 0; $i < $active; $i++) {
            $out[] = [
                'instance_id' => 'ae_' . $i,
                'card_type' => 'エネルギー',
                'card_type_en' => 'Energy',
                'active' => true,
            ];
        }
        for ($i = 0; $i < $inactive; $i++) {
            $out[] = [
                'instance_id' => 'ie_' . $i,
                'card_type' => 'エネルギー',
                'card_type_en' => 'Energy',
                'active' => false,
            ];
        }
        return $out;
    }

    private function countActiveEnergy(array $state): int
    {
        $n = 0;
        foreach ($state['players']['p1']['energy_zone'] ?? [] as $e) {
            if ($e['active'] ?? false) {
                $n++;
            }
        }
        return $n;
    }

    private function baseState(array $nozomi, array $wrLive, array $successLives): array
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
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [$wrLive],
                    'stage' => [
                        'left' => $nozomi,
                        'center' => null,
                        'right' => null,
                    ],
                    'energy_zone' => $this->energyZone(1, 4),
                    'main_deck' => [
                        [
                            'instance_id' => 'deck1',
                            'card_type' => 'メンバー',
                            'card_type_en' => 'Member',
                            'name_en' => 'Filler',
                            'cost' => 1,
                            'active' => true,
                        ],
                    ],
                    'success_lives' => $successLives,
                    'live_zone' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => $this->energyZone(3, 0),
                    'main_deck' => [],
                    'success_lives' => [],
                    'live_zone' => [],
                ],
            ],
        ];
    }

    public function testCatalogAbilityIsActivatedLeaveStage(): void
    {
        $nozomi = $this->cardByNo('PL!-pb2-007-PP', 'nozomi');
        $ab = $nozomi['abilities'][0] ?? [];
        $this->assertSame('activated', $ab['trigger'] ?? null);
        $this->assertSame('leave_stage_add_live_activate_per_success_group', $ab['type'] ?? null);
    }

    public function testActivateOpensLeaveStageWrPick(): void
    {
        $nozomi = $this->cardByNo('PL!-pb2-007-PP', 'nozomi');
        $wrLive = $this->live('shoujo', "μ's");
        $success = [$this->live('succ1', "μ's"), $this->live('succ2', "μ's")];
        $state = $this->baseState($nozomi, $wrLive, $success);

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'nozomi',
            'ability_index' => 0,
        ]);

        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pick_wr_leave_stage_add', $pr['type'] ?? null);
        $this->assertSame(2, intval($pr['ability']['then_activate_energy'] ?? -1));
        $this->assertSame('nozomi', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
    }

    public function testResolvingPickAddsLiveLeavesStageAndActivatesEnergy(): void
    {
        $nozomi = $this->cardByNo('PL!-pb2-007-PP', 'nozomi');
        $wrLive = $this->live('shoujo', "μ's");
        $success = [$this->live('succ1', "μ's"), $this->live('succ2', "μ's")];
        $state = $this->baseState($nozomi, $wrLive, $success);

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'nozomi',
            'ability_index' => 0,
        ]);
        $this->assertSame(1, $this->countActiveEnergy($state));

        $state = \actionResolvePrompt($state, 'p1', [
            'card_id' => 'shoujo',
        ]);

        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertNull($state['players']['p1']['stage']['left'] ?? null);
        $handNos = array_map(
            static fn($c) => $c['instance_id'] ?? '',
            $state['players']['p1']['hand'] ?? []
        );
        $this->assertContains('shoujo', $handNos);
        $wrIds = array_map(
            static fn($c) => $c['instance_id'] ?? '',
            $state['players']['p1']['waiting_room'] ?? []
        );
        $this->assertContains('nozomi', $wrIds);
        // 1 already active + 2 from Success μ's Lives
        $this->assertSame(3, $this->countActiveEnergy($state));
    }

    public function testNoWrLiveBlocksActivation(): void
    {
        $nozomi = $this->cardByNo('PL!-pb2-007-PP', 'nozomi');
        $state = $this->baseState($nozomi, $this->live('other', 'Liella!'), []);
        // Wrong-group Live should not count.
        $reason = \activatedAbilityWrBlockReason(
            $state['players']['p1'],
            $nozomi['abilities'][0]
        );
        $this->assertNotNull($reason);

        $state['players']['p1']['waiting_room'] = [];
        $before = $state;
        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'nozomi',
            'ability_index' => 0,
        ]);
        // Fizzle path: Nozomi stays on Stage, no prompt.
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame('nozomi', $state['players']['p1']['stage']['left']['instance_id'] ?? null);
        $this->assertSame(
            $before['players']['p1']['stage']['left']['instance_id'],
            $state['players']['p1']['stage']['left']['instance_id']
        );
    }
}
