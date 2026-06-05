<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Participants;

use Phalanx\Tui\Collab\Plans\WorkPlanItem;
use Phalanx\Tui\Collab\Plans\WorkResult;
use Phalanx\Tui\Collab\WorkContext;

interface AgentParticipant
{
    public function __invoke(WorkPlanItem $item, WorkContext $ctx): WorkResult;

    public function supports(WorkPlanItem $item, WorkContext $ctx): bool;
}
