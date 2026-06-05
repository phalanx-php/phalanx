<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Participants;

use Phalanx\Tui\Collab\Events\AgentHarnessEvent;
use Phalanx\Tui\Collab\WorkContext;

interface Reactor
{
    public function __invoke(AgentHarnessEvent $event, WorkContext $ctx): void;
}
