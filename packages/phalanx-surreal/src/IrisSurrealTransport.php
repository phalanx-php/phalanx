<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use JsonException;
use OpenSwoole\Atomic;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpRequest;
use Phalanx\Iris\HttpResponse;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;

class IrisSurrealTransport implements SurrealTransport
{
    private Atomic $nextId;

    public function __construct(
        private readonly HttpClient $http,
    ) {
        $this->nextId = new Atomic(0);
    }

    public function rpc(
        Scope&Suspendable $scope,
        SurrealConfig $config,
        ?string $token,
        string $method,
        array $params = [],
    ): mixed {
        $next = $this->nextId->add(1);
        if (!is_int($next)) {
            throw new SurrealException('Atomic RPC counter overflow.');
        }
        $id = $next;
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

        $response = $http->request($scope, $request);

        $data = $this->decodeResponse($response, $id);
        if (is_array($data) && array_key_exists('error', $data)) {
            throw SurrealException::fromErrorEnvelope($data['error']);
        }

        return is_array($data) && array_key_exists('result', $data) ? $data['result'] : $data;
    }

    public function status(Scope&Suspendable $scope, SurrealConfig $config, ?string $token): int
    {
        return $this->head($scope, $config, $token, '/status');
    }

    public function health(Scope&Suspendable $scope, SurrealConfig $config, ?string $token): int
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

    private function head(Scope&Suspendable $scope, SurrealConfig $config, ?string $token, string $path): int
    {
        $http = $this->http;
        $request = new HttpRequest(
            method: 'GET',
            url: $config->endpoint . $path,
            headers: $this->headers($config, $token),
            connectTimeout: $config->connectTimeout,
            readTimeout: $config->readTimeout,
        );

        $response = $http->request($scope, $request);

        return $response->status;
    }

    private function decodeResponse(HttpResponse $response, int $expectedId): mixed
    {
        if (!$response->successful) {
            throw new SurrealException("Surreal HTTP request failed with status {$response->status}.");
        }

        if ($response->body === '') {
            return null;
        }

        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SurrealException("Failed to decode Surreal response: {$e->getMessage()}", previous: $e);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new SurrealException('Surreal RPC response was not a JSON object.');
        }

        if (array_key_exists('id', $data) && (int) $data['id'] !== $expectedId) {
            throw new SurrealException("Surreal RPC response id mismatch: expected {$expectedId}.");
        }

        if (!array_key_exists('result', $data) && !array_key_exists('error', $data)) {
            throw new SurrealException('Surreal RPC response was missing result or error.');
        }

        return $data;
    }
}
