<?php

declare(strict_types=1);

use Phalanx\Scope;
use Phalanx\WebSocket\WsGateway;
use Phalanx\WebSocket\WsMessage;
use React\Http\Message\Response;

final class DumpReceiver
{
    public function __invoke(Scope $scope): Response
    {
        $request = $scope->attribute('request');
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            return Response::json(['error' => 'Invalid JSON'])->withStatus(400);
        }

        $store = $scope->service(DumpStore::class);
        $gateway = $scope->service(WsGateway::class);

        $channel = $payload['channel'] ?? 'app';
        $entry = $store->push([
            'channel' => $channel,
            'data' => $payload['data'] ?? $payload,
            'file' => $payload['file'] ?? null,
            'line' => $payload['line'] ?? null,
        ]);

        $msg = WsMessage::text(json_encode([
            'type' => 'dump',
            'entry' => $entry,
        ]));

        $gateway->publish("dump:{$channel}", $msg);
        $gateway->publish('dump:*', $msg);

        return Response::json(['ok' => true, 'id' => $entry['id']]);
    }
}
