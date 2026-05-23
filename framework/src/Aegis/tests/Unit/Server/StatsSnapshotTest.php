<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Server;

use Phalanx\Server\StatsSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * StatsSnapshot is a defensive read of the OpenSwoole stats payload —
 * unrecognized keys must not break the snapshot, missing keys default to
 * 0, and event_loop_lag converts microseconds to milliseconds at read time.
 */
final class StatsSnapshotTest extends TestCase
{
    public function testFromCompletePayload(): void
    {
        $snapshot = StatsSnapshot::fromStatsArray([
            'connection_num' => 42,
            'accept_count' => 100,
            'close_count' => 58,
            'coroutine_num' => 12,
            'worker_request_num' => 1500,
            'worker_dispatch_num' => 1500,
            'event_loop_lag' => 25_000,
            'start_time' => 1_700_000_000,
        ]);

        self::assertSame(42, $snapshot->connectionNum);
        self::assertSame(100, $snapshot->acceptCount);
        self::assertSame(58, $snapshot->closeCount);
        self::assertSame(12, $snapshot->coroutineNum);
        self::assertSame(1500, $snapshot->workerRequestNum);
        self::assertSame(25.0, $snapshot->eventLoopLagMs);
        self::assertSame(1_700_000_000, $snapshot->startTime);
    }

    public function testMissingKeysDefaultToZero(): void
    {
        $snapshot = StatsSnapshot::fromStatsArray([]);

        self::assertSame(0, $snapshot->connectionNum);
        self::assertSame(0, $snapshot->acceptCount);
        self::assertSame(0, $snapshot->closeCount);
        self::assertSame(0.0, $snapshot->eventLoopLagMs);
    }

    public function testEventLoopLagConvertsMicrosecondsToMilliseconds(): void
    {
        $snapshot = StatsSnapshot::fromStatsArray(['event_loop_lag' => 50_000]);

        self::assertSame(50.0, $snapshot->eventLoopLagMs);
    }

    public function testAcceptsStringNumerics(): void
    {
        // OpenSwoole occasionally returns numeric strings depending on
        // the underlying counter type; the snapshot coerces.
        $snapshot = StatsSnapshot::fromStatsArray([
            'connection_num' => '42',
            'event_loop_lag' => '1000',
        ]);

        self::assertSame(42, $snapshot->connectionNum);
        self::assertSame(1.0, $snapshot->eventLoopLagMs);
    }
}
