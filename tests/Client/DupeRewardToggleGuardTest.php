<?php

declare(strict_types=1);

namespace LLTCG\Tests\Client;

use PHPUnit\Framework\TestCase;

/**
 * Booster + gacha menus have a pill toggle (Star Gem | N seal) choosing what maxed-out duplicates
 * convert into. Static guard for the wiring; behaviour was verified in the browser.
 */
final class DupeRewardToggleGuardTest extends TestCase
{
    private string $html;

    protected function setUp(): void
    {
        $this->html = (string) file_get_contents(dirname(__DIR__, 2) . '/index.html');
    }

    private function screenBlock(string $id, string $nextId): string
    {
        $a = strpos($this->html, 'id="' . $id . '"');
        $b = strpos($this->html, 'id="' . $nextId . '"', (int) $a);
        $this->assertNotFalse($a);
        $this->assertNotFalse($b);
        return substr($this->html, $a, $b - $a);
    }

    public function testBothMenusHaveTheGemAndSealToggle(): void
    {
        foreach ([['screen-booster', 'screen-sticker'], ['screen-gacha', 'gacha-spot']] as [$id, $next]) {
            $block = $this->screenBlock($id, $next);
            $this->assertStringContainsString('data-dupe-reward', $block, "$id is missing the toggle");
            $this->assertStringContainsString('assets/Star_Gem.png', $block);
            $this->assertStringContainsString('assets/seals/N.png', $block);
            $this->assertStringContainsString('data-dupe-reward-opt="gems"', $block);
            $this->assertStringContainsString('data-dupe-reward-opt="seals"', $block);
        }
    }

    public function testRequestsCarryThePreference(): void
    {
        $this->assertStringContainsString("dupe_reward: getDupeRewardPref()", $this->html);
        $gacha = (string) file_get_contents(dirname(__DIR__, 2) . '/client/js/gacha.js');
        $this->assertStringContainsString('dupe_reward', $gacha);
        $this->assertStringContainsString('seal_tier', $gacha);
    }

    public function testResultsShowSealsNotGemsForSealConversions(): void
    {
        $this->assertStringContainsString('function convertBadgeHtml', $this->html);
        $this->assertStringContainsString('booster.convertedToSeals', $this->html);
        $popup = (string) file_get_contents(dirname(__DIR__, 2) . '/client/js/ranked-pr-reward.js');
        $this->assertStringContainsString('converted: !!c.converted', $popup);
        $this->assertStringNotContainsString('c.converted || reward.converted', $popup);
    }

    public function testStringsExistInEveryLocale(): void
    {
        $i18n = (string) file_get_contents(dirname(__DIR__, 2) . '/i18n.js');
        foreach (['dupeRewardLabel', 'dupeRewardAria', 'dupeRewardGems', 'dupeRewardSeals', 'convertedToSeals', 'sealsBonus', 'duplicateSealsOne', 'duplicateSealsCount'] as $key) {
            $this->assertSame(8, substr_count($i18n, '"' . $key . '"'), "$key must exist in all 8 locales");
        }
    }
}
