<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use JsonException;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpRequest;
use Phalanx\Iris\HttpResponse;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\WaitReason;

final class IrisSurrealTransport implements SurrealTransport
{
    private int $nextId = 1;

    public function __construct(
        private readonly HttpClient $http,
    ) {
    }

    public function rpc(
        ExecutionScope $scope,
        SurrealConfig $config,
        ?string $token,
        string $method,
        array $params = [],
    ): mixed {
        $id = $this->nextId++;
        $body = self::encodeJson([
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);

        $http = $this->http;
        $request = new HttpRequest(
            method: 'POST',
            url: $config->endpoint . '/rpc',
            headers: $this->headers($config, $token),
            body: $body,
            connectTimeout: $config->connectTimeout,
            readTimeout: $config->readTimeout,
        );

        $response = $scope->call(
            static fn(): HttpResponse => $http->request($scope, $request),
            WaitReason::surreal($method),
        );

        $data = $this->decodeResponse($response);
        if (is_array($data) && array_key_exists('error', $data)) {
            throw SurrealException::fromErrorEnvelope($data['error']);
        }

        return is_array($data) && array_key_exists('result', $data) ? $data['result'] : $data;
    }

    public function status(ExecutionScope $scope, SurrealConfig $config, ?string $token): int
    {
        return $this->head($scope, $config, $token, '/status');
    }

    public function health(ExecutionScope $scope, SurrealConfig $config, ?string $token): int
    {
        return $this->head($scope, $config, $token, '/health');
    }

    /** @param array<string, mixed> $payload */
    private static function encodeJson(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SurrealException("Failed to encode Surreal RPC payload: {$e->getMessage()}", previous: $e);
        }
    }

    /** @return array<string, list<string>> */
    private function headers(SurrealConfig $config, ?string $token): array
    {
        $headers = [
            'accept' => ['application/json'],
            'content-type' => ['application/json'],
            'surreal-db' => [$config->database],
            'surreal-ns' => [$config->namespace],
        ];

        if ($token !== null) {
            $headers['authorization'] = ["Bearer {$token}"];
        } elseif ($config->username !== null && $config->password !== null) {
            $headers['authorization'] = [
                'Basic ' . base64_encode("{$config->username}:{$config->password}"),
            ];
        }

        return $headers;
    }

    private function head(ExecutionScope $scope, SurrealConfig $config, ?string $token, string $path): int
    {
        $http = $this->http;
        $request = new HttpRequest(
            method: 'GET',
            url: $config->endpoint . $path,
            headers: $this->headers($config, $token),
            connectTimeout: $config->connectTimeout,
            readTimeout: $config->readTimeout,
        );

        $response = $scope->call(
            static fn(): HttpResponse => $http->request($scope, $request),
            WaitReason::surreal(ltrim($path, '/')),
        );

        return $response->status;
    }

    private function decodeResponse(HttpResponse $response): mixed
    {
        if (!$response->successful) {
            throw new SurrealException("Surreal HTTP request failed with status {$response->status}.");
        }

        if ($response->body === '') {
            return null;
        }

        try {
            return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SurrealException("Failed to decode Surreal response: {$e->getMessage()}", previous: $e);
        }
    }
}
