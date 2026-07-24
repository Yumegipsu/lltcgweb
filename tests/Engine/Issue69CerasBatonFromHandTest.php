<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #69: PL!HS-bp6-015-R Ceras — [On Enter] draw/discard only if Stage entry
 * was not from hand. Baton Touch from hand must not trigger it.
 */
final class Issue69CerasBatonFromHandTest extends TestCase
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

    private function energy(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'instance_id' => 'en_' . $i,
                'card_type' => 'エネルギー',
                'active' => true,
            ];
        }
        return $out;
    }

    private function filler(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Filler ' . $id,
            'active' => true,
        ];
    }

    private function baseState(array $stageMember, array $ceras, array $extraHand = [], array $deck = []): array
    {
        $stageMember['entered_turn'] = 1;
        return [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 4,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => array_merge([$ceras], $extraHand),
                    'waiting_room' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $stageMember,
                        'right' => null,
                    ],
                    'energy_zone' => $this->energy(20),
                    'main_deck' => $deck,
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

    public function testAbilityDefinition(): void
    {
        $ceras = $this->cardByNo('PL!HS-bp6-015-R', 'ceras');
        $ab = ($ceras['abilities'] ?? [])[0] ?? null;
        $this->assertIsArray($ab);
        $this->assertSame('on_enter', $ab['trigger'] ?? null);
        $this->assertSame('draw_and_discard_if_not_from_hand', $ab['type'] ?? null);
    }

    public function testBatonFromHandDoesNotTriggerDrawDiscard(): void
    {
        $stage = $this->cardByNo('PL!HS-sd1-010-SD', 'stage_sayaka');
        $ceras = $this->cardByNo('PL!HS-bp6-015-R', 'ceras_hand');
        $deck = [$this->filler('d1'), $this->filler('d2'), $this->filler('d3')];
        $handExtra = [$this->filler('h1'), $this->filler('h2')];

        $state = $this->baseState($stage, $ceras, $handExtra, $deck);
        $handBefore = count($state['players']['p1']['hand']);
        $deckBefore = count($state['players']['p1']['main_deck']);

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'ceras_hand',
            'slot' => 'center',
            'baton_id' => 'stage_sayaka',
        ]);

        $onStage = $state['players']['p1']['stage']['center'] ?? null;
        $this->assertSame('ceras_hand', $onStage['instance_id'] ?? null);
        $this->assertTrue(!empty($onStage['entered_via_baton']));
        $this->assertTrue(!empty($onStage['entered_from_hand']), 'Baton from hand is still from hand');
        $this->assertNull($state['pending_prompt'] ?? null, 'Must not open discard prompt');
        // Hand: played Ceras (-1); no draw/discard from On Enter.
        $this->assertSame($handBefore - 1, count($state['players']['p1']['hand']));
        $this->assertSame($deckBefore, count($state['players']['p1']['main_deck']));
    }

    public function testNormalPlayFromHandDoesNotTrigger(): void
    {
        $ceras = $this->cardByNo('PL!HS-bp6-015-R', 'ceras_play');
        $deck = [$this->filler('d1'), $this->filler('d2')];
        $state = $this->baseState(
            $this->cardByNo('PL!HS-sd1-010-SD', 'other'),
            $ceras,
            [],
            $deck
        );
        // Play to empty left (not baton).
        $state['players']['p1']['stage']['center'] = null;
        $deckBefore = count($state['players']['p1']['main_deck']);

        $state = \actionPlayMember($state, 'p1', [
            'card_id' => 'ceras_play',
            'slot' => 'left',
        ]);

        $onStage = $state['players']['p1']['stage']['left'] ?? null;
        $this->assertSame('ceras_play', $onStage['instance_id'] ?? null);
        $this->assertTrue(!empty($onStage['entered_from_hand']));
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame($deckBefore, count($state['players']['p1']['main_deck']));
    }

    public function testEnterNotFromHandStillTriggers(): void
    {
        $ceras = $this->cardByNo('PL!HS-bp6-015-R', 'ceras_wr');
        $ceras['entered_from_hand'] = false;
        $ceras['entered_from_wr'] = true;
        $ab = ($ceras['abilities'] ?? [])[0];
        $p = [
            'hand' => [$this->filler('h1'), $this->filler('h2'), $this->filler('h3')],
            'main_deck' => [$this->filler('d1'), $this->filler('d2'), $this->filler('d3')],
            'waiting_room' => [],
            'stage' => ['left' => null, 'center' => $ceras, 'right' => null],
            'energy_zone' => [],
        ];
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'log' => [],
            'players' => [
                'p1' => array_merge($p, ['id' => 'p1', 'name' => 'P1', 'success_lives' => [], 'live_zone' => []]),
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

        $state = \resolveAbilityEffect($state, 'p1', $ceras, $ab, ['phase' => 'on_enter']);
        // Draw 2 then ask to discard 2.
        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(5, count($state['players']['p1']['hand'])); // 3 + 2 drawn
    }
}
