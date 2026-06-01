<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Tui\Styles;

use Phalanx\Theatron\Tui\Styles\Line;
use Phalanx\Theatron\Tui\Styles\Span;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LineTest extends TestCase
{
    #[Test]
    public function plainCreatesFromString(): void
    {
        $line = Line::plain('hello');

        self::assertCount(1, $line->spans);
        self::assertSame('hello', $line->spans[0]->content);
        self::assertSame(5, $line->width);
    }

    #[Test]
    public function appendProducesNewInstance(): void
    {
        $line = Line::plain('hello');
        $appended = $line->append(Span::plain(' world'));

        self::assertCount(1, $line->spans);
        self::assertCount(2, $appended->spans);
        self::assertSame(11, $appended->width);
    }

    #[Test]
    public function pushMutatesInPlace(): void
    {
        $line = Line::plain('hi');
        $line->push(Span::plain('!'));

        self::assertCount(2, $line->spans);
        self::assertSame(3, $line->width);
    }

    #[Test]
    public function wrapToWidthNoOpWhenFits(): void
    {
        $line = Line::plain('short');
        $wrapped = $line->wrapToWidth(20);

        self::assertCount(1, $wrapped);
        self::assertSame('short', $wrapped[0]->spans[0]->content);
    }

    #[Test]
    public function wrapToWidthBreaksAtWordBoundary(): void
    {
        $line = Line::plain('hello world');
        $wrapped = $line->wrapToWidth(7);

        self::assertCount(2, $wrapped);
        self::assertSame('hello', $wrapped[0]->spans[0]->content);
        self::assertSame('world', $wrapped[1]->spans[0]->content);
    }

    #[Test]
    public function wrapToWidthBreaksLongWord(): void
    {
        $line = Line::plain('abcdefghij');
        $wrapped = $line->wrapToWidth(5);

        self::assertCount(2, $wrapped);
        self::assertSame('abcde', $wrapped[0]->spans[0]->content);
        self::assertSame('fghij', $wrapped[1]->spans[0]->content);
    }

    #[Test]
    public function wrapToWidthReturnsOriginalForZeroWidth(): void
    {
        $line = Line::plain('test');
        $wrapped = $line->wrapToWidth(0);

        self::assertCount(1, $wrapped);
    }

    #[Test]
    public function widthUsesMbStrwidth(): void
    {
        $line = Line::plain('漢字');

        self::assertSame(4, $line->width);
    }

    #[Test]
    public function fromCombinesMultipleSpans(): void
    {
        $line = Line::from(Span::plain('a'), Span::plain('b'), Span::plain('c'));

        self::assertCount(3, $line->spans);
        self::assertSame(3, $line->width);
    }
}
