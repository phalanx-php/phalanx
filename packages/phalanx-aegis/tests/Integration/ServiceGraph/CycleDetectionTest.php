<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\ServiceGraph;

use Phalanx\Exception\CyclicDependencyException;
use Phalanx\Service\ServiceCatalog;
use Phalanx\Service\ServiceGraphCompiler;
use Phalanx\Tests\Support\Fixtures\ServiceA;
use Phalanx\Tests\Support\Fixtures\ServiceB;
use Phalanx\Tests\Support\Fixtures\ServiceC;
use Phalanx\Tests\Support\Fixtures\ServiceD;
use Phalanx\Tests\Support\Fixtures\ServiceE;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CycleDetectionTest extends TestCase
{
    private ServiceGraphCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new ServiceGraphCompiler();
    }

    #[Test]
    public function detects_direct_cycle(): void
    {
        $catalog = new ServiceCatalog();

        $catalog->singleton(ServiceA::class);
        $catalog->singleton(ServiceB::class);

        $catalog->updateDefinition(
            ServiceA::class,
            $catalog->getDefinition(ServiceA::class)->withDependencies(ServiceB::class),
        );

        $catalog->updateDefinition(
            ServiceB::class,
            $catalog->getDefinition(ServiceB::class)->withDependencies(ServiceA::class),
        );

        $this->expectException(CyclicDependencyException::class);

        try {
            $this->compiler->compile($catalog, [], []);
        } catch (CyclicDependencyException $e) {
            // Verify the cycle path is captured
            $this->assertNotEmpty($e->cycle);
            $this->assertStringContainsString('ServiceA', $e->getMessage());
            $this->assertStringContainsString('ServiceB', $e->getMessage());
            throw $e;
        }
    }

    #[Test]
    public function detects_indirect_cycle(): void
    {
        $catalog = new ServiceCatalog();

        // ServiceC -> ServiceD -> ServiceE -> ServiceC
        $catalog->singleton(ServiceC::class);
        $catalog->singleton(ServiceD::class);
        $catalog->singleton(ServiceE::class);

        $catalog->updateDefinition(
            ServiceC::class,
            $catalog->getDefinition(ServiceC::class)->withDependencies(ServiceD::class),
        );

        $catalog->updateDefinition(
            ServiceD::class,
            $catalog->getDefinition(ServiceD::class)->withDependencies(ServiceE::class),
        );

        $catalog->updateDefinition(
            ServiceE::class,
            $catalog->getDefinition(ServiceE::class)->withDependencies(ServiceC::class),
        );

        $this->expectException(CyclicDependencyException::class);

        try {
            $this->compiler->compile($catalog, [], []);
        } catch (CyclicDependencyException $e) {
            // Cycle should contain all three services
            $message = $e->getMessage();
            $this->assertStringContainsString('ServiceC', $message);
            $this->assertStringContainsString('ServiceD', $message);
            $this->assertStringContainsString('ServiceE', $message);
            throw $e;
        }
    }

    #[Test]
    public function detects_self_reference(): void
    {
        $catalog = new ServiceCatalog();

        $catalog->singleton(SelfReferencing::class);

        $catalog->updateDefinition(
            SelfReferencing::class,
            $catalog->getDefinition(SelfReferencing::class)->withDependencies(SelfReferencing::class),
        );

        $this->expectException(CyclicDependencyException::class);

        try {
            $this->compiler->compile($catalog, [], []);
        } catch (CyclicDependencyException $e) {
            $this->assertStringContainsString('SelfReferencing', $e->getMessage());
            throw $e;
        }
    }

    #[Test]
    public function no_cycle_with_linear_dependencies(): void
    {
        $catalog = new ServiceCatalog();

        // Linear: A -> B -> C (no cycle)
        $catalog->singleton(ServiceC::class);
        $catalog->singleton(ServiceD::class);
        $catalog->singleton(ServiceE::class);

        // C depends on D, D depends on E (linear chain)
        $catalog->updateDefinition(
            ServiceC::class,
            $catalog->getDefinition(ServiceC::class)->withDependencies(ServiceD::class),
        );

        $catalog->updateDefinition(
            ServiceD::class,
            $catalog->getDefinition(ServiceD::class)->withDependencies(ServiceE::class),
        );

        // E has no dependencies - this breaks the cycle
        $catalog->updateDefinition(
            ServiceE::class,
            $catalog->getDefinition(ServiceE::class),
        );

        // Should compile without exception
        $graph = $this->compiler->compile($catalog, [], []);

        $this->assertTrue($graph->has(ServiceC::class));
        $this->assertTrue($graph->has(ServiceD::class));
        $this->assertTrue($graph->has(ServiceE::class));
    }

    #[Test]
    public function detects_cycle_through_alias(): void
    {
        $catalog = new ServiceCatalog();

        // ServiceA -> SomeInterface (aliased to ServiceB) -> ServiceA
        $catalog->singleton(ServiceA::class);
        $catalog->singleton(ServiceB::class);
        $catalog->alias(SomeInterface::class, ServiceB::class);

        $catalog->updateDefinition(
            ServiceA::class,
            $catalog->getDefinition(ServiceA::class)->withDependencies(SomeInterface::class),
        );

        $catalog->updateDefinition(
            ServiceB::class,
            $catalog->getDefinition(ServiceB::class)->withDependencies(ServiceA::class),
        );

        $this->expectException(CyclicDependencyException::class);

        $this->compiler->compile($catalog, [], []);
    }

    #[Test]
    public function cycle_exception_contains_path(): void
    {
        $catalog = new ServiceCatalog();

        $catalog->singleton(ServiceA::class);
        $catalog->singleton(ServiceB::class);

        $catalog->updateDefinition(
            ServiceA::class,
            $catalog->getDefinition(ServiceA::class)->withDependencies(ServiceB::class),
        );

        $catalog->updateDefinition(
            ServiceB::class,
            $catalog->getDefinition(ServiceB::class)->withDependencies(ServiceA::class),
        );

        try {
            $this->compiler->compile($catalog, [], []);
            $this->fail('Expected CyclicDependencyException');
        } catch (CyclicDependencyException $e) {
            $this->assertIsArray($e->cycle);
            $this->assertNotEmpty($e->cycle);
            // Path should show the cycle
            $this->assertStringContainsString(' -> ', $e->getMessage());
        }
    }
}

// Test fixtures for self-reference test
final readonly class SelfReferencing
{
    public function __construct(
        public self $self,
    ) {
    }
}

interface SomeInterface
{
}
