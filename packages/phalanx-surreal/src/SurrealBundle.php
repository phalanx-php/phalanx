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

final class SurrealBundle extends ServiceBundle
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
        private readonly ?SurrealTransport $transport = null,
        private readonly ?SurrealLiveTransport $liveTransport = null,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $config = $this->config;
        $transport = $this->transport;
        $liveTransport = $this->liveTransport;

        $services->config(
            SurrealConfig::class,
            static fn(AppContext $ctx): SurrealConfig => $config ?? SurrealConfig::fromContext($ctx),
        );

        $services->singleton(HttpClient::class)
            ->needs(SurrealConfig::class)
            ->factory(static fn(SurrealConfig $config): HttpClient => new HttpClient(new HttpClientConfig(
                connectTimeout: $config->connectTimeout,
                readTimeout: $config->readTimeout,
                maxResponseBytes: $config->maxResponseBytes,
                userAgent: 'Phalanx-Surreal/0.6',
            )));

        $services->singleton(SurrealTransport::class)
            ->needs(HttpClient::class)
            ->factory(static fn(HttpClient $http): SurrealTransport => $transport ?? new IrisSurrealTransport($http));

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

        $services->singleton(WsClient::class)
            ->needs(WsClientConfig::class)
            ->factory(static fn(WsClientConfig $config): WsClient => new WsClient($config));

        $services->singleton(SurrealLiveTransport::class)
            ->needs(WsClient::class)
            ->factory(static fn(WsClient $client): SurrealLiveTransport => $liveTransport ?? new HermesSurrealLiveTransport($client));

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
