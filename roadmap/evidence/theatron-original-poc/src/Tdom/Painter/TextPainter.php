<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Painter;

use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Text\Line;

class TextPainter
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
