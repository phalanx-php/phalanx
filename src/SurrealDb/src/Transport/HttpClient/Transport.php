<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Transport\HttpClient;

use JsonException;
use Phalanx\HttpClient\HttpClient;
use Phalanx\HttpClient\HttpRequest;
use Phalanx\HttpClient\HttpResponse;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;
use Swoole\Atomic;

final class Transport implements \Phalanx\SurrealDb\Transport
{
    private Atomic $nextId;

    /** @var array<string, list<string>>|null */
    private ?array $cachedHeaders = null;

    private ?string $cachedToken = null;

    private ?string $cachedConfigKey = null;

    public function __construct(
        private HttpClient $http,
    ) {
        $this->nextId = new Atomic(0);
    }

    public function rpc(
        Scope&Suspendable $scope,
        \Phalanx\SurrealDb\Config $config,
        ?string $token,
        string $method,
        array $params = [],
    ): mixed {
        $id = $this->nextId->add(1);
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
            throw \Phalanx\SurrealDb\Exception::fromErrorEnvelope($data['error']);
        }

        return is_array($data) && array_key_exists('result', $data) ? $data['result'] : $data;
    }

    public function status(Scope&Suspendable $scope, \Phalanx\SurrealDb\Config $config, ?string $token): int
    {
        return $this->head($scope, $config, $token, '/status');
    }

    public function health(Scope&Suspendable $scope, \Phalanx\SurrealDb\Config $config, ?string $token): int
    {
        return $this->head($scope, $config, $token, '/health');
    }

    /** @param array<string, mixed> $payload */
    private static function encodeJson(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new \Phalanx\SurrealDb\Exception("Failed to encode SurrealDb RPC payload: {$e->getMessage()}", previous: $e);
        }
    }

    /** @return array<string, list<string>> */
    private function headers(\Phalanx\SurrealDb\Config $config, ?string $token): array
    {
        $configKey = $config->database . "\0" . $config->namespace . "\0" . ($config->username ?? '') . "\0" . ($config->password ?? '');

        if ($this->cachedHeaders !== null && $this->cachedToken === $token && $this->cachedConfigKey === $configKey) {
            return $this->cachedHeaders;
        }

        $headers = [
            'accept' => ['application/json'],
            'content-type' => ['application/json'],
            'surrealdb-db' => [$config->database],
            'surrealdb-ns' => [$config->namespace],
        ];

        if ($token !== null) {
            $headers['authorization'] = ["Bearer {$token}"];
        } elseif ($config->username !== null && $config->password !== null) {
            $headers['authorization'] = [
                'Basic ' . base64_encode("{$config->username}:{$config->password}"),
            ];
        }

        $this->cachedHeaders = $headers;
        $this->cachedToken = $token;
        $this->cachedConfigKey = $configKey;

        return $headers;
    }

    private function head(Scope&Suspendable $scope, \Phalanx\SurrealDb\Config $config, ?string $token, string $path): int
    {
        $request = new HttpRequest(
            method: 'GET',
            url: $config->endpoint . $path,
            headers: $this->headers($config, $token),
            connectTimeout: $config->connectTimeout,
            readTimeout: $config->readTimeout,
        );

        $response = $this->http->request($scope, $request);

        return $response->status;
    }

    private function decodeResponse(HttpResponse $response, int $expectedId): mixed
    {
        if (!$response->successful) {
            throw new \Phalanx\SurrealDb\Exception("SurrealDb HTTP request failed with status {$response->status}.");
        }

        if ($response->body === '') {
            return null;
        }

        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \Phalanx\SurrealDb\Exception("Failed to decode SurrealDb response: {$e->getMessage()}", previous: $e);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new \Phalanx\SurrealDb\Exception('SurrealDb RPC response was not a JSON object.');
        }

        if (array_key_exists('id', $data) && (int) $data['id'] !== $expectedId) {
            throw new \Phalanx\SurrealDb\Exception("SurrealDb RPC response id mismatch: expected {$expectedId}.");
        }

        if (!array_key_exists('result', $data) && !array_key_exists('error', $data)) {
            throw new \Phalanx\SurrealDb\Exception('SurrealDb RPC response was missing result or error.');
        }

        return $data;
    }
}
