<?php

declare(strict_types=1);

namespace LLTCG\Game\Store;

/**
 * Minimal Redis client (RESP) — GET/SET/DEL/SET NX PX — no ext-redis required.
 */
final class RedisClient
{
    /** @var resource|null */
    private $sock = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $timeoutSec = 2.0,
        private readonly string $password = '',
        private readonly int $db = 0,
    ) {
    }

    public static function fromUrl(string $url): self
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new \InvalidArgumentException('Invalid TCG_REDIS_URL');
        }
        $host = $parts['host'] ?? '127.0.0.1';
        $port = intval($parts['port'] ?? 6379);
        $pass = '';
        if (isset($parts['pass'])) {
            $pass = rawurldecode((string)$parts['pass']);
        } elseif (isset($parts['user']) && ($parts['user'] !== '' || isset($parts['pass']))) {
            // redis://:password@host
            $pass = rawurldecode((string)($parts['pass'] ?? $parts['user'] ?? ''));
        }
        $db = 0;
        if (!empty($parts['path']) && $parts['path'] !== '/') {
            $db = intval(ltrim($parts['path'], '/'));
        }
        return new self($host, $port, 2.0, $pass, $db);
    }

    public function get(string $key): ?string
    {
        $r = $this->command(['GET', $key]);
        if ($r === null || $r === false) {
            return null;
        }
        return is_string($r) ? $r : null;
    }

    public function set(string $key, string $value, ?int $ttlSec = null): void
    {
        if ($ttlSec !== null && $ttlSec > 0) {
            $this->command(['SET', $key, $value, 'EX', (string)$ttlSec]);
            return;
        }
        $this->command(['SET', $key, $value]);
    }

    /** @return bool true if lock acquired */
    public function setNxPx(string $key, string $value, int $ttlMs): bool
    {
        $r = $this->command(['SET', $key, $value, 'NX', 'PX', (string)$ttlMs]);
        return $r === true || $r === 'OK';
    }

    public function del(string $key): void
    {
        $this->command(['DEL', $key]);
    }

    /**
     * Delete $key only when its value is still $token.
     * A lock whose TTL already expired must not be removed out from under a newer holder.
     */
    public function compareAndDel(string $key, string $token): bool
    {
        $script = 'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end';
        $r = $this->command(['EVAL', $script, '1', $key, $token]);
        return intval($r) === 1;
    }

    /**
     * KEYS pattern (small private Redis only — spectate listing).
     *
     * @return list<string>
     */
    public function keys(string $pattern): array
    {
        $r = $this->command(['KEYS', $pattern]);
        if (!is_array($r)) {
            return [];
        }
        $out = [];
        foreach ($r as $k) {
            if (is_string($k) && $k !== '') {
                $out[] = $k;
            }
        }
        return $out;
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
        $this->sock = null;
    }

    /** @param list<string> $args */
    private function command(array $args): mixed
    {
        $this->connect();
        $buf = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $a = (string)$a;
            $buf .= '$' . strlen($a) . "\r\n" . $a . "\r\n";
        }
        $written = fwrite($this->sock, $buf);
        if ($written === false) {
            $this->close();
            throw new \RuntimeException('Redis write failed');
        }
        return $this->readReply();
    }

    private function connect(): void
    {
        if (is_resource($this->sock)) {
            return;
        }
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeoutSec);
        if ($sock === false) {
            throw new \RuntimeException("Redis connect failed: $errstr ($errno)");
        }
        stream_set_timeout($sock, (int)ceil($this->timeoutSec));
        $this->sock = $sock;
        if ($this->password !== '') {
            $this->command(['AUTH', $this->password]);
        }
        if ($this->db > 0) {
            $this->command(['SELECT', (string)$this->db]);
        }
    }

    private function readReply(): mixed
    {
        $line = fgets($this->sock);
        if ($line === false) {
            $this->close();
            throw new \RuntimeException('Redis read failed');
        }
        $type = $line[0] ?? '';
        $payload = substr($line, 1);
        return match ($type) {
            '+' => rtrim($payload, "\r\n"),
            '-' => throw new \RuntimeException('Redis error: ' . rtrim($payload, "\r\n")),
            ':' => intval($payload),
            '$' => $this->readBulk(intval($payload)),
            '*' => $this->readArray(intval($payload)),
            default => throw new \RuntimeException('Redis protocol error'),
        };
    }

    private function readBulk(int $len): mixed
    {
        if ($len < 0) {
            return null;
        }
        $data = '';
        $remaining = $len + 2; // include CRLF
        while ($remaining > 0) {
            $chunk = fread($this->sock, $remaining);
            if ($chunk === false || $chunk === '') {
                $this->close();
                throw new \RuntimeException('Redis bulk read failed');
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }
        return substr($data, 0, $len);
    }

    private function readArray(int $n): array
    {
        if ($n < 0) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->readReply();
        }
        return $out;
    }
}
