<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Participants;

use Phalanx\Theatron\Collab\Plans\WorkPlanItem;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\WorkContext;

interface Collaborator
{
    public function __invoke(WorkPlanItem $item, WorkContext $ctx): WorkResult;

    public function supports(WorkPlanItem $item, WorkContext $ctx): bool;
}
