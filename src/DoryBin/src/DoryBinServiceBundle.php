<?php

declare(strict_types=1);

namespace Phalanx\DoryBin;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class DoryBinServiceBundle extends ServiceBundle
{
    /** @return list<class-string<\Phalanx\Themis\Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [BuildConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
        $services->singleton(BuildProfileRegistry::class)
            ->factory(static function () use ($context): BuildProfileRegistry {
                $env = (array) $context->get('env', []);
                $home = isset($env['HOME']) && is_string($env['HOME']) ? $env['HOME'] : '';

                return new BuildProfileRegistry(
                    profileDir: BuildProfileRegistry::defaultProfileDir(),
                    home: $home,
                    env: array_filter($env, static fn(mixed $v): bool => is_string($v)),
                );
            });
    }
}
