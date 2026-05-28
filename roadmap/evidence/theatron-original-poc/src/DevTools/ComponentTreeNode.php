<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

final class ComponentTreeNode
{
    public function __construct(
        private(set) string $name,
        private(set) string $class,
        private(set) int $depth,
        private(set) int $signalCount,
        private(set) int $subscriptionCount,
    ) {
    }
}
