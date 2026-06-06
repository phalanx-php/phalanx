<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tdom\Painter;

use Phalanx\Tui\Styles\Line;
use Phalanx\Tui\Styles\Style as AnsiStyle;
use Phalanx\Tui\Tdom\Element\InputElement;

final class InputPainter
{
    private static ?AnsiStyle $cursorStyle = null;

    public static function paint(PaintContext $ctx, InputElement $element): void
    {
        if ($ctx->area->width === 0 || $ctx->area->height === 0) {
            return;
        }

        $ansi = Painter::resolveAnsiStyle($element->style);
        $prompt = $element->prompt;
        $maxLen = $ctx->area->width;

        if ($prompt instanceof Line) {
            $promptWidth = $prompt->width;
            $ctx->buffer->putLine($ctx->area->x, $ctx->area->y, $prompt, $maxLen);
            $valueX = $ctx->area->x + min($promptWidth, $maxLen);
            $valueMax = $maxLen - min($promptWidth, $maxLen);

            if ($valueMax > 0) {
                $value = mb_strlen($element->value) > $valueMax
                    ? mb_substr($element->value, 0, $valueMax)
                    : $element->value;
                $ctx->buffer->putString($valueX, $ctx->area->y, $value, $ansi);
            }
        } else {
            $promptWidth = mb_strlen($prompt);
            $text = $prompt . $element->value;

            if (mb_strlen($text) > $maxLen) {
                $text = mb_substr($text, 0, $maxLen);
            }

            $ctx->buffer->putString($ctx->area->x, $ctx->area->y, $text, $ansi);
        }

        self::paintSelection($ctx, $element, $promptWidth, $ansi);

        $cursorX = $ctx->area->x + $promptWidth + $element->cursor;
        $totalWidth = $promptWidth + mb_strlen($element->value);

        if ($cursorX < $ctx->area->right && $cursorX >= $ctx->area->x) {
            $cursorStyle = self::$cursorStyle ??= AnsiStyle::new()->reverse();
            $offset = $cursorX - $ctx->area->x;
            $cursorChar = $offset < $totalWidth
                ? ($offset >= $promptWidth
                    ? mb_substr($element->value, $offset - $promptWidth, 1)
                    : ' ')
                : ' ';
            $ctx->buffer->set($cursorX, $ctx->area->y, $cursorChar, $cursorStyle);
        }
    }

    private static function paintSelection(
        PaintContext $ctx,
        InputElement $element,
        int $promptWidth,
        AnsiStyle $ansi,
    ): void {
        if ($element->selectionStart === null || $element->selectionEnd === null) {
            return;
        }

        $length = mb_strlen($element->value);
        $start = max(0, min($element->selectionStart, $element->selectionEnd, $length));
        $end = min($length, max($element->selectionStart, $element->selectionEnd));

        if ($start === $end) {
            return;
        }

        $selectionStyle = $ansi->reverse();

        for ($i = $start; $i < $end; $i++) {
            $x = $ctx->area->x + $promptWidth + $i;

            if ($x < $ctx->area->x || $x >= $ctx->area->right) {
                continue;
            }

            $ctx->buffer->set($x, $ctx->area->y, mb_substr($element->value, $i, 1), $selectionStyle);
        }
    }
}
