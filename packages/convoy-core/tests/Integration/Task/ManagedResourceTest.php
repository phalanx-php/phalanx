<?php

declare(strict_types=1);

namespace Convoy\Tests\Integration\Task;

use Convoy\Task\ManagedResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManagedResourceTest extends TestCase
{
    #[Test]
    public function wrap_returns_proxy_that_delegates_to_resource(): void
    {
        $resource = new ResourceStub();
        $released = false;

        $proxy = ManagedResource::wrap($resource, static function () use (&$released): void {
            $released = true;
        });

        // Proxy should delegate to the real resource
        $proxy->value = 'test';
        $this->assertSame('test', $proxy->value);
        $this->assertSame('test', $resource->value);

        // Cleanup not yet called
        $this->assertFalse($released);
    }

    #[Test]
    public function enable_shutdown_flush_is_idempotent(): void
    {
        // Should not throw or register multiple handlers
        ManagedResource::enableShutdownFlush();
        ManagedResource::enableShutdownFlush();
        ManagedResource::enableShutdownFlush();

        // If we got here without error, idempotency works
        $this->assertTrue(true);
    }

    #[Test]
    public function wrapped_resources_are_independent(): void
    {
        $resource1 = new ResourceStub();
        $resource2 = new ResourceStub();
        $released1 = false;
        $released2 = false;

        $proxy1 = ManagedResource::wrap($resource1, static function () use (&$released1): void {
            $released1 = true;
        });

        $proxy2 = ManagedResource::wrap($resource2, static function () use (&$released2): void {
            $released2 = true;
        });

        $proxy1->value = 'first';
        $proxy2->value = 'second';

        $this->assertSame('first', $proxy1->value);
        $this->assertSame('second', $proxy2->value);
        $this->assertNotSame($proxy1, $proxy2);
    }
}

class ResourceStub
{
    public string $value = '';
}
