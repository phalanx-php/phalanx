<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Painter;

use Phalanx\Theatron\Layout\Size;
use Phalanx\Theatron\Layout\SizeResolver;
use Phalanx\Theatron\Tdom\Element\RowElement;

final class RowPainter
{
    public static function paint(RowElement $element, PaintContext $ctx): void
    {
        if ($element->children === [] || $ctx->area->width === 0) {
            return;
        }

        $sizes = [];

        foreach ($element->children as $child) {
            $style = $child->style;
            $sizes[] = $style !== null ? ($style->size ?? Size::fill()) : Size::fill();
        }

        $rects = SizeResolver::horizontal($ctx->area, $sizes);

        foreach ($element->children as $i => $child) {
            if (isset($rects[$i]) && $rects[$i]->width > 0) {
                Painter::paint($child, $ctx->sub($rects[$i]));
            }
        }
    }
}
