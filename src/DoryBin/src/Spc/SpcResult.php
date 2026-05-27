<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Spc;

final class SpcResult
{
    public bool $success {
        get => $this->exitCode === 0;
    }

    public function __construct(
        private(set) int $exitCode,

        private(set) string $stdout,

        private(set) string $stderr,

        private(set) float $durationMs,
    ) {
    }
}
