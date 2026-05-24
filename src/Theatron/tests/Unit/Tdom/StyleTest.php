<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Tdom;

use Phalanx\Theatron\Layout\Align;
use Phalanx\Theatron\Layout\Border;
use Phalanx\Theatron\Layout\Padding;
use Phalanx\Theatron\Layout\Size;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Tdom\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    #[Test]
    public function ofWithAllNulls(): void
    {
        $style = Style::of();
        self::assertNull($style->size);
        self::assertNull($style->align);
        self::assertNull($style->border);
        self::assertNull($style->padding);
        self::assertNull($style->color);
        self::assertNull($style->background);
    }

    #[Test]
    public function ofWithAllValues(): void
    {
        $style = Style::of(
            size: Size::fixed(20),
            align: Align::Center,
            border: Border::Single,
            padding: Padding::all(1),
            color: Color::red(),
            background: Color::blue(),
        );

        self::assertNotNull($style->size);
        self::assertSame(Align::Center, $style->align);
        self::assertSame(Border::Single, $style->border);
        self::assertNotNull($style->padding);
        self::assertNotNull($style->color);
        self::assertTrue($style->color->equals(Color::red()));
        self::assertNotNull($style->background);
        self::assertTrue($style->background->equals(Color::blue()));
    }

    #[Test]
    public function ofWithPartialValues(): void
    {
        $style = Style::of(color: Color::green());
        self::assertNull($style->size);
        self::assertNull($style->border);
        self::assertNotNull($style->color);
        self::assertTrue($style->color->equals(Color::green()));
    }

    #[Test]
    public function patchOverlaysNonNullFields(): void
    {
        $base = Style::of(size: Size::fixed(10), border: Border::Single);
        $overlay = Style::of(align: Align::Center, color: Color::red());

        $result = $base->patch($overlay);

        self::assertSame($base->size, $result->size);
        self::assertSame(Align::Center, $result->align);
        self::assertSame(Border::Single, $result->border);
        self::assertNotNull($result->color);
        self::assertTrue($result->color->equals(Color::red()));
    }

    #[Test]
    public function patchOverlayWinsOnConflict(): void
    {
        $base = Style::of(border: Border::Single);
        $overlay = Style::of(border: Border::Rounded);

        $result = $base->patch($overlay);

        self::assertSame(Border::Rounded, $result->border);
    }

    #[Test]
    public function patchWithEmptyOverlayReturnsSameValues(): void
    {
        $base = Style::of(size: Size::fixed(20), align: Align::End, padding: Padding::all(2));
        $result = $base->patch(Style::of());

        self::assertSame($base->size, $result->size);
        self::assertSame(Align::End, $result->align);
        self::assertSame($base->padding, $result->padding);
        self::assertNull($result->color);
        self::assertNull($result->background);
    }

    #[Test]
    public function patchWithEmptyBaseReturnsOverlay(): void
    {
        $overlay = Style::of(border: Border::Heavy, color: Color::blue(), background: Color::red());
        $result = Style::of()->patch($overlay);

        self::assertSame(Border::Heavy, $result->border);
        self::assertNotNull($result->color);
        self::assertTrue($result->color->equals(Color::blue()));
        self::assertNotNull($result->background);
        self::assertTrue($result->background->equals(Color::red()));
        self::assertNull($result->size);
        self::assertNull($result->align);
        self::assertNull($result->padding);
    }

    #[Test]
    public function patchChainLastOverlayWins(): void
    {
        $result = Style::of(border: Border::Single)
            ->patch(Style::of(color: Color::red()))
            ->patch(Style::of(color: Color::blue()));

        self::assertSame(Border::Single, $result->border);
        self::assertNotNull($result->color);
        self::assertTrue($result->color->equals(Color::blue()));
    }
}
