<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Tdom\Painter;

use Phalanx\Tui\Tui\Tdom\Element\DividerElement;

final class DividerPainter
{
    public static function paint(DividerElement $element, PaintContext $ctx): void
    {
        if ($ctx->area->width === 0 || $ctx->area->height === 0) {
            return;
        }

        $ansi = Painter::resolveAnsiStyle($element->style);

        for ($x = $ctx->area->x; $x < $ctx->area->right; $x++) {
            $ctx->buffer->set($x, $ctx->area->y, '─', $ansi);
        }
    }
}
