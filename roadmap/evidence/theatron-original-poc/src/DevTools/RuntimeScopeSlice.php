<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

use Phalanx\Theatron\Store\Slice;

final class RuntimeScopeSlice implements Slice
{
    public string $key {
        get => 'theatron.runtime.scope';
    }

    public function __construct(
        private(set) string $currentRunId = '',
        private(set) string $currentRunState = '',
        private(set) int $activeScopes = 0,
    ) {
    }
}
