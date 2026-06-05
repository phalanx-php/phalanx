<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Tdom\Painter;

use Phalanx\Tui\Tui\Drawing\Buffer;
use Phalanx\Tui\Tui\Drawing\Rect;
use Phalanx\Tui\Tui\Core\MountedComponent;
use Phalanx\Tui\Tui\Core\MountSystem;
use Phalanx\Tui\Tui\Core\SignalScanner;
use Phalanx\Tui\Tui\Core\RenderContext;
use Phalanx\Tui\Tui\Core\Component;
use Phalanx\Tui\Tui\Styles\Border;
use Phalanx\Tui\Tui\Styles\Padding;
use Phalanx\Tui\Tui\Styles\Size;
use Phalanx\Tui\Tui\Reactive\DirtyBatch;
use Phalanx\Tui\Tui\Styles\Color;
use Phalanx\Tui\Tui\Styles\Modifier;
use Phalanx\Tui\Tui\Styles\Style as AnsiStyle;
use Phalanx\Tui\Tui\Styles\Theme;
use Phalanx\Tui\Tui\Tdom\Element\ColumnElement;
use Phalanx\Tui\Tui\Tdom\Element\DividerElement;
use Phalanx\Tui\Tui\Tdom\Element\GridElement;
use Phalanx\Tui\Tui\Tdom\Element\InputElement;
use Phalanx\Tui\Tui\Tdom\Element\PanelElement;
use Phalanx\Tui\Tui\Tdom\Element\ProgressElement;
use Phalanx\Tui\Tui\Tdom\Element\RowElement;
use Phalanx\Tui\Tui\Tdom\Element\ScrollElement;
use Phalanx\Tui\Tui\Tdom\Element\SpinnerElement;
use Phalanx\Tui\Tui\Tdom\Element\StatusLineElement;
use Phalanx\Tui\Tui\Tdom\Element\TextElement;
use Phalanx\Tui\Tui\Tdom\Painter\PaintContext;
use Phalanx\Tui\Tui\Tdom\Painter\Painter;
use Phalanx\Tui\Tui\Tdom\Renderable;
use Phalanx\Tui\Tui\Tdom\Style;
use Phalanx\Tui\Tui\Styles\Line;
use Phalanx\Tui\Tui\Styles\Span;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Phalanx\Tui\Tui\Kit\column;
use function Phalanx\Tui\Tui\Kit\mount;
use function Phalanx\Tui\Tui\Kit\panel;

final class PainterTest extends TestCase
{
    #[Test]
    public function textPaintsStringIntoBuffer(): void
    {
        $buf = Buffer::empty(20, 3);
        $ctx = new PaintContext(Rect::sized(20, 3), $buf);

        Painter::paint(new TextElement('Hello'), $ctx);

        self::assertSame('H', $buf->get(0, 0)->char);
        self::assertSame('e', $buf->get(1, 0)->char);
        self::assertSame('l', $buf->get(2, 0)->char);
        self::assertSame('l', $buf->get(3, 0)->char);
        self::assertSame('o', $buf->get(4, 0)->char);
    }

    #[Test]
    public function panelPaintsBorderAndTitle(): void
    {
        $buf = Buffer::empty(20, 5);
        $ctx = new PaintContext(Rect::sized(20, 5), $buf);

        $panel = new PanelElement(
            'Test',
            new TextElement('inner'),
            Style::of(border: Border::Single),
        );

        Painter::paint($panel, $ctx);

        [$tl, $tr, $bl, $br, $h, $v] = Border::Single->chars();
        self::assertSame($tl, $buf->get(0, 0)->char);
        self::assertSame($tr, $buf->get(19, 0)->char);
        self::assertSame($bl, $buf->get(0, 4)->char);
        self::assertSame($br, $buf->get(19, 4)->char);
        self::assertSame($v, $buf->get(0, 1)->char);
        self::assertSame($h, $buf->get(1, 4)->char);
    }

