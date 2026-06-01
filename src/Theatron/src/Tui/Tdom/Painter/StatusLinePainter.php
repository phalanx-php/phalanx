<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Tdom\Painter;

use Phalanx\Theatron\Tui\Styles\Size;
use Phalanx\Theatron\Tui\Styles\SizeResolver;
use Phalanx\Theatron\Tui\Tdom\Element\StatusLineElement;

final class StatusLinePainter
{
    public static function paint(StatusLineElement $element, PaintContext $ctx): void
    {
        if ($element->sections === [] || $ctx->area->width === 0 || $ctx->area->height === 0) {
            return;
        }

        $ansi = Painter::resolveAnsiStyle($element->style);
        $ctx->buffer->fill($ctx->area, $ansi);

        $sizes = [];

        foreach ($element->sections as $section) {
            $style = $section->style;
            $sizes[] = $style !== null ? ($style->size ?? Size::fill()) : Size::fill();
        }

        $rects = SizeResolver::horizontal($ctx->area, $sizes);

        foreach ($element->sections as $i => $section) {
            if (isset($rects[$i]) && $rects[$i]->width > 0) {
                Painter::paint($section, $ctx->sub($rects[$i]));
            }
        }
    }
}
