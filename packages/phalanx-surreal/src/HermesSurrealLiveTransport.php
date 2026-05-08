<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Hermes\Client\WsClient;
use Phalanx\Scope\ExecutionScope;
use Throwable;

class HermesSurrealLiveTransport implements SurrealLiveTransport
{
    public function __construct(
        private readonly WsClient $client,
    ) {
    }

    public function open(ExecutionScope $scope, SurrealConfig $config, ?string $token): SurrealLiveConnection
    {
        if ($config->websocketEndpoint === null) {
            throw new SurrealException('Surreal websocket endpoint is not configured.');
        }

        $connection = new HermesSurrealLiveConnection(
            scope: $scope,
            socket: new HermesSurrealLiveSocket($this->client->connect($scope, $config->websocketEndpoint)),
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
