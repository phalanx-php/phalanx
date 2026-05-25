<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Replay;

use Phalanx\Agora\Harness\ProjectionSet;

interface ProjectionCheckpointReader
{
    public function latestProjectionSet(
        string $sessionId,
    ): ?ProjectionSet;
}
