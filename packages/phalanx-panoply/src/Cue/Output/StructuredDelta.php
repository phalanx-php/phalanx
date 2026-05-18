<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Output;

use Phalanx\Panoply\Cue;

final class StructuredDelta extends Cue
{
    final public string $type { get => 'cue.output.structured_delta'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $jsonDelta,
        private(set) ?string $path = null,
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'json_delta' => $this->jsonDelta,
            'path'       => $this->path,
        ];
    }
}
