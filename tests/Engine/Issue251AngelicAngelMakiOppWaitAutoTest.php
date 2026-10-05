<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-015 Angelic Angel Maki — Auto when opp Waited by a BiBi effect (#251).
 *
 * resolveAutomaticOpponentWaitEffects only handled draw_on_opp_wait (trigger
 * "automatic"); AA Maki's auto_on_opp_wait_by_subunit_choose never fired.
 * Prompts are queued until the Wait pick clears so they are not wiped.
 */
final class Issue251AngelicAngelMakiOppWaitAutoTest extends TestCase
{
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

    private function oneHeartMember(string $id, string $nameEn): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => $nameEn,
            'name' => $nameEn,
            'group' => 'Superstar',
            'blade' => 1,
            'active' => true,
            'hearts' => [['color' => 'yellow', 'count' => 1]],
        ];
    }

    public function testCatalogHasAutoOnOppWaitChoose(): void
    {
        $aa = $this->cardByNo('PL!-pb2-015-R', 'aa');
        $ab = $aa['abilities'][0] ?? [];
        $this->assertSame('auto', $ab['trigger'] ?? null);
        $this->assertSame('auto_on_opp_wait_by_subunit_choose', $ab['type'] ?? null);
        $this->assertSame('BiBi', $ab['subunit'] ?? null);
    }

    public function testBibiWaitOpensAngelicAngelChoice(): void
    {
        $baMaki = $this->cardByNo('PL!-pb2-006-PP', 'ba');
        $aaMaki = $this->cardByNo('PL!-pb2-015-R', 'aa');
        $hand = $this->oneHeartMember('hand1', 'Hand Fodder');
        $opp = $this->oneHeartMember('opp1', 'One Heart Opp');

        $state = [
            'room_id' => 'ISSUE251',
            'status' => 'playing',
            'seq' => 3,
            'turn' => 2,
            'phase' => 'main_first',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$hand],
                    'stage' => [
                        'left' => $baMaki,
                        'center' => null,
                        'right' => $aaMaki,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => array_fill(0, 4, [
                        'card_type' => 'エネルギー',
                        'active' => false,
                        'instance_id' => 'e' . random_int(1, 99999),
                    ]),
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $opp,
                        'right' => null,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        // Activated BA Maki → Wait self → discard → Wait opp (single target auto).
        $state = actionActivateAbility($state, 'p1', [
            'card_id' => 'ba',
            'ability_index' => 0,
        ]);
        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);

        $state = actionResolvePrompt($state, 'p1', ['discard_ids' => ['hand1']]);

        // Opp Waited + AA Maki auto choice (or intermediate wait pick then choice).
        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['center']));

        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        // Single legal opp target auto-waits then flushes AA Maki choose.
        $this->assertSame(
            'auto_on_opp_wait_by_subunit_choose',
            $pr['type'] ?? null,
            'Expected AA Maki choose prompt, got ' . json_encode($pr['type'] ?? null)
        );
        $this->assertContains('0', $pr['choices'] ?? []);
        $this->assertContains('1', $pr['choices'] ?? []);
    }

    public function testNonBibiWaitDoesNotTriggerAngelicAngel(): void
    {
        $aaMaki = $this->cardByNo('PL!-pb2-015-R', 'aa');
        // Printemps Kotori is not BiBi.
        $kotori = $this->cardByNo('PL!-pb2-012-P+', 'kotori');
        $opp = $this->oneHeartMember('opp1', 'One Heart Opp');

        $state = [
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'main_first',
            'active_player' => 'p1',
            'log' => [],
            '_mod_source' => $kotori,
            '_wait_effect_source' => $kotori,
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => [
                        'left' => $kotori,
                        'center' => null,
                        'right' => $aaMaki,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => [
                        'left' => null,
                        'center' => $opp,
                        'right' => null,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        waitOpponentMemberAtSlot($state, 'p2', 'center', 'p1');
        $state = plMusePb2FlushPendingOppWaitAutos($state);

        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['center']));
        $this->assertNull($state['pending_prompt'] ?? null);
    }

    public function testBlueAngelLiveStartSkipsWhenOppHasTooManyPrintedHearts(): void
    {
        // Replay case: Kinako blade 1 but 3 printed hearts — not a legal BA Maki target.
        $ba = $this->cardByNo('PL!-pb2-006-PP', 'ba');
        $kinako = $this->oneHeartMember('kinako', 'Kinako Sakurakoji');
        $kinako['hearts'] = [['color' => 'yellow', 'count' => 3]];
        $kinako['blade'] = 1;

        $state = [
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$this->oneHeartMember('h1', 'Hand')],
                    'stage' => ['left' => $ba, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => $kinako, 'right' => null],
                    'waiting_room' => [],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $ls = null;
        foreach ($ba['abilities'] as $ab) {
            if (($ab['trigger'] ?? '') === 'live_start') {
                $ls = $ab;
                break;
            }
        }
        $this->assertNotNull($ls);

        $state = resolveAbilityEffect($state, 'p1', $ba, $ls, ['phase' => 'live_start']);
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertFalse(memberIsInWait($state['players']['p2']['stage']['center']));
    }
}
