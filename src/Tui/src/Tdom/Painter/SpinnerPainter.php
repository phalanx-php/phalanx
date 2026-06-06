<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tdom\Painter;

use Phalanx\Tui\Styles\Line;
use Phalanx\Tui\Tdom\Element\SpinnerElement;

final class SpinnerPainter
{
    private const array DOTS = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    public static function paint(PaintContext $ctx, SpinnerElement $element): void
    {
        if ($ctx->area->width === 0 || $ctx->area->height === 0) {
            return;
        }

        $ansi = Painter::resolveAnsiStyle($element->style);
        $idx = $element->frame % count(self::DOTS);
        $ctx->buffer->putString($ctx->area->x, $ctx->area->y, self::DOTS[$idx], $ansi);

        $label = $element->label;

        if ($label instanceof Line) {
            if ($label->width > 0 && $ctx->area->width > 2) {
                $ctx->buffer->putLine($ctx->area->x + 2, $ctx->area->y, $label, $ctx->area->width - 2);
            }
        } elseif ($label !== null && $label !== '' && $ctx->area->width > 2) {
            $ctx->buffer->putString($ctx->area->x + 2, $ctx->area->y, $label, $ansi);
        }
    }
}
