<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Boot\Optional;
use Phalanx\Hermes\Client\WsClient;
use Phalanx\Hermes\Client\WsClientConfig;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpClientConfig;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class SurrealBundle extends ServiceBundle
{
    /**
     * SurrealConfig::fromContext reads `surreal_namespace`/`SURREAL_NAMESPACE`
     * and `surreal_database`/`SURREAL_DATABASE` — both have defaults ('phalanx'
     * and 'app' respectively), so they are optional here. The endpoint also
     * has a default (http://127.0.0.1:8000). Credentials are fully optional.
     * No TCP probe: SurrealDB may be unavailable at boot (dev containers,
     * lazy-start, etc.) — connection failures surface at query time.
     */
    public static function harness(): BootHarness
    {
        return BootHarness::of(
            Optional::env('SURREAL_ENDPOINT', fallback: 'http://127.0.0.1:8000', description: 'SurrealDB HTTP endpoint'),
            Optional::env('SURREAL_NAMESPACE', fallback: 'phalanx', description: 'SurrealDB namespace'),
            Optional::env('SURREAL_DATABASE', fallback: 'app', description: 'SurrealDB database'),
            Optional::env('SURREAL_USERNAME', description: 'SurrealDB username'),
            Optional::env('SURREAL_PASSWORD', description: 'SurrealDB password'),
            Optional::env('SURREAL_TOKEN', description: 'SurrealDB authentication token'),
        );
    }


    public function __construct(
        private readonly ?SurrealConfig $config = null,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $config = $this->config;

        if (!$services->has(SurrealConfig::class)) {
            $services->config(
                SurrealConfig::class,
                static fn(AppContext $ctx): SurrealConfig => $config ?? SurrealConfig::fromContext($ctx),
            );
        }

        if (!$services->has(HttpClient::class)) {
            $services->singleton(HttpClient::class)
                ->needs(SurrealConfig::class)
                ->factory(static fn(SurrealConfig $config): HttpClient => new HttpClient(new HttpClientConfig(
                    connectTimeout: $config->connectTimeout,
                    readTimeout: $config->readTimeout,
                    maxResponseBytes: $config->maxResponseBytes,
                    userAgent: 'Phalanx-Surreal/0.6',
                )));
        }

        if (!$services->has(SurrealTransport::class)) {
            $services->singleton(SurrealTransport::class)
                ->needs(HttpClient::class)
                ->factory(static fn(HttpClient $http): SurrealTransport => new IrisSurrealTransport($http));
        }

        if (!$services->has(WsClientConfig::class)) {
            $services->config(
                WsClientConfig::class,
                static function (AppContext $ctx) use ($config): WsClientConfig {
                    $surreal = $config ?? SurrealConfig::fromContext($ctx);

                    return new WsClientConfig(
                        connectTimeout: $surreal->connectTimeout,
                        recvTimeout: $surreal->readTimeout,
                    );
                },
            );
        }

        if (!$services->has(WsClient::class)) {
            $services->singleton(WsClient::class)
                ->needs(WsClientConfig::class)
                ->factory(static fn(WsClientConfig $config): WsClient => new WsClient($config));
        }

        if (!$services->has(SurrealLiveTransport::class)) {
            $services->singleton(SurrealLiveTransport::class)
                ->needs(WsClient::class)
                ->factory(static fn(WsClient $client): SurrealLiveTransport => new HermesSurrealLiveTransport($client));
        }

        if (!$services->has(Surreal::class)) {
            $services->scoped(Surreal::class)
                ->factory(static fn(
                    SurrealConfig $config,
                    SurrealTransport $transport,
                    SurrealLiveTransport $liveTransport,
                    ExecutionScope $scope,
                ): Surreal => new Surreal($config, $transport, $scope, liveTransport: $liveTransport))
                ->onDispose(static function (Surreal $surreal): void {
                    $surreal->close();
                });
        }
    }
}
