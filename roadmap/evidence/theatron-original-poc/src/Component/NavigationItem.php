<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Component;

final class NavigationItem
{
    public function __construct(
        private(set) string $label,
        private(set) string $focusName,
    ) {
    }
}
