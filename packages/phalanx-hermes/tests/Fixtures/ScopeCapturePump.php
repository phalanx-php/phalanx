<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Fixtures;

use Phalanx\Task\Scopeable;
use Phalanx\Hermes\WsScope;

final class ScopeCapturePump implements Scopeable
{
    public static ?WsScope $captured = null;

    public function __invoke(WsScope $scope): void
    {
        self::$captured = $scope;
        $scope->connection->close();
    }
}
