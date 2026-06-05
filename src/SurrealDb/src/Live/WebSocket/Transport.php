<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Live\WebSocket;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\WebSocket\Client\WsClient;
use Throwable;

class Transport implements \Phalanx\SurrealDb\Live\Transport
{
    public function __construct(
        private readonly WsClient $client,
    ) {
    }

    public function open(ExecutionScope $scope, \Phalanx\SurrealDb\Config $config, ?string $token): \Phalanx\SurrealDb\Live\Connection
    {
        if ($config->websocketEndpoint === null) {
            throw new \Phalanx\SurrealDb\Exception('SurrealDb websocket endpoint is not configured.');
        }

        $connection = new \Phalanx\SurrealDb\Live\WebSocket\Connection(
            scope: $scope,
            socket: new \Phalanx\SurrealDb\Live\WebSocket\Socket($this->client->connect($scope, $config->websocketEndpoint)),
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
