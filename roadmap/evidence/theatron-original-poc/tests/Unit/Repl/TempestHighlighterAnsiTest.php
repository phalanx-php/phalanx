<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Highlight\TempestHighlighter;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Modifier;
use Phalanx\Theatron\Style\Style;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests the ANSI parsing logic in TempestHighlighter.
 *
 * Uses reflection to directly test parseAnsiLine since that method contains
 * the core logic for converting ANSI escape sequences to Line/Span structures.
 */
final class TempestHighlighterAnsiTest extends TestCase
{
    private ReflectionMethod $parseAnsiLine;
    private ReflectionMethod $parseAnsiLines;

    protected function setUp(): void
    {
        $this->parseAnsiLine = new ReflectionMethod(TempestHighlighter::class, 'parseAnsiLine');
        $this->parseAnsiLines = new ReflectionMethod(TempestHighlighter::class, 'parseAnsiLines');
    }

    #[Test]
    public function plain_text_without_escapes_produces_single_span(): void
    {
        $line = $this->parseLine('hello world');

        self::assertCount(1, $line->spans);
        self::assertSame('hello world', $line->spans[0]->content);
        self::assertTrue($line->spans[0]->style->isEmpty);
    }

    #[Test]
    public function empty_string_produces_plain_empty_line(): void
    {
        $line = $this->parseLine('');

        self::assertSame(0, $line->width);
    }

    #[Test]
    public function bold_escape_produces_bold_span(): void
    {
        $line = $this->parseLine("\033[1mBOLD\033[0m");

        self::assertCount(1, $line->spans);
        self::assertSame('BOLD', $line->spans[0]->content);
        self::assertTrue($line->spans[0]->style->hasModifier(Modifier::Bold));
    }

    #[Test]
    public function foreground_color_red_produces_colored_span(): void
    {
        $line = $this->parseLine("\033[31mred text\033[0m");

        self::assertCount(1, $line->spans);
        self::assertSame('red text', $line->spans[0]->content);
        self::assertNotNull($line->spans[0]->style->foreground);
    }

    #[Test]
    public function reset_code_clears_style(): void
    {
        $line = $this->parseLine("\033[1mBOLD\033[0mnormal");

        self::assertCount(2, $line->spans);
        self::assertSame('BOLD', $line->spans[0]->content);
        self::assertTrue($line->spans[0]->style->hasModifier(Modifier::Bold));
        self::assertSame('normal', $line->spans[1]->content);
        self::assertTrue($line->spans[1]->style->isEmpty);
    }

    #[Test]
    public function multiple_escapes_produce_multiple_spans(): void
    {
        $line = $this->parseLine("\033[31mred\033[32mgreen\033[0m");

        self::assertCount(2, $line->spans);
        self::assertSame('red', $line->spans[0]->content);
        self::assertSame('green', $line->spans[1]->content);
    }

    #[Test]
    public function text_before_first_escape_uses_current_style(): void
    {
        $line = $this->parseLine("prefix\033[1mbold");

        self::assertCount(2, $line->spans);
        self::assertSame('prefix', $line->spans[0]->content);
        self::assertTrue($line->spans[0]->style->isEmpty);
        self::assertSame('bold', $line->spans[1]->content);
        self::assertTrue($line->spans[1]->style->hasModifier(Modifier::Bold));
    }

    #[Test]
    public function italic_and_dim_escapes_apply(): void
    {
        $line = $this->parseLine("\033[3mitalic\033[0m \033[2mdim\033[0m");

        self::assertSame('italic', $line->spans[0]->content);
        self::assertTrue($line->spans[0]->style->hasModifier(Modifier::Italic));

        // Find the dim span (might be at index 2 due to space span)
        $dimSpan = null;

        foreach ($line->spans as $span) {
            if ($span->content === 'dim') {
                $dimSpan = $span;
                break;
            }
        }

        self::assertNotNull($dimSpan);
        self::assertTrue($dimSpan->style->hasModifier(Modifier::Dim));
    }

    #[Test]
    public function multiline_ansi_splits_into_line_array(): void
    {
        $lines = $this->parseLines("line1\n\033[1mline2\033[0m\nline3");

        self::assertCount(3, $lines);
        self::assertSame('line1', $lines[0]->spans[0]->content);
        self::assertSame('line2', $lines[1]->spans[0]->content);
        self::assertTrue($lines[1]->spans[0]->style->hasModifier(Modifier::Bold));
        self::assertSame('line3', $lines[2]->spans[0]->content);
    }

    #[Test]
    public function incomplete_escape_sequence_treated_as_plain_text(): void
    {
        // Escape without terminating 'm'
        $line = $this->parseLine("\033[31");

        // Should not crash; content preserved as plain text
        self::assertGreaterThanOrEqual(1, count($line->spans));
    }

    #[Test]
    public function underline_escape_applies(): void
    {
        $line = $this->parseLine("\033[4munderlined\033[0m");

        self::assertSame('underlined', $line->spans[0]->content);
        self::assertTrue($line->spans[0]->style->hasModifier(Modifier::Underline));
    }

    #[Test]
    public function bright_color_escapes_produce_colored_spans(): void
    {
        $line = $this->parseLine("\033[92mbright green\033[0m");

        self::assertSame('bright green', $line->spans[0]->content);
        self::assertNotNull($line->spans[0]->style->foreground);
    }

    private function parseLine(string $raw): Line
    {
        return $this->parseAnsiLine->invoke(null, $raw);
    }

    /** @return list<Line> */
    private function parseLines(string $ansi): array
    {
        return $this->parseAnsiLines->invoke(null, $ansi);
    }
}
