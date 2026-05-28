<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Painter;

use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Element\PanelElement;

class PanelPainter
{
    public static function paint(PanelElement $element, PaintContext $ctx): void
    {
        $area = $ctx->area;

        if ($area->width < 2 || $area->height < 2) {
            return;
        }

        $border = $element->style?->border ?? Border::Single;
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

        if ($element->title !== '' && $area->width > 4) {
            $maxTitleLen = $area->width - 4;
            $titleText = mb_strlen($element->title) > $maxTitleLen
                ? mb_substr($element->title, 0, $maxTitleLen - 1) . '~'
                : $element->title;

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
