<?php

declare(strict_types=1);

namespace Phalanx\System;

use OpenSwoole\Coroutine\Http2\Client as Http2Client;
use OpenSwoole\Http2\Request as Http2Request;
use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\WaitReason;

/**
 * Aegis-managed HTTP/2 client primitive.
 *
 * Backed by `OpenSwoole\Coroutine\Http2\Client`. HTTP/2 is the lingua
 * franca of the upstream LLM APIs Athena talks to (Anthropic, OpenAI,
 * Gemini); Ollama also serves HTTP/2. Using HTTP/2 across the board
 * gives true streaming semantics — each DATA frame can be consumed as
 * it arrives via {@see HttpStream::read()} — without rolling our own
 * HTTP/1.1 chunked parser.
 *
 * One client instance corresponds to one TCP+TLS connection to the
 * configured host:port. The {@see send()} method is for one-shot
 * request/response cycles; {@see stream()} returns an HttpStream that
 * the caller drains incrementally and must close.
 *
 * The blocking surface — `connect()`, `send()`, `read()` on the
 * underlying client — flows through `$scope->call(...)` so cancellation
 * propagates and the supervisor records typed waits via
 * {@see WaitReason::http()}.
 *
 * Connection pooling is intentionally out of scope here: each call to
 * `send()` or `stream()` opens a fresh connection. Long-lived clients
 * that reuse the same Http2Client across many requests are an opt-in
 * pattern — instantiate once with `keepAlive: true` and the client
 * object survives across calls until `close()`.
 */
final class HttpClient
{
    public bool $tls {
        get => $this->tlsEnabled;
    }

    private readonly bool $tlsEnabled;

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        bool $tls = false,
        private readonly ?TlsOptions $tlsOptions = null,
        private readonly float $defaultTimeout = 30.0,
    ) {
        $this->tlsEnabled = $tls;
    }

    public function send(Suspendable $scope, HttpRequest $request): HttpResponse
    {
        $client = $this->buildClient();
        $this->connectClient($scope, $client);

        try {
            $req = $this->buildHttp2Request($request, pipeline: false);
            $waitReason = WaitReason::http($request->method, $this->urlFor($request->path));
            $sent = $scope->call(
                static fn(): mixed => $client->send($req),
                $waitReason,
            );
            if ($sent === false) {
                throw HttpException::sendFailed(
                    $request->method,
                    $request->path,
                    $client->errCode,
                    (string) $client->errMsg,
                );
            }

            $response = $scope->call(
                static fn(): mixed => $client->recv(),
                WaitReason::custom("http.send.recv {$request->method} {$request->path}"),
            );

            if (!is_object($response)) {
                throw HttpException::recvFailed($client->errCode, (string) $client->errMsg);
            }

            return new HttpResponse(
                status: (int) ($response->statusCode ?? 0),
                headers: is_array($response->headers) ? $response->headers : [],
                body: (string) ($response->data ?? ''),
            );
        } finally {
            $client->close();
        }
    }

    public function stream(Suspendable $scope, HttpRequest $request): HttpStream
    {
        $client = $this->buildClient();
        $this->connectClient($scope, $client);

        $req = $this->buildHttp2Request($request, pipeline: true);
        $waitReason = WaitReason::http($request->method, $this->urlFor($request->path));
        $sent = $scope->call(
            static fn(): mixed => $client->send($req),
            $waitReason,
        );
        if ($sent === false) {
            $errCode = $client->errCode;
            $errMsg = (string) $client->errMsg;
            $client->close();
            throw HttpException::sendFailed($request->method, $request->path, $errCode, $errMsg);
        }

        $streamId = is_int($sent) ? $sent : 0;
        return new HttpStream($client, $streamId, "{$request->method} {$request->path}");
    }

    private function buildClient(): Http2Client
    {
        $client = new Http2Client($this->host, $this->port, $this->tlsEnabled);
        $settings = ['timeout' => $this->defaultTimeout];
        if ($this->tlsEnabled) {
            // SNI + cert hostname verification target. Without this OpenSwoole's
            // Http2 client completes the TLS handshake but cannot read response
            // frames — the upstream server requires SNI for HTTP/2 routing.
            $settings['ssl_host_name'] = $this->host;
            if ($this->tlsOptions !== null) {
                $settings = array_merge($settings, $this->tlsOptions->toClientOptions());
            }
        }
        $client->set($settings);
        return $client;
    }

    private function connectClient(Suspendable $scope, Http2Client $client): void
    {
        $host = $this->host;
        $port = $this->port;
        $connected = $scope->call(
            static fn(): mixed => $client->connect(),
            WaitReason::custom("http.connect {$host}:{$port}"),
        );
        if ($connected !== true) {
            $errCode = $client->errCode;
            $errMsg = (string) $client->errMsg;
            $client->close();
            throw HttpException::connectFailed($host, $port, $errCode, $errMsg);
        }
    }

    private function buildHttp2Request(HttpRequest $request, bool $pipeline): Http2Request
    {
        $req = new Http2Request();
        $req->method = $request->method;
        $req->path = $request->path;
        $req->data = $request->body;
        $req->pipeline = $pipeline;

        $headers = [
            ':authority' => $this->host,
            'host' => $this->host,
        ];
        foreach ($request->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }
        $req->headers = $headers;

        return $req;
    }

    private function urlFor(string $path): string
    {
        $scheme = $this->tlsEnabled ? 'https' : 'http';
        return "{$scheme}://{$this->host}:{$this->port}{$path}";
    }
}
