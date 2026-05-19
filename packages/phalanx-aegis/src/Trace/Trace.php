<?php

declare(strict_types=1);

namespace Phalanx\Trace;

use Closure;
use ReflectionClass;

final class Trace
{
    private const int RING_SIZE = 10_000;

    /** @var array<int, TraceEvent> */
    private array $slots;

    private int $cursor = 0;

    private int $count = 0;

    /** @var ReflectionClass<TraceEvent> */
    private ReflectionClass $reflector;

    public function __construct()
    {
        $this->reflector = new ReflectionClass(TraceEvent::class);
        $this->slots = [];

        // Pre-allocate all ring slots as uninitialized lazy ghosts. After warmup
        // every subsequent log() recycles in place — zero allocation per call.
        for ($i = 0; $i < self::RING_SIZE; $i++) {
            $this->slots[] = $this->reflector->newLazyGhost(static function (): void {
            });
        }
    }

    /** @param array<string, mixed> $attrs */
    public function log(TraceType $type, string $name, array $attrs = []): void
    {
        $timestamp = microtime(true);

        // Bind to TraceEvent so the initializer can write private(set) properties.
        $initializer = Closure::bind(
            static function (TraceEvent $e) use ($type, $name, $timestamp, $attrs): void {
                $e->type      = $type;
                $e->name      = $name;
                $e->timestamp = $timestamp;
                $e->attrs     = $attrs;
            },
            null,
            TraceEvent::class,
        );

        $slot = $this->slots[$this->cursor];

        // resetAsLazyGhost requires the object to already be in an initialized
        // state. Newly constructed ghosts are uninitialized — mark them first.
        if ($this->reflector->isUninitializedLazyObject($slot)) {
            $this->reflector->markLazyObjectAsInitialized($slot);
        }

        $this->reflector->resetAsLazyGhost($slot, $initializer);
        $this->reflector->initializeLazyObject($slot);

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
        // Return each initialized slot to an empty lazy ghost so the ring stays
        // pre-allocated — no deallocation/reallocation on the next warm cycle.
        $noop = static function (): void {
        };

        for ($i = 0; $i < self::RING_SIZE; $i++) {
            $slot = $this->slots[$i];

            if (!$this->reflector->isUninitializedLazyObject($slot)) {
                $this->reflector->resetAsLazyGhost($slot, $noop);
            }
        }

        $this->cursor = 0;
        $this->count  = 0;
    }
}
