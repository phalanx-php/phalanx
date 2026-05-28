<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Painter;

use Phalanx\Theatron\Tdom\Element\SpinnerElement;

class SpinnerPainter
{
    private const array DOTS = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    public static function paint(SpinnerElement $element, PaintContext $ctx): void
    {
        if ($ctx->area->width === 0 || $ctx->area->height === 0) {
            return;
        }

        $ansi = Painter::resolveAnsiStyle($element->style);
        $idx = $element->frame % count(self::DOTS);
        $ctx->buffer->putString($ctx->area->x, $ctx->area->y, self::DOTS[$idx], $ansi);

        if ($element->label !== null && $element->label !== '' && $ctx->area->width > 2) {
            $ctx->buffer->putString($ctx->area->x + 2, $ctx->area->y, $element->label, $ansi);
        }
    }
}
