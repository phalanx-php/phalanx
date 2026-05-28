<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

use Phalanx\Theatron\Store\Slice;

final class DevToolsModeSlice implements Slice
{
    public string $key { get => 'theatron.devtools.mode'; }

    public function __construct(
        private(set) DevToolsMode $mode = DevToolsMode::Docked,
    ) {
    }

    public function withMode(DevToolsMode $mode): self
    {
        return new self($mode);
    }
}
