<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Support\Fixtures;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class DependentLifecycleBundle extends ServiceBundle
{
    public function __construct(
        private readonly ManagedRunnerEvents $events,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $events = $this->events;

        $services->eager(ManagedRunnerDependentProbe::class)
            ->needs(ManagedRunnerDependencyProbe::class)
            ->factory(
                static fn(
                    ManagedRunnerDependencyProbe $dependency
                ): ManagedRunnerDependentProbe => new ManagedRunnerDependentProbe($dependency),
            )
            ->onInit(static function () use ($events): void {
                $events->record('dependent.init');
            })
            ->onStartup(static function () use ($events): void {
                $events->record('dependent.startup');
            })
            ->onReady(static function () use ($events): void {
                $events->record('dependent.ready');
            });

        $services->eager(ManagedRunnerDependencyProbe::class)
            ->factory(static fn(): ManagedRunnerDependencyProbe => new ManagedRunnerDependencyProbe())
            ->onInit(static function () use ($events): void {
                $events->record('dependency.init');
            })
            ->onStartup(static function () use ($events): void {
                $events->record('dependency.startup');
            })
            ->onReady(static function () use ($events): void {
                $events->record('dependency.ready');
            });
    }
}
