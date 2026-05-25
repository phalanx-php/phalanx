<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness;

interface EventReader
{
    /** @return iterable<HarnessEvent> */
    public function readAfter(
        string $sessionId,
        int $sequence,
    ): iterable;
}
