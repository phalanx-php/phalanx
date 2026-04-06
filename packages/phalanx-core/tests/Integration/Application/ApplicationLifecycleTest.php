<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\Application;

use Phalanx\Application;
use Phalanx\Concurrency\CancellationToken;
use Phalanx\ExecutionScope;
use Phalanx\Tests\Support\Fixtures\CountingService;
use Phalanx\Tests\Support\Fixtures\Logger;
use Phalanx\Tests\Support\TestServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplicationLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        CountingService::reset();
    }

    #[Test]
    public function startup_hooks_run_on_eager_services(): void
    {
        $startupCalled = false;

        $bundle = TestServiceBundle::create()
            ->eager(Logger::class, static fn() => new Logger())
            ->withLifecycle(Logger::class, 'startup', static function (Logger $logger) use (&$startupCalled): void {
                $logger->startup();
                $startupCalled = true;
            });

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $this->assertFalse($startupCalled);

        $app->startup();

        $this->assertTrue($startupCalled);
    }

    #[Test]
    public function startup_is_idempotent(): void
    {
        $callCount = 0;

        $bundle = TestServiceBundle::create()
            ->eager(Logger::class, static fn() => new Logger())
            ->withLifecycle(Logger::class, 'startup', static function () use (&$callCount): void {
                $callCount++;
            });

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $app->startup();
        $app->startup();
        $app->startup();

        $this->assertSame(1, $callCount);
    }

    #[Test]
    public function shutdown_hooks_run_on_instantiated_services(): void
    {
        $shutdownCalled = false;

        $bundle = TestServiceBundle::create()
            ->eager(Logger::class, static fn() => new Logger())
            ->withLifecycle(Logger::class, 'shutdown', static function (Logger $logger) use (&$shutdownCalled): void {
                $logger->shutdown();
                $shutdownCalled = true;
            });

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $app->startup();

        $this->assertFalse($shutdownCalled);

        $app->shutdown();

        $this->assertTrue($shutdownCalled);
    }

    #[Test]
    public function shutdown_is_idempotent(): void
    {
        $callCount = 0;

        $bundle = TestServiceBundle::create()
            ->eager(Logger::class, static fn() => new Logger())
            ->withLifecycle(Logger::class, 'shutdown', static function () use (&$callCount): void {
                $callCount++;
            });

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $app->startup();

        $app->shutdown();
        $app->shutdown();
        $app->shutdown();

        // Shutdown is idempotent because started flag is set to false
        $this->assertSame(1, $callCount);
    }

    #[Test]
    public function multiple_providers_are_merged(): void
    {
        $bundle1 = TestServiceBundle::create()
            ->singleton(Logger::class, static fn() => new Logger());

        $bundle2 = TestServiceBundle::create()
            ->singleton(CountingService::class, static fn() => new CountingService());

        $app = Application::starting()
            ->providers($bundle1, $bundle2)
            ->compile();

        $scope = $app->createScope();

        // Both services should be accessible
        $logger = $scope->service(Logger::class);
        $counting = $scope->service(CountingService::class);

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertInstanceOf(CountingService::class, $counting);
    }

    #[Test]
    public function eager_services_instantiated_at_startup(): void
    {
        $initTime = null;

        $bundle = TestServiceBundle::create()
            ->eager(Logger::class, static function () use (&$initTime): Logger {
                $initTime = hrtime(true);
                return new Logger();
            });

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        // Not instantiated yet at compile time
        $this->assertNull($initTime);

        $app->startup();

        // Now it should be instantiated
        $this->assertNotNull($initTime);
    }

    #[Test]
    public function lazy_services_not_instantiated_at_startup(): void
    {
        $initCalled = false;

        $bundle = TestServiceBundle::create()
            ->singleton(Logger::class, static function () use (&$initCalled): Logger {
                $initCalled = true;
                return new Logger();
            });

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $app->startup();

        // Lazy singletons should NOT be instantiated at startup
        $this->assertFalse($initCalled);
    }

    #[Test]
    public function shutdown_without_startup_is_noop(): void
    {
        $shutdownCalled = false;

        $bundle = TestServiceBundle::create()
            ->eager(Logger::class, static fn() => new Logger())
            ->withLifecycle(Logger::class, 'shutdown', static function () use (&$shutdownCalled): void {
                $shutdownCalled = true;
            });

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        // Shutdown without startup should be a no-op
        $app->shutdown();

        $this->assertFalse($shutdownCalled);
    }

    #[Test]
    public function startup_after_shutdown_restarts(): void
    {
        $startupCount = 0;

        $bundle = TestServiceBundle::create()
            ->eager(CountingService::class, static fn() => new CountingService())
            ->withLifecycle(CountingService::class, 'startup', static function () use (&$startupCount): void {
                $startupCount++;
            });

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $app->startup();
        $this->assertSame(1, $startupCount);

        $app->shutdown();

        $app->startup();
        $this->assertSame(2, $startupCount);
    }

    #[Test]
    public function providers_method_returns_registered_providers(): void
    {
        $bundle1 = TestServiceBundle::create();
        $bundle2 = TestServiceBundle::create();

        $app = Application::starting()
            ->providers($bundle1, $bundle2)
            ->compile();

        $providers = $app->providers();

        $this->assertCount(2, $providers);
        $this->assertSame($bundle1, $providers[0]);
        $this->assertSame($bundle2, $providers[1]);
    }

    #[Test]
    public function graph_method_returns_service_graph(): void
    {
        $bundle = TestServiceBundle::create()
            ->singleton(Logger::class, static fn() => new Logger());

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $graph = $app->graph();

        $this->assertTrue($graph->has(Logger::class));
    }

    #[Test]
    public function boot_returns_app_and_scope(): void
    {
        $bundle = TestServiceBundle::create()
            ->singleton(Logger::class, static fn() => new Logger());

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        [$bootedApp, $scope] = $app->boot();

        $this->assertSame($app, $bootedApp);
        $this->assertInstanceOf(ExecutionScope::class, $scope);

        $scope->dispose();
        $bootedApp->shutdown();
    }

    #[Test]
    public function boot_passes_cancellation_token(): void
    {
        $bundle = TestServiceBundle::create();

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $token = CancellationToken::timeout(5.0);
        [$bootedApp, $scope] = $app->boot($token);

        $this->assertSame($token, $scope->cancellation());

        $scope->dispose();
        $bootedApp->shutdown();
    }

    #[Test]
    public function boot_calls_startup_idempotently(): void
    {
        $startupCount = 0;

        $bundle = TestServiceBundle::create()
            ->eager(Logger::class, static fn() => new Logger())
            ->withLifecycle(Logger::class, 'startup', static function () use (&$startupCount): void {
                $startupCount++;
            });

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        [$bootedApp, $scope] = $app->boot();

        $this->assertSame(1, $startupCount);

        // Calling startup() again after boot() is safe (idempotent)
        $bootedApp->startup();

        $this->assertSame(1, $startupCount);

        $scope->dispose();
        $bootedApp->shutdown();
    }
}
