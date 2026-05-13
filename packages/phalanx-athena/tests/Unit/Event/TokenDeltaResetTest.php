<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Event;

use Phalanx\Athena\Event\TokenDelta;
use PHPUnit\Framework\TestCase;

final class TokenDeltaResetTest extends TestCase
{
    public function testResetUpdatesAllProperties(): void
    {
        $delta = new TokenDelta(text: 'Sparta');

        $delta->reset(toolCallId: 'call-1', toolName: 'lookup', toolInputJson: '{"q":"Zeus"}');

        self::assertNull($delta->text);
        self::assertSame('call-1', $delta->toolCallId);
        self::assertSame('lookup', $delta->toolName);
        self::assertSame('{"q":"Zeus"}', $delta->toolInputJson);
    }

    public function testResetClearsFieldsWhenOmitted(): void
    {
        $delta = new TokenDelta(text: 'hello', toolCallId: 'x', toolName: 'y', toolInputJson: 'z');

        $delta->reset(text: 'Marathon');

        self::assertSame('Marathon', $delta->text);
        self::assertNull($delta->toolCallId);
        self::assertNull($delta->toolName);
        self::assertNull($delta->toolInputJson);
    }

    public function testMultipleResetsStable(): void
    {
        $delta = new TokenDelta();

        for ($i = 0; $i < 10; $i++) {
            $text = "token-$i";
            $delta->reset(text: $text);

            self::assertSame($text, $delta->text);
            self::assertNull($delta->toolCallId);
        }
    }

    public function testObjectIdentityPreserved(): void
    {
        $delta = new TokenDelta(text: 'Thermopylae');
        $id = spl_object_id($delta);

        $delta->reset(text: 'Olympia');

        self::assertSame($id, spl_object_id($delta));
    }
}
