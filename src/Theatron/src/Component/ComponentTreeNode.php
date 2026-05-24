<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Component;

final class ComponentTreeNode
{
    public function __construct(
        private(set) string $class,
        private(set) int $signalCount,
        private(set) int $subscriptionCount,
    ) {
    }
}
