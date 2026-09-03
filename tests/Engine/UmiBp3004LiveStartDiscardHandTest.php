<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Umi PL!-bp3-004 Live Start: discard ANY hand card, then add μ's Live from WR.
 * Client previously filtered the discard hand by ability group/filter (WR targets).
 */
final class UmiBp3004LiveStartDiscardHandTest extends TestCase
{
    private function cardByNo(string $cardNo, string $instanceId): array {
        $data = json_decode((string) file_get_contents(CARDS_FILE), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = $instanceId;
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    private function firstMusLive(string $instanceId): array {
        $data = json_decode((string) file_get_contents(CARDS_FILE), true);
        foreach ($data['cards'] ?? [] as $card) {
            $isLive = ($card['card_type_en'] ?? '') === 'Live' || ($card['card_type'] ?? '') === 'ライブ';
            if (($card['group'] ?? '') === "μ's" && $isLive) {
                $card['instance_id'] = $instanceId;
                return $card;
            }
        }
        $this->fail('Need a μ\'s Live in catalog');
    }

    public function testUmiLiveStartAcceptsAnyHandCardDiscardThenAddsMusLive(): void {
        $umi = $this->cardByNo('PL!-bp3-004-R＋', 'umi');
        $handMember = $this->cardByNo('PL!HS-sd1-015-SD', 'hand_any'); // Hasunosora — not μ's Live
        $wrLive = $this->firstMusLive('wr_mus_live');
        $successLive = $this->firstMusLive('success_live');
        $liveInZone = $this->firstMusLive('live_zone');

        $state = [
            'room_id' => 'UMI_LS',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$handMember],
                    'stage' => ['left' => null, 'center' => $umi, 'right' => null],
                    'waiting_room' => [$wrLive],
                    'energy_zone' => [],
                    'main_deck' => [['instance_id' => 'd1', 'card_type' => 'メンバー', 'card_type_en' => 'Member']],
                    'energy_deck' => [],
                    'live_zone' => [$liveInZone],
                    'success_lives' => [$successLive],
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

        $ab = null;
        foreach ($umi['abilities'] as $a) {
            if (($a['type'] ?? '') === 'optional_discard_add_from_wr') {
                $ab = $a;
                break;
            }
        }
        $this->assertNotNull($ab);

        $queueItem = [
            'owner' => 'p1',
            'source_id' => 'umi',
            'source_name' => 'Umi Sonoda',
            'ability_index' => 1,
            'ability' => $ab,
        ];
        $state['pending_prompt'] = buildOptionalLiveStartPrompt($state, $queueItem);
        $this->assertSame('optional_live_start', $state['pending_prompt']['type'] ?? null);
        $this->assertSame(1, intval($state['pending_prompt']['discard_count'] ?? 0));

        $state = applyAction($state, 'p1', 'resolve_prompt', [
            'choice' => 'yes',
            'discard_ids' => ['hand_any'],
        ]);

        $handIds = array_column($state['players']['p1']['hand'], 'instance_id');
        $this->assertNotContains('hand_any', $handIds, 'Discard cost must accept a non-μ\'s hand card');
        $pr = $state['pending_prompt'] ?? null;
        if ($pr) {
            $this->assertTrue(
                str_contains((string) ($pr['type'] ?? ''), 'wr')
                || ($pr['type'] ?? '') === 'pick_wr_to_hand'
                || !empty($pr['candidates']),
                'Expected Waiting Room Live pick after discard, got ' . json_encode($pr['type'] ?? null)
            );
        } else {
            $this->assertContains('wr_mus_live', $handIds);
        }
    }

    /** Issue #144: no optional Live Start prompt when WR has no μ's Live. */
    public function testUmiLiveStartSkippedWhenNoMusLiveInWaitingRoom(): void {
        $umi = $this->cardByNo('PL!-bp3-004-R＋', 'umi');
        $handMember = $this->cardByNo('PL!HS-sd1-015-SD', 'hand_any');
        $successLive = $this->firstMusLive('success_live');
        $liveInZone = $this->firstMusLive('live_zone');
        $wrMember = $this->cardByNo('PL!HS-sd1-015-SD', 'wr_member');

        $state = [
            'room_id' => 'UMI_LS_NO_WR',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_set',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [$handMember],
                    'stage' => ['left' => null, 'center' => $umi, 'right' => null],
                    // Member only — no μ's Live target for the add-from-WR half.
                    'waiting_room' => [$wrMember],
                    'energy_zone' => [
                        ['instance_id' => 'e0', 'active' => true, 'card_type' => 'エネルギー'],
                        ['instance_id' => 'e1', 'active' => true, 'card_type' => 'エネルギー'],
                        ['instance_id' => 'e2', 'active' => true, 'card_type' => 'エネルギー'],
                    ],
                    'main_deck' => [['instance_id' => 'd1', 'card_type' => 'メンバー', 'card_type_en' => 'Member']],
                    'energy_deck' => [],
                    'live_zone' => [$liveInZone],
                    'success_lives' => [$successLive],
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

        $ab = null;
        foreach ($umi['abilities'] as $a) {
            if (($a['type'] ?? '') === 'optional_discard_add_from_wr') {
                $ab = $a;
                break;
            }
        }
        $this->assertNotNull($ab);
        $this->assertFalse(
            optionalLiveStartAbilityEligible($state, 'p1', $umi, $ab),
            'Must not offer Umi Live Start with no μ\'s Live in Waiting Room'
        );

        $state = resolveLiveStartAbilities($state, 'p1');
        $pr = $state['pending_prompt'] ?? null;
        if ($pr !== null) {
            $this->assertNotSame(
                'optional_live_start',
                $pr['type'] ?? null,
                'Must not open optional Live Start for Umi with no WR target'
            );
            $this->assertNotSame('umi', $pr['source_id'] ?? null);
        }
        $this->assertSame(
            [],
            collectOptionalLiveStartAbilities($state),
            'Optional queue must omit Umi when WR has no μ\'s Live'
        );
    }

    public function testUmiLiveStartEligibleWhenMusLiveInWaitingRoom(): void {
        $umi = $this->cardByNo('PL!-bp3-004-R＋', 'umi');
        $handMember = $this->cardByNo('PL!HS-sd1-015-SD', 'hand_any');
        $wrLive = $this->firstMusLive('wr_mus_live');
        $successLive = $this->firstMusLive('success_live');

        $state = [
            'room_id' => 'UMI_LS_OK',
            'status' => 'playing',
            'seq' => 1,
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
                    'hand' => [$handMember],
                    'stage' => ['left' => null, 'center' => $umi, 'right' => null],
                    'waiting_room' => [$wrLive],
                    'energy_zone' => [],
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [$this->firstMusLive('live_zone')],
                    'success_lives' => [$successLive],
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

        $ab = null;
        foreach ($umi['abilities'] as $a) {
            if (($a['type'] ?? '') === 'optional_discard_add_from_wr') {
                $ab = $a;
                break;
            }
        }
        $this->assertNotNull($ab);
        $this->assertTrue(optionalLiveStartAbilityEligible($state, 'p1', $umi, $ab));
    }
}
