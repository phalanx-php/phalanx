<?php

declare(strict_types=1);

namespace Phalanx\Tui\Inputs;

final class PasteEvent implements InputEvent
{
    public function __construct(
        private(set) string $content,
    ) {
    }
}
