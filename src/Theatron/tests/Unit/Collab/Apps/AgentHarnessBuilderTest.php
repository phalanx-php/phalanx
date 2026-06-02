<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Collab\Apps;

use InvalidArgumentException;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Theatron\Collab\Apps\AgentHarnessBuilder;
use Phalanx\Theatron\Collab\Apps\AgentHarnessRuntime;
use Phalanx\Theatron\Collab\Apps\AgentHarnessServiceBundle;
use Phalanx\Theatron\Collab\Boundaries\BoundaryRunner;
use Phalanx\Theatron\Collab\Boundaries\InputPromptSubmitter;
use Phalanx\Theatron\Collab\Lifecycle\AgentHarnessLoop;
use Phalanx\Theatron\Collab\Participants\AgentParticipant;
use Phalanx\Theatron\Collab\Plans\Activity;
use Phalanx\Theatron\Collab\Plans\WorkItem;
use Phalanx\Theatron\Collab\Plans\WorkPlan;
use Phalanx\Theatron\Collab\Plans\WorkPlanItem;
use Phalanx\Theatron\Collab\Plans\WorkPlanStatus;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\Screens\WorkspaceScreen;
use Phalanx\Theatron\Collab\State\AgentHarnessStore;
use Phalanx\Theatron\Collab\State\WorkPlanSlice;
use Phalanx\Theatron\Collab\WorkContext;
use Phalanx\Theatron\Tests\Support\RecordingTaskScope;
use Phalanx\Theatron\Theatron;
use Phalanx\Theatron\Tui\Apps\TheatronApp;
use Phalanx\Theatron\Tui\Apps\TheatronServiceBundle;
use Phalanx\Theatron\Tui\Drawing\ScreenMode;
use Phalanx\Theatron\Tui\Drawing\StageConfig;
use PHPUnit\Framework\Attributes\Test;

final class AgentHarnessBuilderTest extends PhalanxTestCase
{
    #[Test]
    public function facadeReturnsAgentHarnessBuilder(): void
    {
        $builder = Theatron::agentHarness(['APP_ENV' => 'test']);

        self::assertInstanceOf(AgentHarnessBuilder::class, $builder);
        self::assertInstanceOf(AppContext::class, $builder->context);
        self::assertSame('test', $builder->context->get('APP_ENV'));
    }

    #[Test]
    public function buildRequiresPrimaryAgentParticipant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('primary');

        Theatron::agentHarness()->build();
    }

    #[Test]
    public function builderDefaultsToAgentHarnessStoreAndWorkspaceScreen(): void
    {
        $builder = Theatron::agentHarness()
            ->primary(new BuilderDoneAgentParticipant(new \ArrayObject()));

        self::assertSame(AgentHarnessStore::class, $builder->registeredStore());
        self::assertSame([WorkspaceScreen::class], $builder->registeredScreens());
        self::assertInstanceOf(TheatronApp::class, $builder->build());
    }

    #[Test]
    public function resolvedProvidersIncludeTuiAndCollabBundlesBeforeUserProviders(): void
    {
        $builder = Theatron::agentHarness()
            ->primary(new BuilderDoneAgentParticipant(new \ArrayObject()))
            ->providers(new BuilderExtraBundle());

        $providers = $builder->resolvedProviders($builder->build());

        self::assertInstanceOf(TheatronServiceBundle::class, $providers[0]);
        self::assertInstanceOf(AgentHarnessServiceBundle::class, $providers[1]);
        self::assertInstanceOf(BuilderExtraBundle::class, $providers[2]);
    }

    #[Test]
    public function inputSubmissionRunsThroughBuilderRegisteredRuntime(): void
    {
        $calls = new \ArrayObject();
        $builder = Theatron::agentHarness()
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
