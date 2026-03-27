<?php

declare(strict_types=1);

use Phalanx\Stream\Emitter;
use Phalanx\Stream\ScopedStream;
use Phalanx\WebSocket\WsGateway;
use Phalanx\WebSocket\WsRoute;
use Phalanx\WebSocket\WsScope;

final class MetricsStream
{
    public static function route(): WsRoute
    {
        return new WsRoute(static function (WsScope $ws): void {
            $conn = $ws->connection;
            $store = $ws->service(DumpStore::class);
            $gateway = $ws->service(WsGateway::class);

            $memory = Emitter::interval(1.0)->map(
                static fn() => [
                    "metric" => "memory",
                    "value" => memory_get_usage(true),
                    "peak" => memory_get_peak_usage(true),
                ],
            );

            $dumps = Emitter::interval(2.0)->map(
                static fn() => [
                    "metric" => "dumps",
                    "value" => $store->count(),
                ],
            );

            $connections = Emitter::interval(3.0)->map(
                static fn() => [
                    "metric" => "connections",
                    "value" => $gateway->count(),
                ],
            );

            // Merge all sources, buffer into batches, send as JSON
            ScopedStream::from($ws, $memory->merge($dumps, $connections))
                ->bufferWindow(5, 2.0)
                ->map(
                    static fn(array $batch) => json_encode([
                        "type" => "metrics",
                        "batch" => $batch,
                    ]),
                )
                ->onEach(static fn(string $json) => $conn->sendText($json))
                ->onError(static function (\Throwable $e): void {
                    fprintf(STDERR, "MetricsStream error: %s\n", $e->getMessage());
                })
                ->consume();

            // Alternative: $ws->concurrent() for parallel metric gathering
            //
            // $ws->concurrent([
            //     'memory'  => Task::of(static fn($s) => /* gather memory stats */),
            //     'dumps'   => Task::of(static fn($s) => /* gather dump counts */),
            //     'conns'   => Task::of(static fn($s) => /* gather connection info */),
            // ]);
        });
    }
}
