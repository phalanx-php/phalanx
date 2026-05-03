<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

use OpenSwoole\Atomic\Long;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Runtime\Identity\AegisCounterSid;
use Phalanx\Runtime\Identity\RuntimeEventId;
use Throwable;

final class RuntimeLifecycleEvents
{
    private Long $sequence;

    /** @var list<\Closure(RuntimeLifecycleEvent): void> */
    private array $listeners = [];

    /** @var list<array{event: RuntimeLifecycleEvent, error: Throwable}> */
    private array $listenerErrors = [];

    public function __construct(
        private readonly ManagedSwooleTables $tables,
        private readonly ?RuntimeCounters $counters = null,
    ) {
        $this->sequence = new Long();
    }

    private static function fit(string $value, int $length): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length);
    }

    /** @param \Closure(RuntimeLifecycleEvent): void $listener */
    public function listen(\Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function record(
        RuntimeEventId|string $type,
        string $resourceId = '',
        string $resourceType = '',
        string $scopeId = '',
        string $runId = '',
        string $state = '',
        string $valueA = '',
        string $valueB = '',
        float $expiresAt = 0.0,
    ): RuntimeLifecycleEvent {
        $type = $type instanceof RuntimeEventId ? $type->value() : $type;
        $sequence = (int) $this->sequence->add();
        $event = new RuntimeLifecycleEvent(
            sequence: $sequence,
            type: self::fit($type, 64),
            resourceId: self::fit($resourceId, 32),
            scopeId: self::fit($scopeId, 32),
            runId: self::fit($runId, 32),
            state: self::fit($state, 32),
            occurredAt: microtime(true),
            valueA: self::fit($valueA, 128),
            valueB: self::fit($valueB, 128),
        );

        $key = (string) ($sequence % $this->tables->config->eventRows);
        $existing = $this->tables->resourceEvents->get($key);
        if (is_array($existing) && (int) $existing['sequence'] > 0) {
            $this->counters?->incr(AegisCounterSid::RuntimeEventsDropped);
        }

        $this->tables->resourceEvents->set($key, [
            'sequence' => $event->sequence,
            'event_type' => $event->type,
            'resource_id' => $event->resourceId,
            'resource_type_symbol' => $resourceType === '' ? 0 : crc32($resourceType),
            'scope_id' => $event->scopeId,
            'run_id' => $event->runId,
            'state' => $event->state,
            'occurred_at' => $event->occurredAt,
            'value_a' => $event->valueA,
            'value_b' => $event->valueB,
            'expires_at' => $expiresAt,
        ]);
        $this->tables->mark('resource_events');

        foreach ($this->listeners as $listener) {
            try {
                $listener($event);
            } catch (Throwable $e) {
                if ($e instanceof Cancelled) {
                    throw $e;
                }

                $this->listenerErrors[] = ['event' => $event, 'error' => $e];
            }
        }

        return $event;
    }

    /** @return list<RuntimeLifecycleEvent> */
    public function recent(): array
    {
        $events = [];
        foreach ($this->tables->resourceEvents as $row) {
            if (!is_array($row) || (int) $row['sequence'] < 1) {
                continue;
            }

            $events[] = new RuntimeLifecycleEvent(
                sequence: (int) $row['sequence'],
                type: (string) $row['event_type'],
                resourceId: (string) $row['resource_id'],
                scopeId: (string) $row['scope_id'],
                runId: (string) $row['run_id'],
                state: (string) $row['state'],
                occurredAt: (float) $row['occurred_at'],
                valueA: (string) $row['value_a'],
                valueB: (string) $row['value_b'],
            );
        }

        usort(
            $events,
            static fn(RuntimeLifecycleEvent $a, RuntimeLifecycleEvent $b): int => $a->sequence <=> $b->sequence,
        );

        return $events;
    }

    /** @return list<array{event: RuntimeLifecycleEvent, error: Throwable}> */
    public function listenerErrors(): array
    {
        return $this->listenerErrors;
    }
}
