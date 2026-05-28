<?php

declare(strict_types=1);

namespace AegisSwoole;

use AegisSwoole\Cancellation\CancellationToken;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Scope\Scope;
use AegisSwoole\Trace\Trace;

interface AppHost
{
    /** @return list<\AegisSwoole\Service\ServiceBundle> */
    public function providers(): array;

    public function createScope(?CancellationToken $token = null): ExecutionScope;

    public function scope(): Scope;

    public function startup(): static;

    public function shutdown(): void;

    public function trace(): Trace;
}
