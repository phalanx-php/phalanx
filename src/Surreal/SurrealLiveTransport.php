<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Phalanx\Scope\ExecutionScope;

interface SurrealLiveTransport
{
    public function open(ExecutionScope $scope, SurrealConfig $config, ?string $token): SurrealLiveConnection;
}
