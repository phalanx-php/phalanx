<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use Phalanx\Supervisor\DeliveryLease;
use Phalanx\Supervisor\Lease;
use PHPUnit\Framework\TestCase;

/**
 * The DeliveryLease shape mirrors the existing Lease family: domain, key,
 * mode, acquiredAt. The fd is stringified to fit the resource_leases
 * table's text column.
 */
final class DeliveryLeaseTest extends TestCase
{
    public function testImplementsLease(): void
    {
        self::assertInstanceOf(Lease::class, DeliveryLease::open('sse-stream', 7));
    }

    public function testOpenCarriesDomainAndFd(): void
    {
        $lease = DeliveryLease::open('ws-frame', 42);

        self::assertSame('ws-frame', $lease->domain);
        self::assertSame('42', $lease->key);
        self::assertSame('flush', $lease->mode);
    }

    public function testAcquiredAtIsRecorded(): void
    {
        $before = microtime(true);
        $lease = DeliveryLease::open('sse-stream', 1);
        $after = microtime(true);

        self::assertGreaterThanOrEqual($before, $lease->acquiredAt);
        self::assertLessThanOrEqual($after, $lease->acquiredAt);
    }

    public function testConstructorAllowsExplicitTimestamp(): void
    {
        $lease = new DeliveryLease('udp-broadcast', '9', 1234.5);

        self::assertSame(1234.5, $lease->acquiredAt);
    }
}
