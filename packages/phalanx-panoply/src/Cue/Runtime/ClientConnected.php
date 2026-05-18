<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Runtime;

use Phalanx\Panoply\Cue;

final class ClientConnected extends Cue
{
    final public string $type { get => 'cue.runtime.client_connected'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $clientId,
        private(set) string $clientKind,
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'client_id'   => $this->clientId,
            'client_kind' => $this->clientKind,
        ];
    }
}
