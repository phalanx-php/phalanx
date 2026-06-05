<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Live;

use Phalanx\Scope\ExecutionScope;

interface Transport
{
    public function open(ExecutionScope $scope, \Phalanx\SurrealDb\Config $config, ?string $token): \Phalanx\SurrealDb\Live\Connection;
}
