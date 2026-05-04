<?php

declare(strict_types=1);

namespace Phalanx\Athena\Swarm;

use Phalanx\Scope\Suspendable;
use Phalanx\Styx\Emitter;

/**
 * Contract for a multi-agent coordination bus.
 *
 * `emit` takes a {@see Suspendable} so implementations that perform
 * HTTP/IPC publication can suspend the caller's coroutine through
 * Aegis-managed scope discipline. In-memory implementations may
 * ignore the scope and dispatch synchronously.
 */
interface SwarmBus
{
    /**
     * Emit an event to the shared swarm blackboard.
     */
    public function emit(Suspendable $scope, SwarmEvent $event): void;

    /**
     * Subscribe to a filtered stream of swarm events.
     *
     * @param array{
     *   workspace?: string,
     *   session?: string,
     *   trace_id?: string,
     *   addressed_to?: string|list<string>,
     *   kinds?: SwarmEventKind|list<SwarmEventKind>,
     *   from?: string|list<string>
     * } $filters
     */
    public function subscribe(array $filters = []): Emitter;
}
