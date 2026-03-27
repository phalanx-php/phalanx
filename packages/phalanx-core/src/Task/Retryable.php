<?php

declare(strict_types=1);

namespace Phalanx\Task;

use Phalanx\Concurrency\RetryPolicy;

interface Retryable
{
    public RetryPolicy $retryPolicy { get; }
}
