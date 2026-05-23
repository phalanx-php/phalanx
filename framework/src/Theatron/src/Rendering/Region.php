<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Rendering;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;

final class Region
{
    private(set) bool $isDirty = true;

    public int $zIndex {
        get => $this->config->zIndex;
    }

    private Buffer $buffer;
    private float $lastRenderTime = -1.0;

    public function __construct(
        private(set) string $name,
        private(set) Rect $area,
        private(set) RegionConfig $config = new RegionConfig(),
    ) {
        $this->buffer = Buffer::empty($this->area->width, $this->area->height);
    }

    public function buffer(): Buffer
    {
        return $this->buffer;
    }

    public function markDirty(): void
    {
        $this->isDirty = true;
    }

    public function resize(Rect $area): void
    {
        $this->area = $area;
        $this->buffer = Buffer::empty($area->width, $area->height);
        $this->isDirty = true;
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
