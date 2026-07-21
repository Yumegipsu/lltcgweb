<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** Regression: Superstar skill audit gaps (activated + continuous handlers). */
final class SkillAuditSuperstarFixesTest extends TestCase
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

    private function baseState(string $phase = 'main_first'): array
    {
        return [
            'status' => 'playing',
            'phase' => $phase,
            'seq' => 1,
            'turn' => 1,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [['instance_id' => 'deck_top']],
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

    /** @return list<array<string, mixed>> */
    private function activeEnergy(int $count, string $prefix = 'ssfix_en'): array
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

    public function testChisatoActivatedRevealHandOpensPrompt(): void
    {
        $chisato = $this->cardByNo('PL!SP-bp1-003-P', 'ssfix_chisato');
        $handMember = $this->cardByNo('PL!SP-bp2-012-N', 'ssfix_hand_member');
        $handMember['cost'] = 10;

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $chisato;
        $state['players']['p1']['hand'] = [$handMember];

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'ssfix_chisato',
            'ability_index' => 0,
        ]);

        $this->assertSame(
            'reveal_hand_member_cost_live_score',
            $state['pending_prompt']['type'] ?? null
        );
    }

    public function testWienBp1ActivatedPaysEnergyAndOpensMandatoryDiscard(): void
    {
        $wien = $this->cardByNo('PL!SP-bp1-010-P', 'ssfix_wien_bp1');
        $discard = $this->cardByNo('PL!SP-bp2-012-N', 'ssfix_discard');

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $wien;
        $state['players']['p1']['hand'] = [$discard];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(2);

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'ssfix_wien_bp1',
            'ability_index' => 0,
        ]);

        $this->assertSame(
            'mandatory_discard_look_reveal',
            $state['pending_prompt']['type'] ?? null
        );
        $activeAfter = count(array_filter(
            $state['players']['p1']['energy_zone'] ?? [],
            fn($c) => !empty($c['active'])
        ));
        $this->assertSame(0, $activeAfter);
    }

    public function testWienBp2ContinuousAddsGrayHeartToEachOppLiveCard(): void
    {
        $wien = $this->cardByNo('PL!SP-bp2-010-P', 'ssfix_wien_bp2');
        $liveA = [
            'instance_id' => 'ssfix_live_a',
            'card_type' => 'ライブ',
            'hearts' => [['color' => 'red', 'count' => 1]],
            'required_hearts' => [['color' => 'red', 'count' => 1]],
        ];
        $liveB = [
            'instance_id' => 'ssfix_live_b',
            'card_type' => 'ライブ',
            'hearts' => [['color' => 'yellow', 'count' => 1]],
            'required_hearts' => [['color' => 'yellow', 'count' => 1]],
        ];

        $state = $this->baseState('live_start_effects');
        $state['players']['p2']['stage']['center'] = $wien;
        $state['players']['p1']['live_zone'] = [$liveA, $liveB];

        $state = \resolveLiveStartAbilities($state, 'p1');

        foreach ($state['players']['p1']['live_zone'] as $lc) {
            $req = $lc['required_hearts'] ?? [];
            $gray = 0;
            foreach ($req as $r) {
                if (($r['color'] ?? '') === 'gray') {
                    $gray += intval($r['count'] ?? 0);
                }
            }
            $this->assertSame(1, $gray, 'Each Live card should require +1 Gray heart');
        }
    }

    public function testKanonBp2012HasNoContinuousCenterBlade(): void
    {
        // Official PL!SP-bp2-012-N has no skill text / abilities (issue #65).
        $kanon = $this->cardByNo('PL!SP-bp2-012-N', 'ssfix_kanon');
        $this->assertSame([], $kanon['abilities'] ?? []);
        $this->assertSame('', trim((string)($kanon['text'] ?? '')));

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $kanon;

        $blade = \getMemberBlade($kanon, $state, 'p1', 'center');
        $this->assertSame(1, $blade, 'Base blade only — no +2 Center continuous');
    }

    public function testKinakoSd2ActivatedOptionalDiscardOpensPrompt(): void
    {
        $kinako = $this->cardByNo('PL!SP-sd2-006-SD2', 'ssfix_kinako');
        $handCard = $this->cardByNo('PL!SP-bp2-012-N', 'ssfix_kinako_discard');
        $wrLive = [
            'instance_id' => 'ssfix_wr_live',
            'card_type' => 'ライブ',
            'group' => 'Superstar',
            'name_en' => 'Test Live',
        ];

        $state = $this->baseState();
        $state['players']['p1']['stage']['center'] = $kinako;
        $state['players']['p1']['hand'] = [$handCard];
        $state['players']['p1']['waiting_room'] = [$wrLive];
        $state['players']['p1']['energy_zone'] = $this->activeEnergy(2, 'ssfix_kinako_en');

        $state = \actionActivateAbility($state, 'p1', [
            'card_id' => 'ssfix_kinako',
            'ability_index' => 0,
        ]);

        $this->assertSame(
            'optional_discard_prompt',
            $state['pending_prompt']['type'] ?? null
        );
        $activeCount = count(array_filter(
            $state['players']['p1']['energy_zone'] ?? [],
            fn($c) => !empty($c['active'])
        ));
        $this->assertSame(2, $activeCount, 'Energy should not be paid until player confirms');
    }
}
