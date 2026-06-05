<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Collab\Apps;

use InvalidArgumentException;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Tui\Collab\Apps\AgentHarnessBuilder;
use Phalanx\Tui\Collab\Apps\AgentHarnessRuntime;
use Phalanx\Tui\Collab\Apps\AgentHarnessServiceBundle;
use Phalanx\Tui\Collab\Boundaries\BoundaryRunner;
use Phalanx\Tui\Collab\Boundaries\InputPromptSubmitter;
use Phalanx\Tui\Collab\Lifecycle\AgentHarnessLoop;
use Phalanx\Tui\Collab\Participants\AgentParticipant;
use Phalanx\Tui\Collab\Plans\Activity;
use Phalanx\Tui\Collab\Plans\WorkItem;
use Phalanx\Tui\Collab\Plans\WorkPlan;
use Phalanx\Tui\Collab\Plans\WorkPlanItem;
use Phalanx\Tui\Collab\Plans\WorkPlanStatus;
use Phalanx\Tui\Collab\Plans\WorkResult;
use Phalanx\Tui\Collab\Screens\WorkspaceScreen;
use Phalanx\Tui\Collab\State\AgentHarnessStore;
use Phalanx\Tui\Collab\State\WorkPlanSlice;
use Phalanx\Tui\Collab\WorkContext;
use Phalanx\Tui\Tests\Support\RecordingTaskScope;
use Phalanx\Tui\Tui;
use Phalanx\Tui\Tui\Apps\TuiApp;
use Phalanx\Tui\Tui\Apps\TuiServiceBundle;
use Phalanx\Tui\Tui\Drawing\ScreenMode;
use Phalanx\Tui\Tui\Drawing\StageConfig;
use PHPUnit\Framework\Attributes\Test;

final class AgentHarnessBuilderTest extends PhalanxTestCase
{
    #[Test]
    public function facadeReturnsAgentHarnessBuilder(): void
    {
        $builder = Tui::agentHarness(['APP_ENV' => 'test']);

        self::assertInstanceOf(AgentHarnessBuilder::class, $builder);
        self::assertInstanceOf(AppContext::class, $builder->context);
        self::assertSame('test', $builder->context->get('APP_ENV'));
    }

    #[Test]
    public function buildRequiresPrimaryAgentParticipant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('primary');

        Tui::agentHarness()->build();
    }

    #[Test]
    public function builderDefaultsToAgentHarnessStoreAndWorkspaceScreen(): void
    {
        $builder = Tui::agentHarness()
            ->primary(new BuilderDoneAgentParticipant(new \ArrayObject()));

        self::assertSame(AgentHarnessStore::class, $builder->registeredStore());
        self::assertSame([WorkspaceScreen::class], $builder->registeredScreens());
        self::assertInstanceOf(TuiApp::class, $builder->build());
    }

    #[Test]
    public function resolvedProvidersIncludeTuiAndAgentHarnessBundlesBeforeUserProviders(): void
    {
        $builder = Tui::agentHarness()
            ->primary(new BuilderDoneAgentParticipant(new \ArrayObject()))
            ->providers(new BuilderExtraBundle());

        $providers = $builder->resolvedProviders($builder->build());

        self::assertInstanceOf(TuiServiceBundle::class, $providers[0]);
        self::assertInstanceOf(AgentHarnessServiceBundle::class, $providers[1]);
        self::assertInstanceOf(BuilderExtraBundle::class, $providers[2]);
    }

    #[Test]
    public function inputSubmissionRunsThroughBuilderRegisteredRuntime(): void
    {
        $calls = new \ArrayObject();
        $builder = Tui::agentHarness()
            ->primary(new BuilderDoneAgentParticipant($calls))
            ->stageConfig(self::stageConfig());
        $app = $builder->build();
        $testApp = $this->testApp([], ...$builder->resolvedProviders($app));

        $testApp->application->scoped(static function (ExecutionScope $scope) use ($calls): void {
            $submit = $scope->service(InputPromptSubmitter::class);
            $runtime = $scope->service(AgentHarnessRuntime::class);
            $store = $scope->service(AgentHarnessStore::class);

            self::assertInstanceOf(InputPromptSubmitter::class, $submit);
            self::assertInstanceOf(AgentHarnessRuntime::class, $runtime);
            self::assertInstanceOf(AgentHarnessStore::class, $store);

            $submit('Lock the public builder');
            $status = $runtime->tick($scope);

            self::assertSame(WorkPlanStatus::Complete, $status);
            self::assertSame(['Lock the public builder'], $calls->getArrayCopy());
            self::assertSame('Lock the public builder', $store->messages->envelopes[0]->payload);
        });
    }

    #[Test]
    public function runtimeRunsPreseededReadyWorkWithoutInlets(): void
    {
        $calls = new \ArrayObject();
        $store = new AgentHarnessStore();
        $store->workPlan = new WorkPlanSlice(WorkPlan::start(new WorkItem(
            Activity::Testing,
            'Run preseeded work',
            id: 'work_preseeded',
        )));
        $runtime = new AgentHarnessRuntime(
            runner: new BoundaryRunner(new AgentHarnessLoop(primary: new BuilderDoneAgentParticipant($calls))),
            store: $store,
        );

        $status = $runtime->tick(new RecordingTaskScope());

        self::assertSame(WorkPlanStatus::Complete, $status);
        self::assertSame(['Run preseeded work'], $calls->getArrayCopy());
        self::assertSame(WorkPlanStatus::Complete, $store->workPlan->plan->status);
    }

    private static function stageConfig(): StageConfig
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        return new StageConfig(
            screenMode: ScreenMode::Inline,
            handleInput: false,
            stream: $stream,
            env: [
                'COLUMNS' => '40',
                'LINES' => '12',
            ],
        );
    }
}

final class BuilderDoneAgentParticipant implements AgentParticipant
{
    public function __construct(
        /** @var \ArrayObject<int, string> */
        private \ArrayObject $calls,
    ) {
    }

    public function __invoke(WorkPlanItem $item, WorkContext $ctx): WorkResult
    {
        $this->calls[] = $item->workItem->prompt;

        return WorkResult::done($item->workItem->id);
    }

    public function supports(WorkPlanItem $item, WorkContext $ctx): bool
    {
        return true;
    }
}

final class BuilderExtraBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
    }
}
