<?php

declare(strict_types=1);

namespace Convoy\Stream\Tests\Unit;

use Convoy\Stream\StreamEvent;
use PHPUnit\Framework\TestCase;

final class StreamEventTest extends TestCase
{
    public function testEnumValuesMatchReactPhpEventStrings(): void
    {
        self::assertSame('data', StreamEvent::Data->value);
        self::assertSame('end', StreamEvent::End->value);
        self::assertSame('error', StreamEvent::Error->value);
        self::assertSame('close', StreamEvent::Close->value);
        self::assertSame('connection', StreamEvent::Connection->value);
        self::assertSame('exit', StreamEvent::Exit->value);
        self::assertSame('drain', StreamEvent::Drain->value);
        self::assertSame('message', StreamEvent::Message->value);
    }

    public function testAllCasesExist(): void
    {
        self::assertCount(8, StreamEvent::cases());
    }
}
