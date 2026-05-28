<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Painter;

use Phalanx\Theatron\Style\Style as AnsiStyle;
use Phalanx\Theatron\Tdom\Element\InputElement;

class InputPainter
{
    public static function paint(InputElement $element, PaintContext $ctx): void
    {
        if ($ctx->area->width === 0 || $ctx->area->height === 0) {
            return;
        }

        $ansi = Painter::resolveAnsiStyle($element->style);
        $text = $element->prompt . $element->value;
        $maxLen = $ctx->area->width;

        if (mb_strlen($text) > $maxLen) {
            $text = mb_substr($text, 0, $maxLen);
        }

        $ctx->buffer->putString($ctx->area->x, $ctx->area->y, $text, $ansi);

        $cursorX = $ctx->area->x + mb_strlen($element->prompt) + $element->cursor;

        if ($cursorX < $ctx->area->right && $cursorX >= $ctx->area->x) {
            $cursorStyle = AnsiStyle::new()->reverse();
            $cursorChar = $cursorX < $ctx->area->x + mb_strlen($text)
                ? mb_substr($text, $cursorX - $ctx->area->x, 1)
                : ' ';
            $ctx->buffer->set($cursorX, $ctx->area->y, $cursorChar, $cursorStyle);
        }
    }
}
