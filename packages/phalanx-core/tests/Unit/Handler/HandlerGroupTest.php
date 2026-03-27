<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Handler;

use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandlerGroupTest extends TestCase
{
    #[Test]
    public function creates_from_dispatchable_directly(): void
    {
        $group = HandlerGroup::of([
            'task' => Task::of(static fn() => 'result'),
        ]);

        $this->assertNotNull($group->get('task'));
    }

    #[Test]
    public function merge_combines_groups(): void
    {
        $group1 = HandlerGroup::of([
            'a' => Handler::of(Task::of(static fn() => 'a')),
        ]);

        $group2 = HandlerGroup::of([
            'b' => Handler::of(Task::of(static fn() => 'b')),
        ]);

        $merged = $group1->merge($group2);

        $this->assertCount(2, $merged->keys());
        $this->assertContains('a', $merged->keys());
        $this->assertContains('b', $merged->keys());
    }

    #[Test]
    public function merge_later_overrides_earlier(): void
    {
        $task1 = Task::of(static fn() => 'first');
        $task2 = Task::of(static fn() => 'second');

        $group1 = HandlerGroup::of(['key' => Handler::of($task1)]);
        $group2 = HandlerGroup::of(['key' => Handler::of($task2)]);

        $merged = $group1->merge($group2);
        $handler = $merged->get('key');

        $this->assertSame($task2, $handler->task);
    }

    #[Test]
    public function add_appends_handler(): void
    {
        $group = HandlerGroup::create()
            ->add('a', Handler::of(Task::of(static fn() => 'a')))
            ->add('b', Handler::of(Task::of(static fn() => 'b')));

        $this->assertCount(2, $group->keys());
    }

    #[Test]
    public function filter_by_config_returns_matching_handlers(): void
    {
        $group = HandlerGroup::of([
            'a' => Handler::of(Task::of(static fn() => 'a')),
            'b' => Handler::of(Task::of(static fn() => 'b')),
        ]);

        $filtered = $group->filterByConfig(\Phalanx\Handler\HandlerConfig::class);

        $this->assertCount(2, $filtered);
    }

    #[Test]
    public function all_returns_all_handlers(): void
    {
        $group = HandlerGroup::of([
            'a' => Handler::of(Task::of(static fn() => 'a')),
            'b' => Handler::of(Task::of(static fn() => 'b')),
        ]);

        $this->assertCount(2, $group->all());
    }
}
