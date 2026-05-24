<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Kit;

use Phalanx\Theatron\Style\Color;

final class StatusBarSection
{
    public function __construct(
        private(set) string $text,
        private(set) ?Color $color = null,
        private(set) bool $fill = false,
    ) {
    }
}
