<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use Phalanx\Runtime\Memory\RuntimeMemory;

class RuntimeContext
{
    public private(set) RuntimeMemory $memory;

    public private(set) QueryScope $query;

    public function __construct(
        RuntimeMemory $memory,
    ) {
        $this->memory = $memory;
        $this->query = new QueryScope($memory);
    }
}
