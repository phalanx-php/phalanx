<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Output;

use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\StopReason;

class TokenStop extends Cue
{
    final public string $type { get => 'cue.output.token_stop'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) StopReason $reason,
        private(set) Channel $channel = Channel::Message,
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'reason'  => $this->reason->value,
            'channel' => $this->channel->value,
        ];
    }
}
