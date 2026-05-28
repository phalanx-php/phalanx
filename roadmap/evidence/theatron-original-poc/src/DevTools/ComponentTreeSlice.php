<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

use Phalanx\Theatron\Store\Slice;

final class ComponentTreeSlice implements Slice
{
    public string $key { get => 'theatron.devtools.tree'; }

    /** @param list<ComponentTreeNode> $nodes */
    public function __construct(
        private(set) array $nodes = [],
    ) {
    }

    /** @param list<ComponentTreeNode> $nodes */
    public function withNodes(array $nodes): self
    {
        return new self($nodes);
    }
}
