<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

final class DevToolsConfig
{
    public function __construct(
        private(set) DockPosition $position = DockPosition::Bottom,
        private(set) int $height = 8,
    ) {
        if ($this->height < 1) {
            throw new \InvalidArgumentException('DevTools height must be at least 1.');
        }
    }
}
