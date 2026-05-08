<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpClientConfig;
use Phalanx\Scope\TaskScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class SurrealBundle implements ServiceBundle
{
    public function __construct(
        private readonly ?SurrealConfig $config = null,
    ) {
    }

    public function services(Services $services, array $context): void
    {
        $config = $this->config;

        if (!$services->has(SurrealConfig::class)) {
            $services->config(
                SurrealConfig::class,
                static fn(array $context): SurrealConfig => $config ?? SurrealConfig::fromContext($context),
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

        if (!$services->has(Surreal::class)) {
            $services->scoped(Surreal::class)
                ->factory(static fn(
                    SurrealConfig $config,
                    SurrealTransport $transport,
                    TaskScope $scope,
                ): Surreal => new Surreal($config, $transport, $scope))
                ->onDispose(static function (Surreal $surreal): void {
                    $surreal->close();
                });
        }
    }
}
