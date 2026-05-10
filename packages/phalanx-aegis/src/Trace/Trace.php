<?php

declare(strict_types=1);

namespace Phalanx\Trace;

class Trace
{
    private const MAX_EVENTS = 10_000;

    /** @var list<TraceEvent> */
    private array $events = [];

    /** @param array<string, mixed> $attrs */
    public function log(TraceType $type, string $name, array $attrs = []): void
    {
        $this->events[] = new TraceEvent($type, $name, microtime(true), $attrs);

        if (count($this->events) > self::MAX_EVENTS) {
            $this->events = array_slice($this->events, (int) (self::MAX_EVENTS * 0.25));
        }
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
