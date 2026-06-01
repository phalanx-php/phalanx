<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Reactive;

final class SignalMeta
{
    public function __construct(
        private(set) string $label,
    ) {
    }
}
