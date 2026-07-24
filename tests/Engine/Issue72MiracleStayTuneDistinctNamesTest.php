<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #72: PL!N-bp5-027-L Miracle STAY TUNE! — multi-name Members (LL-bp2-001)
 * may be treated as any one component name for "3+ differently named" Live Start.
 */
final class Issue72MiracleStayTuneDistinctNamesTest extends TestCase
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

    private function successFill(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'instance_id' => 'succ_' . $i,
                'card_type' => 'ライブ',
                'card_type_en' => 'Live',
                'name_en' => 'Success ' . $i,
                'score' => 1,
            ];
        }
        return $out;
    }

    private function baseState(array $stage, array $live): array
    {
        return [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'turn' => 4,
            'first_player' => 'p1',
            'active_player' => 'p1',
            'live_attempt' => ['p1'],
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'waiting_room' => [],
                    'stage' => $stage,
                    'energy_zone' => [],
                    'main_deck' => [],
                    'success_lives' => $this->successFill(2),
                    'live_zone' => $live,
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

    private function liveScore(array $state, string $iid): int
    {
        foreach ($state['players']['p1']['live_zone'] as $lc) {
            if (($lc['instance_id'] ?? '') === $iid) {
                return intval($lc['score'] ?? 0);
            }
        }
        return -1;
    }

    public function testTwoMultiNamePlusNatsumiCountsAsThreeDistinct(): void
    {
        $ll1 = $this->cardByNo('LL-bp2-001-R＋', 'll1');
        $ll2 = $this->cardByNo('LL-bp2-001-R＋', 'll2');
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'natsumi');
        $p = [
            'stage' => ['left' => $ll1, 'center' => $ll2, 'right' => $natsumi],
        ];
        // Assign You + Rurino + Natsumi.
        $this->assertSame(3, \countDistinctNamedGroupOnStage($p, '', 'member'));
    }

    public function testMultiNamePlusNatsumiPlusOtherCountsAsThree(): void
    {
        $ll = $this->cardByNo('LL-bp2-001-R＋', 'll_solo');
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'natsumi2');
        $other = [
            'instance_id' => 'other',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Shiki Wakana',
            'group' => 'Superstar',
            'cost' => 3,
            'active' => true,
        ];
        $p = ['stage' => ['left' => $ll, 'center' => $natsumi, 'right' => $other]];
        $this->assertSame(3, \countDistinctNamedGroupOnStage($p, '', 'member'));
    }

    public function testTwoIdenticalSingleNamesStillCountAsOne(): void
    {
        $n1 = $this->cardByNo('PL!SP-bp2-009-P', 'n1');
        $n2 = $this->cardByNo('PL!SP-bp2-009-P', 'n2');
        $n3 = $this->cardByNo('PL!SP-bp2-009-P', 'n3');
        $p = ['stage' => ['left' => $n1, 'center' => $n2, 'right' => $n3]];
        $this->assertSame(1, \countDistinctNamedGroupOnStage($p, '', 'member'));
    }

    public function testMiracleStayTuneLiveStartScoresWithTwoLlAndNatsumi(): void
    {
        $miracle = $this->cardByNo('PL!N-bp5-027-L', 'miracle');
        $printed = intval($miracle['score'] ?? 5);
        $ll1 = $this->cardByNo('LL-bp2-001-R＋', 'll_a');
        $ll2 = $this->cardByNo('LL-bp2-001-R＋', 'll_b');
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'natsumi_ls');

        $state = $this->baseState(
            ['left' => $ll1, 'center' => $ll2, 'right' => $natsumi],
            [$miracle]
        );
        $state = \resolveLiveStartAbilities($state, 'p1');

        $this->assertSame($printed + 1, $this->liveScore($state, 'miracle'));
        $log = implode("\n", array_map(
            static fn($e) => is_array($e) ? (string)($e['msg'] ?? '') : (string)$e,
            $state['log'] ?? []
        ));
        $this->assertStringContainsString('Miracle STAY TUNE', $log);
        $this->assertStringContainsString('score +1', $log);
    }

    public function testMiracleStayTuneLiveStartScoresWithLlNatsumiOther(): void
    {
        $miracle = $this->cardByNo('PL!N-bp5-027-L', 'miracle2');
        $printed = intval($miracle['score'] ?? 5);
        $ll = $this->cardByNo('LL-bp2-001-R＋', 'll_c');
        $natsumi = $this->cardByNo('PL!SP-bp2-009-P', 'natsumi_ls2');
        $other = [
            'instance_id' => 'chisato',
            'card_type' => 'メンバー',
            'card_type_en' => 'Member',
            'name_en' => 'Chisato Arashi',
            'group' => 'Superstar',
            'cost' => 4,
            'active' => true,
        ];

        $state = $this->baseState(
            ['left' => $ll, 'center' => $natsumi, 'right' => $other],
            [$miracle]
        );
        $state = \resolveLiveStartAbilities($state, 'p1');
        $this->assertSame($printed + 1, $this->liveScore($state, 'miracle2'));
    }
}
