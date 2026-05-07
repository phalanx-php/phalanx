<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpClientConfig;
use Phalanx\Iris\HttpServiceBundle;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class SurrealServiceBundle implements ServiceBundle
{
    public function __construct(
        private readonly ?SurrealConfig $config = null,
    ) {
    }

    public function services(Services $services, array $context): void
    {
        $config = $this->config ?? SurrealConfig::fromContext($context);

        if (!$services->has(HttpClient::class)) {
            new HttpServiceBundle(new HttpClientConfig(
                connectTimeout: $config->connectTimeout,
                readTimeout: $config->readTimeout,
                maxResponseBytes: $config->maxResponseBytes,
                userAgent: 'Phalanx-Surreal/0.6',
            ))->services($services, $context);
        }

        if (!$services->has(SurrealConfig::class)) {
            $services->config(SurrealConfig::class, static fn(): SurrealConfig => $config);
        }

        if (!$services->has(SurrealTransport::class)) {
            $services->singleton(SurrealTransport::class)
                ->needs(HttpClient::class)
                ->factory(static fn(HttpClient $http): SurrealTransport => new IrisSurrealTransport($http));
        }

        if (!$services->has(SurrealClient::class)) {
            $services->scoped(SurrealClient::class)
                ->needs(SurrealConfig::class, SurrealTransport::class)
                ->factory(static fn(
                    SurrealConfig $config,
                    SurrealTransport $transport,
                ): SurrealClient => new SurrealClient($config, $transport));
        }
    }
}
