<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Painter;

use Phalanx\Theatron\Style\Style as AnsiStyle;
use Phalanx\Theatron\Tdom\Element\ProgressElement;

class ProgressPainter
{
    public static function paint(ProgressElement $element, PaintContext $ctx): void
    {
        if ($ctx->area->width === 0 || $ctx->area->height === 0) {
            return;
        }

        $progress = max(0.0, min(1.0, $element->value));
        $ansi = Painter::resolveAnsiStyle($element->style);
        $filledStyle = AnsiStyle::new()->fg('green');
        $emptyStyle = AnsiStyle::new()->dim();

        $pctText = sprintf(' %3d%%', (int) round($progress * 100));
        $labelLen = ($element->label !== null && $element->label !== '')
            ? mb_strlen($element->label) + 1
            : 0;
        $pctLen = 5;
        $barWidth = $ctx->area->width - $pctLen - $labelLen;

        $x = $ctx->area->x;

        if ($barWidth < 3) {
            $ctx->buffer->putString($x, $ctx->area->y, $pctText, $ansi);
            return;
        }

        if ($element->label !== null && $element->label !== '') {
            $x = $ctx->buffer->putString($x, $ctx->area->y, $element->label . ' ', $ansi);
        }

        $filled = (int) round($barWidth * $progress);
        $empty = $barWidth - $filled;

        for ($i = 0; $i < $filled; $i++) {
            $ctx->buffer->set($x + $i, $ctx->area->y, '█', $filledStyle);
        }

        for ($i = 0; $i < $empty; $i++) {
            $ctx->buffer->set($x + $filled + $i, $ctx->area->y, '░', $emptyStyle);
        }

        $ctx->buffer->putString($x + $barWidth, $ctx->area->y, $pctText, $ansi);
    }
}
