<?php

declare(strict_types=1);

namespace LLTCG\Tests\Store;

use LLTCG\Game\Store\RedisGameStore;
use PHPUnit\Framework\TestCase;

final class RedisGameStoreSnapshotPolicyTest extends TestCase
{
    public function testOffNeverWrites(): void
    {
        $this->assertFalse(RedisGameStore::shouldWriteSnapshot('off', [
            'status' => 'finished',
            'winner' => 'p1',
        ]));
        $this->assertFalse(RedisGameStore::shouldWriteSnapshot('none', ['status' => 'active']));
    }

    public function testAllAlwaysWrites(): void
    {
        $this->assertTrue(RedisGameStore::shouldWriteSnapshot('all', ['status' => 'active']));
        $this->assertTrue(RedisGameStore::shouldWriteSnapshot('every', ['phase' => 'main']));
    }

    public function testFinishedOnlyWhenDone(): void
    {
        $this->assertFalse(RedisGameStore::shouldWriteSnapshot('finished', [
            'status' => 'active',
            'phase' => 'main_first',
        ]));
        $this->assertTrue(RedisGameStore::shouldWriteSnapshot('finished', [
            'status' => 'finished',
        ]));
        $this->assertTrue(RedisGameStore::shouldWriteSnapshot('finished', [
            'status' => 'active',
            'winner' => 'p2',
        ]));
        $this->assertTrue(RedisGameStore::shouldWriteSnapshot('', [
            'status' => 'finished',
        ]) === false); // empty mode = off in helper; factory defaults to finished
    }
}
