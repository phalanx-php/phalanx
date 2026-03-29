<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Input;

final class KeyEvent implements InputEvent
{
    public function __construct(
        public private(set) Key|string $key,
        public private(set) bool $ctrl = false,
        public private(set) bool $alt = false,
        public private(set) bool $shift = false,
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
