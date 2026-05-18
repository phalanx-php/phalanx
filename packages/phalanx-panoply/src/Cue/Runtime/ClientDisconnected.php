<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Runtime;

use Phalanx\Panoply\Cue;

final class ClientDisconnected extends Cue
{
    final public string $type { get => 'cue.runtime.client_disconnected'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $clientId,
        private(set) ?string $reason = null,
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'client_id' => $this->clientId,
            'reason'    => $this->reason,
        ];
    }
}
