<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tdom;

use Phalanx\Tui\Core\RenderEnvironment;
use Phalanx\Tui\Styles\Border;
use Phalanx\Tui\Styles\Size;
use Phalanx\Tui\Styles\Color;
use Phalanx\Tui\Styles\Modifier;
use Phalanx\Tui\Styles\Theme;
use Phalanx\Tui\Tdom\Element\ColumnElement;
use Phalanx\Tui\Tdom\Element\DividerElement;
use Phalanx\Tui\Tdom\Element\GridElement;
use Phalanx\Tui\Tdom\Element\InputElement;
use Phalanx\Tui\Tdom\Element\PanelElement;
use Phalanx\Tui\Tdom\Element\ProgressElement;
use Phalanx\Tui\Tdom\Element\RowElement;
use Phalanx\Tui\Tdom\Element\ScrollElement;
use Phalanx\Tui\Tdom\Element\SpinnerElement;
use Phalanx\Tui\Tdom\Element\StatusLineElement;
use Phalanx\Tui\Tdom\Element\TextElement;
use Phalanx\Tui\Tdom\ElementType;
use Phalanx\Tui\Tdom\Style;
use Phalanx\Tui\Styles\Line;
use Phalanx\Tui\Styles\Span;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Phalanx\Tui\Kit\text;

final class ElementTest extends TestCase
{
    #[Test]
    public function textElementWithString(): void
    {
        $el = new TextElement('hello');
        self::assertSame(ElementType::Text, $el->type);
        self::assertSame('hello', $el->content);
        self::assertNull($el->style);
    }

    #[Test]
    public function textElementWithLine(): void
    {
        $line = new Line(new Span('world'));
        $el = new TextElement($line);
        self::assertSame($line, $el->content);
    }

    #[Test]
    public function textElementWithStyle(): void
    {
        $style = Style::of(color: Color::red());
        $el = new TextElement('styled', $style);
        self::assertSame($style, $el->style);
    }

    #[Test]
    public function panelElement(): void
    {
        $child = new TextElement('inner');
        $el = new PanelElement('Title', $child);
        self::assertSame(ElementType::Panel, $el->type);
        self::assertSame('Title', $el->title);
        self::assertSame($child, $el->child);
    }

    #[Test]
    public function columnElement(): void
    {
        $a = new TextElement('a');
        $b = new TextElement('b');
        $el = new ColumnElement([$a, $b]);
        self::assertSame(ElementType::Column, $el->type);
        self::assertCount(2, $el->children);
    }

    #[Test]
    public function rowElement(): void
    {
        $el = new RowElement([new TextElement('x')]);
        self::assertSame(ElementType::Row, $el->type);
    }

    #[Test]
    public function gridElement(): void
    {
        $el = new GridElement(
            [Size::fill(), Size::fixed(10)],
            [new TextElement('a'), new TextElement('b')],
        );
        self::assertSame(ElementType::Grid, $el->type);
        self::assertCount(2, $el->columns);
        self::assertCount(2, $el->children);
    }

    #[Test]
    public function scrollElement(): void
    {
        $el = new ScrollElement("line1\nline2", 5);
        self::assertSame(ElementType::Scroll, $el->type);
        self::assertSame(5, $el->maxLines);
    }

    #[Test]
    public function inputElement(): void
    {
        $el = new InputElement('text', '$ ', 3);
        self::assertSame(ElementType::Input, $el->type);
        self::assertSame('text', $el->value);
        self::assertSame('$ ', $el->prompt);
        self::assertSame(3, $el->cursor);
    }

    #[Test]
    public function statusLineElement(): void
    {
        $el = new StatusLineElement([new TextElement('left'), new TextElement('right')]);
        self::assertSame(ElementType::StatusLine, $el->type);
    }

    #[Test]
    public function spinnerElement(): void
    {
        $el = new SpinnerElement('Loading', 3);
        self::assertSame(ElementType::Spinner, $el->type);
        self::assertSame('Loading', $el->label);
        self::assertSame(3, $el->frame);
    }

    #[Test]
    public function dividerElement(): void
    {
        $el = new DividerElement();
        self::assertSame(ElementType::Divider, $el->type);
        self::assertNull($el->style);
    }

    #[Test]
    public function progressElement(): void
    {
        $el = new ProgressElement(0.75, 'Upload');
        self::assertSame(ElementType::Progress, $el->type);
        self::assertSame(0.75, $el->value);
        self::assertSame('Upload', $el->label);
    }

    #[Test]
    public function panelWithStyle(): void
    {
        $style = Style::of(border: Border::Rounded);
        $el = new PanelElement('Box', new TextElement('in'), $style);
        self::assertSame($style, $el->style);
        self::assertSame(Border::Rounded, $el->style->border);
    }

    #[Test]
    public function textAutoParsesBBCodeWhenThemePresent(): void
    {
        $el = RenderEnvironment::withTheme(
            Theme::default(),
            static fn() => text('[bold]Sparta[/]'),
        );

        self::assertInstanceOf(Line::class, $el->content);
        self::assertCount(1, $el->content->spans);
        self::assertTrue($el->content->spans[0]->style->hasModifier(Modifier::Bold));
    }

    #[Test]
    public function textPassesThroughStringWhenNoTheme(): void
    {
        $el = text('[bold]Sparta[/]');

        self::assertSame('[bold]Sparta[/]', $el->content);
    }

    #[Test]
    public function plainTextWithoutMarkupRemainsString(): void
    {
        $el = RenderEnvironment::withTheme(
            Theme::default(),
            static fn() => text('plain text'),
        );

        self::assertSame('plain text', $el->content);
    }
}
