<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

final class KeyEvent implements InputEvent
{
    public function __construct(
        private(set) Key|string $key,
        private(set) bool $ctrl = false,
        private(set) bool $alt = false,
        private(set) bool $shift = false,
    ) {}

    public function is(Key|string $key): bool
    {
        return $this->key === $key;
    }

    public function isChar(): bool
    {
        return is_string($this->key) && mb_strlen($this->key) === 1;
    }

    public function char(): ?string
    {
        return $this->isChar() ? $this->key : null;
    }
}
