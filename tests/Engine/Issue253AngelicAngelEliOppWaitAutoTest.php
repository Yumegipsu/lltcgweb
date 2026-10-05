<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-011 Angelic Angel Eli — Auto stack BiBi from WR when opp is Waited (#253).
 *
 * Also covers chaining after Angelic Angel Maki's choose prompt for the same Wait.
 */
final class Issue253AngelicAngelEliOppWaitAutoTest extends TestCase
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
            'cost' => 2,
            'hearts' => [['color' => 'yellow', 'count' => 1]],
        ];
    }

    public function testCatalogHasStackUnderAuto(): void
    {
        $eli = $this->cardByNo('PL!-pb2-011-R', 'eli');
        $found = false;
        foreach ($eli['abilities'] ?? [] as $ab) {
            if (($ab['type'] ?? '') === 'auto_stack_wr_subunit_under_on_opp_wait') {
                $found = true;
                $this->assertSame('auto', $ab['trigger'] ?? null);
                $this->assertSame('BiBi', $ab['subunit'] ?? null);
                $this->assertSame(2, intval($ab['max_under'] ?? 0));
            }
        }
        $this->assertTrue($found);
    }

    public function testBibiWaitOpensEliStackPrompt(): void
    {
        $baMaki = $this->cardByNo('PL!-pb2-006-PP', 'ba');
        $aaEli = $this->cardByNo('PL!-pb2-011-R', 'eli');
        $wrBibi = $this->cardByNo('PL!-pb1-024-N', 'wr_bibi');
        $wrBibi2 = $this->cardByNo('PL!-pb2-027-N', 'wr_bibi2');
        $hand = $this->oneHeartMember('hand1', 'Hand Fodder');
        $opp = $this->oneHeartMember('opp1', 'Natsumi');

        $state = [
            'room_id' => 'ISSUE253',
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
                        'right' => $aaEli,
                    ],
                    'waiting_room' => [$wrBibi, $wrBibi2],
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

        $state = actionActivateAbility($state, 'p1', [
            'card_id' => 'ba',
            'ability_index' => 0,
        ]);
        $this->assertSame('effect_discard_hand', $state['pending_prompt']['type'] ?? null);
        $state = actionResolvePrompt($state, 'p1', ['discard_ids' => ['hand1']]);

        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['center']));
        $pr = $state['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame(
            'stack_wr_under',
            $pr['type'] ?? null,
            'Expected AA Eli stack-under prompt, got ' . json_encode($pr['type'] ?? null)
        );
        $this->assertSame('Eli Ayase', $pr['source_name'] ?? null);
        $this->assertGreaterThanOrEqual(2, count($pr['candidates'] ?? []));
    }

    public function testEliStacksAfterMakiChooseOnSameWait(): void
    {
        $baMaki = $this->cardByNo('PL!-pb2-006-PP', 'ba');
        $aaMaki = $this->cardByNo('PL!-pb2-015-R', 'aa_maki');
        $aaEli = $this->cardByNo('PL!-pb2-011-R', 'eli');
        $wrBibi = $this->cardByNo('PL!-pb1-024-N', 'wr_bibi');
        $hand = $this->oneHeartMember('hand1', 'Hand Fodder');
        $opp = $this->oneHeartMember('opp1', 'Natsumi');

        $state = [
            'status' => 'playing',
            'seq' => 3,
            'turn' => 2,
            'phase' => 'main_first',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$hand],
                    // L→R: BA Maki (Wait source), AA Maki (choose), AA Eli (stack)
                    'stage' => [
                        'left' => $baMaki,
                        'center' => $aaMaki,
                        'right' => $aaEli,
                    ],
                    'waiting_room' => [$wrBibi],
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

        $state = actionActivateAbility($state, 'p1', [
            'card_id' => 'ba',
            'ability_index' => 0,
        ]);
        $state = actionResolvePrompt($state, 'p1', ['discard_ids' => ['hand1']]);

        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['center']));
        $pr = $state['pending_prompt'] ?? null;
        $this->assertSame(
            'auto_on_opp_wait_by_subunit_choose',
            $pr['type'] ?? null,
            'AA Maki choose should open first (L→R)'
        );

        // Activate 2 Energy (choice index 1) — no further Maki pick.
        $state = actionResolvePrompt($state, 'p1', ['choice' => '1']);

        $pr2 = $state['pending_prompt'] ?? null;
        $this->assertSame(
            'stack_wr_under',
            $pr2['type'] ?? null,
            'AA Eli stack-under must follow Maki choose on the same Wait'
        );
        $this->assertSame('eli', $pr2['source_instance_id'] ?? null);
    }

    public function testEliFiresWhenEffectSourceCardMissing(): void
    {
        $aaEli = $this->cardByNo('PL!-pb2-011-R', 'eli');
        $wrBibi = $this->cardByNo('PL!-pb1-024-N', 'wr_bibi');
        $opp = $this->oneHeartMember('opp1', 'Natsumi');

        $state = [
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'main_first',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => [
                        'left' => null,
                        'center' => null,
                        'right' => $aaEli,
                    ],
                    'waiting_room' => [$wrBibi],
                    'energy_zone' => [],
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

        // No _wait_effect_source / effectSourceCard — Eli must still see "your effect" Wait.
        waitOpponentMemberAtSlot($state, 'p2', 'center', 'p1');
        $state = plMusePb2FlushPendingOppWaitAutos($state);

        $this->assertTrue(memberIsInWait($state['players']['p2']['stage']['center']));
        $this->assertSame('stack_wr_under', $state['pending_prompt']['type'] ?? null);
    }
}
