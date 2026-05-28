<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Task\Executable;
use AegisSwoole\Task\Traceable;

class TraceableTask implements Executable, Traceable
{
    public function __construct(public readonly string $name)
    {
    }

    public function traceName(): string
    {
        return $this->name;
    }

    public function __invoke(ExecutionScope $scope): string
    {
        return $this->name;
    }
}
