<?php

declare(strict_types=1);

namespace LLTCG\Game\Store;

final class GameStoreFactory
{
    public static function fromEnv(?\Closure $afterSave = null): GameStoreInterface
    {
        $mode = strtolower(trim((string)(getenv('TCG_GAME_STORE') ?: 'file')));
        $gamesDir = defined('GAMES_DIR') ? GAMES_DIR : (getenv('TCG_GAMES_DIR') ?: '');
        if ($gamesDir === '') {
            $gamesDir = dirname(__DIR__, 2) . '/games/';
        }
        $gamesDir = rtrim($gamesDir, '/\\') . '/';
        $lockTimeout = defined('LOCK_TIMEOUT') ? (float)LOCK_TIMEOUT : 5.0;

        if ($mode === 'redis') {
            $url = trim((string)(getenv('TCG_REDIS_URL') ?: ''));
            if ($url === '') {
                throw new \RuntimeException('TCG_GAME_STORE=redis requires TCG_REDIS_URL');
            }
            $prefix = (string)(getenv('TCG_REDIS_PREFIX') ?: 'lltcg:room:');
            $snapshot = trim((string)(getenv('TCG_GAME_SNAPSHOT_DIR') ?: ''));
            // finished = only after match end (VPS default); all = legacy every-save dual-write; off = Redis only
            $snapshotMode = strtolower(trim((string)(getenv('TCG_GAME_SNAPSHOT_MODE') ?: '')));
            if ($snapshotMode === '') {
                $snapshotMode = 'finished';
            }
            $ttl = intval(getenv('TCG_REDIS_TTL_SEC') ?: 172800);
            return new RedisGameStore(
                RedisClient::fromUrl($url),
                $prefix,
                $ttl > 0 ? $ttl : 172800,
                $snapshot !== '' ? $snapshot : null,
                $lockTimeout,
                $afterSave,
                $snapshotMode,
            );
        }

        return new JsonFileGameStore($gamesDir, $lockTimeout, $afterSave);
    }
}
