<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Inputs;

final class MouseEvent implements InputEvent
{
    public function __construct(
        private(set) MouseButton $button,
        private(set) MouseAction $action,
        private(set) int $x,
        private(set) int $y,
        private(set) bool $ctrl = false,
        private(set) bool $alt = false,
        private(set) bool $shift = false,
    ) {
    }
}
