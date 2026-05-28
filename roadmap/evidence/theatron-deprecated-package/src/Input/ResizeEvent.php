<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

final class ResizeEvent implements InputEvent
{
    public function __construct(
        private(set) int $width,
        private(set) int $height,
    ) {}
}
