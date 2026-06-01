<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Tui\Apps;

use InvalidArgumentException;
use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Theatron\Tui\Inputs\Binding;
use Phalanx\Theatron\Tui\Core\ScreenContext;
use Phalanx\Theatron\Tui\Core\Screen;
use Phalanx\Theatron\Tui\Reactive\SignalRegistry;
use Phalanx\Theatron\Tui\Drawing\ScreenMode;
use Phalanx\Theatron\Tui\Drawing\StageConfig;
use Phalanx\Theatron\Tui\Reactive\Store;
use Phalanx\Theatron\Tui\Tdom\Renderable;
use Phalanx\Theatron\Theatron;
use Phalanx\Theatron\Tui\Apps\TheatronApp;
use Phalanx\Theatron\Tui\Apps\TheatronBuilder;
use Phalanx\Theatron\Tui\Apps\TheatronServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OlympusScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Tui\Kit\text('Olympus');
    }
}

final class SpartaScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Tui\Kit\text('Sparta');
    }
}

final class ZeusStore extends Store
{
    public function __construct()
    {
    }
}

final class AresBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
    }
}

final class TheatronBuilderTest extends TestCase
{
    #[Test]
    public function facadeReturnsBuilder(): void
    {
        $builder = Theatron::app([]);

        self::assertInstanceOf(TheatronBuilder::class, $builder);
    }

    #[Test]
    public function builderHoldsContext(): void
    {
        $builder = Theatron::app(['APP_ENV' => 'test']);

        self::assertInstanceOf(AppContext::class, $builder->context);
        self::assertSame('test', $builder->context->get('APP_ENV'));
    }

    #[Test]
    public function buildRequiresAtLeastOneScreen(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('screen');

        Theatron::app()->build();
    }

    #[Test]
    public function screensMethodRejectsEmptyArray(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Theatron::app()->screens([]);
    }

    #[Test]
    public function buildProducesTheatronApp(): void
    {
        $app = Theatron::app()
            ->screens([OlympusScreen::class])
            ->build();

        self::assertInstanceOf(TheatronApp::class, $app);
    }

    #[Test]
    public function customStageConfigIsAppliedToBuiltApp(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $config = new StageConfig(
            screenMode: ScreenMode::Inline,
            handleInput: false,
            stream: $stream,
            env: [
                'COLUMNS' => '24',
                'LINES' => '8',
            ],
        );

        $app = Theatron::app()
            ->screens([OlympusScreen::class])
            ->stageConfig($config)
            ->build();

        self::assertSame($config, $app->stage->config);
        self::assertSame(24, $app->stage->width());
        self::assertSame(8, $app->stage->height());
    }

    #[Test]
    public function defaultStageConfigLetsBindingsOwnExitBehavior(): void
    {
        $app = Theatron::app()
            ->screens([OlympusScreen::class])
            ->build();

        self::assertTrue($app->stage->config->handleInput);
        self::assertFalse($app->stage->config->defaultExitHandler);
        self::assertCount(1, $app->globalBindings());
        self::assertSame('c', $app->globalBindings()[0]->key);
        self::assertTrue($app->globalBindings()[0]->ctrl);
        self::assertTrue($app->globalBindings()[0]->action?->isQuit());
    }

    #[Test]
    public function defaultScreenIsFirstInList(): void
    {
        $app = Theatron::app()
            ->screens([OlympusScreen::class, SpartaScreen::class])
            ->build();

        self::assertSame(OlympusScreen::class, $app->screens()[0]);
    }

    #[Test]
    public function allRegisteredScreensArePreserved(): void
    {
        $app = Theatron::app()
            ->screens([OlympusScreen::class, SpartaScreen::class])
            ->build();

        self::assertSame([OlympusScreen::class, SpartaScreen::class], $app->screens());
    }

    #[Test]
    public function globalBindingsArePassedToApp(): void
    {
        $binding = Binding::ctrl('c')->quit();

        $app = Theatron::app()
            ->screens([OlympusScreen::class])
            ->globalBindings([$binding])
            ->build();

        self::assertCount(1, $app->globalBindings());
        self::assertSame($binding, $app->globalBindings()[0]);
    }

    #[Test]
    public function defaultGlobalBindingsIncludeQuit(): void
    {
        $app = Theatron::app()
            ->screens([OlympusScreen::class])
            ->build();

        self::assertCount(1, $app->globalBindings());
        self::assertSame('c', $app->globalBindings()[0]->key);
        self::assertTrue($app->globalBindings()[0]->ctrl);
        self::assertTrue($app->globalBindings()[0]->action?->isQuit());
    }

