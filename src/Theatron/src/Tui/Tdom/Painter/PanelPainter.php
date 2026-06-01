<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Tdom\Painter;

use Phalanx\Theatron\Tui\Drawing\Rect;
use Phalanx\Theatron\Tui\Styles\Border;
use Phalanx\Theatron\Tui\Styles\Line;
use Phalanx\Theatron\Tui\Tdom\Element\PanelElement;

final class PanelPainter
{
    public static function paint(PanelElement $element, PaintContext $ctx): void
    {
        $area = $ctx->area;

        if ($area->width < 2 || $area->height < 2) {
            return;
        }

        $border = $element->style->border ?? Border::Single;
        [$tl, $tr, $bl, $br, $h, $v] = $border->chars();
        $ansi = Painter::resolveAnsiStyle($element->style);

        $ctx->buffer->set($area->x, $area->y, $tl, $ansi);
        $ctx->buffer->set($area->right - 1, $area->y, $tr, $ansi);
        $ctx->buffer->set($area->x, $area->bottom - 1, $bl, $ansi);
        $ctx->buffer->set($area->right - 1, $area->bottom - 1, $br, $ansi);

        for ($x = $area->x + 1; $x < $area->right - 1; $x++) {
            $ctx->buffer->set($x, $area->y, $h, $ansi);
            $ctx->buffer->set($x, $area->bottom - 1, $h, $ansi);
        }

        for ($y = $area->y + 1; $y < $area->bottom - 1; $y++) {
            $ctx->buffer->set($area->x, $y, $v, $ansi);
            $ctx->buffer->set($area->right - 1, $y, $v, $ansi);
        }

        $title = $element->title;

        if ($title instanceof Line) {
            if ($title->width > 0 && $area->width > 4) {
                $maxTitleLen = $area->width - 4;
                $ctx->buffer->putString($area->x + 1, $area->y, ' ', $ansi);
                $ctx->buffer->putLine($area->x + 2, $area->y, $title, $maxTitleLen);
                $afterTitle = min($area->x + 2 + $title->width, $area->x + 2 + $maxTitleLen);
                $ctx->buffer->putString($afterTitle, $area->y, ' ', $ansi);
            }
        } elseif ($title !== '' && $area->width > 4) {
            $maxTitleLen = $area->width - 4;
            $titleText = mb_strlen($title) > $maxTitleLen
                ? mb_substr($title, 0, $maxTitleLen - 1) . '~'
                : $title;

            $ctx->buffer->putString($area->x + 1, $area->y, " {$titleText} ", $ansi);
        }

        $innerArea = Rect::of(
            $area->x + 1,
            $area->y + 1,
            $area->width - 2,
            $area->height - 2,
        );

        $padding = $element->style?->padding;

        if ($padding !== null) {
            $innerArea = Rect::of(
                $innerArea->x + $padding->left,
                $innerArea->y + $padding->top,
                max(0, $innerArea->width - $padding->horizontal),
                max(0, $innerArea->height - $padding->vertical),
            );
        }

        if ($innerArea->width > 0 && $innerArea->height > 0) {
            Painter::paint($element->child, $ctx->sub($innerArea));
        }
    }
}
