<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

final class SignalSnapshot
{
    public function __construct(
        private(set) string $label,
        private(set) string $value,
        private(set) int $subscriberCount,
        private(set) bool $isDisposed,
    ) {
    }
}
