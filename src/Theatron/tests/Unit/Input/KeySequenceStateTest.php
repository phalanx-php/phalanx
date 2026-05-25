<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Input;

use Phalanx\Theatron\Input\KeySequenceState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeySequenceStateTest extends TestCase
{
    #[Test]
    public function startsWithoutActivePrefix(): void
    {
        $state = new KeySequenceState();

        self::assertNull($state->prefix);
        self::assertFalse($state->isAwaitingControlX());
    }

    #[Test]
    public function canRepresentControlXPrefix(): void
    {
        $state = new KeySequenceState();

        $next = $state->beginControlX();

        self::assertNull($state->prefix);
        self::assertSame(KeySequenceState::CONTROL_X_PREFIX, $next->prefix);
        self::assertTrue($next->isAwaitingControlX());
    }

    #[Test]
    public function clearRemovesActivePrefix(): void
    {
        $state = new KeySequenceState(KeySequenceState::CONTROL_X_PREFIX);

        $next = $state->clear();

        self::assertNull($next->prefix);
        self::assertFalse($next->isAwaitingControlX());
    }
}
