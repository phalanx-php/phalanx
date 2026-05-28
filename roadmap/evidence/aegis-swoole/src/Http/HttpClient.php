<?php

declare(strict_types=1);

namespace AegisSwoole\Http;

use AegisSwoole\Cancellation\Cancelled;
use AegisSwoole\Scope\Suspendable;
use OpenSwoole\Coroutine\Http\Client;
use RuntimeException;

/**
 * Thin wrapper over OpenSwoole\Coroutine\Http\Client. Exposes a request()
 * surface that returns an HttpResponse value object and routes each call
 * through the injected Suspendable scope so scope-level cancellation
 * propagates to the in-flight HTTP exchange.
 *
 * Connection lifecycle: each request opens, sends, receives, and closes a
 * fresh client. For the POC this is fine; pooling lands in the framework
 * rewrite (phalanx-argos / phalanx-stoa client side).
 */
class HttpClient
{
    public function __construct(private readonly Suspendable $scope)
    {
    }

    /** @param array<string, string> $headers */
    public function get(string $host, int $port, string $path, array $headers = [], float $timeout = 5.0): HttpResponse
    {
        return $this->request('GET', $host, $port, $path, $headers, '', $timeout);
    }

    /** @param array<string, string> $headers */
    public function post(string $host, int $port, string $path, string $body, array $headers = [], float $timeout = 5.0): HttpResponse
    {
        return $this->request('POST', $host, $port, $path, $headers, $body, $timeout);
    }

    /** @param array<string, string> $headers */
    public function request(
        string $method,
        string $host,
        int $port,
        string $path,
        array $headers,
        string $body,
        float $timeout,
    ): HttpResponse {
        return $this->scope->call(static function () use ($method, $host, $port, $path, $headers, $body, $timeout): HttpResponse {
            $client = new Client($host, $port);
            $client->set(['timeout' => $timeout]);
            if ($headers !== []) {
                $client->setHeaders($headers);
            }
            $client->setMethod($method);
            if ($body !== '') {
                $client->setData($body);
            }
            try {
                $ok = $client->execute($path);
                if ($ok === false) {
                    if ((int) $client->errCode === 0 && (int) $client->statusCode === 0) {
                        throw new Cancelled('http request cancelled');
                    }
                    throw new RuntimeException(
                        "http request failed: errCode={$client->errCode} statusCode={$client->statusCode}",
                    );
                }
                return new HttpResponse(
                    statusCode: (int) $client->statusCode,
                    body: (string) $client->body,
                    headers: is_array($client->headers) ? $client->headers : [],
                );
            } finally {
                $client->close();
            }
        });
    }
}
