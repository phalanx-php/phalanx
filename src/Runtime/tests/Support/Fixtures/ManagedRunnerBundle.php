<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Support\Fixtures;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class ManagedRunnerBundle extends ServiceBundle
{
    public function __construct(
        private readonly ManagedRunnerEvents $events,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $events = $this->events;

        $services->eager(ManagedRunnerProbe::class)
            ->factory(static fn(): ManagedRunnerProbe => new ManagedRunnerProbe())
            ->onInit(static function () use ($events): void {
                $events->record('init');
            })
            ->onStartup(static function () use ($events): void {
                $events->record('startup');
            })
            ->onReady(static function () use ($events): void {
                $events->record('ready');
            })
            ->onShutdown(static function () use ($events): void {
                $events->record('shutdown');
            });
    }
}
