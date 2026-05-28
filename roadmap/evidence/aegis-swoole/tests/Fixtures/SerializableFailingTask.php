<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Task\Executable;
use RuntimeException;

class SerializableFailingTask implements Executable
{
    public function __construct(public readonly string $reason)
    {
    }

    public function __invoke(ExecutionScope $scope): never
    {
        throw new RuntimeException("worker-side failure: {$this->reason}");
    }
}
