<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\DevTools;

use Phalanx\Theatron\DevTools\StreamTraceEntry;
use Phalanx\Theatron\DevTools\StreamTraceSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamTraceSliceTest extends TestCase
{
    #[Test]
    public function empty_slice_has_no_latest(): void
    {
        $slice = new StreamTraceSlice();

        self::assertNull($slice->latest());
        self::assertSame([], $slice->entries);
    }

    #[Test]
    public function push_adds_entry(): void
    {
        $entry = new StreamTraceEntry('App\\Event', 1234567890.0, 2);
        $slice = (new StreamTraceSlice())->push($entry);

        self::assertCount(1, $slice->entries);
        self::assertSame($entry, $slice->latest());
    }

    #[Test]
    public function latest_returns_most_recent(): void
    {
        $first = new StreamTraceEntry('App\\First', 1.0, 1);
        $second = new StreamTraceEntry('App\\Second', 2.0, 3);

        $slice = (new StreamTraceSlice())
            ->push($first)
            ->push($second);

        self::assertSame('App\\Second', $slice->latest()->eventClass);
        self::assertSame(3, $slice->latest()->subscriberCount);
    }

    #[Test]
    public function ring_buffer_evicts_oldest(): void
    {
        $slice = new StreamTraceSlice(capacity: 3);

        for ($i = 0; $i < 5; $i++) {
            $slice = $slice->push(new StreamTraceEntry("Event{$i}", (float) $i, 1));
        }

        self::assertCount(3, $slice->entries);
        self::assertSame('Event2', $slice->entries[0]->eventClass);
        self::assertSame('Event4', $slice->latest()->eventClass);
    }

    #[Test]
    public function slice_key(): void
    {
        self::assertSame('theatron.runtime.stream_trace', (new StreamTraceSlice())->key);
    }
}
