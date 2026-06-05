<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Inputs;

use Phalanx\Tui\Tui\Inputs\KeySequenceState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeySequenceStateTest extends TestCase
{
    #[Test]
    public function startsWithoutActivePrefix(): void
    {
        $state = new KeySequenceState();

        self::assertFalse($state->isAwaitingControlX());
    }

    #[Test]
    public function canRepresentControlXPrefix(): void
    {
        $state = new KeySequenceState();

        $next = $state->beginControlX();

        self::assertFalse($state->isAwaitingControlX());
        self::assertTrue($next->isAwaitingControlX());
    }

    #[Test]
    public function clearRemovesActivePrefix(): void
    {
        $state = (new KeySequenceState())->beginControlX();

        $next = $state->clear();

        self::assertFalse($next->isAwaitingControlX());
    }
}
