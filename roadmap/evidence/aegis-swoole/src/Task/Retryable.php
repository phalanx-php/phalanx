<?php

declare(strict_types=1);

namespace AegisSwoole\Task;

use AegisSwoole\Concurrency\RetryPolicy;

/**
 * Behavioral interface declaring this task wants the retry middleware to wrap
 * its execution. The middleware reads retryPolicy() and routes execution
 * through $scope->retry().
 */
interface Retryable
{
    public function retryPolicy(): RetryPolicy;
}
