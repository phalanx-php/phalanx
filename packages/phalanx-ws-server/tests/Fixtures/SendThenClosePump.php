<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Fixtures;

use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use Phalanx\WebSocket\WsScope;

/**
 * Test pump: sends a single text frame then closes the connection. Used to
 * verify outbound traffic reaches the transport.
 */
final class SendThenClosePump implements Scopeable
{
    public function __invoke(Scope $scope): void
    {
        assert($scope instanceof WsScope);
        $scope->connection->sendText('hello from server');
        $scope->connection->close();
    }
}