    #[Test]
    public function panelRendersChildInside(): void
    {
        $buf = Buffer::empty(20, 5);
        $ctx = new PaintContext(Rect::sized(20, 5), $buf);

        $panel = new PanelElement(
            '',
            new TextElement('X'),
            Style::of(border: Border::Single),
        );

        Painter::paint($panel, $ctx);

        self::assertSame('X', $buf->get(1, 1)->char);
    }

    #[Test]
    public function columnStacksChildrenVertically(): void
    {
        $buf = Buffer::empty(10, 4);
        $ctx = new PaintContext(Rect::sized(10, 4), $buf);

        $col = new ColumnElement([
            new TextElement('Top', Style::of(size: Size::fixed(1))),
            new TextElement('Bot', Style::of(size: Size::fixed(1))),
        ]);

        Painter::paint($col, $ctx);

        self::assertSame('T', $buf->get(0, 0)->char);
        self::assertSame('B', $buf->get(0, 1)->char);
    }

    #[Test]
    public function rowDistributesChildrenHorizontally(): void
    {
        $buf = Buffer::empty(20, 1);
        $ctx = new PaintContext(Rect::sized(20, 1), $buf);

        $row = new RowElement([
            new TextElement('L', Style::of(size: Size::fixed(5))),
            new TextElement('R', Style::of(size: Size::fixed(5))),
        ]);

        Painter::paint($row, $ctx);

        self::assertSame('L', $buf->get(0, 0)->char);
        self::assertSame('R', $buf->get(5, 0)->char);
    }

    #[Test]
    public function dividerPaintsHorizontalLine(): void
    {
        $buf = Buffer::empty(10, 1);
        $ctx = new PaintContext(Rect::sized(10, 1), $buf);

        Painter::paint(new DividerElement(), $ctx);

        for ($x = 0; $x < 10; $x++) {
            self::assertSame('─', $buf->get($x, 0)->char);
        }
    }

    #[Test]
    public function progressPaintsFilledAndEmpty(): void
    {
        $buf = Buffer::empty(25, 1);
        $ctx = new PaintContext(Rect::sized(25, 1), $buf);

        Painter::paint(new ProgressElement(0.5), $ctx);

        $filled = 0;
        $empty = 0;

        for ($x = 0; $x < 20; $x++) {
            $ch = $buf->get($x, 0)->char;
            if ($ch === '█') {
                $filled++;
            } elseif ($ch === '░') {
                $empty++;
            }
        }

        self::assertGreaterThan(0, $filled);
        self::assertGreaterThan(0, $empty);
    }

    #[Test]
    public function paintContextSubSharesBuffer(): void
    {
        $buf = Buffer::empty(20, 10);
        $ctx = new PaintContext(Rect::sized(20, 10), $buf);

        $sub = $ctx->sub(Rect::of(5, 5, 10, 5));

        self::assertSame($buf, $sub->buffer);
        self::assertSame(5, $sub->area->x);
        self::assertSame(5, $sub->area->y);
    }

    #[Test]
    public function resolveAnsiStyleConvertsColors(): void
    {
        $tdomStyle = Style::of(color: Color::red(), background: Color::blue());
        $ansi = Painter::resolveAnsiStyle($tdomStyle);

        self::assertNotNull($ansi->foreground);
        self::assertTrue($ansi->foreground->equals(Color::red()));
        self::assertNotNull($ansi->background);
        self::assertTrue($ansi->background->equals(Color::blue()));
    }

    #[Test]
    public function resolveAnsiStyleReturnsEmptyForNull(): void
    {
        $ansi = Painter::resolveAnsiStyle(null);
        self::assertNull($ansi->foreground);
        self::assertNull($ansi->background);
    }

    #[Test]
    public function paddingAdjustsRenderArea(): void
    {
        $buf = Buffer::empty(20, 5);
        $ctx = new PaintContext(Rect::sized(20, 5), $buf);

        $text = new TextElement(
            'padded',
            Style::of(padding: Padding::all(1)),
        );

        Painter::paint($text, $ctx);

        self::assertSame(' ', $buf->get(0, 0)->char);
        self::assertSame('p', $buf->get(1, 1)->char);
    }

