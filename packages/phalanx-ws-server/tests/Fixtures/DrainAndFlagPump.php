<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Fixtures;

use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use Phalanx\WebSocket\WsScope;

/**
 * Test pump: consumes the inbound stream to completion, then sets a static
 * flag. Used to verify transport close completes the inbound channel and
 * the pump returns naturally.
 */
final class DrainAndFlagPump implements Scopeable
{
    public static bool $completed = false;

    public function __invoke(Scope $scope): void
    {
        assert($scope instanceof WsScope);
        $scope->connection->stream($scope)->consume();
        self::$completed = true;
    }
}
