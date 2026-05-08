<?php

declare(strict_types=1);

namespace Phalanx\Iris;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class HttpServiceBundle extends ServiceBundle
{
    public function __construct(
        private readonly ?HttpClientConfig $config = null,
    ) {
    }

    public function services(Services $services, array $context): void
    {
        $config = $this->config;

        if (!$services->has(HttpClientConfig::class)) {
            $services->config(
                HttpClientConfig::class,
                static fn(array $ctx): HttpClientConfig => $config ?? new HttpClientConfig(
                    connectTimeout: (float) ($ctx['IRIS_CONNECT_TIMEOUT'] ?? 5.0),
                    readTimeout: (float) ($ctx['IRIS_READ_TIMEOUT'] ?? 30.0),
                    maxResponseBytes: (int) ($ctx['IRIS_MAX_RESPONSE_BYTES'] ?? 16 * 1024 * 1024),
                    userAgent: (string) ($ctx['IRIS_USER_AGENT'] ?? 'Phalanx-Iris/0.6'),
                ),
            );
        }

        if (!$services->has(HttpClient::class)) {
            $services->singleton(HttpClient::class)
                ->needs(HttpClientConfig::class)
                ->factory(static fn(HttpClientConfig $config): HttpClient => new HttpClient($config));
        }
    }
}
