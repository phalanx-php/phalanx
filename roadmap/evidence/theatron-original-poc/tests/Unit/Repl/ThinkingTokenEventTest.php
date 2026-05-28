<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Event\ThinkingTokenEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThinkingTokenEventTest extends TestCase
{
    #[Test]
    public function delta_is_accessible(): void
    {
        self::assertSame('chunk', (new ThinkingTokenEvent(delta: 'chunk'))->delta);
    }
}
