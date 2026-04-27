<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Handler;

use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Tests\Fixtures\Handlers\HandlerA;
use Phalanx\Tests\Fixtures\Handlers\HandlerB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandlerGroupTest extends TestCase
{
    #[Test]
    public function creates_from_class_string_directly(): void
    {
        $group = HandlerGroup::of([
            'task' => HandlerA::class,
        ]);

        $this->assertNotNull($group->get('task'));
    }

    #[Test]
    public function merge_combines_groups(): void
    {
        $group1 = HandlerGroup::of([
            'a' => Handler::of(HandlerA::class),
        ]);

        $group2 = HandlerGroup::of([
            'b' => Handler::of(HandlerB::class),
        ]);

        $merged = $group1->merge($group2);

        $this->assertCount(2, $merged->keys());
        $this->assertContains('a', $merged->keys());
        $this->assertContains('b', $merged->keys());
    }

    #[Test]
    public function merge_later_overrides_earlier(): void
    {
        $group1 = HandlerGroup::of(['key' => Handler::of(HandlerA::class)]);
        $group2 = HandlerGroup::of(['key' => Handler::of(HandlerB::class)]);

        $merged = $group1->merge($group2);
        $handler = $merged->get('key');

        $this->assertNotNull($handler);
        $this->assertSame(HandlerB::class, $handler->task);
    }

    #[Test]
    public function add_appends_handler(): void
    {
        $group = HandlerGroup::create()
            ->add('a', Handler::of(HandlerA::class))
            ->add('b', Handler::of(HandlerB::class));

        $this->assertCount(2, $group->keys());
    }

    #[Test]
    public function filter_by_config_returns_matching_handlers(): void
    {
        $group = HandlerGroup::of([
            'a' => Handler::of(HandlerA::class),
            'b' => Handler::of(HandlerB::class),
        ]);

        $filtered = $group->filterByConfig(\Phalanx\Handler\HandlerConfig::class);

        $this->assertCount(2, $filtered);
    }

    #[Test]
    public function all_returns_all_handlers(): void
    {
        $group = HandlerGroup::of([
            'a' => Handler::of(HandlerA::class),
            'b' => Handler::of(HandlerB::class),
        ]);

        $this->assertCount(2, $group->all());
    }
}
