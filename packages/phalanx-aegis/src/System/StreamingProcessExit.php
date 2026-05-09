<?php

declare(strict_types=1);

namespace Phalanx\System;

class StreamingProcessExit
{
    public bool $successful {
        get => $this->exitCode === 0 && $this->signal === 0;
    }

    public function __construct(
        private(set) int $pid,
        private(set) int $exitCode,
        private(set) int $signal,
        private(set) float $durationMs,
        private(set) bool $stopped = false,
        private(set) bool $killed = false,
    ) {
    }
}
