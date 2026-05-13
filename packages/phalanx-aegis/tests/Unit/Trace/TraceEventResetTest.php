<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Trace;

use Phalanx\Trace\TraceEvent;
use Phalanx\Trace\TraceType;
use PHPUnit\Framework\TestCase;

final class TraceEventResetTest extends TestCase
{
    public function testResetUpdatesAllProperties(): void
    {
        $event = new TraceEvent(TraceType::Execute, 'Leonidas', 1.0, ['phase' => 'start']);

        $event->reset(TraceType::Failed, 'Thermopylae', 2.0, ['casualties' => 300]);

        self::assertSame(TraceType::Failed, $event->type);
        self::assertSame('Thermopylae', $event->name);
        self::assertSame(2.0, $event->timestamp);
        self::assertSame(['casualties' => 300], $event->attrs);
    }

    public function testPropertiesPubliclyReadable(): void
    {
        $event = new TraceEvent(TraceType::Lifecycle, 'Pericles', 42.0, ['era' => 'golden']);

        self::assertSame(TraceType::Lifecycle, $event->type);
        self::assertSame('Pericles', $event->name);
        self::assertSame(42.0, $event->timestamp);
        self::assertSame(['era' => 'golden'], $event->attrs);
    }

    public function testMultipleResetsStable(): void
    {
        $event = new TraceEvent(TraceType::Execute, 'init', 0.0, []);

        $types = [TraceType::Retry, TraceType::Timeout, TraceType::Defer, TraceType::Worker];

        for ($i = 0; $i < 10; $i++) {
            $type = $types[$i % count($types)];
            $name = "cycle-$i";
            $ts = (float) $i;
            $attrs = ['cycle' => $i];

            $event->reset($type, $name, $ts, $attrs);

            self::assertSame($type, $event->type);
            self::assertSame($name, $event->name);
            self::assertSame($ts, $event->timestamp);
            self::assertSame($attrs, $event->attrs);
        }
    }
}
