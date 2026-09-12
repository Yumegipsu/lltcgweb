<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Issue #180 — PL!-bp4-018 Nico [Always] +2 Blade while Success Live score is higher.
 */
final class Issue180NicoSuccessScoreBladeTest extends TestCase
{
    private function cardByNo(string $cardNo): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                $card['instance_id'] = 'src_' . preg_replace('/[^A-Za-z0-9]+/', '_', $cardNo);
                return $card;
            }
        }
        $this->fail("Missing card $cardNo");
    }

    private function emptyPlayer(string $id): array
    {
        return [
            'id' => $id,
            'name' => strtoupper($id),
            'hand' => [],
            'waiting_room' => [],
            'stage' => ['left' => null, 'center' => null, 'right' => null],
            'energy_zone' => [],
            'main_deck' => [],
            'energy_deck' => [],
            'live_zone' => [],
            'success_lives' => [],
        ];
    }

    public function testBladeBonusWhenSuccessScoreHigher(): void
    {
        $nico = $this->cardByNo('PL!-bp4-018-N');
        $printed = intval($nico['blade'] ?? 0);
        $this->assertSame(2, $printed);

        $state = [
            'phase' => 'main_first',
            'players' => [
                'p1' => $this->emptyPlayer('p1'),
                'p2' => $this->emptyPlayer('p2'),
            ],
            'live_modifiers' => [
                'p1' => [],
                'p2' => [],
            ],
        ];
        $state['players']['p1']['stage']['left'] = $nico;
        $state['players']['p1']['success_lives'] = [
            ['card_no' => 'PL!-bp3-025-L', 'score' => 4, 'abilities' => []],
        ];

        $this->assertSame(
            $printed + 2,
            getMemberBlade($nico, $state, 'p1', 'left'),
            'Higher Success Live score should grant +2 Blade'
        );

        $state['players']['p2']['success_lives'] = [
            ['card_no' => 'PL!-bp3-024-L', 'score' => 4, 'abilities' => []],
        ];
        $this->assertSame(
            $printed,
            getMemberBlade($nico, $state, 'p1', 'left'),
            'Tied Success Live scores should not grant the Always Blade'
        );

        $state['players']['p2']['success_lives'] = [
            ['card_no' => 'PL!-bp3-024-L', 'score' => 5, 'abilities' => []],
        ];
        $this->assertSame(
            $printed,
            getMemberBlade($nico, $state, 'p1', 'left'),
            'Lower Success Live score should not grant the Always Blade'
        );
    }
}
