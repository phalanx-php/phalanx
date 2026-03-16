<?php

declare(strict_types=1);

namespace Convoy;

use Convoy\Concurrency\CancellationToken;
use Convoy\Service\ServiceBundle;
use Convoy\Trace\Trace;

interface AppHost
{
    /** @return list<ServiceBundle> */
    public function providers(): array;

    public function createScope(?CancellationToken $token = null): ExecutionScope;

    public function startup(): void;

    public function shutdown(): void;

    public function trace(): Trace;
}
