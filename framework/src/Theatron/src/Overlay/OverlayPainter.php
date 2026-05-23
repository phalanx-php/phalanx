<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Overlay;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Style\Style as AnsiStyle;
use Phalanx\Theatron\Tdom\Painter\PaintContext;
use Phalanx\Theatron\Tdom\Painter\Painter;
use Phalanx\Theatron\Tdom\Renderable;

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
            $renderable,
            new PaintContext(
                Rect::sized($rect->width, $rect->height),
                $scratch,
                renderContext: $renderContext,
                mountOwner: $mountOwner,
            ),
        );

        if ($rect->equals($bounds) && !$frame->backdrop) {
            $target->blitFull($scratch, $rect->x, $rect->y);

            return;
        }

        $target->blitOpaque($scratch, $rect->x, $rect->y);
    }
}
