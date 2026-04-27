<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Fixtures;

use Phalanx\Task\Scopeable;
use Phalanx\Hermes\WsScope;

final class DrainAndFlagPump implements Scopeable
{
    public static bool $completed = false;

    public function __invoke(WsScope $scope): void
    {
        $scope->connection->stream($scope)->consume();
        self::$completed = true;
    }
}
