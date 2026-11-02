<?php

declare(strict_types=1);

namespace LLTCG\Tests\Client;

use PHPUnit\Framework\TestCase;

/** Guard: Cost / Score pool sort keeps Members+Lives grouped ahead of Energy. */
final class DeckPoolCostSortGuardTest extends TestCase
{
    private function html(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/index.html');
    }

    public function testCostSortOptionIsRegistered(): void
    {
        $h = $this->html();
        $this->assertStringContainsString("{ id: 'cost', label: 'Cost / Score' }", $h);
        $this->assertStringContainsString("case 'cost':", $h);
        $this->assertStringContainsString('deckPoolCostFamilyRank', $h);
        $this->assertStringContainsString('deckPoolCostOrScoreValue', $h);
    }

    public function testCostCompareKeepsMemberLiveFamilyStableOnDesc(): void
    {
        $h = $this->html();
        $this->assertStringContainsString(
            '// Keep Members+Lives ahead of Energy regardless of asc/desc.',
            $h
        );
        $this->assertStringContainsString("if (mode === 'cost')", $h);
    }

    public function testBuiltDeckFollowsCostSortWhenSelected(): void
    {
        $h = $this->html();
        $this->assertStringContainsString("effectiveDeckPoolSort() === 'cost'", $h);
        $this->assertStringContainsString('sortSavedDeckCardNos', $h);
    }

    public function testCostSortLabelExistsInAllEightLocales(): void
    {
        $i18n = (string) file_get_contents(dirname(__DIR__, 2) . '/i18n.js');
        // One "cost" label under deck.filters.sort per locale pack (en/ja/es/ko/zh/th/pt/fr).
        $this->assertSame(
            8,
            preg_match_all('/"type": "[^"]+",\s*"cost": "[^"]+",\s*"group":/', $i18n),
            'deck.filters.sort.cost in each locale'
        );
    }
}
