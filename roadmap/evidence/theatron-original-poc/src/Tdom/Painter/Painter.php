<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Painter;

use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Style\Style as AnsiStyle;
use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\DividerElement;
use Phalanx\Theatron\Tdom\Element\GridElement;
use Phalanx\Theatron\Tdom\Element\InputElement;
use Phalanx\Theatron\Tdom\Element\PanelElement;
use Phalanx\Theatron\Tdom\Element\ProgressElement;
use Phalanx\Theatron\Tdom\Element\RowElement;
use Phalanx\Theatron\Tdom\Element\ScrollElement;
use Phalanx\Theatron\Tdom\Element\SpinnerElement;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style as TdomStyle;

final class Painter
{
    /** @var \WeakMap<TdomStyle, AnsiStyle>|null */
    private static ?\WeakMap $styleCache = null;

    /** @var \WeakMap<TdomStyle, AnsiStyle>|null */
    private static ?\WeakMap $bgCache = null;

    private static ?AnsiStyle $emptyStyle = null;

    public static function paint(Renderable $node, PaintContext $ctx): void
    {
        if (!$node instanceof Element) {
            return;
        }

        $bg = self::resolveBackground($node->style);

        if ($bg !== null) {
            $ctx->buffer->fill($ctx->area, $bg);
        }

        $padding = $node->style?->padding;
        $paintCtx = $ctx;

        if ($padding !== null && !$node instanceof PanelElement) {
            $padded = Rect::of(
                $ctx->area->x + $padding->left,
                $ctx->area->y + $padding->top,
                max(0, $ctx->area->width - $padding->horizontal),
                max(0, $ctx->area->height - $padding->vertical),
            );

            if ($padded->width === 0 || $padded->height === 0) {
                return;
            }

            $paintCtx = $ctx->sub($padded);
        }

        match (true) {
            $node instanceof TextElement => TextPainter::paint($node, $paintCtx),
            $node instanceof PanelElement => PanelPainter::paint($node, $paintCtx),
            $node instanceof ColumnElement => ColumnPainter::paint($node, $paintCtx),
            $node instanceof RowElement => RowPainter::paint($node, $paintCtx),
            $node instanceof GridElement => GridPainter::paint($node, $paintCtx),
            $node instanceof ScrollElement => ScrollPainter::paint($node, $paintCtx),
            $node instanceof InputElement => InputPainter::paint($node, $paintCtx),
            $node instanceof StatusLineElement => StatusLinePainter::paint($node, $paintCtx),
            $node instanceof SpinnerElement => SpinnerPainter::paint($node, $paintCtx),
            $node instanceof DividerElement => DividerPainter::paint($node, $paintCtx),
            $node instanceof ProgressElement => ProgressPainter::paint($node, $paintCtx),
            default => null,
        };
    }

    public static function resolveAnsiStyle(?TdomStyle $style): AnsiStyle
    {
        if ($style === null) {
            return self::$emptyStyle ??= AnsiStyle::new();
        }

        $cache = self::$styleCache ??= new \WeakMap();

        return $cache[$style] ??= AnsiStyle::of($style->color, $style->background);
    }

    private static function resolveBackground(?TdomStyle $style): ?AnsiStyle
    {
        if ($style?->background === null) {
            return null;
        }

        $cache = self::$bgCache ??= new \WeakMap();

        return $cache[$style] ??= AnsiStyle::of(bg: $style->background);
    }
}
