<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * #244 — Umi Always blade HUD / Yell estimate must count Shunjou as 2.
 * Server already did (#227); this locks getMemberBlade for the PP printing
 * shown in the bug screenshots and documents the client mirror requirement.
 */
final class Issue244ShunjouClientBladeMirrorTest extends TestCase
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

    private function stateWithSuccess(array $umi, array $success): array
    {
        return [
            'phase' => 'main',
            'seq' => 1,
            'log' => [],
            'players' => [
                'p1' => [
                    'id' => 'p1',
                    'name' => 'P1',
                    'hand' => [],
                    'success_lives' => $success,
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
    }

    public function testUmiPpPrintingCountsShunjouScoreIconAsTwo(): void
    {
        $umi = $this->cardByNo('PL!-pb2-004-PP', 'umi');
        $shunjou = $this->cardByNo('PL!-pb2-041-L', 'shunjou');
        $printed = intval($umi['blade'] ?? 0);
        $blade = getMemberBlade($umi, $this->stateWithSuccess($umi, [$shunjou]), 'p1', 'center');
        $this->assertSame($printed + 2, $blade);
    }

    public function testUmiPpWithShunjouPlusOtherScoreIconIsThree(): void
    {
        $umi = $this->cardByNo('PL!-pb2-004-PP', 'umi');
        $shunjou = $this->cardByNo('PL!-pb2-041-L', 'shunjou');
        $other = $this->cardByNo('PL!-bp3-019-L', 'bokura');
        $this->assertTrue(plMusePb2CardHasScoreIcon($other));
        $printed = intval($umi['blade'] ?? 0);
        $blade = getMemberBlade(
            $umi,
            $this->stateWithSuccess($umi, [$shunjou, $other]),
            'p1',
            'center'
        );
        // Screenshot case: 2 Success Lives both with Score icons → badge must be +3, not +2.
        $this->assertSame($printed + 3, $blade);
    }

    public function testWeightHelperMatchesAlwaysAbility(): void
    {
        $umi = $this->cardByNo('PL!-pb2-004-PP', 'umi');
        $shunjou = $this->cardByNo('PL!-pb2-041-L', 'shunjou');
        $this->assertSame(2, plMusePb2SuccessCardCountWeight($shunjou, $umi));
        $hikari = $this->cardByNo('PL!-pb2-039-L', 'hikari');
        $this->assertSame(1, plMusePb2SuccessCardCountWeight($shunjou, $hikari));
    }
}
