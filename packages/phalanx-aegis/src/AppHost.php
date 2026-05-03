<?php

declare(strict_types=1);

namespace Phalanx;

use Closure;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;

interface AppHost
{
    /** @return list<\Phalanx\Service\ServiceBundle> */
    public function providers(): array;

    public function supervisor(): Supervisor;

    public function createScope(?CancellationToken $token = null): ExecutionScope;

    public function scope(): Scope;

    public function run(Scopeable|Executable|Closure $task, ?CancellationToken $token = null): mixed;

    public function scoped(Scopeable|Executable|Closure $task, ?CancellationToken $token = null): mixed;

    public function startup(): static;

    public function shutdown(): void;

    public function trace(): Trace;
}
