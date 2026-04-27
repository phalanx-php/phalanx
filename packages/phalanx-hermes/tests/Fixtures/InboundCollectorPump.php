<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Fixtures;

use Phalanx\Task\Scopeable;
use Phalanx\Hermes\WsMessage;
use Phalanx\Hermes\WsScope;

final class InboundCollectorPump implements Scopeable
{
    /** @var list<string> */
    public static array $received = [];

    public function __invoke(WsScope $scope): void
    {
        $scope->connection->stream($scope)
            ->filter(static fn(WsMessage $m) => $m->isText)
            ->onEach(static function (WsMessage $m): void {
                self::$received[] = $m->payload;
            })
            ->take(2)
            ->consume();
    }
}
