<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Output;

use Phalanx\Panoply\Cue;

final class TokenDelta extends Cue
{
    final public string $type { get => 'cue.output.token_delta'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $text,
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
            'text' => $this->text,
            'channel' => $this->channel->value,
        ];
    }
}
