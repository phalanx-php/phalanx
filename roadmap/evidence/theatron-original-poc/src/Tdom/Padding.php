<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom;

final class Padding
{
    public int $vertical { get => $this->top + $this->bottom; }

    public int $horizontal { get => $this->left + $this->right; }

    private function __construct(
        private(set) int $top,
        private(set) int $right,
        private(set) int $bottom,
        private(set) int $left,
    ) {
    }

    public static function all(int $n): self
    {
        return new self($n, $n, $n, $n);
    }

    public static function horizontal(int $n): self
    {
        return new self(0, $n, 0, $n);
    }

    public static function vertical(int $n): self
    {
        return new self($n, 0, $n, 0);
    }

    public static function of(int $top = 0, int $right = 0, int $bottom = 0, int $left = 0): self
    {
        return new self($top, $right, $bottom, $left);
    }
}
