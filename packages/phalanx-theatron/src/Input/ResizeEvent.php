<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

final class ResizeEvent implements InputEvent
{
    public function __construct(
        public private(set) int $width,
        public private(set) int $height,
    ) {}
}
