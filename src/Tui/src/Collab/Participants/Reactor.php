<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Participants;

use Phalanx\Tui\Collab\Events\Event;
use Phalanx\Tui\Collab\WorkContext;

interface Reactor
{
    public function __invoke(Event $event, WorkContext $ctx): void;
}
