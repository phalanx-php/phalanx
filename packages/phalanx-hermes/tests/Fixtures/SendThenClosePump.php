<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Fixtures;

use Phalanx\Task\Scopeable;
use Phalanx\Hermes\WsScope;

final class SendThenClosePump implements Scopeable
{
    public function __invoke(WsScope $scope): void
    {
        $scope->connection->sendText('hello from server');
        $scope->connection->close();
    }
}
