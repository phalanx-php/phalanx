<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

use Phalanx\Theatron\Store\Slice;

final class RuntimeMetricsSlice implements Slice
{
    public string $key {
        get => 'theatron.runtime.metrics';
    }

    public function __construct(
        private(set) int $frames = 0,
        private(set) int $handles = 0,
        private(set) int $tasks = 0,
        private(set) int $events = 0,
    ) {
    }
}
