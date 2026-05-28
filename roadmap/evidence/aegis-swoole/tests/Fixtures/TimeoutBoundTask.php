<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Task\Executable;
use AegisSwoole\Task\HasTimeout;

class TimeoutBoundTask implements Executable, HasTimeout
{
    public function __construct(
        private readonly float $timeout,
        private readonly float $sleep,
    ) {
    }

    public function timeoutSeconds(): float
    {
        return $this->timeout;
    }

    public function __invoke(ExecutionScope $scope): string
    {
        $scope->delay($this->sleep);
        return 'done';
    }
}
