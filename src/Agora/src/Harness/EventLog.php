<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness;

interface EventLog
{
    public function append(
        HarnessEvent $event,
    ): HarnessEvent;

    /** @return iterable<HarnessEvent> */
    public function readAfter(
        string $sessionId,
        int $sequence,
    ): iterable;
}
