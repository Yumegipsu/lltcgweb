<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

final class PublicProfileUsernameFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/db.php';
    }

    public function testFindsByCaseInsensitiveUsernameWhenDiscordIdMissing(): void
    {
        $uid = '900000000000000001';
        tcgEnsureUser($uid, ['username' => 'noodlegirl']);

        $missingId = '900000000000000099';
        $row = tcgFindUserForPublicProfile($missingId, ['NoodleGirl']);
        $this->assertNotNull($row);
        $this->assertSame($uid, $row['discord_id']);
        $this->assertSame('noodlegirl', $row['username']);
    }

    public function testPrefersDiscordIdMatchOverUsername(): void
    {
        $uidA = '900000000000000011';
        $uidB = '900000000000000012';
        tcgEnsureUser($uidA, ['username' => 'SameNameA']);
        tcgEnsureUser($uidB, ['username' => 'SameNameB']);

        $row = tcgFindUserForPublicProfile($uidB, ['SameNameA']);
        $this->assertNotNull($row);
        $this->assertSame($uidB, $row['discord_id']);
        $this->assertSame('SameNameB', $row['username']);
    }
}
