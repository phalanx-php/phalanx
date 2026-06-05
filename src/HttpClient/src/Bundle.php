<?php

declare(strict_types=1);

namespace Phalanx\HttpClient;

use Phalanx\Boot\AppContext;
use Phalanx\Config\Config;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class Bundle extends ServiceBundle
{
    public function __construct(
        private readonly ?\Phalanx\HttpClient\Config $config = null,
    ) {
    }

    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [\Phalanx\HttpClient\Config::class];
    }

    public function services(Services $services, AppContext $context): void
    {
        $config = $this->config;

        if ($config !== null) {
            $services->singleton(\Phalanx\HttpClient\Config::class)
                ->factory(static fn(): \Phalanx\HttpClient\Config => $config);
        }

        $services->singleton(\Phalanx\HttpClient\Client::class)
            ->needs(\Phalanx\HttpClient\Config::class)
            ->factory(static fn(\Phalanx\HttpClient\Config $config): \Phalanx\HttpClient\Client => new \Phalanx\HttpClient\Client($config));
    }
}
