<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Task;

use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Task\Pool;
use Phalanx\Task\TaskConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaskConfigTest extends TestCase
{
    #[Test]
    public function has_defaults(): void
    {
        $config = new TaskConfig();

        $this->assertSame('', $config->name);
        $this->assertSame(0, $config->priority);
        $this->assertNull($config->pool);
        $this->assertNull($config->retry);
        $this->assertNull($config->timeout);
        $this->assertTrue($config->trace);
        $this->assertSame([], $config->tags);
    }

    #[Test]
    public function with_returns_new_instance(): void
    {
        $config = new TaskConfig();
        $updated = $config->with(name: 'Updated');

        $this->assertNotSame($config, $updated);
        $this->assertSame('', $config->name);
        $this->assertSame('Updated', $updated->name);
    }

    #[Test]
    public function with_preserves_unset_values(): void
    {
        $policy = RetryPolicy::exponential(3);
        $config = new TaskConfig(
            name: 'Original',
            priority: 5,
            retry: $policy,
        );

        $updated = $config->with(timeout: 10.0);

        $this->assertSame('Original', $updated->name);
        $this->assertSame(5, $updated->priority);
        $this->assertSame($policy, $updated->retry);
        $this->assertSame(10.0, $updated->timeout);
    }

    #[Test]
    public function accepts_pool_enum(): void
    {
        $config = new TaskConfig(pool: Pool::Http);

        $this->assertSame(Pool::Http, $config->pool);
    }

    #[Test]
    public function accepts_all_constructor_args(): void
    {
        $policy = RetryPolicy::fixed(3, 100);
        $config = new TaskConfig(
            name: 'Test',
            priority: 10,
            pool: Pool::Database,
            retry: $policy,
            timeout: 5.0,
            concurrencyLimit: 3,
            trace: false,
            tags: ['db', 'slow'],
        );

        $this->assertSame('Test', $config->name);
        $this->assertSame(10, $config->priority);
        $this->assertSame(Pool::Database, $config->pool);
        $this->assertSame($policy, $config->retry);
        $this->assertSame(5.0, $config->timeout);
        $this->assertSame(3, $config->concurrencyLimit);
        $this->assertFalse($config->trace);
        $this->assertSame(['db', 'slow'], $config->tags);
    }
}
