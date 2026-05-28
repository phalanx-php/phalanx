<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

use Phalanx\Theatron\Store\Slice;

final class InputModeSlice implements Slice
{
    public string $key { get => 'theatron.input.mode'; }

    public function __construct(
        private(set) InputMode $mode = InputMode::Normal,
        private(set) ?string $focusTarget = null,
    ) {
    }
}
