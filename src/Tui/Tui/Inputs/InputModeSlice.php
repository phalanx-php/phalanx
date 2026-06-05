<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Inputs;

final class InputModeSlice
{
    public function __construct(
        private(set) InputMode $mode = InputMode::Normal,
        private(set) ?string $focusTarget = null,
    ) {
    }
}
