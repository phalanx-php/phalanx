<?php

declare(strict_types=1);

namespace Phalanx\HttpClient;

use Phalanx\Boot\AppContext;
use Phalanx\Config\Config;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class HttpServiceBundle extends ServiceBundle
{
    public function __construct(
        private ?HttpClientConfig $config = null,
    ) {
    }

    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [HttpClientConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
        $config = $this->config;

        if ($config !== null) {
            $services->singleton(HttpClientConfig::class)
                ->factory(static fn(): HttpClientConfig => $config);
        }

        $services->singleton(HttpClient::class)
            ->needs(HttpClientConfig::class)
            ->factory(static fn(HttpClientConfig $config): HttpClient => new HttpClient($config));
    }
}
