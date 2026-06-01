<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Collab\Apps;

use InvalidArgumentException;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Theatron\Collab\Apps\CollabBuilder;
use Phalanx\Theatron\Collab\Apps\CollabRuntime;
use Phalanx\Theatron\Collab\Apps\CollabServiceBundle;
use Phalanx\Theatron\Collab\Boundaries\InputPromptSubmitter;
use Phalanx\Theatron\Collab\Participants\Collaborator;
use Phalanx\Theatron\Collab\Plans\WorkPlanItem;
use Phalanx\Theatron\Collab\Plans\WorkPlanStatus;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\Screens\WorkspaceScreen;
use Phalanx\Theatron\Collab\State\CollabStore;
use Phalanx\Theatron\Collab\WorkContext;
use Phalanx\Theatron\Theatron;
use Phalanx\Theatron\Tui\Apps\TheatronApp;
use Phalanx\Theatron\Tui\Apps\TheatronServiceBundle;
use Phalanx\Theatron\Tui\Drawing\ScreenMode;
use Phalanx\Theatron\Tui\Drawing\StageConfig;
use PHPUnit\Framework\Attributes\Test;

final class CollabBuilderTest extends PhalanxTestCase
{
    #[Test]
    public function facadeReturnsCollabBuilder(): void
    {
        $builder = Theatron::collab(['APP_ENV' => 'test']);

        self::assertInstanceOf(CollabBuilder::class, $builder);
        self::assertInstanceOf(AppContext::class, $builder->context);
        self::assertSame('test', $builder->context->get('APP_ENV'));
    }

    #[Test]
    public function buildRequiresPrimaryCollaborator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('primary');

        Theatron::collab()->build();
    }

    #[Test]
    public function builderDefaultsToCollabStoreAndWorkspaceScreen(): void
    {
        $builder = Theatron::collab()
            ->primary(new BuilderDoneCollaborator(new \ArrayObject()));

        self::assertSame(CollabStore::class, $builder->registeredStore());
        self::assertSame([WorkspaceScreen::class], $builder->registeredScreens());
        self::assertInstanceOf(TheatronApp::class, $builder->build());
    }

    #[Test]
    public function resolvedProvidersIncludeTuiAndCollabBundlesBeforeUserProviders(): void
    {
        $builder = Theatron::collab()
            ->primary(new BuilderDoneCollaborator(new \ArrayObject()))
            ->providers(new BuilderExtraBundle());

        $providers = $builder->resolvedProviders($builder->build());

        self::assertInstanceOf(TheatronServiceBundle::class, $providers[0]);
        self::assertInstanceOf(CollabServiceBundle::class, $providers[1]);
        self::assertInstanceOf(BuilderExtraBundle::class, $providers[2]);
    }

    #[Test]
    public function inputSubmissionRunsThroughBuilderRegisteredRuntime(): void
    {
        $calls = new \ArrayObject();
        $builder = Theatron::collab()
            ->primary(new BuilderDoneCollaborator($calls))
            ->stageConfig(self::stageConfig());
        $app = $builder->build();
        $testApp = $this->testApp([], ...$builder->resolvedProviders($app));

        $testApp->application->scoped(static function (ExecutionScope $scope) use ($calls): void {
            $submit = $scope->service(InputPromptSubmitter::class);
            $runtime = $scope->service(CollabRuntime::class);
            $store = $scope->service(CollabStore::class);

            self::assertInstanceOf(InputPromptSubmitter::class, $submit);
            self::assertInstanceOf(CollabRuntime::class, $runtime);
            self::assertInstanceOf(CollabStore::class, $store);

            $submit('Lock the public builder');
            $status = $runtime->tick($scope);

            self::assertSame(WorkPlanStatus::Complete, $status);
            self::assertSame(['Lock the public builder'], $calls->getArrayCopy());
            self::assertSame('Lock the public builder', $store->messages->envelopes[0]->payload);
        });
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

final class BuilderDoneCollaborator implements Collaborator
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
