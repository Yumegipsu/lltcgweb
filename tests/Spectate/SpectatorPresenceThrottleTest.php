<?php

declare(strict_types=1);

namespace LLTCG\Tests\Spectate;

use PHPUnit\Framework\TestCase;

/**
 * Spectator presence / count must not rewrite spectators_*.json on every get_state.
 */
final class SpectatorPresenceThrottleTest extends TestCase
{
    private string $roomId;

    protected function setUp(): void
    {
        $this->roomId = 'SPEC' . strtoupper(bin2hex(random_bytes(3)));
        $GLOBALS['_tcg_spec_purge_at'] = [];
        $GLOBALS['_tcg_spec_count_cache'] = [];
    }

    protected function tearDown(): void
    {
        $path = tcgSpectatorsFilePath($this->roomId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function testTouchPresenceSkipsRewriteWithinThrottleWindow(): void
    {
        $token = 'spec_' . bin2hex(random_bytes(8));
        $now = time();
        tcgWriteSpectators($this->roomId, [
            $token => ['joined_at' => $now, 'last_seen' => $now],
        ]);
        $path = tcgSpectatorsFilePath($this->roomId);
        $mtime1 = filemtime($path);
        clearstatcache(true, $path);

        // Immediate re-touch must not rewrite the file.
        usleep(20000);
        tcgTouchSpectatorPresence($this->roomId, $token);
        clearstatcache(true, $path);
        $this->assertSame($mtime1, filemtime($path));

        $raw = json_decode((string) file_get_contents($path), true);
        $this->assertSame($now, intval($raw[$token]['last_seen'] ?? 0));
    }

    public function testSpectatorCountUsesShortCache(): void
    {
        $token = 'spec_' . bin2hex(random_bytes(8));
        tcgWriteSpectators($this->roomId, [
            $token => ['joined_at' => time(), 'last_seen' => time()],
        ]);
        $a = tcgLiveSpectatorCount($this->roomId);
        $this->assertSame(1, $a);
        // Corrupt file between calls — cache should still return 1 within TTL.
        tcgWriteSpectators($this->roomId, []);
        $b = tcgLiveSpectatorCount($this->roomId);
        $this->assertSame(1, $b);
    }

    public function testTokenValidWithoutFullPurgeEveryCall(): void
    {
        $token = 'spec_' . bin2hex(random_bytes(8));
        tcgWriteSpectators($this->roomId, [
            $token => ['joined_at' => time(), 'last_seen' => time()],
        ]);
        $this->assertTrue(tcgSpectatorTokenValid($this->roomId, $token));
        $this->assertFalse(tcgSpectatorTokenValid($this->roomId, 'spec_deadbeef'));
    }
}
