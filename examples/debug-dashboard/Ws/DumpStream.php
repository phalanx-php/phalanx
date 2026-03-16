<?php

declare(strict_types=1);

use Convoy\WebSocket\WsGateway;
use Convoy\WebSocket\WsRoute;
use Convoy\WebSocket\WsScope;

final class DumpStream
{
    public static function route(): WsRoute
    {
        return new WsRoute(static function (WsScope $ws): void {
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
                ->map(static fn($msg) => $msg->json())
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
        });
    }
}
