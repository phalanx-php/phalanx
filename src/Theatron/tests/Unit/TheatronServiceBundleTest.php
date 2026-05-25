<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceCatalog;
use Phalanx\Theatron\Binding\BindingRegistry;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\State\Store;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\TheatronApp;
use Phalanx\Theatron\TheatronServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApolloStore extends Store
{
    public function __construct()
    {
    }
}

final class TheatronServiceBundleTest extends TestCase
{
    #[Test]
    public function registersStageAsSingleton(): void
    {
        $catalog = new ServiceCatalog(new AppContext());
        $app = $this->app();
        $bundle = new TheatronServiceBundle($app);
        $bundle->services($catalog, new AppContext());

        self::assertTrue($catalog->has(Stage::class));
    }

    #[Test]
    public function registersProvidedStageInstance(): void
    {
        $catalog = new ServiceCatalog(new AppContext());
        $app = $this->app();
        $bundle = new TheatronServiceBundle($app);
        $bundle->services($catalog, new AppContext());

        $factory = $catalog->compile()->resolve(Stage::class)->factoryFn;
        self::assertNotNull($factory);

        self::assertSame($app->stage, $factory());
    }

    #[Test]
    public function registersAppStageConfigAsSingleton(): void
    {
        $catalog = new ServiceCatalog(new AppContext());
        $app = $this->app();
        $bundle = new TheatronServiceBundle($app);
        $bundle->services($catalog, new AppContext());

        $factory = $catalog->compile()->resolve(StageConfig::class)->factoryFn;
        self::assertNotNull($factory);

        self::assertSame($app->stage->config, $factory());
    }

    #[Test]
    public function registersBindingRegistryAsSingleton(): void
    {
        $catalog = new ServiceCatalog(new AppContext());
        $bundle = new TheatronServiceBundle($this->app());
        $bundle->services($catalog, new AppContext());

        self::assertTrue($catalog->has(BindingRegistry::class));
    }

    #[Test]
    public function registersThemeAsSingleton(): void
    {
        $catalog = new ServiceCatalog(new AppContext());
        $bundle = new TheatronServiceBundle($this->app());
        $bundle->services($catalog, new AppContext());

        self::assertTrue($catalog->has(Theme::class));
    }

    #[Test]
    public function doesNotRegisterStoreWhenNotConfigured(): void
    {
        $catalog = new ServiceCatalog(new AppContext());
        $bundle = new TheatronServiceBundle($this->app());
        $bundle->services($catalog, new AppContext());

        self::assertFalse($catalog->has(ApolloStore::class));
    }

    #[Test]
    public function registersStoreWhenConfigured(): void
    {
        $catalog = new ServiceCatalog(new AppContext());
        $bundle = new TheatronServiceBundle($this->app(storeClass: ApolloStore::class));
        $bundle->services($catalog, new AppContext());

        self::assertTrue($catalog->has(ApolloStore::class));
    }

    #[Test]
    public function seedsAppStoreRuntimeStatusFromContext(): void
    {
        $context = new AppContext([
            'PWD' => '/workspace/phalanx',
            'HOME' => '/workspace',
        ]);
        $catalog = new ServiceCatalog($context);
        $bundle = new TheatronServiceBundle($this->app(storeClass: AppStore::class));
        $bundle->services($catalog, $context);

        $factory = $catalog->compile()->resolve(AppStore::class)->factoryFn;
        self::assertNotNull($factory);

        $store = $factory();

        self::assertInstanceOf(AppStore::class, $store);
        self::assertSame('~/phalanx', $store->runtimeStatus->cwdLabel());
    }

    #[Test]
    public function aliasesStoreBaseClassToConfiguredStore(): void
    {
        $catalog = new ServiceCatalog(new AppContext());
        $bundle = new TheatronServiceBundle($this->app(storeClass: ApolloStore::class));
        $bundle->services($catalog, new AppContext());

        self::assertSame(ApolloStore::class, $catalog->compile()->alias(Store::class));
    }

    #[Test]
    public function appIsStoredOnBundle(): void
    {
        $app = $this->app(storeClass: ApolloStore::class);
        $bundle = new TheatronServiceBundle($app);

        self::assertSame($app, $bundle->app);
    }

    #[Test]
    public function registersAppTheme(): void
    {
        $catalog = new ServiceCatalog(new AppContext());
        $theme = Theme::default();
        $app = $this->app(theme: $theme);
        $bundle = new TheatronServiceBundle($app);
        $bundle->services($catalog, new AppContext());

        $factory = $catalog->compile()->resolve(Theme::class)->factoryFn;
        self::assertNotNull($factory);

        self::assertSame($theme, $factory());
    }

    /** @param class-string<Store>|null $storeClass */
    private function app(?string $storeClass = null, ?Theme $theme = null): TheatronApp
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        return new TheatronApp(
            Stage::boot(new StageConfig(
                handleInput: false,
                stream: $stream,
                env: [
                    'COLUMNS' => '20',
                    'LINES' => '5',
                ],
            )),
            $theme ?? Theme::default(),
            [TheatronServiceBundleProbeScreen::class],
            [],
            $storeClass,
            false,
        );
    }
}

final class TheatronServiceBundleProbeScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text('service bundle probe');
    }
}
