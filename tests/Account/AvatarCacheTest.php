<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

final class AvatarCacheTest extends TestCase
{
    private string $uid;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/db.php';
        require_once dirname(__DIR__, 2) . '/avatar_cache.php';
        $this->uid = '8' . str_pad((string) random_int(1, 99999999999), 17, '0', STR_PAD_LEFT);
    }

    protected function tearDown(): void
    {
        foreach (glob(tcgAvatarDir() . $this->uid . '_*') ?: [] as $f) {
            @unlink($f);
        }
    }

    public function testUrlsRoundTripAndDefaultsAreNeverProxied(): void
    {
        $proxy = tcgAvatarProxyUrl($this->uid, 'a_hash123');
        $this->assertSame([$this->uid, 'a_hash123'], tcgAvatarParseUrl($proxy));
        $cdn = 'https://cdn.discordapp.com/avatars/' . $this->uid . '/hash123.png?size=128';
        $this->assertSame([$this->uid, 'hash123'], tcgAvatarParseUrl($cdn));
        $this->assertSame(tcgAvatarProxyUrl($this->uid, 'hash123'), tcgAvatarNormalizeForStorage($this->uid, $cdn));
        $def = 'https://cdn.discordapp.com/embed/avatars/2.png';
        $this->assertNull(tcgAvatarParseUrl($def));
        $this->assertSame($def, tcgAvatarNormalizeForStorage($this->uid, $def));
        // Somebody else's avatar URL is never rewritten under this id.
        $other = 'https://cdn.discordapp.com/avatars/11111111111/xyz.png';
        $this->assertSame($other, tcgAvatarNormalizeForStorage($this->uid, $other));
        $this->assertNull(tcgAvatarParseUrl('https://example.com/avatar.php?u=bad&h=../../etc'));
    }

    public function testChangingTheAvatarRemovesTheOldCachedFile(): void
    {
        file_put_contents(tcgAvatarCacheFile($this->uid, 'oldhash'), 'old');
        file_put_contents(tcgAvatarCacheFile($this->uid, 'newhash'), 'new');
        tcgAvatarPurgeOthers($this->uid, 'newhash');
        $this->assertFileDoesNotExist(tcgAvatarCacheFile($this->uid, 'oldhash'));
        $this->assertFileExists(tcgAvatarCacheFile($this->uid, 'newhash'));
        $this->assertSame(tcgAvatarCacheFile($this->uid, 'newhash'), tcgAvatarNewestCached($this->uid));
    }

    public function testSweepConvertsStoredCdnUrlsToLocalCacheUrls(): void
    {
        $db = tcgDb();
        tcgEnsureUser($this->uid, ['username' => 'Sweep Tester']);
        $db->prepare('UPDATE tcg_users SET avatar_url = ? WHERE discord_id = ?')
            ->execute(['https://cdn.discordapp.com/avatars/' . $this->uid . '/sweephash.png?size=128', $this->uid]);
        $res = tcgAvatarSweepAllUsers($db);
        $this->assertGreaterThanOrEqual(1, $res['converted']);
        $st = $db->prepare('SELECT avatar_url FROM tcg_users WHERE discord_id = ?');
        $st->execute([$this->uid]);
        $this->assertSame(tcgAvatarProxyUrl($this->uid, 'sweephash'), (string) $st->fetchColumn());
    }

    public function testEndpointAndFallbackFixAreWired(): void
    {
        $php = (string) file_get_contents(dirname(__DIR__, 2) . '/avatar.php');
        $this->assertStringContainsString('tcgAvatarDownload', $php);
        $this->assertStringContainsString("'public, max-age=31536000, immutable'", $php);
        $this->assertStringContainsString('tcgAvatarContentType', $php);
        $this->assertStringContainsString('Do not serve a different hash', $php);
        $this->assertStringNotContainsString("\$last = tcgAvatarNewestCached(\$id);", $php);
        $js = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/client/js/api-client.js'));
        $this->assertStringContainsString("typeof img.onerror === 'function'", $js);
        $cache = (string) file_get_contents(dirname(__DIR__, 2) . '/avatar_cache.php');
        $this->assertStringContainsString('CURLOPT_FOLLOWLOCATION => true', $cache);
    }
}
