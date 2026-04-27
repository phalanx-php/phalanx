<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Fixtures;

use Phalanx\Task\Scopeable;
use Phalanx\Hermes\WsScope;

final class ImmediateClosePump implements Scopeable
{
    public function __invoke(WsScope $scope): void
    {
        $scope->connection->close();
    }
}
