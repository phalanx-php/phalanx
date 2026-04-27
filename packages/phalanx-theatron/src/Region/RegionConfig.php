<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Region;

final class RegionConfig
{
    public function __construct(
        public private(set) float $tickRate = 30.0,
        public private(set) int $zIndex = 0,
        public private(set) bool $scrollable = false,
    ) {}

    public function withTickRate(float $fps): self
    {
        return new self($fps, $this->zIndex, $this->scrollable);
    }

    public function withZIndex(int $z): self
    {
        return new self($this->tickRate, $z, $this->scrollable);
    }
}
