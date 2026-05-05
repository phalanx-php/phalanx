<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use Phalanx\Runtime\Memory\RuntimeMemory;

final readonly class RuntimeContext
{
    public QueryScope $query;

    public function __construct(public RuntimeMemory $memory)
    {
        $this->query = new QueryScope($memory);
    }
}
