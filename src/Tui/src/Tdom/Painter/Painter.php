<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tdom\Painter;

use Phalanx\Tui\Core\MountedComponent;
use Phalanx\Tui\Drawing\Rect;
use Phalanx\Tui\Styles\Style as AnsiStyle;
use Phalanx\Tui\Styles\Stylesheet;
use Phalanx\Tui\Tdom\Element;
use Phalanx\Tui\Tdom\Element\ColumnElement;
use Phalanx\Tui\Tdom\Element\DividerElement;
use Phalanx\Tui\Tdom\Element\GridElement;
use Phalanx\Tui\Tdom\Element\InputElement;
use Phalanx\Tui\Tdom\Element\MountElement;
use Phalanx\Tui\Tdom\Element\PanelElement;
use Phalanx\Tui\Tdom\Element\ProgressElement;
use Phalanx\Tui\Tdom\Element\RowElement;
use Phalanx\Tui\Tdom\Element\ScrollElement;
use Phalanx\Tui\Tdom\Element\SpinnerElement;
use Phalanx\Tui\Tdom\Element\StatusLineElement;
use Phalanx\Tui\Tdom\Element\TextElement;
use Phalanx\Tui\Tdom\Renderable;
use Phalanx\Tui\Tdom\Style as TdomStyle;

final class Painter
{
    /** @var \WeakMap<TdomStyle, AnsiStyle>|null */
    private static ?\WeakMap $styleCache = null;

    /** @var \WeakMap<TdomStyle, AnsiStyle>|null */
    private static ?\WeakMap $bgCache = null;

    private static ?AnsiStyle $emptyStyle = null;

    public static function paint(PaintContext $ctx, Renderable $node): void
    {
        $renderCtx = $ctx->renderContext;

        if ($renderCtx === null || $ctx->hasMountFrame() || $ctx->hasPaintBoundary()) {
            self::paintResolved($ctx, $node);

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
            self::paintResolved($ctx, $node);
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

    private static function paintResolved(PaintContext $ctx, Renderable $node): void
    {
        $renderCtx = $ctx->renderContext;

        if ($node instanceof MountElement) {
            if ($renderCtx === null) {
                throw new \RuntimeException('Mount elements require a render context.');
            }

            if (!$ctx->hasMountFrame()) {
                throw new \RuntimeException('Mount elements must be resolved before painting.');
            }

            self::paint($ctx, $renderCtx->mountSystem->resolve($node));

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
                self::paint($childCtx, $inner);
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
            $node instanceof TextElement => TextPainter::paint($paintCtx, $node),
            $node instanceof PanelElement => PanelPainter::paint($paintCtx, $node),
            $node instanceof ColumnElement => ColumnPainter::paint($paintCtx, $node),
            $node instanceof RowElement => RowPainter::paint($paintCtx, $node),
            $node instanceof GridElement => GridPainter::paint($paintCtx, $node),
            $node instanceof ScrollElement => ScrollPainter::paint($paintCtx, $node),
            $node instanceof InputElement => InputPainter::paint($paintCtx, $node),
            $node instanceof StatusLineElement => StatusLinePainter::paint($paintCtx, $node),
            $node instanceof SpinnerElement => SpinnerPainter::paint($paintCtx, $node),
            $node instanceof DividerElement => DividerPainter::paint($paintCtx, $node),
            $node instanceof ProgressElement => ProgressPainter::paint($paintCtx, $node),
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
