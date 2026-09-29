<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * #210 Ren PL!SP-pb2-005 only inherits Activated abilities from stacked Liella Members,
 * not Live Start / Live Success / On Enter.
 */
final class Issue210RenInheritActivatedOnlyTest extends TestCase
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

    public function testRenDoesNotInheritLiveStartOrLiveSuccess(): void
    {
        $ren = $this->cardByNo('PL!SP-pb2-005-R', 'ren');
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'natsumi_under');
        $ren['stacked_members'] = [$natsumi];

        $this->assertSame([], \getAbilitiesByTrigger($ren, 'live_start'));
        $this->assertSame([], \getAbilitiesByTrigger($ren, 'live_success'));
        $this->assertSame([], \spBp2MemberLiveSuccessAbilities($ren));

        $onEnterTypes = array_map(
            static fn(array $ab): string => (string)($ab['type'] ?? ''),
            \getAbilitiesByTrigger($ren, 'on_enter')
        );
        $this->assertSame(['stack_baton_wr_member_under'], $onEnterTypes);
    }

    public function testRenStillInheritsActivatedFromStackedLiella(): void
    {
        $ren = $this->cardByNo('PL!SP-pb2-005-PP', 'ren');
        $keke = $this->cardByNo('PL!SP-pb2-002-PP', 'keke_under');
        $ren['stacked_members'] = [$keke];

        $inherited = \getAbilitiesByTrigger($ren, 'activated');
        $this->assertNotEmpty($inherited);
        $this->assertSame(
            'activated_discard_liella_choose_energy_or_hearts',
            $inherited[0]['type'] ?? null
        );
        $this->assertSame([], \getAbilitiesByTrigger($ren, 'live_start'));
        $this->assertSame([], \spBp2MemberLiveSuccessAbilities($ren));
    }

    public function testRenLiveSuccessResolutionDoesNotFireStackedLiveSuccess(): void
    {
        $ren = $this->cardByNo('PL!SP-pb2-005-R', 'ren');
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'natsumi_under');
        $ren['stacked_members'] = [$natsumi];
        $live = $this->cardByNo('PL!SP-bp2-024-L', 'live') ;
        // Prefer a known Live if SP-bp2-024 missing — fall back to any Live.
        if (($live['card_type_en'] ?? '') !== 'Live') {
            $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
            foreach ($data['cards'] ?? [] as $card) {
                if (($card['card_type_en'] ?? '') === 'Live') {
                    $live = $card;
                    $live['instance_id'] = 'live';
                    break;
                }
            }
        }

        $state = [
            'status' => 'playing',
            'phase' => 'live_judge',
            'seq' => 1,
            'turn' => 4,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [
                        ['instance_id' => 'h1', 'card_type' => 'メンバー', 'group' => 'Superstar', 'cost' => 1],
                        ['instance_id' => 'h2', 'card_type' => 'メンバー', 'group' => 'Superstar', 'cost' => 1],
                    ],
                    'waiting_room' => [],
                    'stage' => ['left' => null, 'center' => $ren, 'right' => null],
                    'energy_zone' => [],
                    'main_deck' => array_map(
                        static fn(int $i) => [
                            'instance_id' => 'd' . $i,
                            'card_type' => 'メンバー',
                            'group' => 'Superstar',
                            'cost' => 1,
                        ],
                        range(0, 8)
                    ),
                    'success_lives' => [],
                    'live_zone' => [$live],
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

        $state = \resolveLiveSuccessAbilities($state, 'p1', [$live], 0, [], []);
        // Natsumi under Ren has draw_and_discard Live Success — must not open a discard prompt.
        $this->assertNull($state['pending_prompt'] ?? null);
        $this->assertSame(2, count($state['players']['p1']['hand']));
    }

    public function testNijigasakiPrStillInheritsStackedLiveSuccess(): void
    {
        $host = $this->cardByNo('PL!N-PR-026-PR', 'host');
        // Find a Nijigasaki member with live_success cost <= 9
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $under = null;
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['group'] ?? '') !== 'Nijigasaki' || ($card['card_type_en'] ?? '') !== 'Member') {
                continue;
            }
            if (intval($card['cost'] ?? 99) > 9) {
                continue;
            }
            foreach ($card['abilities'] ?? [] as $ab) {
                if (($ab['trigger'] ?? '') === 'live_success') {
                    $under = $card;
                    $under['instance_id'] = 'under';
                    $under['active'] = true;
                    break 2;
                }
            }
        }
        $this->assertNotNull($under);
        $host['stacked_members'] = [$under];
        $abs = \spBp2MemberLiveSuccessAbilities($host);
        $this->assertNotEmpty($abs);
        $types = array_map(static fn(array $ab): string => (string)($ab['type'] ?? ''), $abs);
        $this->assertContains((string)(($under['abilities'][0]['type'] ?? '') ?: 'x'), $types + ['x']);
        // At least one inherited live_success type from under
        $underTypes = [];
        foreach ($under['abilities'] as $ab) {
            if (($ab['trigger'] ?? '') === 'live_success') {
                $underTypes[] = $ab['type'] ?? '';
            }
        }
        $this->assertNotEmpty(array_intersect($underTypes, $types));
    }
}
