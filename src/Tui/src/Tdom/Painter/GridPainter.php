<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tdom\Painter;

use Phalanx\Tui\Drawing\Rect;
use Phalanx\Tui\Styles\Size;
use Phalanx\Tui\Styles\SizeResolver;
use Phalanx\Tui\Tdom\Element\GridElement;

final class GridPainter
{
    public static function paint(GridElement $element, PaintContext $ctx): void
    {
        $colCount = count($element->columns);

        if ($colCount === 0 || $element->children === []) {
            return;
        }

        if ($ctx->area->width === 0 || $ctx->area->height === 0) {
            return;
        }

        $colRects = SizeResolver::horizontal($ctx->area, $element->columns);
        $rowCount = (int) ceil(count($element->children) / $colCount);
        $rowSizes = array_fill(0, $rowCount, Size::fill());
        $rowRects = SizeResolver::vertical($ctx->area, $rowSizes);

        foreach ($element->children as $i => $child) {
            $col = $i % $colCount;
            $row = intdiv($i, $colCount);

            if (!isset($colRects[$col]) || !isset($rowRects[$row])) {
                continue;
            }

            $cellRect = Rect::of(
                $colRects[$col]->x,
                $rowRects[$row]->y,
                $colRects[$col]->width,
                $rowRects[$row]->height,
            );

            if ($cellRect->width > 0 && $cellRect->height > 0) {
                Painter::paint($child, $ctx->sub($cellRect));
            }
        }
    }
}
