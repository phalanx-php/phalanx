<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Kit;

final class FrameLoop
{
    private(set) int $frames = 0;
    private(set) bool $needsDraw = true;
    private float $startTime;

    public function __construct()
    {
        $this->startTime = hrtime(true) / 1_000_000_000;
    }

    public function tick(): void
    {
        $this->frames++;
    }

    public function invalidate(): void
    {
        $this->needsDraw = true;
    }

    public function consume(): bool
    {
        if (!$this->needsDraw) {
            return false;
        }

        $this->needsDraw = false;

        return true;
    }

    public function shouldDraw(bool $componentDirty): bool
    {
        if ($this->needsDraw || $componentDirty) {
            $this->needsDraw = false;

            return true;
        }

        return false;
    }

    public function elapsedSeconds(): float
    {
        return (hrtime(true) / 1_000_000_000) - $this->startTime;
    }

    public function fps(): float
    {
        return Metrics::fps($this->frames, $this->elapsedSeconds());
    }
}
