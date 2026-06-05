<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Apps;

use InvalidArgumentException;
use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Tui\Tui\Inputs\Binding;
use Phalanx\Tui\Tui\Core\ScreenContext;
use Phalanx\Tui\Tui\Core\Screen;
use Phalanx\Tui\Tui\Reactive\SignalRegistry;
use Phalanx\Tui\Tui\Drawing\ScreenMode;
use Phalanx\Tui\Tui\Drawing\StageConfig;
use Phalanx\Tui\Tui\Reactive\Store;
use Phalanx\Tui\Tui\Tdom\Renderable;
use Phalanx\Tui\Tui;
use Phalanx\Tui\Tui\Apps\TuiApp;
use Phalanx\Tui\Tui\Apps\TuiBuilder;
use Phalanx\Tui\Tui\Apps\TuiServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OlympusScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return \Phalanx\Tui\Tui\Kit\text('Olympus');
    }
}

final class SpartaScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return \Phalanx\Tui\Tui\Kit\text('Sparta');
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

final class TuiBuilderTest extends TestCase
{
    #[Test]
    public function facadeReturnsBuilder(): void
    {
        $builder = Tui::app([]);

        self::assertInstanceOf(TuiBuilder::class, $builder);
    }

    #[Test]
    public function builderHoldsContext(): void
    {
        $builder = Tui::app(['APP_ENV' => 'test']);

        self::assertInstanceOf(AppContext::class, $builder->context);
        self::assertSame('test', $builder->context->get('APP_ENV'));
    }

    #[Test]
    public function buildRequiresAtLeastOneScreen(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('screen');

        Tui::app()->build();
    }

    #[Test]
    public function screensMethodRejectsEmptyArray(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Tui::app()->screens([]);
    }

    #[Test]
    public function buildProducesTuiApp(): void
    {
        $app = Tui::app()
            ->screens([OlympusScreen::class])
            ->build();

        self::assertInstanceOf(TuiApp::class, $app);
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

        $app = Tui::app()
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
        $app = Tui::app()
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
        $app = Tui::app()
            ->screens([OlympusScreen::class, SpartaScreen::class])
            ->build();

        self::assertSame(OlympusScreen::class, $app->screens()[0]);
    }

    #[Test]
    public function allRegisteredScreensArePreserved(): void
    {
        $app = Tui::app()
            ->screens([OlympusScreen::class, SpartaScreen::class])
            ->build();

        self::assertSame([OlympusScreen::class, SpartaScreen::class], $app->screens());
    }

    #[Test]
    public function globalBindingsArePassedToApp(): void
    {
        $binding = Binding::ctrl('c')->quit();

        $app = Tui::app()
            ->screens([OlympusScreen::class])
            ->globalBindings([$binding])
            ->build();

        self::assertCount(1, $app->globalBindings());
        self::assertSame($binding, $app->globalBindings()[0]);
    }

    #[Test]
    public function defaultGlobalBindingsIncludeQuit(): void
    {
        $app = Tui::app()
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
        $app = Tui::app()
            ->screens([OlympusScreen::class])
            ->store(ZeusStore::class)
            ->build();

        self::assertSame(ZeusStore::class, $app->storeClass);
    }

    #[Test]
    public function noStoreByDefault(): void
    {
        $app = Tui::app()
            ->screens([OlympusScreen::class])
            ->build();

        self::assertNull($app->storeClass);
    }

    #[Test]
    public function devtoolsDefaultsToFalse(): void
    {
        $app = Tui::app()
            ->screens([OlympusScreen::class])
            ->build();

        self::assertFalse($app->devtools);
    }

    #[Test]
    public function devtoolsCanBeEnabled(): void
    {
        $app = Tui::app()
            ->screens([OlympusScreen::class])
            ->devtools()
            ->build();

        self::assertTrue($app->devtools);
    }

    #[Test]
    public function devtoolsBuildCreatesSignalRegistry(): void
    {
        $app = Tui::app()
            ->screens([OlympusScreen::class])
            ->devtools()
            ->build();

        self::assertInstanceOf(SignalRegistry::class, $app->registry);
    }

    #[Test]
    public function buildWithoutDevtoolsHasNullRegistry(): void
    {
        $app = Tui::app()
            ->screens([OlympusScreen::class])
            ->build();

        self::assertNull($app->registry);
    }

    #[Test]
    public function builderIsFluentChainable(): void
    {
        $builder = Tui::app();
        $returned = $builder->screens([OlympusScreen::class]);

        self::assertSame($builder, $returned);
    }

    #[Test]
    public function registeredScreensIntrospection(): void
    {
        $builder = Tui::app()
            ->screens([OlympusScreen::class, SpartaScreen::class]);

        self::assertSame([OlympusScreen::class, SpartaScreen::class], $builder->registeredScreens());
    }

    #[Test]
    public function registeredStoreIntrospection(): void
    {
        $builder = Tui::app()
            ->screens([OlympusScreen::class])
            ->store(ZeusStore::class);

        self::assertSame(ZeusStore::class, $builder->registeredStore());
    }

    #[Test]
    public function registeredGlobalBindingsIntrospection(): void
    {
        $binding = Binding::ctrl('q')->quit();
        $builder = Tui::app()
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
        $builder = Tui::app()
            ->screens([OlympusScreen::class])
            ->providers($bundle);

        self::assertSame([$bundle], $builder->registeredProviders());
    }

    #[Test]
    public function providerFactoriesAreResolvedAgainstBuiltApp(): void
    {
        $received = null;
        $builder = Tui::app()
            ->screens([OlympusScreen::class])
            ->providers(
                static function (TuiApp $app) use (&$received): TuiServiceBundle {
                    $received = $app;

                    return new TuiServiceBundle($app);
                },
            );
        $app = $builder->build();
        $method = new ReflectionMethod($builder, 'resolvedProviders');
        $providers = $method->invoke($builder, $app);

        self::assertIsArray($providers);
        self::assertCount(1, $providers);
        self::assertInstanceOf(TuiServiceBundle::class, $providers[0]);
        self::assertSame($app, $received);
    }

    #[Test]
    public function multipleBindingsAllPreserved(): void
    {
        $quit = Binding::ctrl('c')->quit();
        $switch = Binding::ctrl('1')->workspace(SpartaScreen::class)->label('Sparta');

        $app = Tui::app()
            ->screens([OlympusScreen::class, SpartaScreen::class])
            ->globalBindings([$quit, $switch])
            ->build();

        self::assertCount(2, $app->globalBindings());
    }
}
