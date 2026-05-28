<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\DevTools;

use Phalanx\Theatron\DevTools\ComponentTreeNode;
use Phalanx\Theatron\DevTools\ComponentTreeSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComponentTreeSliceTest extends TestCase
{
    #[Test]
    public function empty_by_default(): void
    {
        $slice = new ComponentTreeSlice();

        self::assertSame([], $slice->nodes);
    }

    #[Test]
    public function holds_nodes(): void
    {
        $node = new ComponentTreeNode(
            name: 'root',
            class: 'App\\RootComponent',
            depth: 0,
            signalCount: 3,
            subscriptionCount: 2,
        );

        $slice = new ComponentTreeSlice([$node]);

        self::assertCount(1, $slice->nodes);
        self::assertSame('root', $slice->nodes[0]->name);
        self::assertSame(0, $slice->nodes[0]->depth);
        self::assertSame(3, $slice->nodes[0]->signalCount);
    }

    #[Test]
    public function slice_key(): void
    {
        self::assertSame('theatron.devtools.tree', (new ComponentTreeSlice())->key);
    }
}
