<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Config\Config;
use Phalanx\Hermes\Client\WsClient;
use Phalanx\Hermes\Client\WsClientConfig;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpClientConfig;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class SurrealBundle extends ServiceBundle
{
    public function __construct(
        private ?SurrealConfig $config = null,
        private ?SurrealTransport $transport = null,
        private ?SurrealLiveTransport $liveTransport = null,
    ) {
    }

    #[\Override]
    public static function harness(): BootHarness
    {
        return SurrealConfig::contextSchema()->harness();
    }

    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [SurrealConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
        $config = $this->config;
        $transport = $this->transport;
        $liveTransport = $this->liveTransport;

        $services->singleton(SurrealConfig::class)
            ->factory(static fn(): SurrealConfig => $config ?? SurrealConfig::fromContext($context));

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

        $services->singleton(WsClientConfig::class)
            ->needs(SurrealConfig::class)
            ->factory(static fn(SurrealConfig $config): WsClientConfig => new WsClientConfig(
                connectTimeout: $config->connectTimeout,
                recvTimeout: $config->readTimeout,
            ));

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
