<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb;

use Phalanx\Scope\ExecutionScope;

interface SurrealDbLiveTransport
{
    public function open(ExecutionScope $scope, SurrealDbConfig $config, ?string $token): SurrealDbLiveConnection;
}
