<?php

declare(strict_types=1);

namespace Phalanx\Server;

/**
 * Immutable snapshot of OpenSwoole\Server::stats() at a single point in time.
 *
 * The OpenSwoole master process tracks these counters natively; reading
 * stats() is a cheap synchronous call. This snapshot freezes one read so
 * callers can inspect multiple counters consistently without re-querying
 * mid-read (which could skew if a connection accepts/closes between reads).
 *
 * Field availability tracks OpenSwoole 26.2: `connection_num`, `accept_count`,
 * `close_count`, and `event_loop_lag` are documented; missing keys read as
 * 0 so callers get a stable shape across OpenSwoole minor versions.
 */
final readonly class StatsSnapshot
{
    public function __construct(
        public int $connectionNum,
        public int $acceptCount,
        public int $closeCount,
        public int $coroutineNum,
        public int $workerRequestNum,
        public int $workerDispatchNum,
        public float $eventLoopLagMs,
        public int $startTime,
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
