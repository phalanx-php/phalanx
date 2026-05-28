<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Region;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Tdom\Painter\PaintContext;
use Phalanx\Theatron\Tdom\Painter\Painter;
use Phalanx\Theatron\Tdom\Renderable;

final class Region
{
    private(set) Rect $area;
    private(set) bool $isDirty = true;
    private(set) string $name;

    public int $zIndex {
        get => $this->config->zIndex;
    }

    private Buffer $buffer;
    private PaintContext $paintContext;
    private float $lastRenderTime = -1.0;
    private int $lastContentId = -1;
    /** @var \WeakReference<Renderable>|null */
    private ?\WeakReference $lastContent = null;

    public function __construct(
        string $name,
        Rect $area,
        private(set) RegionConfig $config = new RegionConfig(),
    ) {
        $this->name = $name;
        $this->area = $area;
        $this->buffer = Buffer::empty($area->width, $area->height);
        $this->paintContext = new PaintContext(
            Rect::sized($area->width, $area->height),
            $this->buffer,
        );
    }

    public function invalidate(): void
    {
        $this->lastContentId = -1;
        $this->lastContent = null;
        $this->isDirty = true;
    }

    public function resize(Rect $area): void
    {
        $this->area = $area;
        $this->buffer = Buffer::empty($area->width, $area->height);
        $this->paintContext = new PaintContext(
            Rect::sized($area->width, $area->height),
            $this->buffer,
        );
        $this->lastContentId = -1;
        $this->lastContent = null;
        $this->isDirty = true;
    }

    public function draw(Renderable $renderable): void
    {
        $contentId = spl_object_id($renderable);
        if ($contentId === $this->lastContentId && $this->lastContent?->get() === $renderable) {
            return;
        }
        $this->lastContentId = $contentId;
        $this->lastContent = \WeakReference::create($renderable);

        $this->buffer->clear();
        Painter::paint($renderable, $this->paintContext);
        $this->isDirty = true;
    }

    public function buffer(): Buffer
    {
        return $this->buffer;
    }

    public function clean(): void
    {
        $this->isDirty = false;
    }

    public function isDueForTick(float $now): bool
    {
        if (!$this->isDirty) {
            return false;
        }

        $interval = 1.0 / $this->config->tickRate;

        if ($now - $this->lastRenderTime < $interval) {
            return false;
        }

        $this->lastRenderTime = $now;

        return true;
    }
}
