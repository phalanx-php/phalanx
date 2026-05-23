<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation;

use Phalanx\Panoply\Hash\Canonicalizable;

/**
 * Immutable parser configuration passed to {@see Parser::parse()}. Three
 * named factories cover the common cases; the canonical form feeds into
 * config-hash stability across runs.
 *
 * Final because subclassing would alter {@see self::toCanonical()} and
 * break parser config hash stability.
 */
final class Options implements Canonicalizable
{
    private function __construct(
        private(set) StrictMode $strictMode,
    ) {
    }

    public static function default(): self
    {
        return new self(strictMode: StrictMode::Loud);
    }

    public static function lenient(): self
    {
        return new self(strictMode: StrictMode::Lenient);
    }

    public static function silent(): self
    {
        return new self(strictMode: StrictMode::Silent);
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        return [
            'strict_mode' => $this->strictMode->value,
        ];
    }
}
