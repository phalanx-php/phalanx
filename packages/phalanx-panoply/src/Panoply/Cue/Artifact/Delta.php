<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Artifact;

use Phalanx\Panoply\Cue;

final class Delta extends Cue
{
    final public string $type { get => 'cue.artifact.delta'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $artifactId,
        private(set) string $contentDelta,
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
            'artifact_id'   => $this->artifactId,
            'content_delta' => $this->contentDelta,
            'path'          => $this->path,
        ];
    }
}
