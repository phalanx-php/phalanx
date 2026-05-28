<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

use AegisSwoole\Postgres\PostgresPool;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Task\Executable;
use AegisSwoole\Task\HasTimeout;

class SlowQueryTimeoutTask implements Executable, HasTimeout
{
    public function __construct(
        private readonly float $timeout,
        private readonly float $sleepSeconds,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(ExecutionScope $scope): array
    {
        $pg = $scope->service(PostgresPool::class);
        return $pg->query('SELECT pg_sleep($1::float) AS slept', [(string) $this->sleepSeconds]);
    }

    public function timeoutSeconds(): float
    {
        return $this->timeout;
    }
}
