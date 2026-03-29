<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Tests\Unit\Layout;

use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Layout\Constraint;
use Phalanx\Terminal\Layout\Layout;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    #[Test]
    public function vertical_splits_by_length(): void
    {
        $area = Rect::sized(80, 24);

        $rects = Layout::vertical(
            $area,
            Constraint::length(1),
            Constraint::length(20),
            Constraint::length(3),
        );

        self::assertCount(3, $rects);
        self::assertSame(0, $rects[0]->y);
        self::assertSame(1, $rects[0]->height);
        self::assertSame(1, $rects[1]->y);
        self::assertSame(20, $rects[1]->height);
        self::assertSame(21, $rects[2]->y);
        self::assertSame(3, $rects[2]->height);
    }

    #[Test]
    public function vertical_fill_takes_remaining(): void
    {
        $area = Rect::sized(80, 24);

        $rects = Layout::vertical(
            $area,
            Constraint::length(1),
            Constraint::fill(),
            Constraint::length(3),
        );

        self::assertSame(1, $rects[0]->height);
        self::assertSame(20, $rects[1]->height);
        self::assertSame(3, $rects[2]->height);
        self::assertSame(24, $rects[0]->height + $rects[1]->height + $rects[2]->height);
    }

    #[Test]
    public function vertical_multiple_fills_split_evenly(): void
    {
        $area = Rect::sized(80, 20);

        $rects = Layout::vertical(
            $area,
            Constraint::fill(),
            Constraint::fill(),
        );

        self::assertSame(10, $rects[0]->height);
        self::assertSame(10, $rects[1]->height);
    }

    #[Test]
    public function vertical_percentage(): void
    {
        $area = Rect::sized(80, 100);

        $rects = Layout::vertical(
            $area,
            Constraint::percentage(30),
            Constraint::percentage(70),
        );

        self::assertSame(30, $rects[0]->height);
        self::assertSame(70, $rects[1]->height);
    }

    #[Test]
    public function horizontal_splits_width(): void
    {
        $area = Rect::sized(80, 24);

        $rects = Layout::horizontal(
            $area,
            Constraint::length(20),
            Constraint::fill(),
            Constraint::length(20),
        );

        self::assertSame(20, $rects[0]->width);
        self::assertSame(40, $rects[1]->width);
        self::assertSame(20, $rects[2]->width);
        self::assertSame(0, $rects[0]->x);
        self::assertSame(20, $rects[1]->x);
        self::assertSame(60, $rects[2]->x);
    }

    #[Test]
    public function all_rects_preserve_parent_position(): void
    {
        $area = Rect::of(5, 10, 40, 20);

        $rects = Layout::vertical(
            $area,
            Constraint::fill(),
            Constraint::fill(),
        );

        self::assertSame(5, $rects[0]->x);
        self::assertSame(10, $rects[0]->y);
        self::assertSame(40, $rects[0]->width);
        self::assertSame(5, $rects[1]->x);
        self::assertSame(20, $rects[1]->y);
    }

    #[Test]
    public function odd_fill_distributes_extra_to_first(): void
    {
        $area = Rect::sized(80, 21);

        $rects = Layout::vertical(
            $area,
            Constraint::fill(),
            Constraint::fill(),
        );

        self::assertSame(11, $rects[0]->height);
        self::assertSame(10, $rects[1]->height);
    }

    #[Test]
    public function empty_constraints_returns_empty(): void
    {
        $area = Rect::sized(80, 24);

        self::assertSame([], Layout::vertical($area));
    }
}
