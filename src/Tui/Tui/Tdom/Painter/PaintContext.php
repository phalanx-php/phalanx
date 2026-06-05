<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Tdom\Painter;

use Phalanx\Tui\Tui\Core\RenderContext;
use Phalanx\Tui\Tui\Drawing\Buffer;
use Phalanx\Tui\Tui\Drawing\Rect;
use Phalanx\Tui\Tui\Styles\Stylesheet;

final class PaintContext
{
    private object $mountOwner;
    private bool $mountFrameActive = false;
    private bool $paintBoundaryActive = false;

    public function __construct(
        private(set) Rect $area,
        private(set) Buffer $buffer,
        private(set) ?Stylesheet $stylesheet = null,
        private(set) ?RenderContext $renderContext = null,
        ?object $mountOwner = null,
    ) {
        $this->mountOwner = $mountOwner ?? new \stdClass();
    }

    public function sub(Rect $area): self
    {
        $sub = new self($area, $this->buffer, $this->stylesheet, $this->renderContext, $this->mountOwner);
        $sub->mountFrameActive = $this->mountFrameActive;
        $sub->paintBoundaryActive = $this->paintBoundaryActive;

        return $sub;
    }

    public function withStylesheet(?Stylesheet $stylesheet): self
    {
        $clone = new self($this->area, $this->buffer, $stylesheet, $this->renderContext, $this->mountOwner);
        $clone->mountFrameActive = $this->mountFrameActive;
        $clone->paintBoundaryActive = $this->paintBoundaryActive;

        return $clone;
    }

    public function mountOwner(): object
    {
        return $this->mountOwner;
    }

    public function hasMountFrame(): bool
    {
        return $this->mountFrameActive;
    }

    public function hasPaintBoundary(): bool
    {
        return $this->paintBoundaryActive;
    }

    public function enterMountFrame(): void
    {
        $this->mountFrameActive = true;
    }

    public function leaveMountFrame(): void
    {
        $this->mountFrameActive = false;
    }

    public function enterPaintBoundary(): void
    {
        $this->paintBoundaryActive = true;
    }

    public function leavePaintBoundary(): void
    {
        $this->paintBoundaryActive = false;
    }
}
