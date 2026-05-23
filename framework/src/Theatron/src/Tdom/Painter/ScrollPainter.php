<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Painter;

use Phalanx\Theatron\Tdom\Element\ScrollElement;

final class ScrollPainter
{
    public static function paint(ScrollElement $element, PaintContext $ctx): void
    {
        if ($ctx->area->width === 0 || $ctx->area->height === 0) {
            return;
        }

        $ansi = Painter::resolveAnsiStyle($element->style);
        $lines = explode("\n", $element->content);
        $maxLines = $element->maxLines ?? $ctx->area->height;
        $visibleLines = min(count($lines), $ctx->area->height, $maxLines);

        $startLine = max(0, count($lines) - $visibleLines);

        for ($i = 0; $i < $visibleLines; $i++) {
            $line = $lines[$startLine + $i];

            if (mb_strlen($line) > $ctx->area->width) {
                $line = mb_substr($line, 0, $ctx->area->width);
            }

            $ctx->buffer->putString($ctx->area->x, $ctx->area->y + $i, $line, $ansi);
        }
    }
}
