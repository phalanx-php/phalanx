<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use Phalanx\Runtime\Memory\RuntimeMemory;

class RuntimeContext
{
    private(set) RuntimeMemory $memory;

    private(set) QueryScope $query;

    public function __construct(
        RuntimeMemory $memory,
    ) {
        $this->memory = $memory;
        $this->query = new QueryScope($memory);
    }
}
