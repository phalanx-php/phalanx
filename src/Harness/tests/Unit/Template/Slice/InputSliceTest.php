<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Template\Slice;

use Phalanx\Harness\Template\Slice\InputSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InputSliceTest extends TestCase
{
    #[Test]
    public function removeLastQueuedRemovesNewestMessageOnly(): void
    {
        $slice = new InputSlice();
        $slice = $slice
            ->enqueue('first')
            ->enqueue('second')
            ->enqueue('third');

        self::assertSame('third', $slice->lastQueued());

        $updated = $slice->removeLastQueued();

        self::assertSame(['first', 'second'], $updated->queue);
        self::assertSame(['first', 'second', 'third'], $slice->queue);
    }

    #[Test]
    public function queuedTextJoinsMessagesWithBlankLines(): void
    {
        $slice = new InputSlice()
            ->enqueue('first')
            ->enqueue('second')
            ->enqueue('third');

        self::assertSame("first\n\nsecond\n\nthird", $slice->queuedText());
        self::assertSame([], $slice->clearQueue()->queue);
    }

    #[Test]
    public function emptyQueueHelpersAreStable(): void
    {
        $slice = new InputSlice('draft');

        self::assertNull($slice->lastQueued());
        self::assertSame($slice, $slice->removeLastQueued());
        self::assertSame('', $slice->queuedText());
        self::assertSame('draft', $slice->clearQueue()->text);
    }
}
