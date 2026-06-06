<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Participants;

use Phalanx\Tui\Runtime\Plans\WorkPlanItem;
use Phalanx\Tui\Runtime\Plans\WorkResult;
use Phalanx\Tui\Runtime\WorkContext;

interface AgentParticipant
{
    public function __invoke(WorkContext $ctx, WorkPlanItem $item): WorkResult;

    public function supports(WorkContext $ctx, WorkPlanItem $item): bool;
}
