<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Tui\Drawing;

use Phalanx\Theatron\Tui\Drawing\Terminal;
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

    #[Test]
    public function sizeReadsEnvironmentDimensions(): void
    {
        $oldColumns = getenv('COLUMNS');
        $oldLines = getenv('LINES');

        try {
            putenv('COLUMNS=120');
            putenv('LINES=40');

            self::assertSame([120, 40], Terminal::size());
        } finally {
            self::restoreEnvironment('COLUMNS', $oldColumns);
            self::restoreEnvironment('LINES', $oldLines);
        }
    }

    private static function restoreEnvironment(string $key, string|false $value): void
    {
        if ($value === false) {
            putenv($key);

            return;
        }

        putenv($key . '=' . $value);
    }
}
