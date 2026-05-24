<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Overlay;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Layout\Padding;
use Phalanx\Theatron\Overlay\OverlayFrame;
use Phalanx\Theatron\Overlay\OverlayPainter;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Modifier;
use Phalanx\Theatron\Style\Style as AnsiStyle;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Style as TdomStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function Phalanx\Theatron\Ui\panel;
use function Phalanx\Theatron\Ui\text;

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
