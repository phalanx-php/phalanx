<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb;

use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;

interface SurrealDbTransport
{
    /** @param list<mixed> $params */
    public function rpc(
        Scope&Suspendable $scope,
        SurrealDbConfig $config,
        ?string $token,
        string $method,
        array $params = [],
    ): mixed;

    public function status(Scope&Suspendable $scope, SurrealDbConfig $config, ?string $token): int;

    public function health(Scope&Suspendable $scope, SurrealDbConfig $config, ?string $token): int;
}
