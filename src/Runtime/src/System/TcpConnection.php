<?php

declare(strict_types=1);

namespace Phalanx\System;

use Phalanx\Scope\Suspendable;

interface TcpConnection
{
    public function connect(Suspendable $scope, string $host, int $port, float $timeout = 1.0): bool;

    public function send(Suspendable $scope, string $payload, float $timeout = 1.0): int;

    public function recv(Suspendable $scope, float $timeout = 1.0): ?string;

    public function close(): void;
}
