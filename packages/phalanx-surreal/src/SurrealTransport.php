<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Phalanx\Scope\ExecutionScope;

interface SurrealTransport
{
    /** @param list<mixed> $params */
    public function rpc(
        ExecutionScope $scope,
        SurrealConfig $config,
        ?string $token,
        string $method,
        array $params = [],
    ): mixed;

    public function status(ExecutionScope $scope, SurrealConfig $config, ?string $token): int;

    public function health(ExecutionScope $scope, SurrealConfig $config, ?string $token): int;
}
