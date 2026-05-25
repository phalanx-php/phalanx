<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Styling;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Component\SignalScanner;
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Contract\Component;
use Phalanx\Theatron\Contract\Styled;
use Phalanx\Theatron\Layout\Border;
use Phalanx\Theatron\Reactive\DirtyBatch;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Styling\Stylesheet;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Tdom\Painter\PaintContext;
use Phalanx\Theatron\Tdom\Painter\Painter;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StyledIntegrationTest extends TestCase
{
    #[Test]
    public function paintContextPropagatesStylesheet(): void
    {
        $sheet = Stylesheet::of(['root' => Style::of(border: Border::Single)]);
        $buf = Buffer::empty(20, 1);
        $ctx = new PaintContext(Rect::sized(20, 1), $buf, $sheet);

        $sub = $ctx->sub(Rect::of(0, 0, 10, 1));

        self::assertSame($sheet, $sub->stylesheet);
    }

    #[Test]
    public function paintContextDefaultsToNullStylesheet(): void
    {
        $buf = Buffer::empty(20, 1);
        $ctx = new PaintContext(Rect::sized(20, 1), $buf);

        self::assertNull($ctx->stylesheet);
    }

    #[Test]
    public function stylesheetBackgroundFillAppliesToEmptyCells(): void
    {
        $bg = Color::hex('#333333');
        $sheet = Stylesheet::of(['text' => Style::of(background: $bg)]);

        $buf = Buffer::empty(20, 1);
        $ctx = new PaintContext(Rect::sized(20, 1), $buf, $sheet);

        $el = new TextElement('Hi');
        Painter::paint($el, $ctx);

        $filledCell = $buf->get(10, 0);
        self::assertNotNull($filledCell->style->background);
        self::assertTrue($filledCell->style->background->equals($bg));
    }

    #[Test]
    public function elementStyleOverridesStylesheet(): void
    {
        $sheetBg = Color::hex('#111111');
        $inlineBg = Color::hex('#999999');
        $sheet = Stylesheet::of(['text' => Style::of(background: $sheetBg)]);

        $buf = Buffer::empty(20, 1);
        $ctx = new PaintContext(Rect::sized(20, 1), $buf, $sheet);

        $el = new TextElement('Thermopylae', Style::of(background: $inlineBg));
        Painter::paint($el, $ctx);

        $filledCell = $buf->get(15, 0);
        self::assertNotNull($filledCell->style->background);
        self::assertTrue($filledCell->style->background->equals($inlineBg));
    }

    #[Test]
    public function mountedComponentCachesStylesheetFromStyledComponent(): void
    {
        $component = new OlympianStyledComponent();
        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);
        $ctx = new RenderContext($scope, Theme::default(), $mountSystem);

        self::assertNull($mounted->stylesheet());

        $mounted->render($ctx);

        self::assertNotNull($mounted->stylesheet());
    }

    #[Test]
    public function componentWithoutStyledHasNullStylesheet(): void
    {
        $component = new OlympianPlainComponent();
        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);
        $ctx = new RenderContext($scope, Theme::default(), $mountSystem);

        $mounted->render($ctx);

        self::assertNull($mounted->stylesheet());
    }

    #[Test]
    public function stylesheetCachedAcrossRerenders(): void
    {
        $component = new OlympianStyledComponent();
        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);
        $ctx = new RenderContext($scope, Theme::default(), $mountSystem);

        $mounted->render($ctx);
        $first = $mounted->stylesheet();

        $mounted->markDirty();
        $mounted->render($ctx);
        $second = $mounted->stylesheet();

        self::assertSame($first, $second);
    }

    #[Test]
    public function painterPropagatesStylesheetFromMountedComponent(): void
    {
        $component = new OlympianBgStyledComponent();
        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);
        $ctx = new RenderContext($scope, Theme::default(), $mountSystem);

        $mounted->render($ctx);

        $buf = Buffer::empty(20, 1);
        $paintCtx = new PaintContext(Rect::sized(20, 1), $buf);
        Painter::paint($mounted, $paintCtx);

        self::assertSame('S', $buf->get(0, 0)->char);
        $emptyCell = $buf->get(10, 0);
        self::assertNotNull($emptyCell->style->background);
        self::assertTrue($emptyCell->style->background->equals(Color::hex('#222222')));
    }

    #[Test]
    public function stylesheetInvalidatesOnThemeChange(): void
    {
        $component = new OlympianStyledComponent();
        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);

        $theme1 = Theme::default();
        $ctx1 = new RenderContext($scope, $theme1, $mountSystem);
        $mounted->render($ctx1);
        $first = $mounted->stylesheet();

        $theme2 = Theme::default();
        $ctx2 = new RenderContext($scope, $theme2, $mountSystem);
        $mounted->markDirty();
        $mounted->render($ctx2);
        $second = $mounted->stylesheet();

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function disposedComponentClearsStylesheet(): void
    {
        $component = new OlympianStyledComponent();
        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);
        $ctx = new RenderContext($scope, Theme::default(), $mountSystem);

        $mounted->render($ctx);
        self::assertNotNull($mounted->stylesheet());

        $mounted->dispose();
        self::assertNull($mounted->stylesheet());
    }

    protected function setUp(): void
    {
        Painter::reset();
    }

    protected function tearDown(): void
    {
        Painter::reset();
    }
}

final class OlympianStyledComponent implements Component, Styled
{
    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text('Olympus');
    }

    public function stylesheet(Theme $theme): Stylesheet
    {
        return Stylesheet::of([
            'text' => Style::of(color: $theme->fg),
        ]);
    }
}

final class OlympianBgStyledComponent implements Component, Styled
{
    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text('Sparta');
    }

    public function stylesheet(Theme $theme): Stylesheet
    {
        return Stylesheet::of([
            'text' => Style::of(background: Color::hex('#222222')),
        ]);
    }
}

final class OlympianPlainComponent implements Component
{
    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text('Agora');
    }
}
