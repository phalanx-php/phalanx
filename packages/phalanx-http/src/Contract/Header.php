<?php

declare(strict_types=1);

namespace Phalanx\Http\Contract;

/**
 * Required HTTP header descriptor for handler RequiresHeaders capability.
 *
 * Pattern is an optional regex (without delimiters) that the header value
 * must match. Validation runs at dispatch time before the handler is invoked.
 */
final readonly class Header
{
    public function __construct(
        public string $name,
        public ?string $pattern = null,
        public bool $required = true,
    ) {}

    public static function required(string $name, ?string $pattern = null): self
    {
        return new self($name, $pattern, required: true);
    }

    public static function optional(string $name, ?string $pattern = null): self
    {
        return new self($name, $pattern, required: false);
    }
}
