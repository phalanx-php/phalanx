<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Slice\InputSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InputSliceTest extends TestCase
{
    #[Test]
    public function enqueue_adds_message_to_queue(): void
    {
        $slice = new InputSlice();
        $updated = $slice->enqueue('hello');

        self::assertSame(['hello'], $updated->queue);
    }

    #[Test]
    public function enqueue_preserves_order(): void
    {
        $slice = (new InputSlice())
            ->enqueue('first')
            ->enqueue('second')
            ->enqueue('third');

        self::assertSame(['first', 'second', 'third'], $slice->queue);
    }

    #[Test]
    public function dequeue_removes_first_item(): void
    {
        $slice = (new InputSlice())
            ->enqueue('first')
            ->enqueue('second');

        $updated = $slice->dequeue();

        self::assertSame(['second'], $updated->queue);
    }

    #[Test]
    public function dequeue_on_empty_returns_same_instance(): void
    {
        $slice = new InputSlice();

        self::assertSame($slice, $slice->dequeue());
    }

    #[Test]
    public function peek_returns_first_without_removing(): void
    {
        $slice = (new InputSlice())
            ->enqueue('first')
            ->enqueue('second');

        self::assertSame('first', $slice->peek());
        self::assertCount(2, $slice->queue);
    }

    #[Test]
    public function peek_returns_null_when_empty(): void
    {
        self::assertNull((new InputSlice())->peek());
    }

    #[Test]
    public function enqueue_is_immutable(): void
    {
        $original = new InputSlice();
        $original->enqueue('hello');

        self::assertSame([], $original->queue);
    }

    #[Test]
    public function dequeue_to_empty_produces_empty_queue(): void
    {
        $slice = (new InputSlice())->enqueue('only')->dequeue();

        self::assertSame([], $slice->queue);
        self::assertNull($slice->peek());
    }

    #[Test]
    public function queue_and_text_are_independent(): void
    {
        $slice = (new InputSlice())
            ->append('typing')
            ->enqueue('queued message');

        self::assertSame('typing', $slice->text);
        self::assertSame(['queued message'], $slice->queue);

        $cleared = $slice->clear();

        self::assertSame('', $cleared->text);
        self::assertSame(['queued message'], $cleared->queue);
    }
}
