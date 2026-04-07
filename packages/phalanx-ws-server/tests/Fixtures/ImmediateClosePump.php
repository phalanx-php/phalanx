<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Fixtures;

use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use Phalanx\WebSocket\WsScope;

/**
 * Test pump: closes the connection immediately. Used by gateway lifecycle
 * tests that just need a registered connection.
 */
final class ImmediateClosePump implements Scopeable
{
    public function __invoke(Scope $scope): void
    {
        assert($scope instanceof WsScope);
        $scope->connection->close();
    }
}
