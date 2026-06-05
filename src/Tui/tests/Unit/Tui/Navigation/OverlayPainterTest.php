<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Navigation;

use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Tui\Drawing\Buffer;
use Phalanx\Tui\Tui\Drawing\Rect;
use Phalanx\Tui\Tui\Core\MountSystem;
use Phalanx\Tui\Tui\Core\RenderContext;
use Phalanx\Tui\Tui\Styles\Padding;
use Phalanx\Tui\Tui\Navigation\OverlayFrame;
use Phalanx\Tui\Tui\Navigation\OverlayPainter;
use Phalanx\Tui\Tui\Styles\Color;
use Phalanx\Tui\Tui\Styles\Modifier;
use Phalanx\Tui\Tui\Styles\Style as AnsiStyle;
use Phalanx\Tui\Tui\Styles\Theme;
use Phalanx\Tui\Tui\Tdom\Style as TdomStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Phalanx\Tui\Tui\Kit\panel;
use function Phalanx\Tui\Tui\Kit\text;

final class OverlayPainterTest extends TestCase
{
    #[Test]
    public function backdropScrimsBackgroundAndKeepsOverlayTransparentCellsFromErasingIt(): void
    {
        $target = Buffer::empty(10, 3);
        $target->putString(0, 1, 'background', AnsiStyle::new());

        OverlayPainter::paint(
            text('X'),
            $target,
            Rect::sized(10, 3),
            OverlayFrame::centered(Rect::sized(10, 3), 4, 1),
            $this->renderContext(),
            new \stdClass(),
        );

        self::assertSame('b', $target->get(0, 1)->char);
        self::assertTrue($target->get(0, 1)->style->hasModifier(Modifier::Dim));
        self::assertTrue($target->get(0, 1)->style->background?->equals(Color::hex('#050505')));
        self::assertSame('X', $target->get(3, 1)->char);
        self::assertSame('g', $target->get(4, 1)->char);
        self::assertTrue($target->get(4, 1)->style->hasModifier(Modifier::Dim));
        self::assertSame(' ', $target->get(0, 0)->char);
        self::assertFalse($target->get(0, 0)->transparent);
        self::assertTrue($target->get(0, 0)->style->background?->equals(Color::hex('#050505')));
    }

    #[Test]
    public function panelBackgroundMakesOverlaySurfaceOpaque(): void
    {
        $target = Buffer::empty(10, 3);
        $target->putString(0, 1, 'background', AnsiStyle::new());

        OverlayPainter::paint(
            panel(
                '',
                text(''),
                TdomStyle::of(
                    padding: Padding::all(1),
                    background: Color::indexed(236),
                ),
            ),
            $target,
            Rect::sized(10, 3),
            OverlayFrame::centered(Rect::sized(10, 3), 6, 3),
            $this->renderContext(),
            new \stdClass(),
        );

        self::assertSame(' ', $target->get(3, 1)->char);
        self::assertFalse($target->get(3, 1)->transparent);
        self::assertSame('b', $target->get(0, 1)->char);
    }

    #[Test]
    public function fullscreenOverlayWithoutBackdropKeepsReplacementSemantics(): void
    {
        $target = Buffer::empty(10, 3);
        $target->putString(0, 1, 'background', AnsiStyle::new());

        OverlayPainter::paint(
            text('X'),
            $target,
            Rect::sized(10, 3),
            OverlayFrame::fullscreen(Rect::sized(10, 3)),
            $this->renderContext(),
            new \stdClass(),
        );

        self::assertSame('X', $target->get(0, 0)->char);
        self::assertSame(' ', $target->get(0, 1)->char);
        self::assertTrue($target->get(0, 1)->transparent);
    }

    private function renderContext(): RenderContext
    {
        $scope = $this->createStub(TaskScope::class);

        return new RenderContext($scope, Theme::default(), new MountSystem($scope));
    }
}
