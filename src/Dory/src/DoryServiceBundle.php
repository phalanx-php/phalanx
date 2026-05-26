<?php

declare(strict_types=1);

namespace Phalanx\Dory;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Themis\Config;
use Phalanx\Themis\ConfigFactory;

final class DoryServiceBundle extends ServiceBundle
{
    /** @return list<class-string<Config>> */
    public static function configs(): array
    {
        return [DoryConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
        $services->singleton(DoryConfig::class)
            ->factory(static fn(): DoryConfig => ConfigFactory::fromContext($context->values)->hydrate(DoryConfig::class));
    }
}
