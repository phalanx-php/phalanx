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
        $timestamp = microtime(true);

        if ($this->count < self::RING_SIZE) {
            $this->slots[] = new TraceEvent($type, $name, $timestamp, $attrs);
            $this->count++;
        } else {
            $this->slots[$this->cursor] = new TraceEvent($type, $name, $timestamp, $attrs);
        }

        $this->cursor = ($this->cursor + 1) % self::RING_SIZE;
    }

    /** @return list<TraceEvent> */
    public function events(): array
    {
        if ($this->count === 0) {
            return [];
        }

        if ($this->count < self::RING_SIZE) {
            return array_slice($this->slots, 0, $this->count);
        }

        return array_merge(
            array_slice($this->slots, $this->cursor),
            array_slice($this->slots, 0, $this->cursor),
        );
    }

    public function clear(): void
    {
        $this->slots = [];
        $this->cursor = 0;
        $this->count = 0;
    }
}
