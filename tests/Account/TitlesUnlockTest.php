<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

final class TitlesUnlockTest extends TestCase
{
    private string $discordId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/titles.php';
        require_once dirname(__DIR__, 2) . '/play_stats.php';
        $this->discordId = 'test_title_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->discordId, [
            'username' => 'TitleTester',
            'avatar_url' => null,
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->discordId)) {
            return;
        }
        $db = tcgDb();
        $db->prepare('DELETE FROM tcg_play_stats WHERE discord_id = ?')->execute([$this->discordId]);
        $db->prepare('DELETE FROM tcg_users WHERE discord_id = ?')->execute([$this->discordId]);
    }

    public function testCatalogHasFanTitlesOnly(): void
    {
        $titles = tcgTitlesCatalog();
        $this->assertGreaterThanOrEqual(52, count($titles));
        foreach ($titles as $t) {
            $this->assertNotSame('friend', strtolower((string)($t['tier'] ?? '')));
            $this->assertNotEmpty($t['id'] ?? null);
            $this->assertNotEmpty($t['idol'] ?? null);
        }
        $this->assertNotNull(tcgTitleDefById('title_m_0001_01001_0001'));
        $this->assertNotNull(tcgTitleDefById('title_kaho1'));
        $honokaFriend = tcgTitleDefById('title_m_0001_01001_0002');
        $this->assertTrue($honokaFriend === null || strtolower((string)($honokaFriend['tier'] ?? '')) !== 'fan');
    }

    public function testUnlockAtFiveHundredStagePlays(): void
    {
        $def = tcgTitleDefById('title_m_0001_01001_0001');
        $this->assertNotNull($def);
        $this->assertFalse(tcgTitleIsUnlocked($this->discordId, $def));
        tcgBumpPlayStat(
            $this->discordId,
            TCG_PLAY_TRACKER_STAGE,
            TCG_PLAY_DIM_IDOL,
            'Honoka Kosaka',
            499
        );
        $this->assertFalse(tcgTitleIsUnlocked($this->discordId, $def));
        tcgBumpPlayStat(
            $this->discordId,
            TCG_PLAY_TRACKER_STAGE,
            TCG_PLAY_DIM_IDOL,
            'Honoka Kosaka',
            1
        );
        $this->assertTrue(tcgTitleIsUnlocked($this->discordId, $def));
        $row = tcgFormatTitle($def, $this->discordId, ['include_progress' => true]);
        $this->assertTrue($row['unlocked']);
        $this->assertSame(500, $row['progress']);
        $this->assertSame('wide', $row['style']);
    }

    public function testHasunosoraPortraitStyle(): void
    {
        $def = tcgTitleDefById('title_kaho1');
        $this->assertNotNull($def);
        $row = tcgFormatTitle($def);
        $this->assertSame('portrait', $row['style']);
        $this->assertSame('Kaho Fan', $row['name']);
    }
}
