<?php

declare(strict_types=1);

namespace LLTCG\Game\Store;

/**
 * In-memory Redis room body; optional disk snapshot for ops / overflow export retry.
 *
 * Snapshot modes (TCG_GAME_SNAPSHOT_MODE):
 * - off: never write disk copies
 * - finished: write only when status=finished or winner is set (default when dir set)
 * - all: dual-write every save (legacy; fills disk — avoid on VPS)
 */
final class RedisGameStore implements GameStoreInterface
{
    private const DEFAULT_TTL_SEC = 172800; // 48h

    public function __construct(
        private readonly RedisClient $redis,
        private readonly string $prefix = 'lltcg:room:',
        private readonly int $ttlSec = self::DEFAULT_TTL_SEC,
        private readonly ?string $snapshotDir = null,
        private readonly float $defaultLockTimeoutSec = 5.0,
        private readonly ?\Closure $afterSave = null,
        private readonly string $snapshotMode = 'finished',
    ) {
        if ($this->snapshotDir !== null && $this->snapshotDir !== '' && !is_dir($this->snapshotDir)) {
            mkdir($this->snapshotDir, 0755, true);
        }
    }

    /**
     * Whether this room state should be dual-written to the snapshot dir.
     */
    public static function shouldWriteSnapshot(string $mode, array $state): bool
    {
        $mode = strtolower(trim($mode));
        if ($mode === '' || $mode === 'off' || $mode === 'none' || $mode === '0' || $mode === 'false') {
            return false;
        }
        if ($mode === 'all' || $mode === 'every' || $mode === 'always') {
            return true;
        }
        // finished (default)
        if (($state['status'] ?? '') === 'finished') {
            return true;
        }
        $winner = $state['winner'] ?? null;
        return $winner === 'p1' || $winner === 'p2';
    }

    public function normalizeRoomId(string $roomId): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId)) ?? '';
    }

    private function stateKey(string $roomId): string
    {
        return $this->prefix . $this->normalizeRoomId($roomId);
    }

    private function lockKey(string $roomId): string
    {
        return $this->prefix . 'lock:' . $this->normalizeRoomId($roomId);
    }

    private function snapshotPath(string $roomId): ?string
    {
        if ($this->snapshotDir === null || $this->snapshotDir === '') {
            return null;
        }
        return rtrim($this->snapshotDir, '/\\') . '/' . $this->normalizeRoomId($roomId) . '.json';
    }

    public function load(string $roomId): ?array
    {
        $raw = $this->redis->get($this->stateKey($roomId));
        if ($raw === null || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function save(string $roomId, array $state): void
    {
        $json = json_encode($state);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode room state');
        }
        $this->redis->set($this->stateKey($roomId), $json, $this->ttlSec);
        $path = $this->snapshotPath($roomId);
        if ($path !== null && self::shouldWriteSnapshot($this->snapshotMode, $state)) {
            @file_put_contents($path, $json, LOCK_EX);
        }
        if ($this->afterSave) {
            ($this->afterSave)($roomId, $state);
        }
    }

    public function delete(string $roomId): void
    {
        $this->redis->del($this->stateKey($roomId));
        $this->redis->del($this->lockKey($roomId));
        $this->deleteSnapshot($roomId);
    }

    /**
     * Unlink disk snapshot only — keep Redis room for late export / TTL expiry.
     */
    public function deleteSnapshot(string $roomId): bool
    {
        $path = $this->snapshotPath($roomId);
        if ($path === null || !is_file($path)) {
            return false;
        }
        return @unlink($path);
    }

    /**
     * Active room ids currently in Redis (excludes lock keys).
     *
     * @return list<string>
     */
    public function listRoomIds(): array
    {
        $keys = $this->redis->keys($this->prefix . '*');
        $lockNeedle = $this->prefix . 'lock:';
        $out = [];
        foreach ($keys as $key) {
            if (str_starts_with($key, $lockNeedle)) {
                continue;
            }
            $id = substr($key, strlen($this->prefix));
            if ($id === '' || str_contains($id, ':')) {
                continue;
            }
            $out[] = $id;
        }
        return $out;
    }

    public function withLock(string $roomId, callable $fn, ?float $timeoutSec = null): mixed
    {
        $lockKey = $this->lockKey($roomId);
        $token = bin2hex(random_bytes(8));
        $deadline = microtime(true) + ($timeoutSec ?? $this->defaultLockTimeoutSec);
        $ttlMs = (int)max(1000, (int)(($timeoutSec ?? $this->defaultLockTimeoutSec) * 1000) + 2000);
        $acquired = false;
        while (microtime(true) <= $deadline) {
            if ($this->redis->setNxPx($lockKey, $token, $ttlMs)) {
                $acquired = true;
                break;
            }
            usleep(50000);
        }
        if (!$acquired) {
            throw new \Exception('Lock timeout');
        }
        try {
            return $fn();
        } finally {
            // Best-effort unlock (token check omitted for minimal client).
            $this->redis->del($lockKey);
        }
    }
}
