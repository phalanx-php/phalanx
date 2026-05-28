<?php

declare(strict_types=1);

namespace Phalanx\Theatron;

use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Theatron\Store\Lens;
use Phalanx\Theatron\Store\StoreRegistry;
use Phalanx\Theatron\Store\StoreWriter;

final class TheatronServiceBundle extends ServiceBundle
{
    public function __construct(
        private readonly TheatronApp $app,
    ) {
    }

    public function services(Services $services, AppContext $_): void
    {
        $app = $this->app;

        $services->singleton(TheatronApp::class)
            ->factory(static fn(): TheatronApp => $app);

        $services->scoped(StoreRegistry::class)
            ->factory(static function (TheatronApp $app, ExecutionScope $scope): StoreRegistry {
                $registry = $app->createRegistry();
                $registry->start($scope);

                return $registry;
            });

        $services->scoped(Lens::class)
            ->factory(static fn(StoreRegistry $registry): Lens => $registry->lens());

        $services->scoped(StoreWriter::class)
            ->factory(static fn(StoreRegistry $registry): StoreWriter => $registry->writer());
    }
}
