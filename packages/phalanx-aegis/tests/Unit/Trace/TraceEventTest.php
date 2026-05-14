<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Trace;

use Phalanx\Trace\TraceEvent;
use Phalanx\Trace\TraceType;
use PHPUnit\Framework\TestCase;

final class TraceEventTest extends TestCase
{
    public function testPropertiesPubliclyReadable(): void
    {
        $event = new TraceEvent(TraceType::Lifecycle, 'Pericles', 42.0, ['era' => 'golden']);

        self::assertSame(TraceType::Lifecycle, $event->type);
        self::assertSame('Pericles', $event->name);
        self::assertSame(42.0, $event->timestamp);
        self::assertSame(['era' => 'golden'], $event->attrs);
    }
}