    #[Test]
    public function zeroSizeAreaSkipsPainting(): void
    {
        $buf = Buffer::empty(5, 5);
        $ctx = new PaintContext(Rect::sized(0, 0), $buf);

        Painter::paint(new TextElement('nope'), $ctx);

        self::assertSame(' ', $buf->get(0, 0)->char);
    }

    #[Test]
    public function statusLinePaintsUnstyled(): void
    {
        $buf = Buffer::empty(20, 1);
        $ctx = new PaintContext(Rect::sized(20, 1), $buf);

        $sl = new StatusLineElement([
            new TextElement('left'),
            new TextElement('right'),
        ]);

        Painter::paint($sl, $ctx);

        self::assertSame('l', $buf->get(0, 0)->char);
        self::assertSame('r', $buf->get(10, 0)->char);
    }

    #[Test]
    public function gridPaintsCellsInGrid(): void
    {
        $buf = Buffer::empty(20, 4);
        $ctx = new PaintContext(Rect::sized(20, 4), $buf);

        $grid = new GridElement(
            [Size::fixed(10), Size::fixed(10)],
            [
                new TextElement('A'),
                new TextElement('B'),
                new TextElement('C'),
                new TextElement('D'),
            ],
        );

        Painter::paint($grid, $ctx);

        self::assertSame('A', $buf->get(0, 0)->char);
        self::assertSame('B', $buf->get(10, 0)->char);
        self::assertSame('C', $buf->get(0, 2)->char);
        self::assertSame('D', $buf->get(10, 2)->char);
    }

    #[Test]
    public function scrollPaintsVisibleLines(): void
    {
        $buf = Buffer::empty(20, 2);
        $ctx = new PaintContext(Rect::sized(20, 2), $buf);

        Painter::paint(new ScrollElement("line1\nline2\nline3", 2), $ctx);

        self::assertSame('l', $buf->get(0, 0)->char);
        self::assertSame('2', $buf->get(4, 0)->char, 'First visible line should be "line2"');
        self::assertSame('l', $buf->get(0, 1)->char);
        self::assertSame('3', $buf->get(4, 1)->char, 'Second visible line should be "line3"');
    }

    #[Test]
    public function inputPaintsPromptValueAndCursor(): void
    {
        $buf = Buffer::empty(20, 1);
        $ctx = new PaintContext(Rect::sized(20, 1), $buf);

        Painter::paint(new InputElement('hello', '> ', 3), $ctx);

        self::assertSame('>', $buf->get(0, 0)->char);
        self::assertSame(' ', $buf->get(1, 0)->char);
        self::assertSame('h', $buf->get(2, 0)->char);
        self::assertSame('e', $buf->get(3, 0)->char);

        $cursorCell = $buf->get(5, 0);
        self::assertSame('l', $cursorCell->char);
        self::assertTrue($cursorCell->style->hasModifier(Modifier::Reverse));
    }

    #[Test]
    public function inputPaintsSelectionBeforeCursor(): void
    {
        $buf = Buffer::empty(20, 1);
        $ctx = new PaintContext(Rect::sized(20, 1), $buf);

        Painter::paint(new InputElement('hello', '> ', 3, selectionStart: 1, selectionEnd: 4), $ctx);

        self::assertFalse($buf->get(2, 0)->style->hasModifier(Modifier::Reverse));
        self::assertTrue($buf->get(3, 0)->style->hasModifier(Modifier::Reverse));
        self::assertTrue($buf->get(4, 0)->style->hasModifier(Modifier::Reverse));

        $cursorCell = $buf->get(5, 0);
        self::assertSame('l', $cursorCell->char);
        self::assertTrue($cursorCell->style->hasModifier(Modifier::Reverse));
    }

