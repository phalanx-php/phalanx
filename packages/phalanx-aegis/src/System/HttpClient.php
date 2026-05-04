<?php

declare(strict_types=1);

namespace Phalanx\System;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\Suspendable;
use Throwable;

/**
 * Aegis-managed HTTP/1.1 client primitive.
 *
 * Backed by {@see TcpClient} (TLS-aware) so cancellation, wait reasons,
 * and lifecycle stay visible through Phalanx scope discipline. HTTP/1.1
 * with chunked transfer encoding is the canonical streaming path: every
 * LLM provider Athena targets accepts it (Anthropic / OpenAI / Gemini /
 * Ollama), the wire format is parser-friendly, and unlike OpenSwoole's
 * `Coroutine\Http2\Client` the per-frame body delivery actually works.
 *
 * One client → one connection → one request. Connection reuse (HTTP
 * keep-alive across requests) is intentionally out of scope; pool that
 * one level up if you need it.
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
        $stream = $this->stream($scope, $request);
        try {
            $body = '';
            while (!$stream->eof) {
                $chunk = $stream->read($scope);
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
            return new HttpResponse(
                status: $stream->status,
                headers: $stream->headers,
                body: $body,
            );
        } finally {
            $stream->close();
        }
    }

    public function stream(Suspendable $scope, HttpRequest $request): HttpStream
    {
        $tcp = $this->openTcp($scope);
        $payload = $this->encodeRequest($request);
        $waitDetail = "{$request->method} {$request->path}";

        try {
            $tcp->send($scope, $payload, $this->defaultTimeout);
        } catch (Cancelled $e) {
            $tcp->close();
            throw $e;
        } catch (Throwable $e) {
            $tcp->close();
            throw HttpException::sendFailed($request->method, $request->path, 0, $e->getMessage());
        }

        return new HttpStream($tcp, $waitDetail);
    }

    private function openTcp(Suspendable $scope): TcpClient
    {
        $tlsOptions = $this->tlsEnabled
            ? ($this->tlsOptions ?? new TlsOptions(verifyPeer: true, hostName: $this->host))
            : null;

        $tcp = new TcpClient(tls: $this->tlsEnabled, tlsOptions: $tlsOptions);

        $connected = $tcp->connect($scope, $this->host, $this->port, $this->defaultTimeout);
        if (!$connected) {
            $tcp->close();
            throw HttpException::connectFailed($this->host, $this->port, 0, 'connect returned false');
        }
        return $tcp;
    }

    private function encodeRequest(HttpRequest $request): string
    {
        $headers = $this->normalizeHeaders($request);
        $lines = ["{$request->method} {$request->path} HTTP/1.1"];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        return implode("\r\n", $lines) . "\r\n\r\n" . $request->body;
    }

    /** @return array<string, string> */
    private function normalizeHeaders(HttpRequest $request): array
    {
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }
        $headers['host'] ??= $this->hostHeader();
        $headers['user-agent'] ??= 'phalanx-aegis/0.5';
        $headers['accept'] ??= '*/*';
        $headers['connection'] ??= 'close';
        if ($request->body !== '' && !isset($headers['content-length'])) {
            $headers['content-length'] = (string) strlen($request->body);
        }
        return $headers;
    }

    private function hostHeader(): string
    {
        $defaultPort = $this->tlsEnabled ? 443 : 80;
        return $this->port === $defaultPort ? $this->host : "{$this->host}:{$this->port}";
    }
}
