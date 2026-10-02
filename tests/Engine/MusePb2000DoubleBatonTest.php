<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * PL!-pb2-000 / μ's DUO registry smoke.
 */
final class MusePb2000DoubleBatonTest extends TestCase
{
    private function cardByNo(string $cardNo): array
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $this->assertIsArray($data);
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['card_no'] ?? '') === $cardNo) {
                return $card;
            }
        }
        $this->fail('Missing test card ' . $cardNo);
    }

    public function testCardHasDoubleBatonAbility(): void
    {
        $card = $this->cardByNo('PL!-pb2-000-R');
        $this->assertNotEmpty($card['abilities'] ?? []);
        $types = array_map(static fn($a) => $a['type'] ?? '', $card['abilities']);
        $this->assertContains('allows_double_baton', $types);
        $this->assertContains('if_double_baton_add_wr_live_score_if_cost_sum', $types);
        $this->assertStringContainsString('[Always]', (string)($card['text'] ?? ''));
        $this->assertStringContainsString('[On Enter]', (string)($card['text'] ?? ''));
    }

    public function testEffectTypeRegistered(): void
    {
        require_once dirname(__DIR__, 2) . '/effects.php';
        $this->assertTrue(function_exists('plMusePb2IsEffectType'));
        $this->assertTrue(plMusePb2IsEffectType('if_double_baton_add_wr_live_score_if_cost_sum'));
        $this->assertTrue(plMusePb2IsEffectType('success_pile_icon_bonuses'));
        $this->assertTrue(plMusePb2IsEffectType('blade_per_success_score_icon_group'));
    }

    public function testDuoPackCountInCardsJson(): void
    {
        $data = json_decode((string) file_get_contents((string) constant('CARDS_FILE')), true);
        $n = 0;
        foreach ($data['cards'] ?? [] as $card) {
            if (($card['booster_pack'] ?? '') === 'プレミアムブースター ラブライブ！DUO') {
                $n++;
            }
        }
        $this->assertSame(113, $n);
    }
}
