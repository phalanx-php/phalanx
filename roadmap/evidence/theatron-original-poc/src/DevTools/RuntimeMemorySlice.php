<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

use Phalanx\Theatron\Store\Slice;

final class RuntimeMemorySlice implements Slice
{
    public string $key {
        get => 'theatron.runtime.memory';
    }

    public function __construct(
        private(set) int $memReal = 0,
        private(set) int $memZend = 0,
        private(set) int $memRealPeak = 0,
        private(set) int $memZendPeak = 0,
    ) {
    }
}
