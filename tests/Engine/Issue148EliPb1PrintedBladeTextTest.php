<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #148 — Eli PB1 EN text must say "3 or fewer printed Blade" (JP 3つ以下).
 */
final class Issue148EliPb1PrintedBladeTextTest extends TestCase
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

    public function testEliPb1EnTextSaysThreeOrFewerPrintedBlade(): void
    {
        foreach (['PL!-pb1-002-R', 'PL!-pb1-002-P＋'] as $no) {
            $card = $this->cardByNo($no);
            $text = (string)($card['text'] ?? '');
            $this->assertStringContainsString(
                '3 or fewer printed <blade>',
                $text,
                $no . ' EN must match JP 元々持つブレードの数が3つ以下'
            );
            $this->assertStringNotContainsString('3 or printed <blade>', $text, $no);
            $ab = ($card['abilities'] ?? [])[0] ?? [];
            $this->assertSame(3, intval($ab['max_original_blades'] ?? 0), $no);
        }
    }
}
