<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Slice\LlmRequestEntry;
use Phalanx\Theatron\Demos\Repl\Slice\LlmRequestSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LlmRequestSliceTest extends TestCase
{
    #[Test]
    public function slice_key(): void
    {
        self::assertSame('llm-requests', (new LlmRequestSlice())->key);
    }

    #[Test]
    public function empty_by_default(): void
    {
        $slice = new LlmRequestSlice();

        self::assertSame([], $slice->entries);
        self::assertSame(0, $slice->focusedIndex);
        self::assertNull($slice->focused());
    }

    #[Test]
    public function append_adds_entry_and_focuses_it(): void
    {
        $slice = new LlmRequestSlice();
        $entry = new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/api/chat');

        $updated = $slice->append($entry);

        self::assertCount(1, $updated->entries);
        self::assertSame(0, $updated->focusedIndex);
        self::assertSame($entry, $updated->focused());
    }

    #[Test]
    public function append_is_immutable(): void
    {
        $slice = new LlmRequestSlice();

        $slice->append(new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/api/chat'));

        self::assertSame([], $slice->entries);
    }

    #[Test]
    public function append_auto_focuses_latest(): void
    {
        $slice = new LlmRequestSlice();
        $first = new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/first');
        $second = new LlmRequestEntry(requestId: 'req-1', method: 'POST', path: '/second');

        $updated = $slice->append($first)->append($second);

        self::assertCount(2, $updated->entries);
        self::assertSame(1, $updated->focusedIndex);
        self::assertSame('/second', $updated->focused()->path);
    }

    #[Test]
    public function append_trims_at_max_entries(): void
    {
        $slice = new LlmRequestSlice();

        for ($i = 0; $i < 55; $i++) {
            $slice = $slice->append(new LlmRequestEntry(requestId: "req-{$i}", method: 'POST', path: "/req/{$i}"));
        }

        self::assertCount(50, $slice->entries);
        self::assertSame('/req/5', $slice->entries[0]->path);
        self::assertSame('/req/54', $slice->entries[49]->path);
        self::assertSame(49, $slice->focusedIndex);
    }

    #[Test]
    public function complete_by_id_marks_matching_entry(): void
    {
        $slice = (new LlmRequestSlice())
            ->append(new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/first'))
            ->append(new LlmRequestEntry(requestId: 'req-1', method: 'POST', path: '/second'));

        $updated = $slice->completeById('req-0', 200, 150.5, 42, '{"result":"ok"}');

        self::assertTrue($updated->entries[0]->complete);
        self::assertSame(200, $updated->entries[0]->status);
        self::assertSame(150.5, $updated->entries[0]->elapsedMs);
        self::assertSame(42, $updated->entries[0]->tokenCount);
        self::assertFalse($updated->entries[1]->complete);
    }

    #[Test]
    public function complete_by_id_unknown_returns_same(): void
    {
        $slice = (new LlmRequestSlice())
            ->append(new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/api/chat'));

        self::assertSame($slice, $slice->completeById('req-999', 200, 100.0, 10, 'body'));
    }

    #[Test]
    public function complete_by_id_on_empty_returns_same(): void
    {
        $slice = new LlmRequestSlice();

        self::assertSame($slice, $slice->completeById('req-0', 200, 100.0, 10, 'body'));
    }

    #[Test]
    public function error_by_id_marks_matching_entry(): void
    {
        $slice = (new LlmRequestSlice())
            ->append(new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/api/chat'));

        $updated = $slice->errorById('req-0', 'timeout', 5000.0);

        self::assertTrue($updated->entries[0]->complete);
        self::assertSame('timeout', $updated->entries[0]->error);
        self::assertSame(5000.0, $updated->entries[0]->elapsedMs);
    }

    #[Test]
    public function error_by_id_unknown_returns_same(): void
    {
        $slice = (new LlmRequestSlice())
            ->append(new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/api/chat'));

        self::assertSame($slice, $slice->errorById('req-999', 'err', 0.0));
    }

    #[Test]
    public function error_by_id_on_empty_returns_same(): void
    {
        $slice = new LlmRequestSlice();

        self::assertSame($slice, $slice->errorById('req-0', 'err', 0.0));
    }

    #[Test]
    public function focus_up_decrements(): void
    {
        $slice = (new LlmRequestSlice())
            ->append(new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/a'))
            ->append(new LlmRequestEntry(requestId: 'req-1', method: 'POST', path: '/b'));

        self::assertSame(1, $slice->focusedIndex);

        $up = $slice->focusUp();

        self::assertSame(0, $up->focusedIndex);
        self::assertSame('/a', $up->focused()->path);
    }

    #[Test]
    public function focus_up_at_zero_returns_same(): void
    {
        $slice = (new LlmRequestSlice())
            ->append(new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/only'));

        self::assertSame($slice, $slice->focusUp());
    }

    #[Test]
    public function focus_down_increments(): void
    {
        $slice = (new LlmRequestSlice())
            ->append(new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/a'))
            ->append(new LlmRequestEntry(requestId: 'req-1', method: 'POST', path: '/b'));

        $moved = $slice->focusUp()->focusDown();

        self::assertSame(1, $moved->focusedIndex);
    }

    #[Test]
    public function focus_down_at_end_returns_same(): void
    {
        $slice = (new LlmRequestSlice())
            ->append(new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/only'));

        self::assertSame($slice, $slice->focusDown());
    }

    #[Test]
    public function scroll_detail_up_decrements_offset(): void
    {
        $slice = new LlmRequestSlice(detailScrollOffset: 10);

        self::assertSame(7, $slice->scrollDetailUp()->detailScrollOffset);
    }

    #[Test]
    public function scroll_detail_up_clamps_at_zero(): void
    {
        $slice = new LlmRequestSlice(detailScrollOffset: 1);

        self::assertSame(0, $slice->scrollDetailUp()->detailScrollOffset);
    }

    #[Test]
    public function scroll_detail_up_at_zero_returns_same(): void
    {
        $slice = new LlmRequestSlice(detailScrollOffset: 0);

        self::assertSame($slice, $slice->scrollDetailUp());
    }

    #[Test]
    public function scroll_detail_down_increments_offset(): void
    {
        $slice = new LlmRequestSlice(detailScrollOffset: 5);

        self::assertSame(8, $slice->scrollDetailDown()->detailScrollOffset);
    }

    #[Test]
    public function reset_detail_scroll_zeroes_offset(): void
    {
        $slice = new LlmRequestSlice(detailScrollOffset: 15);

        self::assertSame(0, $slice->resetDetailScroll()->detailScrollOffset);
    }

    #[Test]
    public function reset_detail_scroll_at_zero_returns_same(): void
    {
        $slice = new LlmRequestSlice();

        self::assertSame($slice, $slice->resetDetailScroll());
    }
}
