<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

final class InputModeSlice
{
    public function __construct(
        private(set) InputMode $mode = InputMode::Normal,
        private(set) ?string $focusTarget = null,
    ) {
    }
}
