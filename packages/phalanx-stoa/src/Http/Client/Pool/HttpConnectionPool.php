<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Http\Client\Pool;

/**
 * Bounded TTL-based connection pool keyed by `(host, port, tls)`.
 *
 * Pool semantics:
 *   - borrow()  — return an idle connection if available, otherwise null.
 *   - return()  — accept a still-alive connection back into the pool.
 *   - reap()    — drop entries idle longer than maxIdleSeconds.
 *
 * Callers (StoaHttpClient) decide whether a connection is reusable
 * based on the response's `Connection:` header and any error state.
 */
final class HttpConnectionPool
{
    /** @var array<string, list<PooledConnection>> */
    private array $byKey = [];

    public function __construct(
        public readonly int $maxPerKey = 8,
        public readonly float $maxIdleSeconds = 60.0,
    ) {
    }

    private static function poolKey(string $host, int $port, bool $tls): string
    {
        return ($tls ? 'tls' : 'tcp') . '://' . $host . ':' . $port;
    }

    public function borrow(string $host, int $port, bool $tls): ?PooledConnection
    {
        $key = self::poolKey($host, $port, $tls);
        $now = microtime(true);

        while (isset($this->byKey[$key]) && $this->byKey[$key] !== []) {
            $connection = array_pop($this->byKey[$key]);

            if ($connection->isIdleLongerThan($now, $this->maxIdleSeconds)) {
                $connection->client->close();
                continue;
            }

            $connection->lastUsed = $now;
            return $connection;
        }

        return null;
    }

    public function return(PooledConnection $connection): void
    {
        $key = $connection->key();
        $this->byKey[$key] ??= [];

        if (count($this->byKey[$key]) >= $this->maxPerKey) {
            $connection->client->close();
            return;
        }

        $connection->lastUsed = microtime(true);
        $this->byKey[$key][] = $connection;
    }

    public function reap(): int
    {
        $reaped = 0;
        $now = microtime(true);

        foreach ($this->byKey as $key => $connections) {
            $kept = [];
            foreach ($connections as $connection) {
                if ($connection->isIdleLongerThan($now, $this->maxIdleSeconds)) {
                    $connection->client->close();
                    $reaped++;
                    continue;
                }
                $kept[] = $connection;
            }

            if ($kept === []) {
                unset($this->byKey[$key]);
            } else {
                $this->byKey[$key] = $kept;
            }
        }

        return $reaped;
    }

    public function clear(): int
    {
        $closed = 0;

        foreach ($this->byKey as $connections) {
            foreach ($connections as $connection) {
                $connection->client->close();
                $closed++;
            }
        }

        $this->byKey = [];

        return $closed;
    }

    public function size(): int
    {
        $total = 0;
        foreach ($this->byKey as $connections) {
            $total += count($connections);
        }

        return $total;
    }
}
