<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness;

interface EventLog extends EventReader
{
    public function append(
        HarnessEvent $event,
    ): HarnessEvent;
}
