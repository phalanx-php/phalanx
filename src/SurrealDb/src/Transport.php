<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb;

use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;

interface Transport
{
    /** @param list<mixed> $params */
    public function rpc(
        Scope&Suspendable $scope,
        \Phalanx\SurrealDb\Config $config,
        ?string $token,
        string $method,
        array $params = [],
    ): mixed;

    public function status(Scope&Suspendable $scope, \Phalanx\SurrealDb\Config $config, ?string $token): int;

    public function health(Scope&Suspendable $scope, \Phalanx\SurrealDb\Config $config, ?string $token): int;
}
