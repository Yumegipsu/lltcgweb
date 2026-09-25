<?php

declare(strict_types=1);

namespace LLTCG\Tests\Store;

use LLTCG\Game\Store\JsonFileGameStore;
use PHPUnit\Framework\TestCase;

final class JsonFileGameStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lltcg_store_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testSaveLoadRoundTrip(): void
    {
        $store = new JsonFileGameStore($this->dir . '/');
        $state = ['room_id' => 'ABCD12', 'seq' => 3, 'phase' => 'main_first'];
        $store->save('abcd12', $state);
        $loaded = $store->load('ABCD12');
        $this->assertSame(3, $loaded['seq'] ?? null);
        $this->assertSame('main_first', $loaded['phase'] ?? null);
    }

    public function testWithLockSerializesMutations(): void
    {
        $store = new JsonFileGameStore($this->dir . '/');
        $store->save('LOCK1', ['seq' => 0]);
        $store->withLock('LOCK1', function () use ($store) {
            $s = $store->load('LOCK1');
            $s['seq'] = intval($s['seq'] ?? 0) + 1;
            $store->save('LOCK1', $s);
            return true;
        });
        $this->assertSame(1, $store->load('LOCK1')['seq'] ?? null);
    }

    public function testDeleteRemovesFile(): void
    {
        $store = new JsonFileGameStore($this->dir . '/');
        $store->save('DEL1', ['seq' => 1]);
        $store->delete('DEL1');
        $this->assertNull($store->load('DEL1'));
    }

    public function testUnlockedSaveDoesNotResurrectFinishedRoom(): void
    {
        $store = new JsonFileGameStore($this->dir . '/');
        $store->save('FIN1', ['room_id' => 'FIN1', 'status' => 'playing', 'seq' => 4]);
        $store->withLock('FIN1', function () use ($store) {
            $s = $store->load('FIN1');
            $s['status'] = 'finished';
            $s['seq'] = 5;
            $s['end_reason'] = 'resign';
            $store->save('FIN1', $s);
        });
        $store->save('FIN1', ['room_id' => 'FIN1', 'status' => 'playing', 'seq' => 6, 'phase' => 'main']);
        $loaded = $store->load('FIN1');
        $this->assertSame('finished', $loaded['status'] ?? null);
        $this->assertSame(5, $loaded['seq'] ?? null);
    }

    public function testLockedRematchCanReplaceFinishedRoom(): void
    {
        $store = new JsonFileGameStore($this->dir . '/');
        $store->save('REM1', ['status' => 'finished', 'seq' => 9]);
        $store->withLock('REM1', function () use ($store) {
            $store->save('REM1', ['status' => 'playing', 'seq' => 1, 'phase' => 'setup']);
        });
        $loaded = $store->load('REM1');
        $this->assertSame('playing', $loaded['status'] ?? null);
        $this->assertSame(1, $loaded['seq'] ?? null);
    }

    public function testUnlockedSaveDoesNotRollSeqBackward(): void
    {
        $store = new JsonFileGameStore($this->dir . '/');
        $store->save('SEQ1', ['status' => 'playing', 'seq' => 8]);
        $store->save('SEQ1', ['status' => 'playing', 'seq' => 7, 'phase' => 'main']);
        $this->assertSame(8, $store->load('SEQ1')['seq'] ?? null);
        $store->save('SEQ1', ['status' => 'playing', 'seq' => 9, 'phase' => 'live']);
        $this->assertSame(9, $store->load('SEQ1')['seq'] ?? null);
        $this->assertSame('live', $store->load('SEQ1')['phase'] ?? null);
    }
}
