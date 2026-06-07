<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Support\Fixtures;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class InvalidReadyLifecycleBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        $services->scoped(ManagedRunnerProbe::class)
            ->factory(static fn(): ManagedRunnerProbe => new ManagedRunnerProbe())
            ->onReady(static function (): void {
            });
    }
}
