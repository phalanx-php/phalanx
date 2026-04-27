<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

final readonly class ReadinessProbe
{
    private function __construct(
        public ?string $pattern,
    ) {
    }

    public static function outputMatches(string $pattern): self
    {
        return new self($pattern);
    }

    public static function immediate(): self
    {
        return new self(null);
    }

    public static function none(): self
    {
        return new self(null);
    }

    public function isImmediate(): bool
    {
        return $this->pattern === null;
    }

    public function matches(string $line): bool
    {
        return $this->pattern !== null && (bool) preg_match($this->pattern, $line);
    }
}
