<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

final readonly class RuntimeTableStats
{
    public function __construct(
        public string $name,
        public int $configuredRows,
        public int $currentRows,
        public int $memorySize,
        public int $highWaterRows,
    ) {
    }
}
