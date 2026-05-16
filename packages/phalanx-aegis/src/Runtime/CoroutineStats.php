<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use OpenSwoole\Coroutine;

/**
 * Typed snapshot of OpenSwoole\Coroutine::stats().
 *
 * @see https://openswoole.com/docs/modules/swoole-coroutine-stats
 */
final class CoroutineStats
{
    private function __construct(
        private(set) int $eventNum,
        private(set) int $signalListenerNum,
        private(set) int $aioTaskNum,
        private(set) int $aioWorkerNum,
        private(set) int $cStackSize,
        private(set) int $coroutineNum,
        private(set) int $coroutinePeakNum,
        private(set) int $coroutineLastCid,
    ) {
    }

    public static function capture(): self
    {
        return self::fromArray(Coroutine::stats());
    }

    /**
     * @param array<string, int|float|string> $stats
     */
    public static function fromArray(array $stats): self
    {
        return new self(
            eventNum: self::int($stats, 'event_num'),
            signalListenerNum: self::int($stats, 'signal_listener_num'),
            aioTaskNum: self::int($stats, 'aio_task_num'),
            aioWorkerNum: self::int($stats, 'aio_worker_num'),
            cStackSize: self::int($stats, 'c_stack_size'),
            coroutineNum: self::int($stats, 'coroutine_num'),
            coroutinePeakNum: self::int($stats, 'coroutine_peak_num'),
            coroutineLastCid: self::int($stats, 'coroutine_last_cid'),
        );
    }

    /**
     * @param array<string, int|float|string> $stats
     */
    private static function int(array $stats, string $key): int
    {
        return isset($stats[$key]) ? (int) $stats[$key] : 0;
    }
}
