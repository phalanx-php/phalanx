<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Tdom;

use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Context\RenderEnvironment;
use Phalanx\Theatron\Contract\Component;
use Phalanx\Theatron\Layout\Size;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\DividerElement;
use Phalanx\Theatron\Tdom\Element\GridElement;
use Phalanx\Theatron\Tdom\Element\InputElement;
use Phalanx\Theatron\Tdom\Element\MountElement;
use Phalanx\Theatron\Tdom\Element\PanelElement;
use Phalanx\Theatron\Tdom\Element\ProgressElement;
use Phalanx\Theatron\Tdom\Element\RowElement;
use Phalanx\Theatron\Tdom\Element\ScrollElement;
use Phalanx\Theatron\Tdom\Element\SpinnerElement;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function Phalanx\Theatron\Ui\column;
use function Phalanx\Theatron\Ui\divider;
use function Phalanx\Theatron\Ui\grid;
use function Phalanx\Theatron\Ui\input;
use function Phalanx\Theatron\Ui\mount;
use function Phalanx\Theatron\Ui\panel;
use function Phalanx\Theatron\Ui\progress;
use function Phalanx\Theatron\Ui\row;
use function Phalanx\Theatron\Ui\scrollable;
use function Phalanx\Theatron\Ui\spinner;
use function Phalanx\Theatron\Ui\statusLine;
use function Phalanx\Theatron\Ui\text;

final class FunctionBuilderTest extends TestCase
{
    #[Test]
    public function textBuildsTextElement(): void
    {
        $style = Style::empty();
        $element = text('Apollo', $style);

        self::assertInstanceOf(TextElement::class, $element);
        self::assertSame('Apollo', $element->content);
        self::assertSame($style, $element->style);
    }

    #[Test]
    public function textAcceptsLineContent(): void
    {
        $line = Line::plain('Athena');

        $element = text($line);

        self::assertSame($line, $element->content);
    }

    #[Test]
    public function panelBuildsPanelElement(): void
    {
        $child = text('child');
        $style = Style::empty();
        $element = panel('Title', $child, $style);

        self::assertInstanceOf(PanelElement::class, $element);
        self::assertSame('Title', $element->title);
        self::assertSame($child, $element->child);
        self::assertSame($style, $element->style);
    }

    #[Test]
    public function columnBuildsColumnElement(): void
    {
        $child = text('child');
        $element = column($child);

        self::assertInstanceOf(ColumnElement::class, $element);
        self::assertSame([$child], $element->children);
    }

    #[Test]
    public function rowBuildsRowElement(): void
    {
        $child = text('child');
        $element = row($child);

        self::assertInstanceOf(RowElement::class, $element);
        self::assertSame([$child], $element->children);
    }

    #[Test]
    public function allFactoryFunctionsBuildElements(): void
    {
        $child = text('child');
        $style = Style::empty();
        $columns = [Size::fill()];

        $grid = grid($columns, $child);
        self::assertInstanceOf(GridElement::class, $grid);
        self::assertSame($columns, $grid->columns);
        self::assertSame([$child], $grid->children);

        $scroll = scrollable('body', 3, $style);
        self::assertInstanceOf(ScrollElement::class, $scroll);
        self::assertSame('body', $scroll->content);
        self::assertSame(3, $scroll->maxLines);
        self::assertSame($style, $scroll->style);

        $input = input('value', 'Prompt', 2, $style);
        self::assertInstanceOf(InputElement::class, $input);
        self::assertSame('value', $input->value);
        self::assertSame('Prompt', $input->prompt);
        self::assertSame(2, $input->cursor);
        self::assertSame($style, $input->style);

        $statusLine = statusLine($child);
        self::assertInstanceOf(StatusLineElement::class, $statusLine);
        self::assertSame([$child], $statusLine->sections);

        $spinner = spinner('loading', 1, $style);
        self::assertInstanceOf(SpinnerElement::class, $spinner);
        self::assertSame('loading', $spinner->label);
        self::assertSame(1, $spinner->frame);
        self::assertSame($style, $spinner->style);

        $divider = divider($style);
        self::assertInstanceOf(DividerElement::class, $divider);
        self::assertSame($style, $divider->style);

        $progress = progress(0.5, 'half', $style);
        self::assertInstanceOf(ProgressElement::class, $progress);
        self::assertSame(0.5, $progress->value);
        self::assertSame('half', $progress->label);
        self::assertSame($style, $progress->style);
    }

    #[Test]
    public function textParsesMarkupWithAmbientTheme(): void
    {
        $element = RenderEnvironment::withTheme(
            Theme::default(),
            static fn(): TextElement => text('[accent]Sparta[/]'),
        );

        self::assertInstanceOf(Line::class, $element->content);
    }

    #[Test]
    public function textDoesNotParseMarkupWithoutThemeContext(): void
    {
        $element = text('[bold]Sparta[/]');

        self::assertSame('[bold]Sparta[/]', $element->content);
    }

    #[Test]
    public function lineContentIsNeverReparsed(): void
    {
        $line = Line::from(Span::plain('[accent]Sparta[/]'));

        $element = RenderEnvironment::withTheme(
            Theme::default(),
            static fn(): TextElement => text($line),
        );

        self::assertSame($line, $element->content);
    }

    #[Test]
    public function renderEnvironmentRestoresNestedTheme(): void
    {
        $outer = Theme::default();
        $inner = Theme::default();

        RenderEnvironment::withTheme($outer, static function () use ($outer, $inner): void {
            self::assertSame($outer, RenderEnvironment::theme());

            RenderEnvironment::withTheme($inner, static function () use ($inner): void {
                self::assertSame($inner, RenderEnvironment::theme());
            });

            self::assertSame($outer, RenderEnvironment::theme());
        });

        self::assertNull(RenderEnvironment::theme());
    }

    #[Test]
    public function renderEnvironmentRestoresThemeAfterException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            RenderEnvironment::withTheme(Theme::default(), static function (): never {
                throw new RuntimeException('boom');
            });
        } finally {
            self::assertNull(RenderEnvironment::theme());
        }
    }

    #[Test]
    public function renderEnvironmentRestoresOuterThemeAfterNestedException(): void
    {
        $outer = Theme::default();
        $inner = Theme::default();

        RenderEnvironment::withTheme($outer, static function () use ($outer, $inner): void {
            try {
                RenderEnvironment::withTheme($inner, static function (): never {
                    throw new RuntimeException('boom');
                });
                self::fail('Expected nested theme exception.');
            } catch (RuntimeException $e) {
                self::assertSame('boom', $e->getMessage());
            }

            self::assertSame($outer, RenderEnvironment::theme());
        });

        self::assertNull(RenderEnvironment::theme());
    }

    #[Test]
    public function mountStoresComponentClassAndNamedProps(): void
    {
        $element = mount(FunctionBuilderComponent::class, label: 'Hermes');

        self::assertInstanceOf(MountElement::class, $element);
        self::assertSame(FunctionBuilderComponent::class, $element->component);
        self::assertSame(['label' => 'Hermes'], $element->props);
    }

    #[Test]
    public function mountRejectsPositionalProps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Component props must be passed as named arguments.');

        mount(FunctionBuilderComponent::class, 'Hermes');
    }
}

final class FunctionBuilderComponent implements Component
{
    public function __construct(
        private string $label = 'default',
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text($this->label);
    }
}
