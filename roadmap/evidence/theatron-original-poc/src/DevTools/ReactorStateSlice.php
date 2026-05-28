<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

use Phalanx\Theatron\Reactor\ReactorState;
use Phalanx\Theatron\Store\Slice;

final class ReactorStateSlice implements Slice
{
    public string $key {
        get => 'theatron.runtime.reactors';
    }

    /** @param array<string, ReactorState> $reactors */
    public function __construct(
        private(set) array $reactors = [],
    ) {
    }
}
