<?php

declare(strict_types=1);

namespace Phalanx\Config;

use JsonSerializable;
use SensitiveParameter;
use Stringable;

final class Secret implements JsonSerializable, Stringable
{
    /** Computed from the raw secret value so missing secrets stay non-null. */
    public bool $configured {
        get => $this->value !== '';
    }

    public function __construct(
        #[SensitiveParameter]
        private string $value,
    ) {
    }

    public static function empty(): self
    {
        return new self('');
    }

    public function __toString(): string
    {
        return '[redacted]';
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    public function reveal(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return '[redacted]';
    }
}
