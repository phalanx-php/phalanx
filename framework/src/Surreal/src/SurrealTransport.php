<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;

interface SurrealTransport
{
    /** @param list<mixed> $params */
    public function rpc(
        Scope&Suspendable $scope,
        SurrealConfig $config,
        ?string $token,
        string $method,
        array $params = [],
    ): mixed;

    public function status(Scope&Suspendable $scope, SurrealConfig $config, ?string $token): int;

    public function health(Scope&Suspendable $scope, SurrealConfig $config, ?string $token): int;
}
