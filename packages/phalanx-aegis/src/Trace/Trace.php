<?php

declare(strict_types=1);

namespace Phalanx\Trace;

class Trace
{
    /** @var list<TraceEvent> */
    private array $events = [];

    /** @param array<string, mixed> $attrs */
    public function log(TraceType $type, string $name, array $attrs = []): void
    {
        $this->events[] = new TraceEvent($type, $name, microtime(true), $attrs);
    }

    /** @return list<TraceEvent> */
    public function events(): array
    {
        return $this->events;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
