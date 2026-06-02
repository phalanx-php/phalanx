<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Participants;

use Phalanx\Theatron\Collab\Events\AgentHarnessEvent;
use Phalanx\Theatron\Collab\WorkContext;

interface Reactor
{
    public function __invoke(AgentHarnessEvent $event, WorkContext $ctx): void;
}
