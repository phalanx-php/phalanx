<?php

declare(strict_types=1);

namespace Phalanx\Testing;

/**
 * Immutable collection of lens class-strings declared by a ServiceBundle.
 *
 * A bundle returns `TestLens::of(HttpLens::class, ...)` from its
 * `static lens(): TestLens` hook. The codegen plugin and TestApp
 * iterate the contents through {@see all()}.
 */
class TestLens
{
    /** @param list<class-string<Lens>> $lenses */
    private function __construct(private(set) array $lenses)
    {
    }

    /**
     * Build from a variadic list of lens class-strings.
     *
     * @param class-string<Lens> ...$lenses
     */
    public static function of(string ...$lenses): self
    {
        return new self(array_values($lenses));
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** @return list<class-string<Lens>> */
    public function all(): array
    {
        return $this->lenses;
    }

    public function isEmpty(): bool
    {
        return $this->lenses === [];
    }

    public function merge(self $other): self
    {
        if ($other->isEmpty()) {
            return $this;
        }
        if ($this->isEmpty()) {
            return $other;
        }
        return new self(array_values(array_unique([...$this->lenses, ...$other->lenses])));
    }
}
