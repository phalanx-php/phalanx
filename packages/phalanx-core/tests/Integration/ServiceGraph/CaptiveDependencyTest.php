<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\ServiceGraph;

use Phalanx\Exception\InvalidServiceConfigurationException;
use Phalanx\Service\ServiceCatalog;
use Phalanx\Service\ServiceGraphCompiler;
use Phalanx\Tests\Support\Fixtures\Logger;
use Phalanx\Tests\Support\Fixtures\ScopedService;
use Phalanx\Tests\Support\Fixtures\SingletonWithScopedDep;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CaptiveDependencyTest extends TestCase
{
    private ServiceGraphCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new ServiceGraphCompiler();
    }

    #[Test]
    public function rejects_singleton_depending_on_scoped(): void
    {
        $catalog = new ServiceCatalog();

        // Singleton depends on scoped = captive dependency
        $catalog->singleton(SingletonWithScopedDep::class);
        $catalog->scoped(ScopedService::class);

        $catalog->updateDefinition(
            SingletonWithScopedDep::class,
            $catalog->getDefinition(SingletonWithScopedDep::class)
                ->withDependencies(ScopedService::class),
        );

        $this->expectException(InvalidServiceConfigurationException::class);

        try {
            $this->compiler->compile($catalog, [], []);
        } catch (InvalidServiceConfigurationException $e) {
            $this->assertStringContainsString('Singleton', $e->getMessage());
            $this->assertStringContainsString('cannot depend on scoped', $e->getMessage());
            throw $e;
        }
    }

    #[Test]
    public function allows_scoped_depending_on_singleton(): void
    {
        $catalog = new ServiceCatalog();

        // Scoped depending on singleton is valid
        $catalog->singleton(Logger::class);
        $catalog->scoped(ScopedService::class);

        $catalog->updateDefinition(
            ScopedService::class,
            $catalog->getDefinition(ScopedService::class)
                ->withDependencies(Logger::class),
        );

        // Should compile without exception
        $graph = $this->compiler->compile($catalog, [], []);

        $this->assertTrue($graph->has(Logger::class));
        $this->assertTrue($graph->has(ScopedService::class));
    }

    #[Test]
    public function allows_singleton_depending_on_singleton(): void
    {
        $catalog = new ServiceCatalog();

        $catalog->singleton(Logger::class);
        $catalog->singleton(SingletonDependingOnSingleton::class);

        $catalog->updateDefinition(
            SingletonDependingOnSingleton::class,
            $catalog->getDefinition(SingletonDependingOnSingleton::class)
                ->withDependencies(Logger::class),
        );

        // Should compile without exception
        $graph = $this->compiler->compile($catalog, [], []);

        $this->assertTrue($graph->has(Logger::class));
        $this->assertTrue($graph->has(SingletonDependingOnSingleton::class));
    }

    #[Test]
    public function allows_scoped_depending_on_scoped(): void
    {
        $catalog = new ServiceCatalog();

        $catalog->scoped(ScopedService::class);
        $catalog->scoped(AnotherScopedService::class);

        $catalog->updateDefinition(
            AnotherScopedService::class,
            $catalog->getDefinition(AnotherScopedService::class)
                ->withDependencies(ScopedService::class),
        );

        // Should compile without exception
        $graph = $this->compiler->compile($catalog, [], []);

        $this->assertTrue($graph->has(ScopedService::class));
        $this->assertTrue($graph->has(AnotherScopedService::class));
    }

    #[Test]
    public function validates_through_aliases(): void
    {
        $catalog = new ServiceCatalog();

        // Singleton depends on interface, aliased to scoped service
        $catalog->singleton(SingletonWithInterfaceDep::class);
        $catalog->scoped(ScopedService::class);
        $catalog->alias(ScopedInterface::class, ScopedService::class);

        $catalog->updateDefinition(
            SingletonWithInterfaceDep::class,
            $catalog->getDefinition(SingletonWithInterfaceDep::class)
                ->withDependencies(ScopedInterface::class),
        );

        $this->expectException(InvalidServiceConfigurationException::class);

        try {
            $this->compiler->compile($catalog, [], []);
        } catch (InvalidServiceConfigurationException $e) {
            $this->assertStringContainsString('Singleton', $e->getMessage());
            throw $e;
        }
    }

    #[Test]
    public function error_message_is_actionable(): void
    {
        $catalog = new ServiceCatalog();

        $catalog->singleton(SingletonWithScopedDep::class);
        $catalog->scoped(ScopedService::class);

        $catalog->updateDefinition(
            SingletonWithScopedDep::class,
            $catalog->getDefinition(SingletonWithScopedDep::class)
                ->withDependencies(ScopedService::class),
        );

        try {
            $this->compiler->compile($catalog, [], []);
            $this->fail('Expected InvalidServiceConfigurationException');
        } catch (InvalidServiceConfigurationException $e) {
            // Message should mention both services by name
            $this->assertStringContainsString('SingletonWithScopedDep', $e->getMessage());
            $this->assertStringContainsString('ScopedService', $e->getMessage());
            // Should explain the problem
            $this->assertStringContainsString('captive', $e->getMessage());
        }
    }

    #[Test]
    public function detects_transitive_captive_dependency(): void
    {
        $catalog = new ServiceCatalog();

        // Chain: Singleton -> AnotherSingleton -> ScopedService
        // The transitive dependency on scoped should be caught
        $catalog->singleton(TopLevelSingleton::class);
        $catalog->singleton(MiddleSingleton::class);
        $catalog->scoped(ScopedService::class);

        $catalog->updateDefinition(
            TopLevelSingleton::class,
            $catalog->getDefinition(TopLevelSingleton::class)
                ->withDependencies(MiddleSingleton::class),
        );

        $catalog->updateDefinition(
            MiddleSingleton::class,
            $catalog->getDefinition(MiddleSingleton::class)
                ->withDependencies(ScopedService::class),
        );

        // This should fail on MiddleSingleton -> ScopedService
        $this->expectException(InvalidServiceConfigurationException::class);

        $this->compiler->compile($catalog, [], []);
    }
}

// Test fixtures

final readonly class SingletonDependingOnSingleton
{
    public function __construct(
        public Logger $logger,
    ) {
    }
}

final readonly class AnotherScopedService
{
    public function __construct(
        public ScopedService $scoped,
    ) {
    }
}

interface ScopedInterface
{
}

final readonly class SingletonWithInterfaceDep
{
    public function __construct(
        public ScopedInterface $scoped,
    ) {
    }
}

final readonly class TopLevelSingleton
{
    public function __construct(
        public MiddleSingleton $middle,
    ) {
    }
}

final readonly class MiddleSingleton
{
    public function __construct(
        public ScopedService $scoped,
    ) {
    }
}
