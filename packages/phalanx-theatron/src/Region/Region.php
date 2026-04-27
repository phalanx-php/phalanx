<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Region;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Widget\StatefulWidget;
use Phalanx\Theatron\Widget\Widget;

final class Region
{
    private Buffer $buffer;
    private bool $dirty = true;
    private float $lastRenderTime = -1.0;

    public Rect $area {
        get => $this->rect;
    }

    public int $zIndex {
        get => $this->config->zIndex;
    }

    public bool $isDirty {
        get => $this->dirty;
    }

    public string $name {
        get => $this->regionName;
    }

    public function __construct(
        private string $regionName,
        private Rect $rect,
        public private(set) RegionConfig $config = new RegionConfig(),
    ) {
        $this->buffer = Buffer::empty($rect->width, $rect->height);
    }

    public function invalidate(): void
    {
        $this->dirty = true;
    }

    public function resize(Rect $area): void
    {
        $this->rect = $area;
        $this->buffer = Buffer::empty($area->width, $area->height);
        $this->dirty = true;
    }

    public function draw(Widget $widget): void
    {
        $localArea = Rect::sized($this->rect->width, $this->rect->height);
        $this->buffer->clear();
        $widget->render($localArea, $this->buffer);
        $this->dirty = true;
    }

    public function drawStateful(StatefulWidget $widget, object $state): void
    {
        $localArea = Rect::sized($this->rect->width, $this->rect->height);
        $this->buffer->clear();
        $widget->render($localArea, $this->buffer, $state);
        $this->dirty = true;
    }

    public function buffer(): Buffer
    {
        return $this->buffer;
    }

    public function clean(): void
    {
        $this->dirty = false;
    }

    public function isDueForTick(float $now): bool
    {
        if (!$this->dirty) {
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
