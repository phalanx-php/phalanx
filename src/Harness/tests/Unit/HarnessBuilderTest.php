<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit;

use Phalanx\Athena\AthenaBundle;
use Phalanx\Athena\Router\InvocationRouter;
use Phalanx\Harness\Agent\AthenaServiceBundle;
use Phalanx\Harness\Agent\TemplateAgent;
use Phalanx\Harness\Harness;
use Phalanx\Harness\HarnessBuilder;
use Phalanx\Harness\Ui\AppStore;
use Phalanx\Harness\Ui\UiApp;
use Phalanx\Iris\HttpServiceBundle;
use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Stage\StageConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('harness')]
final class HarnessBuilderTest extends TestCase
{
    #[Test]
    public function harnessAppReturnsHarnessBuilder(): void
    {
        self::assertInstanceOf(HarnessBuilder::class, Harness::app());
    }

    #[Test]
    public function builderPreConfiguresStoreScreensAndDevtools(): void
    {
        $builder = Harness::app();

        self::assertSame(AppStore::class, $builder->registeredStore());
        self::assertSame(UiApp::screens(), $builder->registeredScreens());
    }

    #[Test]
    public function contextPassesThroughToAppContext(): void
    {
        $builder = Harness::app(['APP_ENV' => 'testing', 'HARNESS_OLLAMA_MODEL' => 'qwen3:4b']);

        self::assertSame('testing', $builder->context->get('APP_ENV'));
        self::assertSame('qwen3:4b', $builder->context->get('HARNESS_OLLAMA_MODEL'));
    }

    #[Test]
    public function agentIsChainable(): void
    {
        self::assertInstanceOf(HarnessBuilder::class, Harness::app()->agent(TemplateAgent::class));
    }

    #[Test]
    public function athenaIsChainable(): void
    {
        $router = $this->createStub(InvocationRouter::class);

        self::assertInstanceOf(HarnessBuilder::class, Harness::app()->athena(new AthenaBundle($router)));
    }

    #[Test]
    public function defaultProvidersAreEmptyBeforeBuild(): void
    {
        self::assertEmpty(Harness::app()->registeredProviders());
    }

    #[Test]
    public function explicitProvidersPreventDefaults(): void
    {
        $bundle = new HttpServiceBundle();
        $builder = Harness::app()->providers($bundle);

        self::assertCount(1, $builder->registeredProviders());
        self::assertSame($bundle, $builder->registeredProviders()[0]);
    }

    #[Test]
    public function buildPopulatesDefaultProviders(): void
    {
        $builder = self::inlineBuilder();
        self::assertEmpty($builder->registeredProviders());

        $builder->build();

        self::assertCount(3, $builder->registeredProviders());
        self::assertNotFalse(array_find(
            $builder->registeredProviders(),
            static fn(mixed $p): bool => $p instanceof AthenaServiceBundle,
        ));
        self::assertNotFalse(array_find(
            $builder->registeredProviders(),
            static fn(mixed $p): bool => $p instanceof HttpServiceBundle,
        ));
    }

    #[Test]
    public function doubleBuildDoesNotDuplicateProviders(): void
    {
        $builder = self::inlineBuilder();
        $builder->build();
        $countAfterFirst = count($builder->registeredProviders());

        $builder->build();

        self::assertCount($countAfterFirst, $builder->registeredProviders());
    }

    #[Test]
    public function globalBindingsDelegatesToTheatronBuilder(): void
    {
        $custom = Binding::key('z')->action(static fn(): null => null);
        $builder = Harness::app()->globalBindings([$custom]);

        self::assertInstanceOf(HarnessBuilder::class, $builder);
        self::assertTrue(array_any(
            $builder->registeredGlobalBindings(),
            static fn(Binding $b): bool => $b->key === 'z',
        ));
    }

    private static function inlineBuilder(): HarnessBuilder
    {
        $stream = fopen('php://memory', 'w+');
        assert(is_resource($stream));

        return Harness::app(['APP_ENV' => 'test'])->stageConfig(new StageConfig(
            handleInput: false,
            defaultExitHandler: false,
            stream: $stream,
            env: ['COLUMNS' => '80', 'LINES' => '24'],
        ));
    }
}
