<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Input;

use Phalanx\Theatron\Input\EventParser;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventParserShiftTabTest extends TestCase
{
    #[Test]
    public function parses_shift_tab_csi_z(): void
    {
        $parser = new EventParser();
        $events = $parser->parse("\x1B[Z");

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertSame(Key::Tab, $events[0]->key);
        self::assertTrue($events[0]->shift);
    }

    #[Test]
    public function plain_tab_has_no_shift(): void
    {
        $parser = new EventParser();
        $events = $parser->parse("\x09");

        self::assertCount(1, $events);
        self::assertInstanceOf(KeyEvent::class, $events[0]);
        self::assertSame(Key::Tab, $events[0]->key);
        self::assertFalse($events[0]->shift);
    }
}
