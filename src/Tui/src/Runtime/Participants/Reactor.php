<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Participants;

use Phalanx\Tui\Runtime\Events\Event;
use Phalanx\Tui\Runtime\WorkContext;

interface Reactor
{
    public function __invoke(WorkContext $ctx, Event $event): void;
}
