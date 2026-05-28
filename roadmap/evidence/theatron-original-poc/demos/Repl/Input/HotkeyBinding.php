<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Input;

use Closure;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;

final class HotkeyBinding
{
    public function __construct(
        private(set) Key|string $key,
        private(set) Closure $handler,
        private(set) string $label = '',
        private(set) bool $ctrl = false,
        private(set) bool $alt = false,
        private(set) bool $shift = false,
    ) {
    }

    public function matches(KeyEvent $event): bool
    {
        return $event->is($this->key)
            && $event->ctrl === $this->ctrl
            && $event->alt === $this->alt
            && $event->shift === $this->shift;
    }
}
