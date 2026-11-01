<?php

declare(strict_types=1);

namespace LLTCG\Tests\Client;

use PHPUnit\Framework\TestCase;

/** Past-season leaderboard chrome must call TCGSeason.seasonLabel (not .label). */
final class PastSeasonLeaderboardLabelGuardTest extends TestCase
{
    public function testPastSeasonChromeUsesSeasonLabel(): void
    {
        $html = (string) file_get_contents(dirname(__DIR__, 2) . '/index.html');
        $this->assertStringNotContainsString('TCGSeason.label(', $html);
        $this->assertSame(3, substr_count($html, 'TCGSeason.seasonLabel('));
        $exported = (string) file_get_contents(dirname(__DIR__, 2) . '/client/js/season-rank.js');
        $this->assertMatchesRegularExpression('/seasonLabel:\s*seasonLabel/', $exported);
    }
}
