<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Event;

use Phalanx\Athena\Event\TokenDelta;
use PHPUnit\Framework\TestCase;

final class TokenDeltaTest extends TestCase
{
    public function testConstructorSetsToolCallProperties(): void
    {
        $delta = new TokenDelta(toolCallId: 'call-1', toolName: 'lookup', toolInputJson: '{"q":"Zeus"}');

        self::assertNull($delta->text);
        self::assertSame('call-1', $delta->toolCallId);
        self::assertSame('lookup', $delta->toolName);
        self::assertSame('{"q":"Zeus"}', $delta->toolInputJson);
    }

    public function testConstructorSetsTextProperty(): void
    {
        $delta = new TokenDelta(text: 'Marathon');

        self::assertSame('Marathon', $delta->text);
        self::assertNull($delta->toolCallId);
        self::assertNull($delta->toolName);
        self::assertNull($delta->toolInputJson);
    }

    public function testEachDeltaIsOwnedValue(): void
    {
        $first = new TokenDelta(text: 'Sparta');
        $second = new TokenDelta(text: 'Athens');

        self::assertNotSame(spl_object_id($first), spl_object_id($second));
        self::assertSame('Sparta', $first->text);
        self::assertSame('Athens', $second->text);
    }
}
