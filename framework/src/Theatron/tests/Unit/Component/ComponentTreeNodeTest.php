<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Component;

use Phalanx\Theatron\Component\ComponentTreeNode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComponentTreeNodeTest extends TestCase
{
    #[Test]
    public function constructsWithExpectedValues(): void
    {
        $node = new ComponentTreeNode(
            class: 'App\\Scoreboard',
            signalCount: 4,
            subscriptionCount: 3,
        );

        self::assertSame('App\\Scoreboard', $node->class);
        self::assertSame(4, $node->signalCount);
        self::assertSame(3, $node->subscriptionCount);
    }

    #[Test]
    public function zeroSignalAndSubscriptionCounts(): void
    {
        $node = new ComponentTreeNode(
            class: 'App\\Static',
            signalCount: 0,
            subscriptionCount: 0,
        );

        self::assertSame(0, $node->signalCount);
        self::assertSame(0, $node->subscriptionCount);
    }
}
