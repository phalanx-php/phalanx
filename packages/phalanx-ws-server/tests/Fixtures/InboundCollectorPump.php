<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Fixtures;

use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use Phalanx\WebSocket\WsMessage;
use Phalanx\WebSocket\WsScope;

/**
 * Test pump: collects up to 2 inbound text messages into a static array,
 * then completes. Used to verify the WS frame codec and stream wiring deliver
 * messages from the transport to user code.
 */
final class InboundCollectorPump implements Scopeable
{
    /** @var list<string> */
    public static array $received = [];

    public function __invoke(Scope $scope): void
    {
        assert($scope instanceof WsScope);

        $scope->connection->stream($scope)
            ->filter(static fn(WsMessage $m) => $m->isText)
            ->onEach(static function (WsMessage $m): void {
                self::$received[] = $m->payload;
            })
            ->take(2)
            ->consume();
    }
}
