<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Drawing;

use Phalanx\Tui\Tui\Drawing\Terminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TerminalTest extends TestCase
{
    #[Test]
    public function detectUsesExplicitEnvironmentDimensions(): void
    {
        $terminal = Terminal::detect([
            'COLUMNS' => '132',
            'LINES' => '43',
        ]);

        self::assertSame(132, $terminal->width);
        self::assertSame(43, $terminal->height);
    }

    #[Test]
    public function detectFallsBackWithoutProcessProbing(): void
    {
        $terminal = Terminal::detect([
            'COLUMNS' => false,
            'LINES' => false,
        ]);

        self::assertSame(80, $terminal->width);
        self::assertSame(24, $terminal->height);
    }
}
