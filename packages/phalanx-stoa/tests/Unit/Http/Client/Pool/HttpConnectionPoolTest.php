<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit\Http\Client\Pool;

use Phalanx\Stoa\Http\Client\Pool\HttpConnectionPool;
use Phalanx\Stoa\Http\Client\Pool\PooledConnection;
use Phalanx\System\TcpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HttpConnectionPoolTest extends TestCase
{
    #[Test]
    public function emptyPoolBorrowReturnsNull(): void
    {
        $pool = new HttpConnectionPool();

        self::assertNull($pool->borrow('example.com', 80, false));
    }

    #[Test]
    public function returnedConnectionCanBeBorrowedBack(): void
    {
        $pool = new HttpConnectionPool();
        $connection = self::stubConnection('example.com', 80, false);

        $pool->return($connection);
        self::assertSame(1, $pool->size());

        $borrowed = $pool->borrow('example.com', 80, false);
        self::assertSame($connection, $borrowed);
        self::assertSame(0, $pool->size());
    }

    #[Test]
    public function differentTlsKeysAreIsolated(): void
    {
        $pool = new HttpConnectionPool();
        $plain = self::stubConnection('example.com', 80, false);
        $secure = self::stubConnection('example.com', 80, true);

        $pool->return($plain);
        $pool->return($secure);

        self::assertSame($secure, $pool->borrow('example.com', 80, true));
        self::assertSame($plain, $pool->borrow('example.com', 80, false));
    }

    #[Test]
    public function maxPerKeyClosesOverflow(): void
    {
        $pool = new HttpConnectionPool(maxPerKey: 1);
        $a = self::stubConnection('example.com', 80, false);
        $b = self::stubConnection('example.com', 80, false);

        $pool->return($a);
        $pool->return($b);

        self::assertSame(1, $pool->size());
    }

    #[Test]
    public function clearClosesAllAndReturnsCount(): void
    {
        $pool = new HttpConnectionPool();
        $pool->return(self::stubConnection('example.com', 80, false));
        $pool->return(self::stubConnection('example.com', 443, true));

        self::assertSame(2, $pool->clear());
        self::assertSame(0, $pool->size());
    }

    private static function stubConnection(string $host, int $port, bool $tls): PooledConnection
    {
        return new PooledConnection(
            client: new TcpClient(tls: $tls),
            host: $host,
            port: $port,
            tls: $tls,
            createdAt: microtime(true),
        );
    }
}
