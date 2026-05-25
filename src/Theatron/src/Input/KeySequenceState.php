<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

final class KeySequenceState
{
    public const string CONTROL_X_PREFIX = '^X';

    public function __construct(
        private(set) ?string $prefix = null,
    ) {
    }

    public function beginControlX(): self
    {
        return new self(self::CONTROL_X_PREFIX);
    }

    public function clear(): self
    {
        return new self();
    }

    public function isAwaitingControlX(): bool
    {
        return $this->prefix === self::CONTROL_X_PREFIX;
    }
}
