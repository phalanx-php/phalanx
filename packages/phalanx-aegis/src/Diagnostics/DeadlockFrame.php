<?php

declare(strict_types=1);

namespace Phalanx\Diagnostics;

/**
 * One coroutine's backtrace inside a {@see DeadlockReport}. The backtrace
 * comes from `OpenSwoole\Coroutine::printBackTrace($cid, ...)` and is kept
 * as a raw string because the OpenSwoole API returns formatted text, not
 * a structured frame list.
 */
final readonly class DeadlockFrame
{
    public function __construct(
        public int $cid,
        public string $backtrace,
    ) {
    }
}
