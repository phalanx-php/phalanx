<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Task;

use Phalanx\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Task\TaskConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaskTest extends TestCase
{
    #[Test]
    public function accepts_static_closure(): void
    {
        $task = Task::of(static fn() => 'result');

        $this->assertInstanceOf(Task::class, $task);
    }

    #[Test]
    public function rejects_non_static_closure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Task closure must be static');

        Task::of(fn(ExecutionScope $es) => $this);
    }

    #[Test]
    public function accepts_explicit_self_capture(): void
    {
        $self = $this;
        $task = Task::of(static fn() => $self);

        $this->assertInstanceOf(Task::class, $task);
    }

    #[Test]
    public function has_default_config(): void
    {
        $task = Task::of(static fn() => null);

        $this->assertInstanceOf(TaskConfig::class, $task->config);
        $this->assertSame('', $task->config->name);
        $this->assertSame(0, $task->config->priority);
    }

    #[Test]
    public function accepts_custom_config(): void
    {
        $config = new TaskConfig(name: 'MyTask', priority: 10);
        $task = Task::create(static fn() => null, $config);

        $this->assertSame('MyTask', $task->config->name);
        $this->assertSame(10, $task->config->priority);
    }

    #[Test]
    public function with_returns_new_task_with_config(): void
    {
        $task = Task::of(static fn() => 'original');
        $config = new TaskConfig(name: 'Updated');
        $updated = $task->with($config);

        $this->assertNotSame($task, $updated);
        $this->assertSame('Updated', $updated->config->name);
    }

    #[Test]
    public function withConfig_updates_specific_values(): void
    {
        $task = Task::of(static fn() => null);
        $updated = $task->withConfig(name: 'Named', priority: 5);

        $this->assertSame('Named', $updated->config->name);
        $this->assertSame(5, $updated->config->priority);
    }
}
