<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

/**
 * Typed snapshot of a single Swoole\Table's runtime metrics.
 *
 * @see https://wiki.swoole.com/en/#/memory/table
 */
final class RuntimeTableStats
{
    public function __construct(
        private(set) string $name,
        private(set) int $configuredRows,
        private(set) int $currentRows,
        private(set) int $memorySize,
        private(set) int $highWaterRows,
    ) {
    }
}
