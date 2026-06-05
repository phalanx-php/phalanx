<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Tdom\Painter;

use Phalanx\Tui\Tui\Styles\Size;
use Phalanx\Tui\Tui\Styles\SizeResolver;
use Phalanx\Tui\Tui\Tdom\Element\RowElement;

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
