<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Trace;

use Phalanx\Trace\Trace;
use Phalanx\Trace\TraceType;
use PHPUnit\Framework\TestCase;

final class TraceRingBufferTest extends TestCase
{
    public function testLogAndRetrieveEvents(): void
    {
        $trace = new Trace();

        $trace->log(TraceType::Execute, 'Sparta');
        $trace->log(TraceType::Lifecycle, 'Marathon');
        $trace->log(TraceType::Failed, 'Thermopylae');

        $events = $trace->events();

        self::assertCount(3, $events);
        self::assertSame('Sparta', $events[0]->name);
        self::assertSame('Marathon', $events[1]->name);
        self::assertSame('Thermopylae', $events[2]->name);
    }

    public function testEventsReturnsEmptyWhenNoLogs(): void
    {
        $trace = new Trace();

        self::assertSame([], $trace->events());
    }

    public function testExactCapacityFillsWithoutRecycling(): void
    {
        $trace = new Trace();
        $ringSize = self::ringSize();

        for ($i = 0; $i < $ringSize; $i++) {
            $trace->log(TraceType::Execute, "event-$i");
        }

        $events = $trace->events();

        self::assertCount($ringSize, $events);
        self::assertSame('event-0', $events[0]->name);
        self::assertSame('event-' . ($ringSize - 1), $events[$ringSize - 1]->name);
    }

    public function testRingWrapsAtCapacity(): void
    {
        $trace = new Trace();
        $ringSize = self::ringSize();

        for ($i = 0; $i < $ringSize + 5; $i++) {
            $trace->log(TraceType::Execute, "event-$i");
        }

        $events = $trace->events();

        self::assertCount($ringSize, $events);
        self::assertSame('event-5', $events[0]->name);
        self::assertSame("event-" . ($ringSize + 4), $events[$ringSize - 1]->name);
    }

    public function testEventsChronologicalOrderAfterWrap(): void
    {
        $trace = new Trace();
        $ringSize = self::ringSize();

        for ($i = 0; $i < $ringSize + 100; $i++) {
            $trace->log(TraceType::Execute, "event-$i");
        }

        $events = $trace->events();
        $timestamps = array_map(static fn($e) => $e->timestamp, $events);

        for ($i = 1; $i < count($timestamps); $i++) {
            self::assertGreaterThanOrEqual(
                $timestamps[$i - 1],
                $timestamps[$i],
                "Event $i timestamp should be >= event " . ($i - 1),
            );
        }
    }

    public function testClearResetsRing(): void
    {
        $trace = new Trace();

        $trace->log(TraceType::Execute, 'Achilles');
        $trace->log(TraceType::Execute, 'Odysseus');

        $trace->clear();

        self::assertSame([], $trace->events());

        $trace->log(TraceType::Lifecycle, 'Poseidon');

        $events = $trace->events();
        self::assertCount(1, $events);
        self::assertSame('Poseidon', $events[0]->name);
    }

    public function testWrappedEventReplacesAllFields(): void
    {
        $trace = new Trace();
        $ringSize = self::ringSize();

        $trace->log(TraceType::Execute, 'original', ['key' => 'old']);

        for ($i = 1; $i < $ringSize; $i++) {
            $trace->log(TraceType::Lifecycle, "filler-$i");
        }

        $trace->log(TraceType::Failed, 'replacement', ['key' => 'new']);

        $events = $trace->events();
        $newest = $events[$ringSize - 1];

        self::assertSame(TraceType::Failed, $newest->type);
        self::assertSame('replacement', $newest->name);
        self::assertSame(['key' => 'new'], $newest->attrs);
    }

    public function testRetainedEventsRemainStableAfterWrap(): void
    {
        $trace = new Trace();
        $ringSize = self::ringSize();

        $trace->log(TraceType::Execute, 'slot-zero');
        $retained = $trace->events()[0];
        $retainedId = spl_object_id($retained);

        for ($i = 1; $i < $ringSize; $i++) {
            $trace->log(TraceType::Execute, "filler-$i");
        }

        $trace->log(TraceType::Execute, 'recycled');

        $events = $trace->events();
        $replacement = $events[$ringSize - 1];

        self::assertSame($retainedId, spl_object_id($retained));
        self::assertSame('slot-zero', $retained->name);
        self::assertNotSame($retainedId, spl_object_id($replacement));
        self::assertSame('recycled', $replacement->name);
    }

    public function testAttrsReplacedOnWrap(): void
    {
        $trace = new Trace();
        $ringSize = self::ringSize();

        $trace->log(TraceType::Execute, 'heavy', ['payload' => str_repeat('x', 1024)]);

        for ($i = 1; $i < $ringSize; $i++) {
            $trace->log(TraceType::Execute, "filler-$i");
        }

        $trace->log(TraceType::Lifecycle, 'light', ['simple' => true]);

        $events = $trace->events();
        $recycled = $events[$ringSize - 1];

        self::assertSame(['simple' => true], $recycled->attrs);
    }

    private static function ringSize(): int
    {
        return (new \ReflectionClassConstant(Trace::class, 'RING_SIZE'))->getValue();
    }
}
