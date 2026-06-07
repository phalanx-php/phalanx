<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Support\Fixtures;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class InvalidStartupLifecycleBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        $services->singleton(ManagedRunnerProbe::class)
            ->factory(static fn(): ManagedRunnerProbe => new ManagedRunnerProbe())
            ->onStartup(static function (): void {
            });
    }
}
