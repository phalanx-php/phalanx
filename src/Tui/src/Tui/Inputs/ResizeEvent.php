<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Inputs;

final class ResizeEvent implements InputEvent
{
    public function __construct(
        private(set) int $width,
        private(set) int $height,
    ) {
    }
}
