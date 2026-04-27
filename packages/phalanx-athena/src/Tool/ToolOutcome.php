<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tool;

use Phalanx\Task\Scopeable;

final readonly class ToolOutcome
{
    private function __construct(
        public mixed $data,
        public Disposition $disposition,
        public ?Scopeable $next = null,
        public ?string $reason = null,
    ) {}

    public static function data(mixed $data): self
    {
        return new self($data, Disposition::Continue);
    }

    public static function done(mixed $data, string $reason = ''): self
    {
        return new self($data, Disposition::Terminate, reason: $reason);
    }

    public static function handoff(Scopeable $agent, mixed $context = null): self
    {
        return new self($context, Disposition::Delegate, next: $agent);
    }

    public static function escalate(string $reason): self
    {
        return new self(null, Disposition::Escalate, reason: $reason);
    }

    public static function retry(string $hint): self
    {
        return new self(null, Disposition::Retry, reason: $hint);
    }
}
