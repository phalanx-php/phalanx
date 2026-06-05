<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Config\Config;
use Phalanx\HttpClient\HttpClient;
use Phalanx\HttpClient\HttpClientConfig;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\WebSocket\Client\WsClient;
use Phalanx\WebSocket\Client\WsClientConfig;

class Bundle extends ServiceBundle
{
    public function __construct(
        private ?\Phalanx\SurrealDb\Config $config = null,
        private ?\Phalanx\SurrealDb\Transport $transport = null,
        private ?\Phalanx\SurrealDb\Live\Transport $liveTransport = null,
    ) {
    }

    #[\Override]
    public static function harness(): BootHarness
    {
        return \Phalanx\SurrealDb\Config::contextSchema()->harness();
    }

    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [\Phalanx\SurrealDb\Config::class];
    }

    public function services(Services $services, AppContext $context): void
    {
        $config = $this->config;
        $transport = $this->transport;
        $liveTransport = $this->liveTransport;

        $services->singleton(\Phalanx\SurrealDb\Config::class)
            ->factory(static fn(): \Phalanx\SurrealDb\Config => $config ?? \Phalanx\SurrealDb\Config::fromContext($context));

        $services->singleton(HttpClient::class)
            ->needs(\Phalanx\SurrealDb\Config::class)
            ->factory(static fn(\Phalanx\SurrealDb\Config $config): HttpClient => new HttpClient(new HttpClientConfig(
                connectTimeout: $config->connectTimeout,
                readTimeout: $config->readTimeout,
                maxResponseBytes: $config->maxResponseBytes,
                userAgent: 'Phalanx-SurrealDb/0.6',
            )));

        $services->singleton(\Phalanx\SurrealDb\Transport::class)
            ->needs(HttpClient::class)
            ->factory(static fn(HttpClient $http): \Phalanx\SurrealDb\Transport => $transport ?? new \Phalanx\SurrealDb\Transport\HttpClient\Transport($http));

        $services->singleton(WsClientConfig::class)
            ->needs(\Phalanx\SurrealDb\Config::class)
            ->factory(static fn(\Phalanx\SurrealDb\Config $config): WsClientConfig => new WsClientConfig(
                connectTimeout: $config->connectTimeout,
                recvTimeout: $config->readTimeout,
            ));

        $services->singleton(WsClient::class)
            ->needs(WsClientConfig::class)
            ->factory(static fn(WsClientConfig $config): WsClient => new WsClient($config));

        $services->singleton(\Phalanx\SurrealDb\Live\Transport::class)
            ->needs(WsClient::class)
            ->factory(static fn(WsClient $client): \Phalanx\SurrealDb\Live\Transport => $liveTransport ?? new \Phalanx\SurrealDb\Live\WebSocket\Transport($client));

        $services->scoped(\Phalanx\SurrealDb\Client::class)
            ->factory(static fn(
                \Phalanx\SurrealDb\Config $config,
                \Phalanx\SurrealDb\Transport $transport,
                \Phalanx\SurrealDb\Live\Transport $liveTransport,
                ExecutionScope $scope,
            ): \Phalanx\SurrealDb\Client => new \Phalanx\SurrealDb\Client($config, $transport, $scope, liveTransport: $liveTransport))
            ->onDispose(static function (\Phalanx\SurrealDb\Client $surrealdb): void {
                $surrealdb->close();
            });
    }
}
