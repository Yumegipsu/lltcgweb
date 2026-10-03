<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-017 Hanayo Live Start — unstack under → toggle Printemps Active/Wait (#231).
 */
final class Issue231HanayoUnstackToggleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TUT_PERF_MANUAL_PHASES'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TUT_PERF_MANUAL_PHASES']);
    }

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

    private function stackedMember(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Stacked ' . $id,
            'group' => "μ's",
            'subunit' => 'Printemps',
        ];
    }

    private function fillerLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_type' => 'ライブ',
            'card_type_en' => 'Live',
            'name_en' => $id,
            'score' => 1,
            'revealed' => true,
            'abilities' => [],
        ];
    }

    private function baseState(array $hanayo, ?array $left = null): array
    {
        return [
            'room_id' => 'ISSUE231',
            'status' => 'playing',
            'seq' => 10,
            'turn' => 2,
            'phase' => 'live_start_effects',
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1', 'p2'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => [
                        'left' => $left,
                        'center' => $hanayo,
                        'right' => null,
                    ],
                    'waiting_room' => [],
                    'energy_zone' => array_fill(0, 12, ['card_type' => 'エネルギー', 'active' => true]),
                    'main_deck' => [],
                    'energy_deck' => [],
                    'live_zone' => [$this->fillerLive('live_p1')],
                    'success_lives' => [],
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
                    'live_zone' => [$this->fillerLive('live_p2')],
                    'success_lives' => [],
                ],
            ],
        ];
    }

    public function testLiveStartOffersOptionalThenUnstackThenToggle(): void
    {
        $hanayo = $this->cardByNo('PL!-pb2-017-R', 'hanayo017');
        $honoka = $this->cardByNo('PL!-bp3-008-P', 'honoka_stage');
        $hanayo['stacked_members'] = [
            $this->stackedMember('under1'),
            $this->stackedMember('under2'),
            $this->stackedMember('under3'),
            $this->stackedMember('under4'),
        ];

        $state = $this->baseState($hanayo, $honoka);

        $ab = null;
        foreach ($hanayo['abilities'] ?? [] as $a) {
            if (($a['type'] ?? '') === 'optional_unstack_toggle_subunit_members') {
                $ab = $a;
                break;
            }
        }
        $this->assertNotNull($ab);

        $out = plMusePb2ResolveEffect($state, 'p1', $hanayo, $ab, []);
        $pr = $out['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('optional_unstack_toggle_subunit', $pr['type'] ?? null);
        $this->assertTrue(!empty($pr['live_start']));
        $this->assertCount(4, $pr['stacked'] ?? []);
        $this->assertSame(['yes', 'no'], $pr['choices'] ?? null);

        $out = plMusePb2ResolvePrompt($out, 'p1', $pr, 'yes', []);
        $pr = $out['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pb2_pick_unstack_toggle', $pr['type'] ?? null);
        $this->assertSame('unstack', $pr['step'] ?? null);
        $this->assertGreaterThanOrEqual(1, count($pr['candidates'] ?? []));
        $this->assertNotEmpty($pr['candidates'][0]['instance_id'] ?? null);
        $this->assertArrayNotHasKey('card', $pr['candidates'][0]);

        $pickIds = array_column(array_slice($pr['candidates'], 0, 2), 'instance_id');
        $out = plMusePb2ResolvePrompt($out, 'p1', $pr, '', ['instance_ids' => $pickIds]);
        $pr = $out['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pb2_pick_toggle_printemps', $pr['type'] ?? null);
        $this->assertSame(2, $pr['remaining'] ?? null);
        $this->assertNotEmpty($pr['candidates'] ?? []);
        foreach ($pr['candidates'] as $c) {
            $this->assertArrayHasKey('slot', $c);
            $this->assertArrayHasKey('instance_id', $c);
            $this->assertArrayNotHasKey('card', $c);
        }

        $wrIds = array_column($out['players']['p1']['waiting_room'] ?? [], 'instance_id');
        $this->assertContains('under1', $wrIds);
        $this->assertContains('under2', $wrIds);
        $this->assertCount(2, $out['players']['p1']['stage']['center']['stacked_members'] ?? []);

        $wasWait = memberIsInWait($out['players']['p1']['stage']['left']);
        $out = plMusePb2ResolvePrompt($out, 'p1', $pr, '', ['slot' => 'left']);
        $this->assertNotSame($wasWait, memberIsInWait($out['players']['p1']['stage']['left']));

        $pr = $out['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('pb2_pick_toggle_printemps', $pr['type'] ?? null);
        $this->assertSame(1, $pr['remaining'] ?? null);

        $out = plMusePb2ResolvePrompt($out, 'p1', $pr, '', ['slot' => 'center']);
        $this->assertNull($out['pending_prompt'] ?? null);
        $this->assertTrue(memberIsInWait($out['players']['p1']['stage']['center']));
    }

    public function testSkipOptionalFinishesCleanly(): void
    {
        $hanayo = $this->cardByNo('PL!-pb2-017-R', 'hanayo017');
        $hanayo['stacked_members'] = [$this->stackedMember('under1')];
        $state = $this->baseState($hanayo);

        $ab = null;
        foreach ($hanayo['abilities'] ?? [] as $a) {
            if (($a['type'] ?? '') === 'optional_unstack_toggle_subunit_members') {
                $ab = $a;
                break;
            }
        }
        $out = plMusePb2ResolveEffect($state, 'p1', $hanayo, $ab, []);
        $pr = $out['pending_prompt'];
        $out = plMusePb2ResolvePrompt($out, 'p1', $pr, 'no', []);
        $this->assertNull($out['pending_prompt'] ?? null);
        $this->assertCount(1, $out['players']['p1']['stage']['center']['stacked_members'] ?? []);
    }
}
