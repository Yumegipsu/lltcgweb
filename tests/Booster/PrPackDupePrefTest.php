<?php

declare(strict_types=1);

namespace LLTCG\Tests\Booster;

use PHPUnit\Framework\TestCase;

/**
 * #277: PR packs (ranked wins, missions, login bonus, season, tournament) are granted by the server, which never
 * saw the Star Gems / seals toggle, so maxed PR duplicates always paid Star Gems. The preference is now saved on
 * the account and honoured by those grants: a PR duplicate gives 10 PR seals (half of the 20 needed to buy one).
 */
final class PrPackDupePrefTest extends TestCase
{
    private string $discordId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/booster.php';
        require_once dirname(__DIR__, 2) . '/seals.php';
        require_once dirname(__DIR__, 2) . '/ranked_pr_rewards.php';
        $this->discordId = 'test_pr_dupe_pref_' . bin2hex(random_bytes(4));
        tcgEnsureUser($this->discordId, ['username' => 'PR Dupe Pref']);
        $this->maxOutPrPool();
    }

    private function maxOutPrPool(): void
    {
        $data = json_decode((string) file_get_contents(CARDS_FILE), true) ?: [];
        $map = tcgBuildCardMap($data);
        $pools = tcgBuildBoxPools($data, tcgPrCardPoolBox());
        $counts = [];
        foreach ($pools as $pool) {
            foreach ($pool as $entry) {
                $no = is_array($entry) ? (string) ($entry['card_no'] ?? '') : (string) $entry;
                if ($no !== '') {
                    $counts[$no] = tcgGetDeckMaxCopies($map[$no] ?? null, $no);
                }
            }
        }
        $this->assertNotEmpty($counts, 'PR pool has cards');
        tcgUpsertCollectionCounts($this->discordId, $counts);
    }

    public function testPreferenceDefaultsToGemsAndRoundTrips(): void
    {
        $this->assertSame('gems', tcgGetDupeRewardPref($this->discordId));
        $this->assertSame('seals', tcgSetDupeRewardPref($this->discordId, 'seals'));
        $this->assertSame('seals', tcgGetDupeRewardPref($this->discordId));
        $this->assertSame('gems', tcgSetDupeRewardPref($this->discordId, 'nonsense'));
        $this->assertSame('gems', tcgGetDupeRewardPref($this->discordId));
    }

    public function testSealsPreferenceMakesPrDuplicatesPayPrSeals(): void
    {
        tcgSetDupeRewardPref($this->discordId, 'seals');
        $before = tcgSealBalances($this->discordId);
        $gemsBefore = tcgGetStarGems($this->discordId);

        $pack = tcgGrantPrPackCards($this->discordId, 3);

        $this->assertSame('seals', $pack['dupe_reward']);
        $this->assertSame(0, $pack['star_gems_earned']);
        $this->assertSame($gemsBefore, tcgGetStarGems($this->discordId));
        $this->assertCount(3, $pack['cards']);
        foreach ($pack['cards'] as $c) {
            $this->assertTrue($c['converted']);
            $this->assertSame('PR', $c['seal_tier']);
            $this->assertSame(10, $c['seals'], 'half of the 20 PR seals needed to buy a PR card');
            $this->assertSame(0, $c['star_gems']);
        }
        $this->assertSame($before['pr'] + 30, tcgSealBalances($this->discordId)['pr']);
        $this->assertSame(30, $pack['seals_earned']['PR'] ?? 0);
    }

    public function testGemsPreferenceStillPaysGems(): void
    {
        tcgSetDupeRewardPref($this->discordId, 'gems');
        $before = tcgSealBalances($this->discordId);
        $gemsBefore = tcgGetStarGems($this->discordId);

        $pack = tcgGrantPrPackCards($this->discordId, 3);

        $this->assertSame('gems', $pack['dupe_reward']);
        $this->assertGreaterThan(0, $pack['star_gems_earned']);
        $this->assertGreaterThan($gemsBefore, tcgGetStarGems($this->discordId));
        $this->assertSame($before['pr'], tcgSealBalances($this->discordId)['pr']);
        foreach ($pack['cards'] as $c) {
            $this->assertTrue($c['converted']);
            $this->assertSame('', $c['seal_tier']);
            $this->assertGreaterThan(0, $c['star_gems'], 'a maxed PR pays gems, never 0');
        }
    }

    public function testExistingDatabaseGainsThePreferenceColumn(): void
    {
        $db = tcgDb();
        $db->exec('ALTER TABLE tcg_users DROP COLUMN dupe_reward');
        $db->exec("DELETE FROM tcg_schema_meta WHERE key = 'dupe_reward_column_20261017'");

        tcgDbRunMigrationOnce($db, 'dupe_reward_column_20261017', static function ($pdo): void {
            tcgDbMigrateDupeRewardColumn($pdo);
        });

        $this->assertSame('gems', tcgGetDupeRewardPref($this->discordId));
        tcgSetDupeRewardPref($this->discordId, 'seals');
        $this->assertSame('seals', tcgGetDupeRewardPref($this->discordId));

        $pack = tcgGrantPrPackCards($this->discordId, 1);
        $this->assertSame('seals', $pack['dupe_reward']);
        $this->assertSame('PR', $pack['cards'][0]['seal_tier']);
        $this->assertSame(0, $pack['star_gems_earned']);
    }

    public function testOmittedToggleDoesNotResetASavedSealsPreference(): void
    {
        tcgSetDupeRewardPref($this->discordId, 'seals');
        $this->assertSame('seals', tcgResolveDupeRewardFromRequest($this->discordId, []));
        $this->assertSame('seals', tcgGetDupeRewardPref($this->discordId));
        $this->assertSame('gems', tcgResolveDupeRewardFromRequest($this->discordId, ['dupe_reward' => 'gems']));
    }

    public function testOpeningABoosterSavesThePreferenceForLaterServerGrants(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/account.php');
        $this->assertStringContainsString('tcgResolveDupeRewardFromRequest', $src);
        $this->assertStringContainsString("case 'set_dupe_reward'", $src);
        $this->assertStringContainsString("'dupe_reward' => tcgGetDupeRewardPref", $src);
        $gacha = (string) file_get_contents(dirname(__DIR__, 2) . '/gacha.php');
        $this->assertStringContainsString('tcgResolveDupeRewardFromRequest', $gacha);
    }

    /**
     * A PR reprint shares the 4-copy slot with its non-PR printing. Four of the
     * regular card already makes the PR a duplicate, and seals mode pays PR seals.
     */
    public function testPrReprintOfAMaxedNonPrCardPaysPrSeals(): void
    {
        tcgSetDupeRewardPref($this->discordId, 'seals');
        $pr = 'PL!N-sd1-013-PRproteinbar';
        $base = 'PL!N-sd1-013-SD';
        $db = tcgDb();
        $db->prepare('DELETE FROM tcg_collection WHERE discord_id = ? AND card_no = ?')
            ->execute([$this->discordId, $pr]);
        tcgUpsertCollectionCounts($this->discordId, [$base => 4]);
        $gemsBefore = tcgGetStarGems($this->discordId);
        $sealsBefore = tcgSealBalances($this->discordId);

        $data = json_decode((string) file_get_contents(CARDS_FILE), true) ?: [];
        $res = tcgApplyBoosterPullWithGems($this->discordId, [$pr], tcgBuildCardMap($data), 'seals');

        $pull = $res['pulls'][0];
        $this->assertTrue($pull['converted']);
        $this->assertSame('PR', $pull['seal_tier']);
        $this->assertSame(10, $pull['seals']);
        $this->assertSame(0, $pull['star_gems']);
        $this->assertSame(0, $res['star_gems_earned']);
        $this->assertSame($gemsBefore, tcgGetStarGems($this->discordId));
        $this->assertSame($sealsBefore['pr'] + 10, tcgSealBalances($this->discordId)['pr']);
        $owned = tcgGetCollectionMap($this->discordId);
        $this->assertSame(0, $owned[$pr] ?? 0);
        $this->assertSame(4, $owned[$base] ?? 0);
    }

    public function testPrReprintBelowTheSharedCapIsStillCollected(): void
    {
        $pr = 'PL!N-bp1-013-PR';
        $base = 'PL!N-bp1-013-N';
        $db = tcgDb();
        $db->prepare('DELETE FROM tcg_collection WHERE discord_id = ? AND card_no = ?')
            ->execute([$this->discordId, $pr]);
        tcgUpsertCollectionCounts($this->discordId, [$base => 3]);

        $data = json_decode((string) file_get_contents(CARDS_FILE), true) ?: [];
        $res = tcgApplyBoosterPullWithGems($this->discordId, [$pr], tcgBuildCardMap($data), 'seals');

        $this->assertFalse($res['pulls'][0]['converted']);
        $owned = tcgGetCollectionMap($this->discordId);
        $this->assertSame(1, $owned[$pr] ?? 0);
        $this->assertSame(3, $owned[$base] ?? 0);
    }

    public function testNormalPackParallelDoesNotShareTheCopyCap(): void
    {
        $rare = 'PL!N-bp1-001-R';
        $parallel = 'PL!N-bp1-001-P';
        tcgUpsertCollectionCounts($this->discordId, [$rare => 4]);

        $data = json_decode((string) file_get_contents(CARDS_FILE), true) ?: [];
        $res = tcgApplyBoosterPullWithGems($this->discordId, [$parallel], tcgBuildCardMap($data), 'seals');

        $this->assertFalse($res['pulls'][0]['converted'], 'a non-PR parallel is its own collection card');
        $this->assertSame(1, tcgGetCollectionMap($this->discordId)[$parallel] ?? 0);
    }
}
