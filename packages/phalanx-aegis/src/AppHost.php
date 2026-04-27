<?php

declare(strict_types=1);

namespace Phalanx;

use Phalanx\Concurrency\CancellationToken;
use Phalanx\Service\ServiceBundle;
use Phalanx\Trace\Trace;

interface AppHost
{
    /** @return list<ServiceBundle> */
    public function providers(): array;

    public function createScope(?CancellationToken $token = null): ExecutionScope;

    public function startup(): static;

    /** @return array{0: static, 1: \Phalanx\ExecutionScope} */
    public function boot(?CancellationToken $token = null): array;

    public function shutdown(): void;

    public function trace(): Trace;
}
