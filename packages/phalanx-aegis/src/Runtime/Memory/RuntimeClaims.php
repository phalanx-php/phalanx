<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

use OpenSwoole\Lock;

final class RuntimeClaims
{
    private Lock $lock;

    public function __construct(private readonly ManagedSwooleTables $tables)
    {
        $this->lock = new Lock(Lock::MUTEX);
    }

    private static function key(string $key): string
    {
        return substr(sha1($key), 0, 32);
    }

    private static function fit(string $value, int $length): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length);
    }

    public function claim(string $key, float $ttl, string $token = ''): bool
    {
        $now = microtime(true);
        $rowKey = self::key($key);

        if (!$this->lock->trylock()) {
            return false;
        }

        try {
            $row = $this->tables->claims->get($rowKey);
            if (is_array($row) && (float) $row['expires_at'] > $now) {
                return false;
            }

            $this->tables->claims->set($rowKey, [
                'token' => self::fit($token === '' ? $rowKey : $token, 64),
                'claimed_at' => $now,
                'expires_at' => $now + $ttl,
            ]);
            $this->tables->mark('claims');

            return true;
        } finally {
            $this->lock->unlock();
        }
    }

    public function release(string $key): void
    {
        $this->tables->claims->del(self::key($key));
    }

    public function sweepExpired(float $now): int
    {
        $count = 0;
        foreach ($this->tables->claims as $key => $row) {
            if (!is_array($row) || (float) $row['expires_at'] > $now) {
                continue;
            }

            $this->tables->claims->del((string) $key);
            $count++;
        }

        return $count;
    }

    public function destroy(): void
    {
        $this->lock->destroy();
    }
}
