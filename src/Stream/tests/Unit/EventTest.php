<?php

declare(strict_types=1);

namespace Phalanx\Stream\Tests\Unit;

use Phalanx\Stream\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testEnumValuesMatchStableEventStrings(): void
    {
        self::assertSame('data', Event::Data->value);
        self::assertSame('end', Event::End->value);
        self::assertSame('error', Event::Error->value);
        self::assertSame('close', Event::Close->value);
        self::assertSame('connection', Event::Connection->value);
        self::assertSame('exit', Event::Exit->value);
        self::assertSame('drain', Event::Drain->value);
        self::assertSame('message', Event::Message->value);
    }

    public function testAllCasesExist(): void
    {
        self::assertCount(8, Event::cases());
    }
}
