<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tdom;

use Phalanx\Tui\Styles\Align;
use Phalanx\Tui\Styles\Border;
use Phalanx\Tui\Styles\Padding;
use Phalanx\Tui\Styles\Size;
use Phalanx\Tui\Styles\Color;
use Phalanx\Tui\Tdom\Element\ColumnElement;
use Phalanx\Tui\Tdom\Element\PanelElement;
use Phalanx\Tui\Tdom\Element\TextElement;
use Phalanx\Tui\Tdom\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ElementFluentTest extends TestCase
{
    #[Test]
    public function fluentReturnsNewInstance(): void
    {
        $original = new TextElement('Sparta');
        $styled = $original->size(20);

        self::assertNotSame($original, $styled);
        self::assertNull($original->style);
        self::assertNotNull($styled->style);
    }

    #[Test]
    public function nullStyleStartDoesNotThrow(): void
    {
        $el = new TextElement('Thermopylae');

        self::assertNull($el->style);

        $result = $el->border(Border::Single);

        self::assertNotNull($result->style);
        self::assertSame(Border::Single, $result->style->border);
    }

    #[Test]
    public function chainingAccumulatesStyles(): void
    {
        $el = new TextElement('Marathon')
            ->size(40)
            ->border(Border::Rounded)
            ->padding(2)
            ->align(Align::Center)
            ->color('#88ccff')
            ->background('red');

        self::assertNotNull($el->style);
        self::assertNotNull($el->style->size);
        self::assertSame(Border::Rounded, $el->style->border);
        self::assertNotNull($el->style->padding);
        self::assertSame(Align::Center, $el->style->align);

        $color = $el->style->color;
        $background = $el->style->background;

        self::assertNotNull($color);
        self::assertNotNull($background);
        self::assertTrue($color->equals(Color::hex('#88ccff')));
        self::assertTrue($background->equals(Color::named('red')));
    }

    #[Test]
    public function intSizeInputCreatesFixedSize(): void
    {
        $el = new TextElement('Doru')->size(24);

        self::assertNotNull($el->style?->size);
        self::assertEquals(Size::fixed(24), $el->style->size);
    }

    #[Test]
    public function sizeObjectPassthrough(): void
    {
        $size = Size::percent(50);
        $el = new TextElement('Aspis')->size($size);

        self::assertSame($size, $el->style?->size);
    }

    #[Test]
    public function intPaddingInputCreatesUniformPadding(): void
    {
        $el = new TextElement('Sarissa')->padding(1);

        self::assertNotNull($el->style?->padding);
        self::assertEquals(Padding::all(1), $el->style->padding);
    }

    #[Test]
    public function paddingObjectPassthrough(): void
    {
        $pad = Padding::horizontal(3);
        $el = new TextElement('Hoplite')->padding($pad);

        self::assertSame($pad, $el->style?->padding);
    }

    #[Test]
    public function stringColorWithHash(): void
    {
        $el = new TextElement('Olympus')->color('#ff6600');

        self::assertNotNull($el->style?->color);
        self::assertTrue($el->style->color->equals(Color::hex('#ff6600')));
    }

    #[Test]
    public function stringColorNamed(): void
    {
        $el = new TextElement('Agora')->color('cyan');

        self::assertNotNull($el->style?->color);
        self::assertTrue($el->style->color->equals(Color::named('cyan')));
    }

    #[Test]
    public function styledEscapeHatch(): void
    {
        $style = Style::of(border: Border::Heavy);
        $el = new TextElement('Polis')->styled($style);

        self::assertSame($style, $el->style);
    }

    #[Test]
    public function styledNullClearsStyle(): void
    {
        $el = new TextElement('Leonidas')
            ->border(Border::Single)
            ->styled(null);

        self::assertNull($el->style);
    }

    #[Test]
    public function fluentWorksOnPanelElement(): void
    {
        $panel = new PanelElement('Zeus', new TextElement('body'));
        $styled = $panel->padding(1)->border(Border::Double);

        self::assertNotSame($panel, $styled);
        self::assertNull($panel->style);
        self::assertNotNull($styled->style);
        self::assertSame(Border::Double, $styled->style->border);
        self::assertNotNull($styled->style->padding);
    }

    #[Test]
    public function fluentWorksOnColumnElement(): void
    {
        $col = new ColumnElement([new TextElement('Delphi')]);
        $styled = $col->size(60)->align(Align::End);

        self::assertNotSame($col, $styled);
        self::assertSame(Align::End, $styled->style?->align);
    }
}
