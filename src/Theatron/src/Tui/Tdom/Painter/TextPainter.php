<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Tdom\Painter;

use Phalanx\Theatron\Tui\Styles\Line;
use Phalanx\Theatron\Tui\Tdom\Element\TextElement;

final class TextPainter
{
    public static function paint(TextElement $element, PaintContext $ctx): void
    {
        if ($ctx->area->width === 0 || $ctx->area->height === 0) {
            return;
        }

        $ansi = Painter::resolveAnsiStyle($element->style);

        if ($element->content instanceof Line) {
            $ctx->buffer->putLine($ctx->area->x, $ctx->area->y, $element->content, $ctx->area->width);

            return;
        }

        $ctx->buffer->putString($ctx->area->x, $ctx->area->y, $element->content, $ansi);
    }
}
