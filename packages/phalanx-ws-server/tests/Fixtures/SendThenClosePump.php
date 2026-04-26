<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Fixtures;

use Phalanx\Task\Scopeable;
use Phalanx\WebSocket\WsScope;

final class SendThenClosePump implements Scopeable
{
    public function __invoke(WsScope $scope): void
    {
        $scope->connection->sendText('hello from server');
        $scope->connection->close();
    }
}
