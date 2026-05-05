<?php

declare(strict_types=1);

namespace Phalanx\System;

class StreamingProcessExit
{
    public bool $successful {
        get => $this->exitCode === 0 && $this->signal === 0;
    }

    public function __construct(
        public private(set) int $pid,
        public private(set) int $exitCode,
        public private(set) int $signal,
        public private(set) float $durationMs,
        public private(set) bool $stopped = false,
        public private(set) bool $killed = false,
    ) {
    }
}
