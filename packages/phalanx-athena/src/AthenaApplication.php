<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Closure;
use Phalanx\AppHost;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;

final class AthenaApplication implements AppHost
{
    public function __construct(
        private readonly AppHost $host,
    ) {
    }

    public function aegis(): AppHost
    {
        return $this->host;
    }

    public function host(): AppHost
    {
        return $this->host;
    }

    public function providers(): array
    {
        return $this->host->providers();
    }

    public function supervisor(): Supervisor
    {
        return $this->host->supervisor();
    }

    public function runtime(): RuntimeContext
    {
        return $this->host->runtime();
    }

    public function createScope(?CancellationToken $token = null): ExecutionScope
    {
        return $this->host->createScope($token);
    }

    public function scope(): Scope
    {
        return $this->host->scope();
    }

    public function run(Scopeable|Executable|Closure $task, ?CancellationToken $token = null): mixed
    {
        return $this->host->run($task, $token);
    }

    public function scoped(Scopeable|Executable|Closure $task, ?CancellationToken $token = null): mixed
    {
        return $this->host->scoped($task, $token);
    }

    public function startup(): static
    {
        $this->host->startup();

        return $this;
    }

    public function shutdown(): void
    {
        $this->host->shutdown();
    }

    public function trace(): Trace
    {
        return $this->host->trace();
    }
}
