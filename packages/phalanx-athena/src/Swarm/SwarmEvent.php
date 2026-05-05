<?php

declare(strict_types=1);

namespace Phalanx\Athena\Swarm;

use Phalanx\Athena\Event\AgentEvent;

/**
 * Domain model for a swarm coordination event.
 *
 * This object represents the logical swarm fields, which may be serialized
 * differently depending on the transport (e.g., nested in Daemon8's data field).
 */
final readonly class SwarmEvent
{
    /**
     * @param string|list<string>|null $addressedTo
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $from,
        public SwarmEventKind $kind,
        public string $workspace,
        public string $session,
        public array $payload = [],
        public string|array|null $addressedTo = null,
        public ?string $traceId = null,
        public ?string $causationId = null,
        public ?string $eventId = null,
        public ?AgentEvent $inner = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => 'phalanx.swarm.v1',
            'event_id' => $this->eventId ?? uniqid('ev_'),
            'trace_id' => $this->traceId,
            'causation_id' => $this->causationId,
            'workspace' => $this->workspace,
            'session' => $this->session,
            'from' => $this->from,
            'addressed_to' => $this->addressedTo,
            'kind' => $this->kind->value,
            'payload' => $this->payload,
        ];
    }
}
