<?php

declare(strict_types=1);

namespace Phalanx\Theatron;

use Phalanx\Boot\AppContext;
use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Theatron\Binding\BindingRegistry;
use Phalanx\Theatron\Contract\HasRuntimeContext;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\State\Store;
use Phalanx\Theatron\Styling\Theme;

final class TheatronServiceBundle extends ServiceBundle
{
    public function __construct(private(set) TheatronApp $app)
    {
    }

    public function services(Services $services, AppContext $context): void
    {
        $stage = $this->app->stage;
        $theme = $this->app->theme;

        $services->singleton(ConsoleInput::class)
            ->factory(static fn(): ConsoleInput => new ConsoleInput());

        $services->singleton(StageConfig::class)
            ->factory(static fn(): StageConfig => $stage->config);

        $services->singleton(Stage::class)
            ->factory(static fn(): Stage => $stage);

        $services->singleton(BindingRegistry::class)
            ->factory(static fn(): BindingRegistry => new BindingRegistry());

        $services->singleton(Theme::class)
            ->factory(static fn(): Theme => $theme);

        if ($this->app->storeClass !== null) {
            $storeClass = $this->app->storeClass;
            $services->singleton($storeClass)
                ->factory(static fn(): Store => self::buildStore($storeClass, $context));
            $services->alias(Store::class, $storeClass);
        }
    }

    /** @param class-string<Store> $storeClass */
    private static function buildStore(string $storeClass, AppContext $context): Store
    {
        $store = new $storeClass();

        if ($store instanceof HasRuntimeContext) {
            $store->receiveRuntimeContext($context);
        }

        return $store;
    }
}
