<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Honoka Kosaka PL!-bp3-001-R [Live Start] Activate up to 1 Stage Member.
 * Client openStageMemberPickById historically sent card_id; resolver only read
 * member_id/slot → Exception "Choose a Member to activate".
 */
final class HonokaBp3001ActivateMembersPickTest extends TestCase
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
        $this->fail('Missing card ' . $cardNo);
    }

    private function emptyPlayer(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'hand' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'waiting_room' => [],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    private function waited(array $card): array
    {
        $card['active'] = false;
        $card['in_wait'] = true;
        return $card;
    }

    private function baseState(array $p1): array
    {
        return [
            'room_id' => 'HONOKA_BP3',
            'status' => 'playing',
            'seq' => 1,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'live_attempt' => ['p1'],
            '_live_start_perf_pid' => 'p1',
            'players' => [
                'p1' => $p1,
                'p2' => $this->emptyPlayer('p2', 'P2'),
            ],
        ];
    }

    public function testCardIdPayloadActivatesWaitMember(): void
    {
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $eli = $this->waited($this->cardByNo('PL!-bp3-002-R', 'eli'));
        // Force a second Wait target so the effect opens a pick (not auto-resolve).
        $umi = $this->waited($this->cardByNo('PL!-bp3-003-R', 'umi'));

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage'] = [
            'left' => $honoka,
            'center' => $eli,
            'right' => $umi,
        ];
        // Need a Live so Live Start phase has context; Honoka is Stage source.
        $p1['live_zone'] = [
            [
                'instance_id' => 'live1',
                'card_type' => 'ライブ',
                'card_type_en' => 'Live',
                'name_en' => 'Test Live',
                'group' => "μ's",
                'abilities' => [],
            ],
        ];

        $state = $this->baseState($p1);
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            if (($state['pending_prompt']['type'] ?? '') === 'live_start_order_sources') {
                $ids = array_map(
                    static fn($c) => (string)($c['instance_id'] ?? ''),
                    $state['pending_prompt']['candidates'] ?? []
                );
                $state = applyAction($state, 'p1', 'resolve_prompt', ['card_ids' => $ids]);
            }

            $this->assertSame('activate_members_pick', $state['pending_prompt']['type'] ?? null);

            // Legacy client payload (the bug): card_id only, no member_id.
            $state = applyAction($state, 'p1', 'resolve_prompt', ['card_id' => 'eli']);

            $this->assertNotSame(
                'activate_members_pick',
                $state['pending_prompt']['type'] ?? null,
                'Activate pick must resolve when client sends card_id'
            );
            $center = $state['players']['p1']['stage']['center'];
            $this->assertFalse(!empty($center['in_wait']) && empty($center['active']));
            $this->assertTrue(
                !memberIsInWait($center) && memberIsActiveForGame($center),
                'Eli must leave Wait after Honoka Live Start activate'
            );
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testSkipDeclineDoesNotError(): void
    {
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $eli = $this->waited($this->cardByNo('PL!-bp3-002-R', 'eli'));
        $umi = $this->waited($this->cardByNo('PL!-bp3-003-R', 'umi'));

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage'] = [
            'left' => $honoka,
            'center' => $eli,
            'right' => $umi,
        ];
        $p1['live_zone'] = [
            [
                'instance_id' => 'live1',
                'card_type' => 'ライブ',
                'card_type_en' => 'Live',
                'name_en' => 'Test Live',
                'group' => "μ's",
                'abilities' => [],
            ],
        ];

        $state = $this->baseState($p1);
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            if (($state['pending_prompt']['type'] ?? '') === 'live_start_order_sources') {
                $ids = array_map(
                    static fn($c) => (string)($c['instance_id'] ?? ''),
                    $state['pending_prompt']['candidates'] ?? []
                );
                $state = applyAction($state, 'p1', 'resolve_prompt', ['card_ids' => $ids]);
            }
            $this->assertSame('activate_members_pick', $state['pending_prompt']['type'] ?? null);

            $state = applyAction($state, 'p1', 'resolve_prompt', ['choice' => 'skip']);

            $this->assertNotSame('activate_members_pick', $state['pending_prompt']['type'] ?? null);
            $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['center']));
            $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['right']));
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    /**
     * GitHub #201: two swimsuit Honoka Live Starts must each activate their pick.
     * Reporter chose swimsuit + blazer across two prompts but only blazer stood.
     */
    public function testTwoHonokaLiveStartsBothActivationsApply(): void
    {
        $h1 = $this->waited($this->cardByNo('PL!-bp3-001-R', 'honoka_a'));
        $h2 = $this->waited($this->cardByNo('PL!-bp3-001-R', 'honoka_b'));
        $blazer = $this->waited($this->cardByNo('PL!-sd1-001-SD', 'blazer_honoka'));

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage'] = [
            'left' => $h1,
            'center' => $h2,
            'right' => $blazer,
        ];
        $p1['live_zone'] = [
            [
                'instance_id' => 'live1',
                'card_type' => 'ライブ',
                'card_type_en' => 'Live',
                'name_en' => 'Test Live',
                'group' => "μ's",
                'abilities' => [],
            ],
        ];

        $state = $this->baseState($p1);
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            if (($state['pending_prompt']['type'] ?? '') === 'live_start_order_sources') {
                $ids = array_map(
                    static fn($c) => (string)($c['instance_id'] ?? ''),
                    $state['pending_prompt']['candidates'] ?? []
                );
                $state = applyAction($state, 'p1', 'resolve_prompt', ['card_ids' => $ids]);
            }

            $this->assertSame('activate_members_pick', $state['pending_prompt']['type'] ?? null);
            $this->assertSame('honoka_a', $state['pending_prompt']['source_id'] ?? null);
            $this->assertArrayHasKey('ability_index', $state['pending_prompt']);

            // First Honoka activates the other swimsuit.
            $state = applyAction($state, 'p1', 'resolve_prompt', [
                'member_id' => 'honoka_b',
                'card_id' => 'honoka_b',
            ]);

            $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['center']));
            $this->assertSame('activate_members_pick', $state['pending_prompt']['type'] ?? null);
            $this->assertSame('honoka_b', $state['pending_prompt']['source_id'] ?? null);

            // Second Honoka activates blazer.
            $state = applyAction($state, 'p1', 'resolve_prompt', [
                'member_id' => 'blazer_honoka',
                'card_id' => 'blazer_honoka',
            ]);

            $this->assertNotSame('activate_members_pick', $state['pending_prompt']['type'] ?? null);
            $this->assertTrue(memberIsInWait($state['players']['p1']['stage']['left']), 'first swimsuit still Wait');
            $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['center']), 'second swimsuit activated');
            $this->assertFalse(memberIsInWait($state['players']['p1']['stage']['right']), 'blazer activated');
            $this->assertTrue(memberIsActiveForGame($state['players']['p1']['stage']['center']));
            $this->assertTrue(memberIsActiveForGame($state['players']['p1']['stage']['right']));
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }

    public function testActivateMembersPickRejectsAlreadyActiveTarget(): void
    {
        $honoka = $this->cardByNo('PL!-bp3-001-R', 'honoka');
        $eli = $this->waited($this->cardByNo('PL!-bp3-002-R', 'eli'));
        $umi = $this->waited($this->cardByNo('PL!-bp3-003-R', 'umi'));

        $p1 = $this->emptyPlayer('p1', 'P1');
        $p1['stage'] = [
            'left' => $honoka,
            'center' => $eli,
            'right' => $umi,
        ];
        $p1['live_zone'] = [
            [
                'instance_id' => 'live1',
                'card_type' => 'ライブ',
                'card_type_en' => 'Live',
                'name_en' => 'Test Live',
                'group' => "μ's",
                'abilities' => [],
            ],
        ];

        $state = $this->baseState($p1);
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
        try {
            $state = \resolveLiveStartAbilities($state, 'p1');
            if (($state['pending_prompt']['type'] ?? '') === 'live_start_order_sources') {
                $ids = array_map(
                    static fn($c) => (string)($c['instance_id'] ?? ''),
                    $state['pending_prompt']['candidates'] ?? []
                );
                $state = applyAction($state, 'p1', 'resolve_prompt', ['card_ids' => $ids]);
            }
            $this->assertSame('activate_members_pick', $state['pending_prompt']['type'] ?? null);

            // Corrupt Stage so the snapshotted candidate is already active.
            clearMemberWait($state['players']['p1']['stage']['center']);

            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('already active');
            applyAction($state, 'p1', 'resolve_prompt', ['member_id' => 'eli']);
        } finally {
            unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
        }
    }
}
