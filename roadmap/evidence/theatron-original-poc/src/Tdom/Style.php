<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom;

use Phalanx\Theatron\Style\Color;

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
}
