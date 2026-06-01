<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Kit;

use Phalanx\Theatron\Tui\Styles\Color;

final class StatusBarSection
{
    public function __construct(
        private(set) string $text,
        private(set) ?Color $color = null,
        private(set) bool $fill = false,
    ) {
    }
}
