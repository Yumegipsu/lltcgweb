<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-041 Shunjou Romantic — Success Live counts as 2 for lily white effects (#227).
 */
final class Issue227ShunjouCountAsTwoTest extends TestCase
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

    private function plainLilyLive(string $id): array
    {
        return [
            'instance_id' => $id,
            'card_no' => 'PL!-test-lily-live',
            'name_en' => 'Plain Lily Live',
            'card_type' => 'ライブ',
            'group' => "μ's",
            'subunit' => 'lily white',
            'score' => 1,
            'abilities' => [],
        ];
    }

    public function testCatalogHasCountAsTwoAlways(): void
    {
        $live = $this->cardByNo('PL!-pb2-041-L', 'shunjou');
        $found = false;
        foreach ($live['abilities'] ?? [] as $ab) {
            if (($ab['type'] ?? '') === 'success_count_as_two_for_subunit_effects') {
                $found = true;
                $this->assertSame('lily white', $ab['subunit'] ?? '');
            }
        }
        $this->assertTrue($found);
    }

    public function testNozomiPerSuccessChooseCountsShunjouAsTwo(): void
    {
        $nozomi = $this->cardByNo('PL!-pb2-016-R', 'nozomi');
        $shunjou = $this->cardByNo('PL!-pb2-041-L', 'shunjou');
        $state = [
            'status' => 'playing',
            'phase' => 'live_start_effects',
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'success_lives' => [$shunjou],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'stage' => ['left' => null, 'center' => $nozomi, 'right' => null],
                    'live_zone' => [$nozomi],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'main_deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                    'live_zone' => [],
                ],
            ],
        ];

        $ab = null;
        foreach ($nozomi['abilities'] ?? [] as $a) {
            if (($a['type'] ?? '') === 'per_success_subunit_choose') {
                $ab = $a;
                break;
            }
        }
        $this->assertNotNull($ab);

        $out = plMusePb2ResolveEffect($state, 'p1', $nozomi, $ab, []);
        $pr = $out['pending_prompt'] ?? null;
        $this->assertIsArray($pr);
        $this->assertSame('per_success_subunit_choose', $pr['type'] ?? null);
        $this->assertSame(2, intval($pr['remaining'] ?? 0), 'One Shunjou should grant 2 choices');
    }

    public function testUmiAlwaysBladeCountsShunjouScoreIconAsTwo(): void
    {
        $umi = $this->cardByNo('PL!-pb2-004-R', 'umi');
        $shunjou = $this->cardByNo('PL!-pb2-041-L', 'shunjou');
        $state = [
            'phase' => 'main',
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'success_lives' => [$shunjou],
                    'waiting_room' => [],
                    'deck' => [],
                    'stage' => ['left' => null, 'center' => $umi, 'right' => null],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];
        $printed = intval($umi['blade'] ?? 0);
        $this->assertSame(
            $printed + 2,
            getMemberBlade($umi, $state, 'p1', 'center'),
            'Shunjou Score icon should grant +2 Blade to lily white Umi'
        );
    }

    public function testNozomiBladePerLilyCountsShunjouAsTwo(): void
    {
        $nozomi = $this->cardByNo('PL!-pb2-025-N', 'nozomi25');
        $shunjou = $this->cardByNo('PL!-pb2-041-L', 'shunjou');
        $other = $this->plainLilyLive('other');
        $state = [
            'phase' => 'main',
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'success_lives' => [$shunjou, $other],
                    'waiting_room' => [],
                    'deck' => [],
                    'stage' => ['left' => null, 'center' => $nozomi, 'right' => null],
                ],
                'p2' => [
                    'id' => 'p2',
                    'name' => 'P2',
                    'hand' => [],
                    'success_lives' => [],
                    'waiting_room' => [],
                    'deck' => [],
                    'stage' => ['left' => null, 'center' => null, 'right' => null],
                ],
            ],
        ];
        $printed = intval($nozomi['blade'] ?? 0);
        // Shunjou=2 + plain lily=1 → +3 Blade
        $this->assertSame(
            $printed + 3,
            getMemberBlade($nozomi, $state, 'p1', 'center')
        );
    }

    public function testNonLilyWhiteEffectDoesNotDoubleCount(): void
    {
        $hikari = $this->cardByNo('PL!-pb2-039-L', 'hikari');
        $shunjou = $this->cardByNo('PL!-pb2-041-L', 'shunjou');
        $p = [
            'success_lives' => [$shunjou],
        ];
        $this->assertSame(
            1,
            plMusePb2CountSuccessGroup($p, "μ's", $hikari),
            'Non-lily white Live should not treat Shunjou as 2'
        );
    }
}
