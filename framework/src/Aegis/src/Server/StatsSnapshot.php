<?php

declare(strict_types=1);

namespace Phalanx\Server;

/**
 * Typed snapshot of OpenSwoole\Server::stats().
 *
 * @see https://openswoole.com/docs/modules/swoole-server-stats
 */
final class StatsSnapshot
{
    public function __construct(
        private(set) int $connectionNum,
        private(set) int $acceptCount,
        private(set) int $closeCount,
        private(set) int $coroutineNum,
        private(set) int $workerRequestNum,
        private(set) int $workerDispatchNum,
        private(set) float $eventLoopLagMs,
        private(set) int $startTime,
    ) {
    }

    /**
     * @param array<string, int|float|string> $stats raw payload from `OpenSwoole\Server::stats()`
     */
    public static function fromStatsArray(array $stats): self
    {
        return new self(
            connectionNum: self::int($stats, 'connection_num'),
            acceptCount: self::int($stats, 'accept_count'),
            closeCount: self::int($stats, 'close_count'),
            coroutineNum: self::int($stats, 'coroutine_num'),
            workerRequestNum: self::int($stats, 'worker_request_num'),
            workerDispatchNum: self::int($stats, 'worker_dispatch_num'),
            eventLoopLagMs: self::float($stats, 'event_loop_lag') / 1000.0,
            startTime: self::int($stats, 'start_time'),
        );
    }

    /**
     * @param array<string, int|float|string> $stats
     */
    private static function int(array $stats, string $key): int
    {
        return isset($stats[$key]) ? (int) $stats[$key] : 0;
    }

    /**
     * @param array<string, int|float|string> $stats
     */
    private static function float(array $stats, string $key): float
    {
        return isset($stats[$key]) ? (float) $stats[$key] : 0.0;
    }
}
