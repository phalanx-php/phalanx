<?php

declare(strict_types=1);

namespace Phalanx\Tui\Apps;

use Closure;
use InvalidArgumentException;
use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Tui\Core\Screen;
use Phalanx\Tui\Drawing\Stage;
use Phalanx\Tui\Drawing\StageConfig;
use Phalanx\Tui\Inputs\Binding;
use Phalanx\Tui\Reactive\SignalRegistry;
use Phalanx\Tui\Reactive\Store;
use Phalanx\Tui\Styles\Theme;

final class Builder
{
    /** @var list<class-string<Screen>> */
    private array $screens = [];

    /** @var list<Binding> */
    private array $globalBindings = [];

    /** @var list<ServiceBundle|Closure(App): ServiceBundle> */
    private array $providers = [];

    /** @var class-string<Store>|null */
    private ?string $storeClass = null;

    private StageConfig $stageConfig;

    private ?Theme $theme = null;

    private bool $devtools = false;

    public function __construct(private(set) AppContext $context)
    {
        $this->globalBindings = self::withDefaultGlobalBindings([]);
        $this->stageConfig = new StageConfig(handleInput: true, defaultExitHandler: false);
    }

    /** @param class-string<Store> $store */
    public function store(string $store): self
    {
        $this->storeClass = $store;

        return $this;
    }

    /** @param list<class-string<Screen>> $screens */
    public function screens(array $screens): self
    {
        if ($screens === []) {
            throw new InvalidArgumentException('At least one screen is required.');
        }

        $this->screens = $screens;

        return $this;
    }

    /** @param list<Binding> $bindings */
    public function globalBindings(array $bindings): self
    {
        $this->globalBindings = self::withDefaultGlobalBindings($bindings);

        return $this;
    }

    public function stageConfig(StageConfig $config): self
    {
        $this->stageConfig = $config;

        return $this;
    }

    public function theme(Theme $theme): self
    {
        $this->theme = $theme;

        return $this;
    }

    public function devtools(bool $enabled = true): self
    {
        $this->devtools = $enabled;

        return $this;
    }

    /** @param ServiceBundle|Closure(App): ServiceBundle ...$providers */
    public function providers(ServiceBundle|Closure ...$providers): self
    {
        $this->providers = [...$this->providers, ...array_values($providers)];

        return $this;
    }

    public function build(): App
    {
        if ($this->screens === []) {
            throw new InvalidArgumentException('At least one screen must be registered via screens().');
        }

        $registry = $this->devtools ? new SignalRegistry() : null;

        $theme = $this->theme ?? Theme::default();
        $stage = Stage::boot($this->stageConfig);

        return new App(
            stage: $stage,
            theme: $theme,
            screens: $this->screens,
            globalBindings: $this->globalBindings,
            storeClass: $this->storeClass,
            devtools: $this->devtools,
            registry: $registry,
        );
    }

    public function run(): int
    {
        $app = $this->build();

        Application::starting($this->context->values)
            ->providers(...$this->resolvedProviders($app))
            ->run(static function (ExecutionScope $scope) use ($app): void {
                $app->start($scope);
            });

        return 0;
    }

    /** @return class-string<Store>|null */
    public function registeredStore(): ?string
    {
        return $this->storeClass;
    }

    /** @return list<class-string<Screen>> */
    public function registeredScreens(): array
    {
        return $this->screens;
    }

    /** @return list<Binding> */
    public function registeredGlobalBindings(): array
    {
        return $this->globalBindings;
    }

    /** @return list<ServiceBundle|Closure(App): ServiceBundle> */
    public function registeredProviders(): array
    {
        return $this->providers;
    }

    /** @param list<Binding> $bindings */
    private static function hasBinding(array $bindings, Binding $needle): bool
    {
        return array_any(
            $bindings,
            static fn(Binding $binding): bool => $binding->key === $needle->key
                && $binding->ctrl === $needle->ctrl
                && $binding->alt === $needle->alt
                && $binding->shift === $needle->shift,
        );
    }

    /**
     * @param list<Binding> $bindings
     * @return list<Binding>
     */
    private static function withDefaultGlobalBindings(array $bindings): array
    {
        $quit = Binding::ctrl('c')->quit()->label('quit');

        if (self::hasBinding($bindings, $quit)) {
            return $bindings;
        }

        return [...$bindings, $quit];
    }

    /** @return list<ServiceBundle> */
    private function resolvedProviders(App $app): array
    {
        return array_map(
            static fn(ServiceBundle|Closure $provider): ServiceBundle => $provider instanceof Closure
                ? $provider($app)
                : $provider,
            $this->providers,
        );
    }
}
