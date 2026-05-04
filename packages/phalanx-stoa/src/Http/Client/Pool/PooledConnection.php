<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Http\Client\Pool;

use Phalanx\System\TcpClient;

/**
 * Pool entry tracking a TcpClient and its idle metadata.
 *
 * The connection is owned by the pool: callers borrow, use, and return.
 * Returning a connection that the upstream closed (`Connection: close`)
 * should be rejected at the pool boundary so the next borrower never
 * receives a half-shut socket.
 */
final class PooledConnection
{
    public float $lastUsed;

    public function __construct(
        public readonly TcpClient $client,
        public readonly string $host,
        public readonly int $port,
        public readonly bool $tls,
        public readonly float $createdAt,
    ) {
        $this->lastUsed = $createdAt;
    }

    public function key(): string
    {
        return ($this->tls ? 'tls' : 'tcp') . '://' . $this->host . ':' . $this->port;
    }

    public function isIdleLongerThan(float $now, float $maxIdle): bool
    {
        return ($now - $this->lastUsed) > $maxIdle;
    }
}
