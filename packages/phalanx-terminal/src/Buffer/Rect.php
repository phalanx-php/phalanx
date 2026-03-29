<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Buffer;

final class Rect
{
    public function __construct(
        public private(set) int $x,
        public private(set) int $y,
        public private(set) int $width,
        public private(set) int $height,
    ) {}

    public static function of(int $x, int $y, int $width, int $height): self
    {
        return new self($x, $y, $width, $height);
    }

    public static function sized(int $width, int $height): self
    {
        return new self(0, 0, $width, $height);
    }

    public int $right {
        get => $this->x + $this->width;
    }

    public int $bottom {
        get => $this->y + $this->height;
    }

    public int $area {
        get => $this->width * $this->height;
    }

    public function contains(int $x, int $y): bool
    {
        return $x >= $this->x
            && $x < $this->right
            && $y >= $this->y
            && $y < $this->bottom;
    }

    public function intersect(self $other): self
    {
        $x = max($this->x, $other->x);
        $y = max($this->y, $other->y);
        $right = min($this->right, $other->right);
        $bottom = min($this->bottom, $other->bottom);

        return new self($x, $y, max(0, $right - $x), max(0, $bottom - $y));
    }

    public function equals(self $other): bool
    {
        return $this->x === $other->x
            && $this->y === $other->y
            && $this->width === $other->width
            && $this->height === $other->height;
    }
}
