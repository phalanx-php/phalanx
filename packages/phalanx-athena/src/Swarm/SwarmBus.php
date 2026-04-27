<?php

declare(strict_types=1);

namespace Phalanx\Athena\Swarm;

use Phalanx\Styx\Emitter;

/**
 * Contract for a multi-agent coordination bus.
 */
interface SwarmBus
{
    /**
     * Emit an event to the shared swarm blackboard.
     */
    public function emit(SwarmEvent $event): void;

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
