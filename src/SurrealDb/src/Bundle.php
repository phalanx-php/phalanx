<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Config\Config;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class Bundle extends ServiceBundle
{
    public function __construct(
        private readonly ?\Phalanx\SurrealDb\Config $config = null,
        private readonly ?\Phalanx\SurrealDb\Transport $transport = null,
        private readonly ?\Phalanx\SurrealDb\Live\Transport $liveTransport = null,
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

        $services->singleton(\Phalanx\HttpClient\Client::class)
            ->needs(\Phalanx\SurrealDb\Config::class)
            ->factory(static fn(\Phalanx\SurrealDb\Config $config): \Phalanx\HttpClient\Client => new \Phalanx\HttpClient\Client(new \Phalanx\HttpClient\Config(
                connectTimeout: $config->connectTimeout,
                readTimeout: $config->readTimeout,
                maxResponseBytes: $config->maxResponseBytes,
                userAgent: 'Phalanx-SurrealDb/0.6',
            )));

        $services->singleton(\Phalanx\SurrealDb\Transport::class)
            ->needs(\Phalanx\HttpClient\Client::class)
            ->factory(static fn(\Phalanx\HttpClient\Client $http): \Phalanx\SurrealDb\Transport => $transport ?? new \Phalanx\SurrealDb\Transport\HttpClient\Transport($http));

        $services->singleton(\Phalanx\WebSocket\Client\Config::class)
            ->needs(\Phalanx\SurrealDb\Config::class)
            ->factory(static fn(\Phalanx\SurrealDb\Config $config): \Phalanx\WebSocket\Client\Config => new \Phalanx\WebSocket\Client\Config(
                connectTimeout: $config->connectTimeout,
                recvTimeout: $config->readTimeout,
            ));

        $services->singleton(\Phalanx\WebSocket\Client::class)
            ->needs(\Phalanx\WebSocket\Client\Config::class)
            ->factory(static fn(\Phalanx\WebSocket\Client\Config $config): \Phalanx\WebSocket\Client => new \Phalanx\WebSocket\Client($config));

        $services->singleton(\Phalanx\SurrealDb\Live\Transport::class)
            ->needs(\Phalanx\WebSocket\Client::class)
            ->factory(static fn(\Phalanx\WebSocket\Client $client): \Phalanx\SurrealDb\Live\Transport => $liveTransport ?? new \Phalanx\SurrealDb\Live\WebSocket\Transport($client));

        $services->scoped(\Phalanx\SurrealDb\Client::class)
            ->factory(static fn(
                ExecutionScope $scope,
                \Phalanx\SurrealDb\Config $config,
                \Phalanx\SurrealDb\Transport $transport,
                \Phalanx\SurrealDb\Live\Transport $liveTransport,
            ): \Phalanx\SurrealDb\Client => new \Phalanx\SurrealDb\Client($scope, $config, $transport, liveTransport: $liveTransport))
            ->onDispose(static function (\Phalanx\SurrealDb\Client $surrealdb): void {
                $surrealdb->close();
            });
    }
}
