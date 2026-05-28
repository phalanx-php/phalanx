<?php

declare(strict_types=1);

namespace Phalanx\Trace;

final class Trace
{
    private const int RING_SIZE = 10_000;

    /** @var array<int, TraceEvent> */
    private array $slots = [];

    private int $cursor = 0;

    private int $count = 0;

    /** @param array<string, mixed> $attrs */
    public function log(TraceType $type, string $name, array $attrs = []): void
    {
        $this->slots[$this->cursor] = new TraceEvent($type, $name, microtime(true), $attrs);

        if ($this->count < self::RING_SIZE) {
            $this->count++;
        }

        $this->cursor = ($this->cursor + 1) % self::RING_SIZE;
    }

    /**
     * Returns clones of the ring slots so that callers hold a stable snapshot
     * independent of future log() calls that recycle slot identities in place.
     *
     * @return list<TraceEvent>
     */
    public function events(): array
    {
        if ($this->count === 0) {
            return [];
        }

        if ($this->count < self::RING_SIZE) {
            return array_map(
                static fn(TraceEvent $e): TraceEvent => clone $e,
                array_slice($this->slots, 0, $this->count),
            );
        }

        return array_map(
            static fn(TraceEvent $e): TraceEvent => clone $e,
            array_merge(
                array_slice($this->slots, $this->cursor),
                array_slice($this->slots, 0, $this->cursor),
            ),
        );
    }

    public function clear(): void
    {
        $this->slots = [];
        $this->cursor = 0;
        $this->count = 0;
    }
}
