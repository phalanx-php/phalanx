<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

use OpenSwoole\Exception as OpenSwooleException;
use Phalanx\Runtime\Identity\AegisEventSid;
use Phalanx\Runtime\Identity\RuntimeEventId;

final readonly class ManagedResourceTransitionGate
{
    public function __construct(
        private ManagedSwooleTables $tables,
        private RuntimeSymbols $symbols,
        private RuntimeCounters $counters,
        private RuntimeLifecycleEvents $events,
        private ManagedResourceTransitionLocks $locks,
    ) {
    }

    public function open(
        string $id,
        string $type,
        ?string $parentResourceId = null,
        ?string $ownerScopeId = null,
        ?string $ownerRunId = null,
        ManagedResourceState $state = ManagedResourceState::Opening,
        int $workerId = -1,
        int $coroutineId = -1,
    ): ManagedResourceHandle {
        $lock = $this->locks->acquire($id);
        $event = null;

        try {
            $now = microtime(true);
            $typeSymbol = $this->symbols->idFor('resource.type', $type);
            try {
                $ok = $this->tables->resources->set($id, [
                    'type_symbol' => $typeSymbol,
                    'parent_resource_id' => $parentResourceId ?? '',
                    'owner_scope_id' => $ownerScopeId ?? '',
                    'owner_run_id' => $ownerRunId ?? '',
                    'state' => $state->value,
                    'generation' => 1,
                    'worker_id' => $workerId,
                    'coroutine_id' => $coroutineId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'terminal_at' => $state->isTerminal() ? $now : 0.0,
                    'expires_at' => 0.0,
                    'outcome' => '',
                    'reason_symbol' => 0,
                    'cancel_requested' => 0,
                ]);
            } catch (OpenSwooleException) {
                throw RuntimeMemoryCapacityExceeded::forTable('resources', $id);
            }

            if (!$ok) {
                throw RuntimeMemoryCapacityExceeded::forTable('resources', $id);
            }

            $this->tables->mark('resources');
            $this->counters->tryIncr("aegis.resources.{$type}.opened");
            $event = $this->events->record(
                AegisEventSid::ResourceOpened,
                resourceId: $id,
                resourceType: $type,
                scopeId: $ownerScopeId ?? '',
                runId: $ownerRunId ?? '',
                state: $state->value,
                valueA: $type,
                dispatchListeners: false,
            );
        } finally {
            $lock->release();
        }

        $this->events->dispatch($event);

        return new ManagedResourceHandle($id, $type, 1);
    }

    public function transition(
        ManagedResourceHandle|string $resource,
        ManagedResourceState $to,
        string $outcome = '',
        string $reason = '',
        RuntimeEventId|string $eventType = '',
    ): ManagedResourceHandle {
        $id = $resource instanceof ManagedResourceHandle ? $resource->id : $resource;
        $lock = $this->locks->acquire($id);
        $event = null;
        try {
            $row = $this->tables->resources->get($id);
            if (!is_array($row)) {
                throw new ManagedResourceException("managed resource '{$id}' does not exist");
            }

            $from = ManagedResourceState::from((string) $row['state']);
            $generation = (int) $row['generation'];
            if ($resource instanceof ManagedResourceHandle && $resource->generation !== $generation) {
                throw StaleManagedResourceHandle::forGeneration($id, $resource->generation, $generation);
            }

            $type = $this->symbols->valueFor((int) $row['type_symbol'], 'unknown');
            if ($from->isTerminal()) {
                if ($from !== $to) {
                    $event = $this->events->record(
                        AegisEventSid::ResourceLateTransition,
                        resourceId: $id,
                        resourceType: $type,
                        scopeId: (string) $row['owner_scope_id'],
                        runId: (string) $row['owner_run_id'],
                        state: $from->value,
                        valueA: $to->value,
                        valueB: $reason,
                        dispatchListeners: false,
                    );
                }

                return new ManagedResourceHandle($id, $type, $generation);
            }

            if (!self::canTransition($from, $to)) {
                throw InvalidManagedResourceTransition::forState($id, $from, $to);
            }

            $now = microtime(true);
            $row['state'] = $to->value;
            $row['generation'] = $generation + 1;
            $row['updated_at'] = $now;
            $row['terminal_at'] = $to->isTerminal() ? $now : 0.0;
            $row['outcome'] = self::fit($outcome, 32);
            $row['reason_symbol'] = $this->symbols->idFor('resource.reason', $reason);
            $cancelRequested = $to === ManagedResourceState::Aborting || $to === ManagedResourceState::Aborted;
            $row['cancel_requested'] = $cancelRequested ? 1 : 0;

            try {
                $ok = $this->tables->resources->set($id, $row);
            } catch (OpenSwooleException) {
                throw RuntimeMemoryCapacityExceeded::forTable('resources', $id);
            }

            if (!$ok) {
                throw RuntimeMemoryCapacityExceeded::forTable('resources', $id);
            }

            $event = $this->events->record(
                $eventType === '' ? 'resource.' . $to->value : $eventType,
                resourceId: $id,
                resourceType: $type,
                scopeId: (string) $row['owner_scope_id'],
                runId: (string) $row['owner_run_id'],
                state: $to->value,
                valueA: $outcome,
                valueB: $reason,
                dispatchListeners: false,
            );
            $this->counters->tryIncr("aegis.resources.{$type}.{$to->value}");

            return new ManagedResourceHandle($id, $type, $generation + 1);
        } finally {
            $lock->release();
            if ($event !== null) {
                $this->events->dispatch($event);
            }
        }
    }

    private static function canTransition(ManagedResourceState $from, ManagedResourceState $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return match ($from) {
            ManagedResourceState::Opening => in_array(
                $to,
                [
                    ManagedResourceState::Active,
                    ManagedResourceState::Closing,
                    ManagedResourceState::Closed,
                    ManagedResourceState::Aborting,
                    ManagedResourceState::Aborted,
                    ManagedResourceState::Failing,
                    ManagedResourceState::Failed,
                ],
                true,
            ),
            ManagedResourceState::Active => in_array(
                $to,
                [
                    ManagedResourceState::Closing,
                    ManagedResourceState::Closed,
                    ManagedResourceState::Aborting,
                    ManagedResourceState::Aborted,
                    ManagedResourceState::Failing,
                    ManagedResourceState::Failed,
                ],
                true,
            ),
            ManagedResourceState::Closing => in_array(
                $to,
                [
                    ManagedResourceState::Closed,
                    ManagedResourceState::Aborting,
                    ManagedResourceState::Aborted,
                    ManagedResourceState::Failing,
                    ManagedResourceState::Failed,
                ],
                true,
            ),
            ManagedResourceState::Aborting => $to === ManagedResourceState::Aborted
                || $to === ManagedResourceState::Failing
                || $to === ManagedResourceState::Failed,
            ManagedResourceState::Failing => $to === ManagedResourceState::Failed,
            ManagedResourceState::Closed,
            ManagedResourceState::Aborted,
            ManagedResourceState::Failed => false,
        };
    }

    private static function fit(string $value, int $length): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length);
    }
}
