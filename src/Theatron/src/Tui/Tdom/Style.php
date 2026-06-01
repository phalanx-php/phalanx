<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Tdom;

use Phalanx\Theatron\Tui\Styles\Align;
use Phalanx\Theatron\Tui\Styles\Border;
use Phalanx\Theatron\Tui\Styles\Color;
use Phalanx\Theatron\Tui\Styles\Padding;
use Phalanx\Theatron\Tui\Styles\Size;

final class Style
{
    private function __construct(
        private(set) ?Size $size = null,
        private(set) ?Align $align = null,
        private(set) ?Border $border = null,
        private(set) ?Padding $padding = null,
        private(set) ?Color $color = null,
        private(set) ?Color $background = null,
    ) {
    }

    public static function of(
        ?Size $size = null,
        ?Align $align = null,
        ?Border $border = null,
        ?Padding $padding = null,
        ?Color $color = null,
        ?Color $background = null,
    ): self {
        return new self($size, $align, $border, $padding, $color, $background);
    }

    public static function empty(): self
    {
        return new self();
    }

    public function patch(self $other): self
    {
        return new self(
            $other->size ?? $this->size,
            $other->align ?? $this->align,
            $other->border ?? $this->border,
            $other->padding ?? $this->padding,
            $other->color ?? $this->color,
            $other->background ?? $this->background,
        );
    }

    public function withSize(Size $size): self
    {
        return new self($size, $this->align, $this->border, $this->padding, $this->color, $this->background);
    }

    public function withAlign(Align $align): self
    {
        return new self($this->size, $align, $this->border, $this->padding, $this->color, $this->background);
    }

    public function withBorder(Border $border): self
    {
        return new self($this->size, $this->align, $border, $this->padding, $this->color, $this->background);
    }

    public function withPadding(Padding $padding): self
    {
        return new self($this->size, $this->align, $this->border, $padding, $this->color, $this->background);
    }

    public function withColor(Color $color): self
    {
        return new self($this->size, $this->align, $this->border, $this->padding, $color, $this->background);
    }

    public function withBackground(Color $background): self
    {
        return new self($this->size, $this->align, $this->border, $this->padding, $this->color, $background);
    }
}
