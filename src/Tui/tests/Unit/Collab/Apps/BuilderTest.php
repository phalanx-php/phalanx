<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Collab\Apps;

use InvalidArgumentException;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Tui\Collab\Apps\Builder;
use Phalanx\Tui\Collab\Apps\Runtime;
use Phalanx\Tui\Collab\Apps\Bundle as CollabBundle;
use Phalanx\Tui\Collab\Boundaries\BoundaryRunner;
use Phalanx\Tui\Collab\Boundaries\InputPromptSubmitter;
use Phalanx\Tui\Collab\Lifecycle\Loop;
use Phalanx\Tui\Collab\Participants\AgentParticipant;
use Phalanx\Tui\Collab\Plans\Activity;
use Phalanx\Tui\Collab\Plans\WorkItem;
use Phalanx\Tui\Collab\Plans\WorkPlan;
use Phalanx\Tui\Collab\Plans\WorkPlanItem;
use Phalanx\Tui\Collab\Plans\WorkPlanStatus;
use Phalanx\Tui\Collab\Plans\WorkResult;
use Phalanx\Tui\Collab\Screens\WorkspaceScreen;
use Phalanx\Tui\Collab\State\Store;
use Phalanx\Tui\Collab\State\WorkPlanSlice;
use Phalanx\Tui\Collab\WorkContext;
use Phalanx\Tui\Tests\Support\RecordingTaskScope;
use Phalanx\Tui\Facade;
use Phalanx\Tui\Apps\App;
use Phalanx\Tui\Apps\Bundle as TuiBundle;
use Phalanx\Tui\Drawing\ScreenMode;
use Phalanx\Tui\Drawing\StageConfig;
use PHPUnit\Framework\Attributes\Test;

final class BuilderTest extends PhalanxTestCase
{
    #[Test]
    public function facadeReturnsCollabBuilder(): void
    {
        $builder = Facade::collab(['APP_ENV' => 'test']);

        self::assertInstanceOf(Builder::class, $builder);
        self::assertInstanceOf(AppContext::class, $builder->context);
        self::assertSame('test', $builder->context->get('APP_ENV'));
    }

    #[Test]
    public function buildRequiresPrimaryAgentParticipant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('primary');

        Facade::collab()->build();
    }

    #[Test]
    public function builderDefaultsToCollabStoreAndWorkspaceScreen(): void
    {
        $builder = Facade::collab()
            ->primary(new BuilderDoneAgentParticipant(new \ArrayObject()));

        self::assertSame(Store::class, $builder->registeredStore());
        self::assertSame([WorkspaceScreen::class], $builder->registeredScreens());
        self::assertInstanceOf(App::class, $builder->build());
    }

    #[Test]
    public function resolvedProvidersIncludeTuiAndCollabBundlesBeforeUserProviders(): void
    {
        $builder = Facade::collab()
            ->primary(new BuilderDoneAgentParticipant(new \ArrayObject()))
            ->providers(new BuilderExtraBundle());

        $providers = $builder->resolvedProviders($builder->build());

        self::assertInstanceOf(TuiBundle::class, $providers[0]);
        self::assertInstanceOf(CollabBundle::class, $providers[1]);
        self::assertInstanceOf(BuilderExtraBundle::class, $providers[2]);
    }

    #[Test]
    public function inputSubmissionRunsThroughBuilderRegisteredRuntime(): void
    {
        $calls = new \ArrayObject();
        $builder = Facade::collab()
            ->primary(new BuilderDoneAgentParticipant($calls))
            ->stageConfig(self::stageConfig());
        $app = $builder->build();
        $testApp = $this->testApp([], ...$builder->resolvedProviders($app));

        $testApp->application->scoped(static function (ExecutionScope $scope) use ($calls): void {
            $submit = $scope->service(InputPromptSubmitter::class);
            $runtime = $scope->service(Runtime::class);
            $store = $scope->service(Store::class);

            self::assertInstanceOf(InputPromptSubmitter::class, $submit);
            self::assertInstanceOf(Runtime::class, $runtime);
            self::assertInstanceOf(Store::class, $store);

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
        $store = new Store();
        $store->workPlan = new WorkPlanSlice(WorkPlan::start(new WorkItem(
            Activity::Testing,
            'Run preseeded work',
            id: 'work_preseeded',
        )));
        $runtime = new Runtime(
            runner: new BoundaryRunner(new Loop(primary: new BuilderDoneAgentParticipant($calls))),
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
