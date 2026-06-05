<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Drawing;

use Phalanx\Tui\Tui\Drawing\DisplayWidth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DisplayWidthTest extends TestCase
{
    #[Test]
    public function stripAnsiRemovesEscapeSequences(): void
    {
        $input = "\033[32mhello\033[0m";
        $stripped = DisplayWidth::stripAnsi($input);

        self::assertSame('hello', $stripped);
    }

    #[Test]
    public function stripAnsiLeavesPlainTextAlone(): void
    {
        self::assertSame('hello', DisplayWidth::stripAnsi('hello'));
    }

    #[Test]
    public function ofReturnsCorrectWidthForPlainText(): void
    {
        self::assertSame(5, DisplayWidth::of('hello'));
    }

    #[Test]
    public function ofStripsAnsiBeforeMeasuring(): void
    {
        self::assertSame(5, DisplayWidth::of("\033[1mhello\033[0m"));
    }

    #[Test]
    public function ofHandlesMultibyteCharacters(): void
    {
        self::assertSame(3, DisplayWidth::of('åbc'));
    }

    #[Test]
    public function truncateNoOpWhenWithinMaxWidth(): void
    {
        self::assertSame('hello', DisplayWidth::truncate('hello', 10));
    }

    #[Test]
    public function truncateAddsEllipsisWhenExceeding(): void
    {
        $result = DisplayWidth::truncate('hello world', 8);

        self::assertStringEndsWith('...', $result);
        self::assertLessThanOrEqual(8, DisplayWidth::of($result));
    }

    #[Test]
    public function truncateHandlesCustomEllipsis(): void
    {
        $result = DisplayWidth::truncate('hello world', 7, '…');

        self::assertStringEndsWith('…', $result);
        self::assertLessThanOrEqual(7, DisplayWidth::of($result));
    }

    #[Test]
    public function truncateExactlyAtMaxWidth(): void
    {
        $result = DisplayWidth::truncate('hello', 5);

        self::assertSame('hello', $result);
    }
}
