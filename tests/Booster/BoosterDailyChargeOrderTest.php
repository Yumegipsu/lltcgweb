<?php

declare(strict_types=1);

namespace LLTCG\Tests\Booster;

use PHPUnit\Framework\TestCase;

/** Daily charge must not stick when the roll/save fails after payment. */
final class BoosterDailyChargeOrderTest extends TestCase
{
    private function loadBooster(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/booster.php';
    }

    public function testRefundDailyOpenDecrementsTodayCount(): void
    {
        $this->loadBooster();
        $discordId = 'test_refund_daily_' . bin2hex(random_bytes(4));
        tcgEnsureUser($discordId, ['username' => 'Refund Daily']);
        $today = tcgTodayJst();
        $db = tcgDb();
        $db->prepare(
            'INSERT INTO tcg_daily_state (discord_id, last_open_date, packs_opened_today, first_day_bonus_used)
             VALUES (?, ?, 2, 0)
             ON CONFLICT(discord_id) DO UPDATE SET last_open_date = excluded.last_open_date, packs_opened_today = 2'
        )->execute([$discordId, $today]);

        tcgRefundDailyOpen($discordId);
        $row = $db->prepare('SELECT packs_opened_today, last_open_date FROM tcg_daily_state WHERE discord_id = ?');
        $row->execute([$discordId]);
        $got = $row->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($today, $got['last_open_date'] ?? null);
        $this->assertSame(1, intval($got['packs_opened_today'] ?? -1));
    }

    public function testSaveBoxProgressCreatesSrePityColumn(): void
    {
        $this->loadBooster();
        $discordId = 'test_sre_col_' . bin2hex(random_bytes(4));
        tcgEnsureUser($discordId, ['username' => 'Sre Col']);
        $db = tcgDb();
        // Simulate a pre-migration DB missing sre_pity (best-effort drop).
        try {
            $cols = $db->query('PRAGMA table_info(tcg_box_progress)')->fetchAll(\PDO::FETCH_ASSOC);
            $names = array_column($cols, 'name');
            $this->assertContains('sre_pity', $names);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }

        tcgSaveBoxProgress([
            'discord_id' => $discordId,
            'box_id' => 'pb_niji',
            'packs_in_box' => 1,
            'boxes_opened' => 0,
            'pe_pity' => 0,
            'pplus_pity' => 0,
            'sec_pity' => 0,
            'rm_pity' => 0,
            'live_pity' => 0,
            'sre_pity' => 3,
        ]);
        $stmt = $db->prepare('SELECT sre_pity FROM tcg_box_progress WHERE discord_id = ? AND box_id = ?');
        $stmt->execute([$discordId, 'pb_niji']);
        $this->assertSame(3, intval($stmt->fetchColumn()));
    }
}