    #[Test]
    public function inputSelectionPreservesBaseStyle(): void
    {
        $buf = Buffer::empty(20, 1);
        $ctx = new PaintContext(Rect::sized(20, 1), $buf);

        Painter::paint(
            new InputElement(
                value: 'hello',
                prompt: '> ',
                cursor: 5,
                style: Style::of(color: Color::red()),
                selectionStart: 1,
                selectionEnd: 3,
            ),
            $ctx,
        );

        $selectedCell = $buf->get(3, 0);

        self::assertTrue($selectedCell->style->hasModifier(Modifier::Reverse));
        self::assertNotNull($selectedCell->style->foreground);
        self::assertTrue($selectedCell->style->foreground->equals(Color::red()));
    }

    #[Test]
    public function spinnerPaintsFrameAndLabel(): void
    {
        $buf = Buffer::empty(20, 1);
        $ctx = new PaintContext(Rect::sized(20, 1), $buf);

        Painter::paint(new SpinnerElement('Wait', 0), $ctx);

        self::assertNotSame(' ', $buf->get(0, 0)->char);
        self::assertSame('W', $buf->get(2, 0)->char);
        self::assertSame('a', $buf->get(3, 0)->char);
    }

    #[Test]
    public function progressClampsOutOfRangeValues(): void
    {
        $buf = Buffer::empty(25, 1);
        $ctx = new PaintContext(Rect::sized(25, 1), $buf);

        Painter::paint(new ProgressElement(1.5), $ctx);

        for ($x = 0; $x < 20; $x++) {
            self::assertSame('█', $buf->get($x, 0)->char, "Cell {$x} should be filled at 150%");
        }
    }

    #[Test]
    public function columnWithUnstyledChildren(): void
    {
        $buf = Buffer::empty(10, 4);
        $ctx = new PaintContext(Rect::sized(10, 4), $buf);

        $col = new ColumnElement([
            new TextElement('A'),
            new TextElement('B'),
        ]);

        Painter::paint($col, $ctx);

        self::assertSame('A', $buf->get(0, 0)->char);
        self::assertSame('B', $buf->get(0, 2)->char);
    }

    #[Test]
    public function paintDirtyMountedComponentRerenders(): void
    {
        $tracker = new \stdClass();
        $tracker->count = 0;

        $component = new class ($tracker) implements Component {
            public function __construct(private \stdClass $tracker)
            {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                $this->tracker->count++;

                return \Phalanx\Tui\Tui\Kit\text('rendered');
            }
        };

        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);
        $ctx = new RenderContext($scope, Theme::default(), $mountSystem);

        $mounted->render($ctx);
        self::assertSame(1, $tracker->count);

        $mounted->markDirty();

        $buf = Buffer::empty(20, 1);
        $paintCtx = new PaintContext(Rect::sized(20, 1), $buf);

        Painter::paint($mounted, $paintCtx);

