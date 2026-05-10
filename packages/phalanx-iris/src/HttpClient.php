<?php

declare(strict_types=1);

namespace Phalanx\Iris;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Iris\Runtime\Identity\IrisResourceSid;
use Phalanx\Iris\Wire\HttpRequestEncoder;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Scope\Scope;
use Phalanx\Scope\ScopeIdentity;
use Phalanx\Scope\Suspendable;
use Phalanx\System\DnsResolver;
use Phalanx\System\TcpClient;
use Phalanx\System\TlsOptions;
use Throwable;

/**
 * Native outbound HTTP/1.1 client for Phalanx packages.
 *
 * Iris owns HTTP request/response semantics. Aegis still owns the
 * underlying coroutine-aware DNS, TCP, TLS, cancellation, and managed
 * resource machinery consumed here.
 */
class HttpClient
{
    public function __construct(
        private readonly HttpClientConfig $config = new HttpClientConfig(),
        private readonly DnsResolver $dns = new DnsResolver(),
    ) {
    }

    /** @param array<string, list<string>> $headers */
    public function get(Scope&Suspendable $scope, string $url, array $headers = []): HttpResponse
    {
        return $this->request($scope, HttpRequest::get($url, $headers));
    }

    /** @param array<string, list<string>> $headers */
    public function post(Scope&Suspendable $scope, string $url, string $body, array $headers = []): HttpResponse
    {
        return $this->request($scope, HttpRequest::post($url, $body, $headers));
    }

    public function request(Scope&Suspendable $scope, HttpRequest $request): HttpResponse
    {
        $stream = $this->stream($scope, $request);

        try {
            $body = '';
            while (!$stream->eof) {
                $chunk = $stream->read($scope, $this->config->maxResponseBytes);
                if ($chunk === '') {
                    break;
                }

                $body .= $chunk;
                if (strlen($body) > $this->config->maxResponseBytes) {
                    throw new HttpClientException(
                        "Response exceeded maxResponseBytes ({$this->config->maxResponseBytes}).",
                    );
                }
            }

            return new HttpResponse(
                status: $stream->status,
                reasonPhrase: $stream->reasonPhrase,
                headers: $stream->headerLines,
                body: $body,
                protocolVersion: $stream->protocolVersion,
            );
        } catch (Cancelled $e) {
            $stream->abort('cancelled');
            throw $e;
        } catch (Throwable $e) {
            $stream->fail($e::class);
            throw $e;
        } finally {
            $stream->close();
        }
    }

    public function stream(Scope&Suspendable $scope, HttpRequest $request): HttpStream
    {
        $encoded = HttpRequestEncoder::encode($request, $this->config->userAgent);
        $resource = $this->openResource($scope);
        $client = $this->buildClient($encoded['scheme'], $encoded['host']);

        try {
            $address = $this->resolveHost($scope, $encoded['host']);
            $connected = $client->connect(
                $scope,
                $address,
                $encoded['port'],
                $request->connectTimeout > 0.0 ? $request->connectTimeout : $this->config->connectTimeout,
            );

            if (!$connected) {
                throw new HttpClientException("Failed to connect to {$encoded['host']}:{$encoded['port']}.");
            }

            $client->send(
                $scope,
                $encoded['request'],
                $request->readTimeout > 0.0 ? $request->readTimeout : $this->config->readTimeout,
            );
        } catch (Cancelled $e) {
            $client->close();
            $this->abortResource($scope, $resource, 'cancelled');
            throw $e;
        } catch (Throwable $e) {
            $client->close();
            $this->failResource($scope, $resource, $e::class);
            throw $e;
        }

        return new HttpStream(
            client: $client,
            waitDetail: strtoupper($request->method) . ' ' . $encoded['path'],
            runtime: $scope->runtime,
            resource: $resource,
            recvTimeout: $request->readTimeout > 0.0 ? $request->readTimeout : $this->config->readTimeout,
            maxResponseBytes: $this->config->maxResponseBytes,
        );
    }

    private function openResource(Scope $scope): ManagedResourceHandle
    {
        $resource = $scope->runtime->memory->resources->open(
            type: IrisResourceSid::OutboundHttpRequest,
            id: $scope->runtime->memory->ids->nextRuntime('outbound-http'),
            ownerScopeId: $scope instanceof ScopeIdentity ? $scope->scopeId : null,
        );

        return $scope->runtime->memory->resources->activate($resource);
    }

    private function buildClient(string $scheme, string $host): TcpClient
    {
        if ($scheme !== 'https') {
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
        $address = $result->first();
        if ($address === null) {
            throw new HttpClientException("Failed to resolve {$host}.");
        }

        return $address;
    }

    private function abortResource(Scope $scope, ManagedResourceHandle $resource, string $reason): void
    {
        $scope->runtime->memory->resources->abort($resource, $reason);
        $scope->runtime->memory->resources->release($resource->id);
    }

    private function failResource(Scope $scope, ManagedResourceHandle $resource, string $reason): void
    {
        $scope->runtime->memory->resources->fail($resource, $reason);
        $scope->runtime->memory->resources->release($resource->id);
    }
}
