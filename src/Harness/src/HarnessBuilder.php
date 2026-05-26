<?php

declare(strict_types=1);

namespace Phalanx\Harness;

use Closure;
use Phalanx\Athena\AthenaBundle;
use Phalanx\Boot\AppContext;
use Phalanx\Harness\Agent\AthenaServiceBundle;
use Phalanx\Harness\Ui\UiApp;
use Phalanx\Iris\HttpServiceBundle;
use Phalanx\Panoply\Agent;
use Phalanx\Service\ServiceBundle;
use Phalanx\Surreal\SurrealBundle;
use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\State\Store;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Theatron;
use Phalanx\Theatron\TheatronApp;
use Phalanx\Theatron\TheatronBuilder;
use Phalanx\Theatron\TheatronServiceBundle;

final class HarnessBuilder
{
    private TheatronBuilder $theatron;

    /** @var class-string<Agent>|null */
    private ?string $agentClass = null;

    private ?AthenaBundle $athenaBundle = null;

    private bool $providersConfigured = false;

    private HarnessMode $mode = HarnessMode::Ephemeral;

    public AppContext $context {
        get => $this->theatron->context;
    }

    /** @param array<string, mixed> $context */
    public function __construct(array $context = [])
    {
        $this->theatron = Theatron::app($context)
            ->store(UiApp::store())
            ->screens(UiApp::screens())
            ->globalBindings(UiApp::bindings())
            ->devtools();
    }

    /** @param class-string<Agent> $agentClass */
    public function agent(string $agentClass): self
    {
        $this->agentClass = $agentClass;

        return $this;
    }

    public function athena(AthenaBundle $bundle): self
    {
        $this->athenaBundle = $bundle;

        return $this;
    }

    public function durable(): self
    {
        $this->mode = HarnessMode::Durable;

        return $this;
    }

    /** @param list<Binding> $bindings */
    public function globalBindings(array $bindings): self
    {
        $this->theatron->globalBindings($bindings);

        return $this;
    }

    public function stageConfig(StageConfig $config): self
    {
        $this->theatron->stageConfig($config);

        return $this;
    }

    public function devtools(bool $enabled = true): self
    {
        $this->theatron->devtools($enabled);

        return $this;
    }

    public function theme(Theme $theme): self
    {
        $this->theatron->theme($theme);

        return $this;
    }

    /** @param ServiceBundle|Closure(TheatronApp): ServiceBundle ...$providers */
    public function providers(ServiceBundle|Closure ...$providers): self
    {
        $this->theatron->providers(...$providers);
        $this->providersConfigured = true;

        return $this;
    }

    public function build(): TheatronApp
    {
        $this->ensureProviders();

        return $this->theatron->build();
    }

    public function run(): int
    {
        $this->ensureProviders();

        return $this->theatron->run();
    }

    /** @return class-string<Store>|null */
    public function registeredStore(): ?string
    {
        return $this->theatron->registeredStore();
    }

    /** @return list<class-string<Screen>> */
    public function registeredScreens(): array
    {
        return $this->theatron->registeredScreens();
    }

    /** @return list<Binding> */
    public function registeredGlobalBindings(): array
    {
        return $this->theatron->registeredGlobalBindings();
    }

    /** @return list<ServiceBundle|Closure(TheatronApp): ServiceBundle> */
    public function registeredProviders(): array
    {
        return $this->theatron->registeredProviders();
    }

    private function ensureProviders(): void
    {
        if ($this->providersConfigured) {
            return;
        }

        $athenaBundle = $this->athenaBundle !== null
            ? AthenaServiceBundle::from($this->athenaBundle, $this->agentClass)
            : AthenaServiceBundle::ollama($this->agentClass);

        $this->theatron->providers(
            static fn(TheatronApp $app): TheatronServiceBundle => new TheatronServiceBundle($app),
            new HttpServiceBundle(),
            $athenaBundle,
            ...($this->mode === HarnessMode::Durable ? [new SurrealBundle(), new AgoraServiceBundle($this->mode)] : []),
        );

        $this->providersConfigured = true;
    }
}
