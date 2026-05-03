<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

use OpenSwoole\Atomic\Long;
use Symfony\Component\Uid\Ulid;

final class RuntimeIds
{
    private Long $internal;

    public function __construct(private readonly RuntimeCounters $counters)
    {
        $this->internal = new Long();
    }

    public function next(string $name): int
    {
        return $this->counters->incr('ids.' . $name);
    }

    public function nextRuntime(string $prefix): string
    {
        return $prefix . '-' . str_pad((string) $this->internal->add(), 6, '0', STR_PAD_LEFT);
    }

    public function ulid(): string
    {
        return (string) new Ulid();
    }
}
