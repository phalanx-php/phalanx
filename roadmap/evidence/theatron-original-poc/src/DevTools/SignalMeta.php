<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

final class SignalMeta
{
    public function __construct(
        private(set) string $label,
    ) {
    }
}
