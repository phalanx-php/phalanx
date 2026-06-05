<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tdom;

use Phalanx\Tui\Styles\Align;
use Phalanx\Tui\Styles\Border;
use Phalanx\Tui\Styles\Color;
use Phalanx\Tui\Styles\Padding;
use Phalanx\Tui\Styles\Size;

trait HasFluentStyle
{
    public function size(Size|int $size): static
    {
        $clone = clone $this;
        $clone->style = ($clone->style ?? Style::empty())->withSize(
            $size instanceof Size ? $size : Size::fixed($size),
        );

        return $clone;
    }

    public function border(Border $border): static
    {
        $clone = clone $this;
        $clone->style = ($clone->style ?? Style::empty())->withBorder($border);

        return $clone;
    }

    public function padding(Padding|int $padding): static
    {
        $clone = clone $this;
        $clone->style = ($clone->style ?? Style::empty())->withPadding(
            $padding instanceof Padding ? $padding : Padding::all($padding),
        );

        return $clone;
    }

    public function align(Align $align): static
    {
        $clone = clone $this;
        $clone->style = ($clone->style ?? Style::empty())->withAlign($align);

        return $clone;
    }

    public function background(Color|string $color): static
    {
        $clone = clone $this;
        $clone->style = ($clone->style ?? Style::empty())->withBackground(
            $color instanceof Color ? $color : self::resolveColor($color),
        );

        return $clone;
    }

    public function color(Color|string $color): static
    {
        $clone = clone $this;
        $clone->style = ($clone->style ?? Style::empty())->withColor(
            $color instanceof Color ? $color : self::resolveColor($color),
        );

        return $clone;
    }

    public function styled(?Style $style): static
    {
        $clone = clone $this;
        $clone->style = $style;

        return $clone;
    }

    private static function resolveColor(string $color): Color
    {
        return str_starts_with($color, '#') ? Color::hex($color) : Color::named($color);
    }
}
