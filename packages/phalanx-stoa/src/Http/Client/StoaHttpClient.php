<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Http\Client;

use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\Suspendable;
use Phalanx\Stoa\Http\Client\Wire\HttpRequestEncoder;
use Phalanx\Stoa\Http\Client\Wire\HttpResponseDecoder;
use Phalanx\Stoa\Runtime\Identity\StoaResourceSid;
use Phalanx\System\DnsResolver;
use Phalanx\System\TcpClient;
use Phalanx\System\TlsOptions;

/**
 * Native outbound HTTP/1.1 client.
 *
 * Each request opens a Stoa OutboundHttpRequest managed resource so the
 * supervisor and diagnostics surface see in-flight calls, resolves DNS
 * through the Aegis DnsResolver, opens a TcpClient (TLS or plain)
 * scoped to a single request/response cycle, encodes the request,
 * recv-loops the response, and decodes the wire payload.
 *
 * No keep-alive in this round — Round I introduces the connection pool.
 */
final class StoaHttpClient
{
    public function __construct(
        private readonly RuntimeContext $runtime,
        private readonly StoaHttpClientConfig $config = new StoaHttpClientConfig(),
        private readonly DnsResolver $dns = new DnsResolver(),
    ) {
    }

    public function get(Suspendable $scope, string $url, array $headers = []): StoaHttpResponse
    {
        return $this->request($scope, StoaHttpRequest::get($url, $headers));
    }

    /** @param array<string, list<string>> $headers */
    public function post(Suspendable $scope, string $url, string $body, array $headers = []): StoaHttpResponse
    {
        return $this->request($scope, StoaHttpRequest::post($url, $body, $headers));
    }

    public function request(Suspendable $scope, StoaHttpRequest $request): StoaHttpResponse
    {
        $encoded = HttpRequestEncoder::encode($request, $this->config->userAgent);
        $resource = $this->runtime->memory->resources->open(
            type: StoaResourceSid::OutboundHttpRequest,
            id: $this->runtime->memory->ids->nextRuntime('outbound-http'),
        );
        $this->runtime->memory->resources->activate($resource);

        $client = $this->buildClient($encoded['scheme'], $encoded['host']);
        $address = $this->resolveHost($scope, $encoded['host']);

        try {
            $connected = $client->connect(
                $scope,
                $address,
                $encoded['port'],
                $request->connectTimeout > 0.0 ? $request->connectTimeout : $this->config->connectTimeout,
            );
            if (!$connected) {
                throw new HttpClientException("Failed to connect to {$encoded['host']}:{$encoded['port']}");
            }

            $client->send($scope, $encoded['request'], $request->readTimeout > 0.0 ? $request->readTimeout : $this->config->readTimeout);

            $payload = '';
            $readTimeout = $request->readTimeout > 0.0 ? $request->readTimeout : $this->config->readTimeout;
            while (true) {
                $chunk = $client->recv($scope, $readTimeout);

                if ($chunk === null || $chunk === '') {
                    break;
                }

                $payload .= $chunk;

                if (strlen($payload) > $this->config->maxResponseBytes) {
                    throw new HttpClientException(
                        "Response exceeded maxResponseBytes ({$this->config->maxResponseBytes}).",
                    );
                }
            }

            if ($payload === '') {
                throw new HttpClientException('Empty response from upstream.');
            }

            $response = HttpResponseDecoder::decode($payload);
            $this->runtime->memory->resources->close($resource->id, "status:{$response->status}");

            return $response;
        } catch (\Throwable $e) {
            $this->runtime->memory->resources->close($resource->id, 'failed:' . $e::class);
            throw $e;
        } finally {
            $client->close();
        }
    }

    private function buildClient(string $scheme, string $host): TcpClient
    {
        $tls = $scheme === 'https';

        if (!$tls) {
            return new TcpClient();
        }

        $tlsOptions = $this->config->tlsOptions ?? new TlsOptions(verifyPeer: true, hostName: $host);

        if ($tlsOptions->hostName === null) {
            $tlsOptions = new TlsOptions(
                verifyPeer: $tlsOptions->verifyPeer,
                allowSelfSigned: $tlsOptions->allowSelfSigned,
                hostName: $host,
                caFile: $tlsOptions->caFile,
                caPath: $tlsOptions->caPath,
                certFile: $tlsOptions->certFile,
                keyFile: $tlsOptions->keyFile,
                passphrase: $tlsOptions->passphrase,
                ciphers: $tlsOptions->ciphers,
                protocols: $tlsOptions->protocols,
            );
        }

        return new TcpClient(tls: true, tlsOptions: $tlsOptions);
    }

    private function resolveHost(Suspendable $scope, string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $result = $this->dns->resolve($scope, $host, $this->config->connectTimeout);

        return $result->address;
    }
}
