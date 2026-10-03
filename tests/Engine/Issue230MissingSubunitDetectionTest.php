<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Missing catalog subunit on μ's Members must still match Printemps etc. (#230).
 */
final class Issue230MissingSubunitDetectionTest extends TestCase
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

    public function testReportedCardsMatchPrintemps(): void
    {
        foreach (['PL!-bp3-008-P', 'PL!-bp4-017-N', 'PL!-bp4-003-P'] as $no) {
            $card = $this->cardByNo($no, 'x');
            // Simulate older in-match copies / incomplete catalog rows.
            unset($card['subunit'], $card['card_no']);
            $this->assertTrue(
                cardMatchesSubunit($card, 'Printemps'),
                "$no character should match Printemps via name inference"
            );
        }
    }

    public function testHanayoPb2StacksMissingSubunitWrMembers(): void
    {
        $hanayo = $this->cardByNo('PL!-pb2-017-R', 'hanayo017');
        $sourceNos = ['PL!-bp3-008-P', 'PL!-bp4-017-N', 'PL!-bp4-003-P', 'PL!-bp3-008-SEC'];
        $wr = [];
        foreach ($sourceNos as $i => $no) {
            $c = $this->cardByNo($no, 'wr' . $i);
            // Strip subunit and card_no so WR matching cannot rely on catalog merge alone.
            unset($c['subunit'], $c['card_no']);
            $wr[] = $c;
        }

        $state = [
            'status' => 'playing',
            'phase' => 'main',
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => $hanayo, 'right' => null],
                    'waiting_room' => $wr,
                    'main_deck' => [],
                    'energy_zone' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'energy_zone' => [],
                    'live_zone' => [],
                    'success_lives' => [],
                ],
            ],
        ];

        $ab = null;
        foreach ($hanayo['abilities'] ?? [] as $a) {
            if (($a['type'] ?? '') === 'stack_wr_subunit_members_under') {
                $ab = $a;
                break;
            }
        }
        $this->assertNotNull($ab);

        $out = plMusePb2ResolveEffect($state, 'p1', $hanayo, $ab, []);
        $pr = $out['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('stack_wr_under', $pr['type'] ?? null);
        $this->assertGreaterThanOrEqual(4, count($pr['candidates'] ?? []));
    }

    public function testInferDoesNotGuessMultiNameCards(): void
    {
        $card = [
            'card_type_en' => 'Member',
            'name_en' => 'Umi Sonoda & Yoshiko Tsushima & Rina Tennoji',
            'group' => "μ's",
        ];
        $this->assertSame('', inferMemberSubunitFromName($card));
        $this->assertFalse(cardMatchesSubunit($card, 'lily white'));
    }
}
