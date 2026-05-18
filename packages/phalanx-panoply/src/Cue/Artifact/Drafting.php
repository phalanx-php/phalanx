<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue\Artifact;

use Phalanx\Panoply\Artifact\Kind;
use Phalanx\Panoply\Cue;

final class Drafting extends Cue
{
    final public string $type { get => 'cue.artifact.drafting'; }

    public function __construct(
        string $id,
        int $sequence,
        string $activityId,
        ?string $invocationId,
        ?string $agentId,
        \DateTimeImmutable $at,
        private(set) string $artifactId,
        private(set) Kind $kind,
        private(set) ?string $title = null,
    ) {
        parent::__construct($id, $sequence, $activityId, $invocationId, $agentId, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'artifact_id' => $this->artifactId,
            'kind' => $this->kind->value,
            'title' => $this->title,
        ];
    }
}
