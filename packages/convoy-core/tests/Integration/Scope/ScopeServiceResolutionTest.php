<?php

declare(strict_types=1);

namespace Convoy\Tests\Integration\Scope;

use Convoy\Application;
use Convoy\Tests\Support\Fixtures\CountingService;
use Convoy\Tests\Support\Fixtures\DisposalTracker;
use Convoy\Tests\Support\Fixtures\Logger;
use Convoy\Tests\Support\Fixtures\ScopedService;
use Convoy\Tests\Support\Fixtures\LazyableService;
use Convoy\Tests\Support\Fixtures\SlowService;
use Convoy\Tests\Support\Fixtures\TrackedServiceA;
use Convoy\Tests\Support\Fixtures\TrackedServiceB;
use Convoy\Tests\Support\Fixtures\TrackedServiceC;
use Convoy\Tests\Support\TestServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScopeServiceResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        CountingService::reset();
        DisposalTracker::reset();
    }

    #[Test]
    public function singleton_same_across_scopes(): void
    {
        $bundle = TestServiceBundle::create()
            ->singleton(Logger::class, static fn() => new Logger());

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope1 = $app->createScope();
        $scope2 = $app->createScope();

        $logger1 = $scope1->service(Logger::class);
        $logger2 = $scope2->service(Logger::class);

        $this->assertSame($logger1, $logger2);
    }

    #[Test]
    public function scoped_unique_per_scope(): void
    {
        $bundle = TestServiceBundle::create()
            ->scoped(ScopedService::class, static fn() => new ScopedService());

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope1 = $app->createScope();
        $scope2 = $app->createScope();

        $service1 = $scope1->service(ScopedService::class);
        $service2 = $scope2->service(ScopedService::class);

        // Different instances across scopes
        $this->assertNotSame($service1, $service2);
        $this->assertNotSame($service1->id, $service2->id);

        // Same instance within same scope (cached)
        $service1Again = $scope1->service(ScopedService::class);
        $this->assertSame($service1, $service1Again);
    }

    #[Test]
    public function disposal_order_reverses_creation(): void
    {
        $bundle = TestServiceBundle::create()
            ->scoped(TrackedServiceA::class, static fn() => new TrackedServiceA())
            ->scoped(TrackedServiceB::class, static fn() => new TrackedServiceB(
                new TrackedServiceA(),
            ))
            ->scoped(TrackedServiceC::class, static fn() => new TrackedServiceC(
                new TrackedServiceB(new TrackedServiceA()),
            ))
            ->withLifecycle(TrackedServiceA::class, 'dispose', static fn(TrackedServiceA $s) => $s->dispose())
            ->withLifecycle(TrackedServiceB::class, 'dispose', static fn(TrackedServiceB $s) => $s->dispose())
            ->withLifecycle(TrackedServiceC::class, 'dispose', static fn(TrackedServiceC $s) => $s->dispose());

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope = $app->createScope();

        // Create services in order: A, then B, then C
        $scope->service(TrackedServiceA::class);
        $scope->service(TrackedServiceB::class);
        $scope->service(TrackedServiceC::class);

        $this->assertEmpty(DisposalTracker::$disposed);

        // Dispose should reverse the order: C, B, A
        $scope->dispose();

        $this->assertEquals(['C', 'B', 'A'], DisposalTracker::$disposed);
    }

    #[Test]
    public function lazy_service_not_initialized_until_access(): void
    {
        $initCalled = false;

        // LazyableService is non-final, so newLazyGhost works
        $bundle = TestServiceBundle::create()
            ->singleton(LazyableService::class, static function () use (&$initCalled): LazyableService {
                $initCalled = true;
                $service = new LazyableService();
                $service->initialize();
                return $service;
            })
            ->asLazy(LazyableService::class);

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope = $app->createScope();

        // Get the lazy proxy - should not trigger initialization
        $service = $scope->service(LazyableService::class);

        // Factory should not have been called yet (lazy ghost)
        $this->assertFalse($initCalled);

        // Access a property - this triggers initialization
        $initialized = $service->initialized;

        $this->assertTrue($initCalled);
        $this->assertTrue($initialized);
    }

    #[Test]
    public function eager_service_initialized_on_startup(): void
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

        $this->assertNull($initTime);

        $app->startup();

        $startupTime = $initTime;
        $this->assertNotNull($startupTime);

        // Creating scope and accessing service should return the same instance
        $scope = $app->createScope();
        $logger = $scope->service(Logger::class);

        $this->assertInstanceOf(Logger::class, $logger);
    }

    #[Test]
    public function singleton_with_dependencies_resolved_once(): void
    {
        CountingService::reset();

        $bundle = TestServiceBundle::create()
            ->singleton(CountingService::class, static fn() => new CountingService())
            ->singleton(DependsOnCounting::class, static fn() => new DependsOnCounting(new CountingService()))
            ->withDependencies(DependsOnCounting::class, CountingService::class);

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope1 = $app->createScope();
        $scope2 = $app->createScope();

        // Access the dependent service from multiple scopes
        $scope1->service(DependsOnCounting::class);
        $scope2->service(DependsOnCounting::class);

        // CountingService should only be created once (singleton)
        $this->assertSame(1, CountingService::$instanceCount);
    }

    #[Test]
    public function scoped_service_disposed_with_scope(): void
    {
        $disposed = false;

        $bundle = TestServiceBundle::create()
            ->scoped(ScopedService::class, static fn() => new ScopedService())
            ->withLifecycle(ScopedService::class, 'dispose', static function () use (&$disposed): void {
                $disposed = true;
            });

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope = $app->createScope();
        $scope->service(ScopedService::class);

        $this->assertFalse($disposed);

        $scope->dispose();

        $this->assertTrue($disposed);
    }

    #[Test]
    public function scope_dispose_is_idempotent(): void
    {
        $disposeCount = 0;

        $bundle = TestServiceBundle::create()
            ->scoped(ScopedService::class, static fn() => new ScopedService())
            ->withLifecycle(ScopedService::class, 'dispose', static function () use (&$disposeCount): void {
                $disposeCount++;
            });

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope = $app->createScope();
        $scope->service(ScopedService::class);

        $scope->dispose();
        $scope->dispose();
        $scope->dispose();

        $this->assertSame(1, $disposeCount);
    }

    #[Test]
    public function multiple_scoped_services_disposed_correctly(): void
    {
        $disposedServices = [];

        $bundle = TestServiceBundle::create()
            ->scoped(ScopedService::class, static fn() => new ScopedService())
            ->scoped(AnotherScopedForDisposal::class, static fn() => new AnotherScopedForDisposal())
            ->withLifecycle(ScopedService::class, 'dispose', static function () use (&$disposedServices): void {
                $disposedServices[] = 'ScopedService';
            })
            ->withLifecycle(AnotherScopedForDisposal::class, 'dispose', static function () use (&$disposedServices): void {
                $disposedServices[] = 'AnotherScopedForDisposal';
            });

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope = $app->createScope();

        // Create in specific order
        $scope->service(ScopedService::class);
        $scope->service(AnotherScopedForDisposal::class);

        $scope->dispose();

        // Both should be disposed, in reverse order
        $this->assertCount(2, $disposedServices);
        $this->assertSame('AnotherScopedForDisposal', $disposedServices[0]);
        $this->assertSame('ScopedService', $disposedServices[1]);
    }

    #[Test]
    public function service_resolution_via_alias(): void
    {
        $bundle = TestServiceBundle::create()
            ->singleton(Logger::class, static fn() => new Logger())
            ->alias(LoggerInterface::class, Logger::class);

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $scope = $app->createScope();

        $byInterface = $scope->service(LoggerInterface::class);
        $byConcrete = $scope->service(Logger::class);

        $this->assertSame($byInterface, $byConcrete);
        $this->assertInstanceOf(Logger::class, $byInterface);
    }
}

// Test fixtures

final readonly class DependsOnCounting
{
    public function __construct(
        public CountingService $counting,
    ) {
    }
}

final class AnotherScopedForDisposal
{
}

interface LoggerInterface
{
}
