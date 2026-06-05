<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Drawing;

use Phalanx\Tui\Tui\Styles\Style;

final class BufferUpdate
{
    public function __construct(
        private(set) int $x,
        private(set) int $y,
        private(set) string $char,
        private(set) Style $style,
    ) {
    }
}
