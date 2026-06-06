<?php

declare(strict_types=1);

namespace Phalanx\Tui\Navigation;

use Phalanx\Tui\Core\RenderContext;
use Phalanx\Tui\Drawing\Buffer;
use Phalanx\Tui\Drawing\Rect;
use Phalanx\Tui\Styles\Style as AnsiStyle;
use Phalanx\Tui\Tdom\Painter\PaintContext;
use Phalanx\Tui\Tdom\Painter\Painter;
use Phalanx\Tui\Tdom\Renderable;

final class OverlayPainter
{
    public static function paint(
        Renderable $renderable,
        Buffer $target,
        Rect $bounds,
        OverlayFrame $frame,
        RenderContext $renderContext,
        object $mountOwner,
    ): void {
        if ($frame->backdrop) {
            $target->scrim($bounds, AnsiStyle::new()->bg($renderContext->theme->overlayBackdrop));
        }

        $rect = $frame->rect->intersect($bounds);

        if ($rect->width === 0 || $rect->height === 0) {
            return;
        }

        $scratch = Buffer::empty($rect->width, $rect->height);

        Painter::paint(
            new PaintContext(
                Rect::sized($rect->width, $rect->height),
                $scratch,
                renderContext: $renderContext,
                mountOwner: $mountOwner,
            ),
            $renderable,
        );

        if ($rect->equals($bounds) && !$frame->backdrop) {
            $target->blitFull($scratch, $rect->x, $rect->y);

            return;
        }

        $target->blitOpaque($scratch, $rect->x, $rect->y);
    }
}
