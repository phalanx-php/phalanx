<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

use Phalanx\Mark\Mark;

final class CircuitSnapshot
{
    public function __construct(
        private(set) CircuitKey $key,
        private(set) CircuitState $state,
        private(set) int $failureCount,
        private(set) ?Mark $openedAt,
        private(set) ?Mark $halfOpenedAt,
        private(set) int $activeProbes,
    ) {
    }
}
