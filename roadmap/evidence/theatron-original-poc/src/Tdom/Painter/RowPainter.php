<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Painter;

use Phalanx\Theatron\Tdom\Element\RowElement;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\SizeResolver;

class RowPainter
{
    public static function paint(RowElement $element, PaintContext $ctx): void
    {
        if ($element->children === [] || $ctx->area->width === 0) {
            return;
        }

        $sizes = [];

        foreach ($element->children as $child) {
            $sizes[] = $child->style?->size ?? Size::fill();
        }

        $rects = SizeResolver::horizontal($ctx->area, $sizes);

        foreach ($element->children as $i => $child) {
            if (isset($rects[$i]) && $rects[$i]->width > 0) {
                Painter::paint($child, $ctx->sub($rects[$i]));
            }
        }
    }
}
