<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb;

use Phalanx\Cancellation\Cancelled;
use Phalanx\WebSocket\Client\WsClient;
use Phalanx\Scope\ExecutionScope;
use Throwable;

class WebSocketSurrealDbLiveTransport implements SurrealDbLiveTransport
{
    public function __construct(
        private readonly WsClient $client,
    ) {
    }

    public function open(ExecutionScope $scope, SurrealDbConfig $config, ?string $token): SurrealDbLiveConnection
    {
        if ($config->websocketEndpoint === null) {
            throw new SurrealDbException('SurrealDb websocket endpoint is not configured.');
        }

        $connection = new WebSocketSurrealDbLiveConnection(
            scope: $scope,
            socket: new WebSocketSurrealDbLiveSocket($this->client->connect($scope, $config->websocketEndpoint)),
            requestTimeout: $config->readTimeout,
        );

        try {
            if ($token !== null) {
                $connection->request('authenticate', [$token]);
            } elseif ($config->username !== null && $config->password !== null) {
                $connection->request('signin', [[
                    'user' => $config->username,
                    'pass' => $config->password,
                ]]);
            }

            $connection->request('use', [$config->namespace, $config->database]);
        } catch (Cancelled $e) {
            $connection->close();

            throw $e;
        } catch (Throwable $e) {
            $connection->close();

            throw $e;
        }

        return $connection;
    }
}
