<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Component;

final class TabbedLayoutConfig
{
    public function __construct(
        private(set) LayoutDirection $direction = LayoutDirection::Horizontal,
        private(set) bool $showNavBar = true,
    ) {
    }

    public static function horizontal(): self
    {
        return new self(LayoutDirection::Horizontal);
    }

    public static function vertical(): self
    {
        return new self(LayoutDirection::Vertical);
    }
}
