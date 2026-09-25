<?php

declare(strict_types=1);

namespace LLTCG\Game\Store;

/**
 * Current Hostinger behavior: GAMES_DIR JSON + flock lock files.
 */
final class JsonFileGameStore implements GameStoreInterface
{
    /** @var array<string,int> */
    private static array $lockDepth = [];

    public function __construct(
        private readonly string $gamesDir,
        private readonly float $defaultLockTimeoutSec = 5.0,
        private readonly ?\Closure $afterSave = null,
    ) {
        if (!is_dir($this->gamesDir)) {
            mkdir($this->gamesDir, 0755, true);
        }
    }

    public function normalizeRoomId(string $roomId): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($roomId)) ?? '';
    }

    public function gamePath(string $roomId): string
    {
        return $this->gamesDir . $this->normalizeRoomId($roomId) . '.json';
    }

    public function load(string $roomId): ?array
    {
        $file = $this->gamePath($roomId);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public function save(string $roomId, array $state): void
    {
        $safe = $this->normalizeRoomId($roomId);
        if ((self::$lockDepth[$safe] ?? 0) < 1) {
            $existing = $this->load($roomId);
            if (is_array($existing) && SaveGuard::isStaleOverwrite($existing, $state)) {
                return;
            }
        }
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || $json === '') {
            throw new \RuntimeException('Failed to encode room state');
        }
        $file = $this->gamePath($roomId);
        file_put_contents($file, $json, LOCK_EX);
        if ($this->afterSave) {
            ($this->afterSave)($roomId, $state);
        }
    }

    public function delete(string $roomId): void
    {
        $file = $this->gamePath($roomId);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function withLock(string $roomId, callable $fn, ?float $timeoutSec = null): mixed
    {
        $safe = $this->normalizeRoomId($roomId);
        $lockFile = $this->gamesDir . 'lock_' . $safe;
        $lock = fopen($lockFile, 'c+');
        if ($lock === false) {
            throw new \Exception('Cannot acquire lock');
        }
        $deadline = microtime(true) + ($timeoutSec ?? $this->defaultLockTimeoutSec);
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) > $deadline) {
                fclose($lock);
                throw new \Exception('Lock timeout');
            }
            usleep(50000);
        }
        self::$lockDepth[$safe] = (self::$lockDepth[$safe] ?? 0) + 1;
        try {
            return $fn();
        } finally {
            self::$lockDepth[$safe] = max(0, (self::$lockDepth[$safe] ?? 1) - 1);
            if (self::$lockDepth[$safe] === 0) {
                unset(self::$lockDepth[$safe]);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
