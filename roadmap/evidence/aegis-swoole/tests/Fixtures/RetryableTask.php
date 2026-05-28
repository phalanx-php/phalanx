<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

use AegisSwoole\Concurrency\RetryPolicy;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Task\Executable;
use AegisSwoole\Task\Retryable;
use RuntimeException;

class RetryableTask implements Executable, Retryable
{
    public int $attempts = 0;

    public function __construct(
        private readonly int $failUntilAttempt,
        private readonly RetryPolicy $policy,
    ) {
    }

    public function retryPolicy(): RetryPolicy
    {
        return $this->policy;
    }

    public function __invoke(ExecutionScope $scope): int
    {
        $this->attempts++;
        if ($this->attempts < $this->failUntilAttempt) {
            throw new RuntimeException("attempt {$this->attempts} failed");
        }
        return $this->attempts;
    }
}
