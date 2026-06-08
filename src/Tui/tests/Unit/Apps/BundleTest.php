<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Apps;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceCatalog;
use Phalanx\Stream\ResourceHandle;
use Phalanx\Stream\Stream;
use Phalanx\Tui\Inputs\BindingRegistry;
use Phalanx\Tui\Core\ScreenContext;
use Phalanx\Tui\Core\HasRuntimeContext;
use Phalanx\Tui\Core\Screen;
use Phalanx\Tui\Drawing\Stage;
use Phalanx\Tui\Drawing\StageConfig;
use Phalanx\Tui\Reactive\Store;
use Phalanx\Tui\Styles\Theme;
use Phalanx\Tui\Tdom\Renderable;
use Phalanx\Tui\Apps\App;
use Phalanx\Tui\Apps\Bundle;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApolloStore extends Store
{
    public function __construct()
    {
    }
}

final class ContextAwareStore extends Store implements HasRuntimeContext
{
    public ?AppContext $context = null;

    public function receiveRuntimeContext(AppContext $context): void
    {
        $this->context = $context;
    }
}

final class BundleTest extends TestCase
{
    /** @var list<ResourceHandle> */
    private array $streams = [];

    #[After]
    protected function closeStreams(): void
    {
        foreach ($this->streams as $stream) {
            $stream->close();
        }

        $this->streams = [];
    }

    #[Test]
    public function registersStageAsSingleton(): void
    {
        $catalog = new ServiceCatalog();
        $app = $this->app();
        $bundle = new Bundle($app);
        $bundle->services($catalog, new AppContext());

        self::assertTrue($catalog->has(Stage::class));
    }

    #[Test]
    public function registersProvidedStageInstance(): void
    {
        $catalog = new ServiceCatalog();
        $app = $this->app();
        $bundle = new Bundle($app);
        $bundle->services($catalog, new AppContext());

        $factory = $catalog->compile()->resolve(Stage::class)->factoryFn;
        self::assertNotNull($factory);

        self::assertSame($app->stage, $factory());
    }

    #[Test]
    public function registersAppStageConfigAsSingleton(): void
    {
        $catalog = new ServiceCatalog();
        $app = $this->app();
        $bundle = new Bundle($app);
        $bundle->services($catalog, new AppContext());

        $factory = $catalog->compile()->resolve(StageConfig::class)->factoryFn;
        self::assertNotNull($factory);

        self::assertSame($app->stage->config, $factory());
    }

    #[Test]
    public function registersBindingRegistryAsSingleton(): void
    {
        $catalog = new ServiceCatalog();
        $bundle = new Bundle($this->app());
        $bundle->services($catalog, new AppContext());

        self::assertTrue($catalog->has(BindingRegistry::class));
    }

    #[Test]
    public function registersThemeAsSingleton(): void
    {
        $catalog = new ServiceCatalog();
        $bundle = new Bundle($this->app());
        $bundle->services($catalog, new AppContext());

        self::assertTrue($catalog->has(Theme::class));
    }

    #[Test]
    public function doesNotRegisterStoreWhenNotConfigured(): void
    {
        $catalog = new ServiceCatalog();
        $bundle = new Bundle($this->app());
        $bundle->services($catalog, new AppContext());

        self::assertFalse($catalog->has(ApolloStore::class));
    }

    #[Test]
    public function registersStoreWhenConfigured(): void
    {
        $catalog = new ServiceCatalog();
        $bundle = new Bundle($this->app(storeClass: ApolloStore::class));
        $bundle->services($catalog, new AppContext());

        self::assertTrue($catalog->has(ApolloStore::class));
    }

    #[Test]
    public function passesRuntimeContextToContextAwareStore(): void
    {
        $context = new AppContext([
            'PWD' => '/workspace/phalanx',
            'HOME' => '/workspace',
        ]);
        $catalog = new ServiceCatalog();
        $bundle = new Bundle($this->app(storeClass: ContextAwareStore::class));
        $bundle->services($catalog, $context);

        $factory = $catalog->compile()->resolve(ContextAwareStore::class)->factoryFn;
        self::assertNotNull($factory);

        $store = $factory();

        self::assertInstanceOf(ContextAwareStore::class, $store);
        self::assertSame($context, $store->context);
    }

    #[Test]
    public function aliasesStoreBaseClassToConfiguredStore(): void
    {
        $catalog = new ServiceCatalog();
        $bundle = new Bundle($this->app(storeClass: ApolloStore::class));
        $bundle->services($catalog, new AppContext());

        self::assertSame(ApolloStore::class, $catalog->compile()->alias(Store::class));
    }

    #[Test]
    public function appIsStoredOnBundle(): void
    {
        $app = $this->app(storeClass: ApolloStore::class);
        $bundle = new Bundle($app);

        self::assertSame($app, $bundle->app);
    }

    #[Test]
    public function registersAppTheme(): void
    {
        $catalog = new ServiceCatalog();
        $theme = Theme::default();
        $app = $this->app(theme: $theme);
        $bundle = new Bundle($app);
        $bundle->services($catalog, new AppContext());

        $factory = $catalog->compile()->resolve(Theme::class)->factoryFn;
        self::assertNotNull($factory);

        self::assertSame($theme, $factory());
    }

    /** @param class-string<Store>|null $storeClass */
    private function app(?string $storeClass = null, ?Theme $theme = null): App
    {
        $stream = $this->streams[] = Stream::memoryBuffer();

        return new App(
            Stage::boot(new StageConfig(
                handleInput: false,
                stream: $stream->resource(),
                env: [
                    'COLUMNS' => '20',
                    'LINES' => '5',
                ],
            )),
            $theme ?? Theme::default(),
            [BundleProbeScreen::class],
            [],
            $storeClass,
            false,
        );
    }
}

final class BundleProbeScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return \Phalanx\Tui\Kit\text('service bundle probe');
    }
}
