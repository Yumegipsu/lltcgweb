<?php

declare(strict_types=1);

namespace LLTCG\Tests\Account;

use PHPUnit\Framework\TestCase;

final class DiscordAvatarTest extends TestCase
{
    private string $uid;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        require_once dirname(__DIR__, 2) . '/db.php';
        require_once dirname(__DIR__, 2) . '/avatar_cache.php';
        require_once dirname(__DIR__, 2) . '/auth_profile.php';
        $this->uid = '9' . str_pad((string) random_int(1, 99999999999), 17, '0', STR_PAD_LEFT);
    }

    private function storedAvatar(): ?string
    {
        $st = tcgDb()->prepare('SELECT avatar_url FROM tcg_users WHERE discord_id = ?');
        $st->execute([$this->uid]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function testGuessedDefaultNeverOverwritesAStoredCustomAvatar(): void
    {
        $custom = tcgDiscordAvatarUrl($this->uid, 'abc123def456');
        $customStored = tcgAvatarProxyUrl($this->uid, 'abc123def456');
        tcgEnsureUser($this->uid, ['username' => 'Avatar Tester', 'avatar_url' => $custom, 'avatar_known' => true]);
        $this->assertSame($customStored, $this->storedAvatar(), 'CDN URLs are stored as local-cache URLs');

        // Session / cache lost: the profile can only guess the CDN default.
        $guess = tcgDiscordAvatarUrl($this->uid, null);
        tcgEnsureUser($this->uid, ['username' => 'Avatar Tester', 'avatar_url' => $guess, 'avatar_known' => false]);
        $this->assertSame($customStored, $this->storedAvatar(), 'a guessed default must not replace the real avatar');

        // A resolved current avatar (changed on Discord) does replace it.
        $changed = tcgDiscordAvatarUrl($this->uid, 'a_newanimatedhash');
        tcgEnsureUser($this->uid, ['username' => 'Avatar Tester', 'avatar_url' => $changed, 'avatar_known' => true]);
        $this->assertSame(tcgAvatarProxyUrl($this->uid, 'a_newanimatedhash'), $this->storedAvatar());

        // Removing the avatar on Discord is a known state too (bot lookup says "no custom avatar").
        $removed = tcgDiscordAvatarUrl($this->uid, null);
        tcgEnsureUser($this->uid, ['username' => 'Avatar Tester', 'avatar_url' => $removed, 'avatar_known' => true]);
        $this->assertSame($removed, $this->storedAvatar());
    }

    public function testLegacyProfilesWithoutTheFlagStillUpdate(): void
    {
        tcgEnsureUser($this->uid, ['username' => 'Legacy', 'avatar_url' => tcgDiscordAvatarUrl($this->uid, 'oldhash')]);
        $new = tcgDiscordAvatarUrl($this->uid, 'newhash');
        tcgEnsureUser($this->uid, ['username' => 'Legacy', 'avatar_url' => $new]);
        $this->assertSame(tcgAvatarProxyUrl($this->uid, 'newhash'), $this->storedAvatar());
    }

    public function testFreshenIsANoOpWithoutABotToken(): void
    {
        if (tcgDiscordBotToken() !== '') {
            $this->markTestSkipped('a bot token is configured in this environment');
        }
        $profile = ['username' => 'x', 'avatar_url' => 'https://cdn.discordapp.com/avatars/1/h.png?size=128', 'avatar_known' => true];
        $this->assertSame($profile, tcgFreshenDiscordAvatar($this->uid, $profile));
    }

    public function testFreshenInsideTtlKeepsStoredAvatarOverStaleSessionHash(): void
    {
        if (!defined('TCG_DISCORD_BOT_TOKEN')) {
            define('TCG_DISCORD_BOT_TOKEN', 'test-token-not-used-inside-ttl');
        }
        if (tcgDiscordBotToken() === '') {
            $this->markTestSkipped('could not set a bot token for this test');
        }
        $fresh = tcgAvatarProxyUrl($this->uid, 'freshhashfrombot');
        tcgEnsureUser($this->uid, [
            'username' => 'Freshen TTL',
            'avatar_url' => tcgDiscordAvatarUrl($this->uid, 'freshhashfrombot'),
            'avatar_known' => true,
        ]);
        tcgDb()->prepare('UPDATE tcg_users SET avatar_checked_at = ? WHERE discord_id = ?')
            ->execute([time(), $this->uid]);

        $stale = [
            'username' => 'Freshen TTL',
            'avatar_url' => tcgDiscordAvatarUrl($this->uid, 'stalesessionhash'),
            'avatar_known' => true,
        ];
        $out = tcgFreshenDiscordAvatar($this->uid, $stale);
        $this->assertSame($fresh, $out['avatar_url'], 'TTL skip must not hand ensureUser a stale session hash');
        $this->assertTrue($out['avatar_known']);

        // And ensureUser must not write the stale hash back over the stored one.
        tcgEnsureUser($this->uid, $out);
        $this->assertSame($fresh, $this->storedAvatar());
    }

    public function testFreshHashPrefersBotPathInSource(): void
    {
        $php = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/avatar_cache.php'));
        $botPos = strpos($php, 'function tcgAvatarFreshHash');
        $this->assertNotFalse($botPos);
        $fn = substr($php, $botPos, 800);
        $lookup = strpos($fn, 'tcgLookupDiscordBotProfile');
        $cache = strpos($fn, 'tcgAvatarHashFromLoginCache');
        $this->assertNotFalse($lookup);
        $this->assertNotFalse($cache);
        $this->assertLessThan($cache, $lookup, 'bot lookup must run before the OAuth login cache');
    }

    public function testClientFallsBackToTheDiscordDefaultWhenAnAvatarFailsToLoad(): void
    {
        $js = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/client/js/api-client.js'));
        $this->assertStringContainsString("document.addEventListener('error'", $js);
        $this->assertStringContainsString('cdn.discordapp.com/embed/avatars/', $js);
        $this->assertStringContainsString('avatarFallback', $js);
    }
}