    #[Test]
    public function storeClassIsPassedToApp(): void
    {
        $app = Theatron::app()
            ->screens([OlympusScreen::class])
            ->store(ZeusStore::class)
            ->build();

        self::assertSame(ZeusStore::class, $app->storeClass);
    }

    #[Test]
    public function noStoreByDefault(): void
    {
        $app = Theatron::app()
            ->screens([OlympusScreen::class])
            ->build();

        self::assertNull($app->storeClass);
    }

    #[Test]
    public function devtoolsDefaultsToFalse(): void
    {
        $app = Theatron::app()
            ->screens([OlympusScreen::class])
            ->build();

        self::assertFalse($app->devtools);
    }

    #[Test]
    public function devtoolsCanBeEnabled(): void
    {
        $app = Theatron::app()
            ->screens([OlympusScreen::class])
            ->devtools()
            ->build();

        self::assertTrue($app->devtools);
    }

    #[Test]
    public function devtoolsBuildCreatesSignalRegistry(): void
    {
        $app = Theatron::app()
            ->screens([OlympusScreen::class])
            ->devtools()
            ->build();

        self::assertInstanceOf(SignalRegistry::class, $app->registry);
    }

    #[Test]
    public function buildWithoutDevtoolsHasNullRegistry(): void
    {
        $app = Theatron::app()
            ->screens([OlympusScreen::class])
            ->build();

        self::assertNull($app->registry);
    }

    #[Test]
    public function builderIsFluentChainable(): void
    {
        $builder = Theatron::app();
        $returned = $builder->screens([OlympusScreen::class]);

        self::assertSame($builder, $returned);
    }

    #[Test]
    public function registeredScreensIntrospection(): void
    {
        $builder = Theatron::app()
            ->screens([OlympusScreen::class, SpartaScreen::class]);

        self::assertSame([OlympusScreen::class, SpartaScreen::class], $builder->registeredScreens());
    }

    #[Test]
    public function registeredStoreIntrospection(): void
    {
        $builder = Theatron::app()
            ->screens([OlympusScreen::class])
            ->store(ZeusStore::class);

        self::assertSame(ZeusStore::class, $builder->registeredStore());
    }

    #[Test]
    public function registeredGlobalBindingsIntrospection(): void
    {
        $binding = Binding::ctrl('q')->quit();
        $builder = Theatron::app()
            ->screens([OlympusScreen::class])
            ->globalBindings([$binding]);

        self::assertCount(2, $builder->registeredGlobalBindings());
        self::assertSame($binding, $builder->registeredGlobalBindings()[0]);
        self::assertSame('c', $builder->registeredGlobalBindings()[1]->key);
        self::assertTrue($builder->registeredGlobalBindings()[1]->ctrl);
    }

    #[Test]
    public function providersAreRegisteredOnTheAppBuilder(): void
    {
        $bundle = new AresBundle();
        $builder = Theatron::app()
            ->screens([OlympusScreen::class])
            ->providers($bundle);

        self::assertSame([$bundle], $builder->registeredProviders());
    }

    #[Test]
    public function providerFactoriesAreResolvedAgainstBuiltApp(): void
    {
        $received = null;
        $builder = Theatron::app()
            ->screens([OlympusScreen::class])
            ->providers(
                static function (TheatronApp $app) use (&$received): TheatronServiceBundle {
                    $received = $app;

                    return new TheatronServiceBundle($app);
                },
            );
        $app = $builder->build();
        $method = new ReflectionMethod($builder, 'resolvedProviders');
        $providers = $method->invoke($builder, $app);

        self::assertIsArray($providers);
        self::assertCount(1, $providers);
        self::assertInstanceOf(TheatronServiceBundle::class, $providers[0]);
        self::assertSame($app, $received);
    }

    #[Test]
    public function multipleBindingsAllPreserved(): void
    {
        $quit = Binding::ctrl('c')->quit();
        $switch = Binding::ctrl('1')->workspace(SpartaScreen::class)->label('Sparta');

        $app = Theatron::app()
            ->screens([OlympusScreen::class, SpartaScreen::class])
            ->globalBindings([$quit, $switch])
            ->build();

        self::assertCount(2, $app->globalBindings());
    }
}
