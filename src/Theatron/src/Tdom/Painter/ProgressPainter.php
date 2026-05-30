<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Painter;

use Phalanx\Theatron\Style\Style as AnsiStyle;
use Phalanx\Theatron\Tdom\Element\ProgressElement;
use Phalanx\Theatron\Text\Line;

final class ProgressPainter
{
    private static ?AnsiStyle $filledStyle = null;
    private static ?AnsiStyle $emptyStyle = null;

    public static function paint(ProgressElement $element, PaintContext $ctx): void
    {
        if ($ctx->area->width === 0 || $ctx->area->height === 0) {
            return;
        }

        $progress = max(0.0, min(1.0, $element->value));
        $ansi = Painter::resolveAnsiStyle($element->style);
        $filledStyle = self::$filledStyle ??= AnsiStyle::new()->fg('green');
        $emptyStyle = self::$emptyStyle ??= AnsiStyle::new()->dim();

        $pctText = sprintf(' %3d%%', (int) round($progress * 100));
        $label = $element->label;
        $labelLen = match (true) {
            $label instanceof Line => $label->width > 0 ? $label->width + 1 : 0,
            $label !== null && $label !== '' => mb_strlen($label) + 1,
            default => 0,
        };
        $pctLen = 5;
        $barWidth = $ctx->area->width - $pctLen - $labelLen;

        $x = $ctx->area->x;

        if ($barWidth < 3) {
            $ctx->buffer->putString($x, $ctx->area->y, $pctText, $ansi);

            return;
        }

        if ($label instanceof Line && $label->width > 0) {
            $ctx->buffer->putLine($x, $ctx->area->y, $label, $ctx->area->width - $pctLen);
            $x += $label->width;
            $x = $ctx->buffer->putString($x, $ctx->area->y, ' ', $ansi);
        } elseif (is_string($label) && $label !== '') {
            $x = $ctx->buffer->putString($x, $ctx->area->y, $label . ' ', $ansi);
        }

        $filled = (int) round($barWidth * $progress);
        $empty = $barWidth - $filled;

        for ($i = 0; $i < $filled; $i++) {
            $ctx->buffer->set($x + $i, $ctx->area->y, '█', $filledStyle);
        }

        for ($i = 0; $i < $empty; $i++) {
            $ctx->buffer->set($x + $filled + $i, $ctx->area->y, '░', $emptyStyle);
        }

        $ctx->buffer->putString($x + $barWidth, $ctx->area->y, $pctText, $ansi);
    }
}
