<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Apps;

use Closure;
use InvalidArgumentException;
use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Theatron\Collab\Boundaries\Inlet;
use Phalanx\Theatron\Collab\Boundaries\Outlet;
use Phalanx\Theatron\Collab\Participants\AgentParticipant;
use Phalanx\Theatron\Collab\Participants\Preparer;
use Phalanx\Theatron\Collab\Participants\Reactor;
use Phalanx\Theatron\Collab\Participants\Reviewer;
use Phalanx\Theatron\Collab\Screens\WorkspaceScreen;
use Phalanx\Theatron\Collab\State\AgentHarnessStore;
use Phalanx\Theatron\Tui\Apps\TheatronApp;
use Phalanx\Theatron\Tui\Apps\TheatronBuilder;
use Phalanx\Theatron\Tui\Apps\TheatronServiceBundle;
use Phalanx\Theatron\Tui\Core\Screen;
use Phalanx\Theatron\Tui\Drawing\StageConfig;
use Phalanx\Theatron\Tui\Inputs\Binding;
use Phalanx\Theatron\Tui\Styles\Theme;

final class AgentHarnessBuilder
{
    /** @var list<Preparer> */
    private array $preparers = [];

    /** @var list<AgentParticipant> */
    private array $participants = [];

    /** @var list<Reactor> */
    private array $reactors = [];

    /** @var list<Reviewer> */
    private array $reviewers = [];

    /** @var list<Inlet> */
    private array $inlets = [];

    /** @var list<Outlet> */
    private array $outlets = [];

    /** @var list<ServiceBundle|Closure(TheatronApp): ServiceBundle> */
    private array $providers = [];

    private ?AgentParticipant $primary = null;

    private int $maxReviewPasses = 8;

    private float $tickIntervalSeconds = 0.1;

    private TheatronBuilder $tui;

    public function __construct(private(set) AppContext $context)
    {
        $this->tui = (new TheatronBuilder($this->context))
            ->store(AgentHarnessStore::class)
            ->screens([WorkspaceScreen::class]);
    }

    public function primary(AgentParticipant $primary): self
    {
        $this->primary = $primary;

        return $this;
    }

    public function preparers(Preparer ...$preparers): self
    {
        $this->preparers = array_values([...$this->preparers, ...$preparers]);

        return $this;
    }

    public function participants(AgentParticipant ...$participants): self
    {
        $this->participants = array_values([...$this->participants, ...$participants]);

        return $this;
    }

    public function reactors(Reactor ...$reactors): self
    {
        $this->reactors = array_values([...$this->reactors, ...$reactors]);

        return $this;
    }

    public function reviewers(Reviewer ...$reviewers): self
    {
        $this->reviewers = array_values([...$this->reviewers, ...$reviewers]);

        return $this;
    }

    public function inlets(Inlet ...$inlets): self
    {
        $this->inlets = array_values([...$this->inlets, ...$inlets]);

        return $this;
    }

    public function outlets(Outlet ...$outlets): self
    {
        $this->outlets = array_values([...$this->outlets, ...$outlets]);

        return $this;
    }

    public function maxReviewPasses(int $maxReviewPasses): self
    {
        if ($maxReviewPasses < 1) {
            throw new InvalidArgumentException('AgentHarness max review passes must be >= 1.');
        }

        $this->maxReviewPasses = $maxReviewPasses;

        return $this;
    }

    public function tickInterval(float $seconds): self
    {
        if ($seconds <= 0.0) {
            throw new InvalidArgumentException('AgentHarness tick interval must be greater than zero.');
        }

        $this->tickIntervalSeconds = $seconds;

        return $this;
    }

    /** @param list<class-string<Screen>> $screens */
    public function screens(array $screens): self
    {
        $this->tui->screens($screens);

        return $this;
    }

    /** @param list<Binding> $bindings */
    public function globalBindings(array $bindings): self
    {
        $this->tui->globalBindings($bindings);

        return $this;
    }

    public function stageConfig(StageConfig $config): self
    {
        $this->tui->stageConfig($config);

        return $this;
    }

    public function theme(Theme $theme): self
    {
        $this->tui->theme($theme);

        return $this;
    }

    public function devtools(bool $enabled = true): self
    {
        $this->tui->devtools($enabled);

        return $this;
    }

    /** @param ServiceBundle|Closure(TheatronApp): ServiceBundle ...$providers */
    public function providers(ServiceBundle|Closure ...$providers): self
    {
        $this->providers = [...$this->providers, ...array_values($providers)];

        return $this;
    }

    public function build(): TheatronApp
    {
        $this->requirePrimary();

        return $this->tui->build();
    }

    public function run(): int
    {
        $app = $this->build();
        $interval = $this->tickIntervalSeconds;

        Application::starting($this->context->values)
            ->providers(...$this->resolvedProviders($app))
            ->run(static function (ExecutionScope $scope) use ($app, $interval): void {
                $runtime = $scope->service(AgentHarnessRuntime::class);
                if (!$runtime instanceof AgentHarnessRuntime) {
                    throw new \RuntimeException('AgentHarness runtime service did not resolve.');
                }

                $scope->periodic($interval, static function () use ($runtime, $scope): void {
                    $runtime->tick($scope);
                });

                $app->start($scope);
            });

        return 0;
    }

    /** @return class-string<\Phalanx\Theatron\Tui\Reactive\Store>|null */
    public function registeredStore(): ?string
    {
        return $this->tui->registeredStore();
    }

    /** @return list<class-string<Screen>> */
    public function registeredScreens(): array
    {
        return $this->tui->registeredScreens();
    }

    /** @return list<ServiceBundle|Closure(TheatronApp): ServiceBundle> */
    public function registeredProviders(): array
    {
        return $this->providers;
    }

    /** @return list<ServiceBundle> */
    public function resolvedProviders(TheatronApp $app): array
    {
        $primary = $this->requirePrimary();

        return [
            new TheatronServiceBundle($app),
            new AgentHarnessServiceBundle(
                primary: $primary,
                preparers: $this->preparers,
                participants: $this->participants,
                reactors: $this->reactors,
                reviewers: $this->reviewers,
                inlets: $this->inlets,
                outlets: $this->outlets,
                maxReviewPasses: $this->maxReviewPasses,
            ),
            ...array_map(
                static fn(ServiceBundle|Closure $provider): ServiceBundle => $provider instanceof Closure
                    ? $provider($app)
                    : $provider,
                $this->providers,
            ),
        ];
    }

    private function requirePrimary(): AgentParticipant
    {
        return $this->primary ?? throw new InvalidArgumentException('A primary AgentHarness participant is required.');
    }
}
