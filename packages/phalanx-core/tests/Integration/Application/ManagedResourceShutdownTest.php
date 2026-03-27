<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\Application;

use Phalanx\Application;
use Phalanx\Task\ManagedResource;
use Phalanx\Tests\Support\TestServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests that Application::startup() enables ManagedResource shutdown flush.
 */
final class ManagedResourceShutdownTest extends TestCase
{
    #[Test]
    public function startup_enables_managed_resource_shutdown_flush(): void
    {
        // Reset the static flag via reflection
        $this->resetManagedResourceState();

        $bundle = TestServiceBundle::create();
        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        // Before startup, flag should be false
        $this->assertFalse($this->isShutdownRegistered());

        $app->startup();

        // After startup, flag should be true
        $this->assertTrue($this->isShutdownRegistered());

        $app->shutdown();
    }

    #[Test]
    public function multiple_startups_only_register_once(): void
    {
        $this->resetManagedResourceState();

        $bundle = TestServiceBundle::create();
        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $app->startup();
        $app->shutdown();
        $app->startup();
        $app->shutdown();
        $app->startup();

        // Should still be registered (idempotent)
        $this->assertTrue($this->isShutdownRegistered());

        $app->shutdown();
    }

    private function resetManagedResourceState(): void
    {
        $reflection = new ReflectionClass(ManagedResource::class);
        $property = $reflection->getProperty('shutdownRegistered');
        $property->setValue(null, false);
    }

    private function isShutdownRegistered(): bool
    {
        $reflection = new ReflectionClass(ManagedResource::class);
        $property = $reflection->getProperty('shutdownRegistered');
        return $property->getValue(null);
    }
}
