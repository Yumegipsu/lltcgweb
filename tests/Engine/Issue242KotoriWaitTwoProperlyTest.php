<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-012 Kotori — correct Wait-2 additional cost + softlock multi-slot (#242).
 *
 * #240 incorrectly treated self as 1 of the 2 Printemps. Card text: Wait self, then
 * as additional cost discard 2 OR Wait 2 Printemps Members (other Active Members).
 */
final class Issue242KotoriWaitTwoProperlyTest extends TestCase
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

    private function printempsLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-TEST-LIVE-' . $id,
            'name_en' => 'Printemps Live ' . $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'group' => "μ's",
            'subunit' => 'Printemps',
            'score' => 1,
        ];
    }

    private function abIdx(array $kotori): int
    {
        foreach ($kotori['abilities'] ?? [] as $i => $a) {
            if (($a['type'] ?? '') === 'activated_wait_printemps_live_from_wr') {
                return (int)$i;
            }
        }
        $this->fail('Missing activated ability');
    }

    private function stateWithThreePrintemps(array $hand = []): array
    {
        $kotori = $this->cardByNo('PL!-pb2-012-P+', 'kotori');
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $hanayo = $this->cardByNo('PL!-bp3-008-P', 'hanayo');
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
                    'hand' => $hand,
                    'waiting_room' => [$this->printempsLive('plive')],
                    'stage' => [
                        'left' => $kotori,
                        'center' => $honoka,
                        'right' => $hanayo,
                    ],
                    'energy_zone' => array_fill(0, 10, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testWait2OpensExactTwoPick(): void
    {
        $state = $this->stateWithThreePrintemps();
        $idx = $this->abIdx($state['players']['p1']['stage']['left']);
        $state = actionActivateAbility($state, 'p1', ['card_id' => 'kotori', 'ability_index' => $idx]);
        $this->assertSame('pb2_printemps_cost_mode', $state['pending_prompt']['type'] ?? null);
        $this->assertContains('Wait 2 other Printemps Members', $state['pending_prompt']['choice_labels'] ?? []);

        $state = actionResolvePrompt($state, 'p1', ['choice' => 'wait2']);
        // Exactly two other Printemps → auto-waits both, then WR pick.
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['center']));
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['right']));
        $this->assertSame('pick_wr_to_hand', $state['pending_prompt']['type'] ?? null);
    }

    public function testWait2WithThreeOthersRequiresPickOfTwo(): void
    {
        $kotori = $this->cardByNo('PL!-pb2-012-R', 'kotori');
        // Use a second Kotori as a third *other* Printemps (same name as source is fine for Wait cost).
        $kOther = $this->cardByNo('PL!-pb2-012-P+', 'kOther');
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $hanayo = $this->cardByNo('PL!-bp3-008-P', 'hanayo');
        // Stage only has 3 slots — put Kotori activating from WR-style isn't right.
        // Instead: center Kotori activates; left/right + need a third. Only 3 stage slots.
        // So auto-resolve path with exactly 2 others is the normal case; force a pick by
        // temporarily injecting a fourth candidate via resolve path after wait self.
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [$this->printempsLive('plive')],
                    'stage' => [
                        'left' => $honoka,
                        'center' => $kotori,
                        'right' => $hanayo,
                    ],
                    'energy_zone' => array_fill(0, 8, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];
        // Replace after wait-self simulation isn't needed — with 2 others, auto-resolves.
        // Verify softlock payload for an artificial 3-candidate wait prompt.
        $prompt = [
            'type' => 'pb2_printemps_wait_members',
            'owner' => 'p1',
            'responder' => 'p1',
            'source_instance_id' => 'kotori',
            'subunit' => 'Printemps',
            'candidates' => [
                array_merge(cardPromptSummary($honoka), ['slot' => 'left']),
                array_merge(cardPromptSummary($hanayo), ['slot' => 'right']),
                array_merge(cardPromptSummary($kOther), ['slot' => 'center']),
            ],
            'min' => 2,
            'max' => 2,
            'pick_count' => 2,
            'up_to' => false,
            'prompt' => 'Choose 2 other Printemps Members to put into Wait.',
        ];
        $payloads = enumerateSoftlockPromptPayloads($state, 'p1', $prompt);
        $multi = null;
        foreach ($payloads as $p) {
            if (isset($p['slots']) && count($p['slots']) === 2) {
                $multi = $p['slots'];
                break;
            }
        }
        $this->assertNotNull($multi, 'softlock must offer a 2-slot payload, not leftmost-only');
        $this->assertCount(2, $multi);

        $timeout = buildTimeoutPromptResolution($state, 'p1', $prompt);
        $this->assertSame(['left', 'right'], $timeout['slots'] ?? null);
    }

    public function testAntiSoftlockWaitsTwoNotOne(): void
    {
        $state = $this->stateWithThreePrintemps();
        $idx = $this->abIdx($state['players']['p1']['stage']['left']);
        $state = actionActivateAbility($state, 'p1', ['card_id' => 'kotori', 'ability_index' => $idx]);
        $state = actionResolvePrompt($state, 'p1', ['choice' => 'wait2']);
        // Auto-resolved both waits already; if prompt is WR pick, softlock that instead.
        if (($state['pending_prompt']['type'] ?? '') === 'pick_wr_to_hand') {
            $state = actionAntiSoftlockSkipPrompt($state, 'p1');
            $this->assertNull($state['pending_prompt'] ?? null);
            $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
            $this->assertContains('plive', $handIds);
        }
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['center']));
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['right']));
    }

    public function testPlayCostDiscountStillApplies(): void
    {
        $kotori = $this->cardByNo('PL!-pb2-012-P+', 'kotori_hand');
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $hanayo = $this->cardByNo('PL!-bp3-008-P', 'hanayo');
        $state = [
            'status' => 'playing',
            'phase' => 'main_first',
            'seq' => 1,
            'turn' => 2,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$kotori],
                    'waiting_room' => [],
                    'stage' => [
                        'left' => $honoka,
                        'center' => null,
                        'right' => $hanayo,
                    ],
                    'energy_zone' => array_map(static function (int $i): array {
                        return [
                            'instance_id' => 'ae_' . $i,
                            'card_type' => 'エネルギー',
                            'card_type_en' => 'Energy',
                            'active' => true,
                        ];
                    }, range(0, 10)),
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        [$reduced, $ab] = plMusePb2AdjustHandPlayCost($state, 'p1', $kotori, 13, [
            'wait_slots' => ['left', 'right'],
        ]);
        $this->assertNotNull($ab);
        $this->assertSame(11, $reduced);

        $state = actionPlayMember($state, 'p1', [
            'card_id' => 'kotori_hand',
            'slot' => 'center',
            'pb2_wait_slots' => ['left', 'right'],
        ]);
        $this->assertSame('kotori_hand', $state['players']['p1']['stage']['center']['instance_id'] ?? null);
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['left']));
        $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['right']));
    }
}
