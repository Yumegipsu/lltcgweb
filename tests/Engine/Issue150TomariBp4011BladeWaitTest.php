<?php

declare(strict_types=1);

namespace LLTCG\Tests\Engine;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #150 — Tomari PL!SP-bp4-011 waits on printed Blade ≤3 (JP ブレード), not Hearts.
 * Official EN app text wrongly said Hearts; engine IR matched that error.
 */
final class Issue150TomariBp4011BladeWaitTest extends TestCase
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

    public function testCatalogUsesPrintedBladeNotHearts(): void
    {
        foreach (['PL!SP-bp4-011-P', 'PL!SP-bp4-011-P＋', 'PL!SP-bp4-011-R＋', 'PL!SP-bp4-011-SEC'] as $no) {
            $card = $this->cardByNo($no);
            $text = (string)($card['text'] ?? '');
            $this->assertStringContainsString(
                '3 or fewer printed <blade>',
                $text,
                $no . ' EN must match JP 元々持つブレードの数が3つ以下'
            );
            $this->assertStringNotContainsString('printed Hearts', $text, $no);
            $this->assertStringNotContainsString('printed hearts', $text, $no);
            $ab = ($card['abilities'] ?? [])[0] ?? [];
            $this->assertSame('wait_opp_max_original_blade', $ab['type'] ?? null, $no);
            $this->assertSame(3, intval($ab['max_original_blade'] ?? 0), $no);
            $jp = (string)($card['text_jp'] ?? '');
            $this->assertStringContainsString('ブレード', $jp, $no);
        }
    }
}
