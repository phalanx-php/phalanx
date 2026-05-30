<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom\Painter;

use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Style\Style as AnsiStyle;
use Phalanx\Theatron\Styling\Stylesheet;
use Phalanx\Theatron\Tdom\Element;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\DividerElement;
use Phalanx\Theatron\Tdom\Element\GridElement;
use Phalanx\Theatron\Tdom\Element\InputElement;
use Phalanx\Theatron\Tdom\Element\MountElement;
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
        $renderCtx = $ctx->renderContext;

        if ($renderCtx === null || $ctx->hasMountFrame() || $ctx->hasPaintBoundary()) {
            self::paintResolved($node, $ctx);

            return;
        }

        $renderCtx->mountSystem->enterFrame($ctx->mountOwner());
        $commitMountFrame = false;
        $ctx->enterMountFrame();

        try {
            $node = $renderCtx->mountSystem->resolve($node);
            $commitMountFrame = true;
        } finally {
            $ctx->leaveMountFrame();
            $renderCtx->mountSystem->leaveFrame($ctx->mountOwner(), $commitMountFrame);
        }

        $ctx->enterPaintBoundary();

        try {
            self::paintResolved($node, $ctx);
        } finally {
            $ctx->leavePaintBoundary();
        }
    }

    public static function resolveAnsiStyle(?TdomStyle $style): AnsiStyle
    {
        if ($style === null) {
            return self::$emptyStyle ??= AnsiStyle::new();
        }

        $cache = self::$styleCache ??= new \WeakMap();

        return $cache[$style] ??= AnsiStyle::of($style->color, $style->background);
    }

    public static function reset(): void
    {
        self::$styleCache = null;
        self::$bgCache = null;
        self::$emptyStyle = null;
    }

    private static function paintResolved(Renderable $node, PaintContext $ctx): void
    {
        $renderCtx = $ctx->renderContext;

        if ($node instanceof MountElement) {
            if ($renderCtx === null) {
                throw new \RuntimeException('Mount elements require a render context.');
            }

            if (!$ctx->hasMountFrame()) {
                throw new \RuntimeException('Mount elements must be resolved before painting.');
            }

            self::paint($renderCtx->mountSystem->resolve($node), $ctx);

            return;
        }

        if ($node instanceof MountedComponent) {
            if ($node->isDirty) {
                if ($ctx->renderContext !== null) {
                    $node->render($ctx->renderContext);
                } else {
                    $node->rerender();
                }
            }
            $inner = $node->lastResult();
            if ($inner !== null) {
                $sheet = $node->stylesheet();
                $childCtx = $sheet !== null
                    ? $ctx->withStylesheet($sheet)
                    : $ctx;
                self::paint($inner, $childCtx);
            }

            return;
        }

        if (!$node instanceof Element) {
            return;
        }

        $effective = self::resolveEffectiveStyle($node, $ctx->stylesheet);
        $bg = self::resolveBackground($effective);

        if ($bg !== null) {
            $ctx->buffer->fill($ctx->area, $bg);
        }

        $padding = $effective?->padding;
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

    private static function resolveEffectiveStyle(Element $node, ?Stylesheet $stylesheet): ?TdomStyle
    {
        $sheetStyle = $stylesheet?->match($node->type);

        if ($sheetStyle === null) {
            return $node->style;
        }

        if ($node->style === null) {
            return $sheetStyle;
        }

        return $sheetStyle->patch($node->style);
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
