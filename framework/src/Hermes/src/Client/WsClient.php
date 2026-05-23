<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Client;

use OpenSwoole\Coroutine\Http\Client as SwooleHttpClient;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Hermes\Runtime\Identity\HermesEventSid;
use Phalanx\Hermes\Runtime\Identity\HermesResourceSid;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\ScopeIdentity;
use Phalanx\Supervisor\WaitReason;
use Throwable;

/**
 * Native outbound WebSocket client.
 *
 * Builds a Phalanx-managed WebSocket session on top of OpenSwoole's
 * coroutine-aware HTTP client. RFC6455 framing, masking, and the 101
 * handshake are owned by OpenSwoole; Hermes layers Aegis scope ownership,
 * cancellation, managed-resource lifecycle, Styx backpressure, and
 * supervised reader/writer/ping coroutines.
 */
class WsClient
{
    public function __construct(
        private readonly WsClientConfig $config = new WsClientConfig(),
    ) {
    }

    public function connect(
        ExecutionScope $scope,
        string $url,
        ?WsClientConfig $config = null,
    ): WsClientConnectionHandle {
        $config ??= $this->config;
        $parsed = self::parseUrl($url);

        $resource = $this->openResource($scope);
        $client = new SwooleHttpClient($parsed['host'], $parsed['port'], $parsed['ssl']);

        try {
            $client->set([
                'timeout' => $config->connectTimeout,
                'websocket_mask' => true,
            ]);
            $client->setHeaders([
                'Host' => $parsed['host'] . ':' . $parsed['port'],
                'User-Agent' => 'Phalanx-Hermes/0.6',
            ]);

            $upgraded = $scope->call(
                static fn(): bool => $client->upgrade($parsed['path']),
                WaitReason::wsFrameWrite($parsed['host']),
            );

            if ($upgraded !== true) {
                throw new WsClientException(sprintf(
                    'WebSocket upgrade failed for %s (status=%d errCode=%d errMsg=%s)',
                    $url,
                    $client->statusCode,
                    $client->errCode,
                    $client->errMsg,
                ));
            }

            $resource = $scope->runtime->memory->resources->activate($resource);
            $scope->runtime->memory->resources->recordEvent(
                $resource,
                HermesEventSid::ConnectionOpened,
                $url,
            );
        } catch (Cancelled $e) {
            $this->failResource($scope, $resource, 'aborted', 'cancelled');
            try {
                $client->close();
            } catch (Cancelled $cancelled) {
                throw $cancelled;
            } catch (Throwable) {
            }
            throw $e;
        } catch (Throwable $e) {
            $scope->runtime->memory->resources->recordEvent(
                $resource,
                HermesEventSid::HandshakeFailed,
                $e::class,
            );
            $this->failResource($scope, $resource, 'failed', $e::class);
            try {
                $client->close();
            } catch (Cancelled $cancelled) {
                throw $cancelled;
            } catch (Throwable) {
            }
            throw $e instanceof WsClientException
                ? $e
                : new WsClientException($e->getMessage(), 0, $e);
        }

        return new WsClientConnectionHandle(
            scope: $scope,
            client: $client,
            config: $config,
            resource: $resource,
            host: $parsed['host'],
        );
    }

    /** @return array{scheme: string, host: string, port: int, path: string, ssl: bool} */
    private static function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new WsClientException("Invalid WebSocket URL: {$url}");
        }

        $scheme = strtolower($parts['scheme'] ?? 'ws');
        if ($scheme !== 'ws' && $scheme !== 'wss') {
            throw new WsClientException("Unsupported WebSocket scheme: {$scheme}");
        }

        $ssl = $scheme === 'wss';
        $port = $parts['port'] ?? ($ssl ? 443 : 80);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return [
            'scheme' => $scheme,
            'host' => $parts['host'],
            'port' => (int) $port,
            'path' => $path,
            'ssl' => $ssl,
        ];
    }

    private function openResource(ExecutionScope $scope): ManagedResourceHandle
    {
        return $scope->runtime->memory->resources->open(
            type: HermesResourceSid::WebSocketClientConnection,
            id: $scope->runtime->memory->ids->nextRuntime('hermes-ws-client'),
            ownerScopeId: $scope instanceof ScopeIdentity ? $scope->scopeId : null,
        );
    }

    private function failResource(
        ExecutionScope $scope,
        ManagedResourceHandle $resource,
        string $kind,
        string $reason,
    ): void {
        try {
            if ($kind === 'aborted') {
                $scope->runtime->memory->resources->abort($resource, $reason);
            } else {
                $scope->runtime->memory->resources->fail($resource, $reason);
            }
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (Throwable) {
        } finally {
            try {
                $scope->runtime->memory->resources->release($resource->id);
            } catch (Cancelled $cancelled) {
                throw $cancelled;
            } catch (Throwable) {
            }
        }
    }
}
