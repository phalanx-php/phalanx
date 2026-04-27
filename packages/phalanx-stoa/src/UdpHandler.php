<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\ExecutionScope;

/**
 * Invokable handler for incoming UDP datagrams.
 *
 * Argument order: scope first (consistent with every other Phalanx invokable),
 * then the datagram payload, then the sender address.
 */
interface UdpHandler
{
    public function __invoke(ExecutionScope $scope, string $data, string $remote): void;
}
