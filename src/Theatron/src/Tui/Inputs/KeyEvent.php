<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Inputs;

final class KeyEvent implements InputEvent
{
    public function __construct(
        private(set) Key|string $key,
        private(set) bool $ctrl = false,
        private(set) bool $alt = false,
        private(set) bool $shift = false,
    ) {
    }

    public function is(Key|string $key): bool
    {
        return $this->key === $key;
    }

    public function isChar(): bool
    {
        if ($this->key === Key::Space) {
            return true;
        }

        return is_string($this->key) && mb_strlen($this->key) === 1;
    }

    public function char(): ?string
    {
        if ($this->key === Key::Space) {
            return ' ';
        }

        if ($this->isChar() && is_string($this->key)) {
            return $this->key;
        }

        return null;
    }
}
