<?php

declare(strict_types=1);

namespace Phalanx\Boot;

final readonly class BootHarness
{
    /** @param list<BootRequirement> $requirements */
    private function __construct(public array $requirements)
    {
    }

    public static function of(BootRequirement ...$requirements): self
    {
        return new self(array_values($requirements));
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** @return list<BootRequirement> */
    public function all(): array
    {
        return $this->requirements;
    }

    public function isEmpty(): bool
    {
        return $this->requirements === [];
    }

    public function merge(self $other): self
    {
        if ($other->isEmpty()) {
            return $this;
        }
        if ($this->isEmpty()) {
            return $other;
        }
        return new self([...$this->requirements, ...$other->requirements]);
    }
}
