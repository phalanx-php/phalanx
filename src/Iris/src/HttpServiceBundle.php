<?php

declare(strict_types=1);

namespace Phalanx\Iris;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class HttpServiceBundle extends ServiceBundle
{
    private const float DEFAULT_CONNECT_TIMEOUT = 5.0;
    private const float DEFAULT_READ_TIMEOUT = 30.0;
    private const int DEFAULT_MAX_RESPONSE_BYTES = 16 * 1024 * 1024;
    private const string DEFAULT_USER_AGENT = 'Phalanx-Iris/0.6';

    public function __construct(
        private ?HttpClientConfig $config = null,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $config = $this->config;

        $services->config(
            HttpClientConfig::class,
            static fn(AppContext $ctx): HttpClientConfig => $config ?? new HttpClientConfig(
                connectTimeout: $ctx->float('IRIS_CONNECT_TIMEOUT', self::DEFAULT_CONNECT_TIMEOUT),
                readTimeout: $ctx->float('IRIS_READ_TIMEOUT', self::DEFAULT_READ_TIMEOUT),
                maxResponseBytes: $ctx->int('IRIS_MAX_RESPONSE_BYTES', self::DEFAULT_MAX_RESPONSE_BYTES),
                userAgent: $ctx->string('IRIS_USER_AGENT', self::DEFAULT_USER_AGENT),
            ),
        );

        $services->singleton(HttpClient::class)
            ->needs(HttpClientConfig::class)
            ->factory(static fn(HttpClientConfig $config): HttpClient => new HttpClient($config));
    }
}
