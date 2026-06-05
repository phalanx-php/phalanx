<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\WebSocket\Client\WsClient;
use Phalanx\WebSocket\Client\WsClientConfig;
use Phalanx\HttpClient\HttpClient;
use Phalanx\HttpClient\HttpClientConfig;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Config\Config;

class SurrealDbBundle extends ServiceBundle
{
    public function __construct(
        private ?SurrealDbConfig $config = null,
        private ?SurrealDbTransport $transport = null,
        private ?SurrealDbLiveTransport $liveTransport = null,
    ) {
    }

    #[\Override]
    public static function harness(): BootHarness
    {
        return SurrealDbConfig::contextSchema()->harness();
    }

    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [SurrealDbConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
        $config = $this->config;
        $transport = $this->transport;
        $liveTransport = $this->liveTransport;

        $services->singleton(SurrealDbConfig::class)
            ->factory(static fn(): SurrealDbConfig => $config ?? SurrealDbConfig::fromContext($context));

        $services->singleton(HttpClient::class)
            ->needs(SurrealDbConfig::class)
            ->factory(static fn(SurrealDbConfig $config): HttpClient => new HttpClient(new HttpClientConfig(
                connectTimeout: $config->connectTimeout,
                readTimeout: $config->readTimeout,
                maxResponseBytes: $config->maxResponseBytes,
                userAgent: 'Phalanx-SurrealDb/0.6',
            )));

        $services->singleton(SurrealDbTransport::class)
            ->needs(HttpClient::class)
            ->factory(static fn(HttpClient $http): SurrealDbTransport => $transport ?? new HttpClientSurrealDbTransport($http));

        $services->singleton(WsClientConfig::class)
            ->needs(SurrealDbConfig::class)
            ->factory(static fn(SurrealDbConfig $config): WsClientConfig => new WsClientConfig(
                connectTimeout: $config->connectTimeout,
                recvTimeout: $config->readTimeout,
            ));

        $services->singleton(WsClient::class)
            ->needs(WsClientConfig::class)
            ->factory(static fn(WsClientConfig $config): WsClient => new WsClient($config));

        $services->singleton(SurrealDbLiveTransport::class)
            ->needs(WsClient::class)
            ->factory(static fn(WsClient $client): SurrealDbLiveTransport => $liveTransport ?? new WebSocketSurrealDbLiveTransport($client));

        $services->scoped(SurrealDb::class)
            ->factory(static fn(
                SurrealDbConfig $config,
                SurrealDbTransport $transport,
                SurrealDbLiveTransport $liveTransport,
                ExecutionScope $scope,
            ): SurrealDb => new SurrealDb($config, $transport, $scope, liveTransport: $liveTransport))
            ->onDispose(static function (SurrealDb $surrealdb): void {
                $surrealdb->close();
            });
    }
}
