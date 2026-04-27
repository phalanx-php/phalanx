<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

final class MouseEvent implements InputEvent
{
    public function __construct(
        public private(set) MouseButton $button,
        public private(set) MouseAction $action,
        public private(set) int $x,
        public private(set) int $y,
        public private(set) bool $ctrl = false,
        public private(set) bool $alt = false,
        public private(set) bool $shift = false,
    ) {}
}
