<?php

declare(strict_types=1);

use Phalanx\Task\Scopeable;
use Phalanx\WebSocket\WsGateway;
use Phalanx\WebSocket\WsScope;

final class DumpStream implements Scopeable
{
    public function __invoke(WsScope $scope): void
    {
        $ws = $scope;

        $conn = $ws->connection;
        $store = $ws->service(DumpStore::class);
        $gateway = $ws->service(WsGateway::class);

        $gateway->subscribe($conn, "dump:*");

        $backlog = $store->recent(50);
        if ($backlog !== []) {
            $conn->sendText(
                json_encode([
                    "type" => "backlog",
                    "entries" => $backlog,
                ]),
            );
        }

        $conn
            ->stream($ws)
            ->filter(static fn($msg) => $msg->isText)
            ->map(static fn($msg) => $msg->decode())
            ->onEach(static function (array $cmd) use ($conn, $gateway): void {
                $action = $cmd["action"] ?? null;
                $channel = $cmd["channel"] ?? null;

                if ($action === "subscribe" && $channel !== null) {
                    $gateway->subscribe($conn, "dump:{$channel}");
                }

                if ($action === "unsubscribe" && $channel !== null) {
                    $gateway->unsubscribe($conn, "dump:{$channel}");
                }
            })
            ->onComplete(static function () use ($conn, $gateway): void {
                $gateway->unregister($conn);
            })
            ->consume();
    }
}
