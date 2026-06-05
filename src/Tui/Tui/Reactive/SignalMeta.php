<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Reactive;

final class SignalMeta
{
    public function __construct(
        private(set) string $label,
    ) {
    }
}