        self::assertSame(2, $tracker->count, 'Dirty MountedComponent must rerender during paint');
        self::assertSame('r', $buf->get(0, 0)->char);
    }

    #[Test]
    public function paintCleanMountedComponentSkipsRerender(): void
    {
        $tracker = new \stdClass();
        $tracker->count = 0;

        $component = new class ($tracker) implements Component {
            public function __construct(private \stdClass $tracker)
            {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                $this->tracker->count++;

                return \Phalanx\Tui\Tui\Kit\text('cached');
            }
        };

        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);
        $ctx = new RenderContext($scope, Theme::default(), $mountSystem);

        $mounted->render($ctx);
        self::assertSame(1, $tracker->count);

        $buf = Buffer::empty(20, 1);
        $paintCtx = new PaintContext(Rect::sized(20, 1), $buf);

        Painter::paint($mounted, $paintCtx);

        self::assertSame(1, $tracker->count, 'Clean MountedComponent must not rerender');
        self::assertSame('c', $buf->get(0, 0)->char);
    }

    #[Test]
    public function paintMountedComponentWithNullLastResultIsNoOp(): void
    {
        $component = new class () implements Component {
            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Tui\Kit\text('never');
            }
        };

        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        $buf = Buffer::empty(20, 1);
        $paintCtx = new PaintContext(Rect::sized(20, 1), $buf);

        $mounted->dispose();

        Painter::paint($mounted, $paintCtx);

        self::assertSame(' ', $buf->get(0, 0)->char, 'Disposed component with null lastResult must not crash');
    }

    #[Test]
    public function panelPaintsLineTitleIntoBuffer(): void
    {
        $buf = Buffer::empty(20, 5);
        $ctx = new PaintContext(Rect::sized(20, 5), $buf);

        $title = Line::from(
            Span::styled('Zeus', AnsiStyle::new()->fg('#88ccff')),
        );

        $panel = new PanelElement(
            $title,
            new TextElement('inner'),
            Style::of(border: Border::Single),
        );

        Painter::paint($panel, $ctx);

        self::assertSame(' ', $buf->get(1, 0)->char);
        self::assertSame('Z', $buf->get(2, 0)->char);
        self::assertSame('e', $buf->get(3, 0)->char);
        self::assertSame('u', $buf->get(4, 0)->char);
        self::assertSame('s', $buf->get(5, 0)->char);
    }

    #[Test]
    public function panelPaintsEmptyWidthLineTitleWithoutGarbage(): void
    {
        $buf = Buffer::empty(20, 5);
        $ctx = new PaintContext(Rect::sized(20, 5), $buf);

        $title = Line::plain('');

        $panel = new PanelElement(
            $title,
            new TextElement('inner'),
            Style::of(border: Border::Single),
        );

        Painter::paint($panel, $ctx);

        [$tl, $tr] = Border::Single->chars();
        self::assertSame($tl, $buf->get(0, 0)->char);
        self::assertSame($tr, $buf->get(19, 0)->char);
    }

    #[Test]
    public function spinnerPaintsLineLabelIntoBuffer(): void
    {
        $buf = Buffer::empty(20, 1);
        $ctx = new PaintContext(Rect::sized(20, 1), $buf);

        $label = Line::from(
            Span::styled('Loading', AnsiStyle::new()->fg('#77cc77')),
        );

        Painter::paint(new SpinnerElement($label, 0), $ctx);

        self::assertSame('⠋', $buf->get(0, 0)->char);
        self::assertSame('L', $buf->get(2, 0)->char);
        self::assertSame('o', $buf->get(3, 0)->char);
    }

    #[Test]
    public function inputPaintsLinePromptIntoBuffer(): void
    {
        $buf = Buffer::empty(20, 1);
        $ctx = new PaintContext(Rect::sized(20, 1), $buf);

        $prompt = Line::from(
            Span::styled('> ', AnsiStyle::new()->fg('#88ccff')),
        );

        Painter::paint(new InputElement('hello', $prompt, 3), $ctx);

        self::assertSame('>', $buf->get(0, 0)->char);
        self::assertSame(' ', $buf->get(1, 0)->char);
        self::assertSame('h', $buf->get(2, 0)->char);

        $cursorCell = $buf->get(5, 0);
        self::assertSame('l', $cursorCell->char);
        self::assertTrue($cursorCell->style->hasModifier(Modifier::Reverse));
    }

    #[Test]
    public function progressPaintsLineLabelIntoBuffer(): void
    {
        $buf = Buffer::empty(30, 1);
        $ctx = new PaintContext(Rect::sized(30, 1), $buf);

        $label = Line::from(
            Span::styled('CPU', AnsiStyle::new()->bold()),
        );

        Painter::paint(new ProgressElement(0.5, $label), $ctx);

        self::assertSame('C', $buf->get(0, 0)->char);
        self::assertSame('P', $buf->get(1, 0)->char);
        self::assertSame('U', $buf->get(2, 0)->char);
        self::assertSame(' ', $buf->get(3, 0)->char);
        self::assertNotSame(' ', $buf->get(4, 0)->char);
    }

    #[Test]
    public function panelMountElementResolvesAndPaintsThroughRenderContext(): void
    {
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);
        $renderCtx = new RenderContext($scope, Theme::default(), $mountSystem);
        $buf = Buffer::empty(20, 5);
        $paintCtx = new PaintContext(Rect::sized(20, 5), $buf, renderContext: $renderCtx);

        Painter::paint(panel('Title', mount(PainterMountChildComponent::class)), $paintCtx);

        self::assertSame('M', $buf->get(1, 1)->char);
        self::assertCount(1, $mountSystem->mounted());
    }

    #[Test]
    public function mountElementPaintUsesStablePaintFrame(): void
    {
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);
        $renderCtx = new RenderContext($scope, Theme::default(), $mountSystem);
        $owner = new \stdClass();
        $buf = Buffer::empty(20, 5);
        $renderable = panel('Title', mount(PainterMountChildComponent::class));

        Painter::paint(
            $renderable,
            new PaintContext(Rect::sized(20, 5), $buf, renderContext: $renderCtx, mountOwner: $owner),
        );
        $first = $mountSystem->mounted()[0];

        Painter::paint(
            $renderable,
            new PaintContext(Rect::sized(20, 5), $buf, renderContext: $renderCtx, mountOwner: $owner),
        );

        self::assertSame($first, $mountSystem->mounted()[0]);
        self::assertCount(1, $mountSystem->mounted());
    }

    #[Test]
    public function paintFrameTracksMultipleMountSlots(): void
    {
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);
        $renderCtx = new RenderContext($scope, Theme::default(), $mountSystem);
        $buf = Buffer::empty(20, 5);
        $paintCtx = new PaintContext(Rect::sized(20, 5), $buf, renderContext: $renderCtx);
        $renderable = column(
            mount(PainterMountChildComponent::class),
            mount(PainterMountChildComponent::class),
        );

        Painter::paint($renderable, $paintCtx);

        self::assertCount(2, $mountSystem->mounted());
    }

    #[Test]
    public function paintFrameDisposesUnusedMountSlots(): void
    {
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);
        $renderCtx = new RenderContext($scope, Theme::default(), $mountSystem);
        $owner = new \stdClass();
        $buf = Buffer::empty(20, 5);

        Painter::paint(
            column(
                mount(PainterMountChildComponent::class),
                mount(PainterMountChildComponent::class),
            ),
            new PaintContext(Rect::sized(20, 5), $buf, renderContext: $renderCtx, mountOwner: $owner),
        );
        $unused = $mountSystem->mounted()[1];

        Painter::paint(
            column(mount(PainterMountChildComponent::class)),
            new PaintContext(Rect::sized(20, 5), $buf, renderContext: $renderCtx, mountOwner: $owner),
        );

        self::assertTrue($unused->isDisposed);
        self::assertCount(1, $mountSystem->mounted());
    }

    #[Test]
    public function paintFrameReplacesMountWhenPropsChange(): void
    {
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);
        $renderCtx = new RenderContext($scope, Theme::default(), $mountSystem);
        $owner = new \stdClass();
        $buf = Buffer::empty(20, 5);

        Painter::paint(
            panel('Title', mount(PainterLabelMountChildComponent::class, label: 'first')),
            new PaintContext(Rect::sized(20, 5), $buf, renderContext: $renderCtx, mountOwner: $owner),
        );
        $first = $mountSystem->mounted()[0];

        Painter::paint(
            panel('Title', mount(PainterLabelMountChildComponent::class, label: 'second')),
            new PaintContext(Rect::sized(20, 5), $buf, renderContext: $renderCtx, mountOwner: $owner),
        );
        $second = $mountSystem->mounted()[0];

        self::assertNotSame($first, $second);
        self::assertTrue($first->isDisposed);
        self::assertFalse($second->isDisposed);
        self::assertCount(1, $mountSystem->mounted());
    }

    #[Test]
    public function mountElementWithoutRenderContextIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mount elements require a render context.');

        $buf = Buffer::empty(20, 5);

        Painter::paint(
            mount(PainterMountChildComponent::class),
            new PaintContext(Rect::sized(20, 5), $buf),
        );
    }

    protected function tearDown(): void
    {
        Painter::reset();
    }
}

final class PainterMountChildComponent implements Component
{
    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Tui\Tui\Kit\text('Mounted');
    }
}

final class PainterLabelMountChildComponent implements Component
{
    public function __construct(
        private string $label,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Tui\Tui\Kit\text($this->label);
    }
}
