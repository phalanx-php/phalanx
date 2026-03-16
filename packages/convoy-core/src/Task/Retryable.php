<?php

declare(strict_types=1);

namespace Convoy\Task;

use Convoy\Concurrency\RetryPolicy;

interface Retryable
{
    public RetryPolicy $retryPolicy { get; }
}
