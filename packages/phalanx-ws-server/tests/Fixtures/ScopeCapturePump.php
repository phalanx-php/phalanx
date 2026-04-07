<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Fixtures;

use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use Phalanx\WebSocket\WsScope;

/**
 * Test pump: captures the WsScope into a static slot for inspection, then
 * closes the connection. Used to verify the typed scope wraps the right
 * connection, request, params, and config.
 */
final class ScopeCapturePump implements Scopeable
{
    public static ?WsScope $captured = null;

    public function __invoke(Scope $scope): void
    {
        assert($scope instanceof WsScope);
        self::$captured = $scope;
        $scope->connection->close();
    }
}
