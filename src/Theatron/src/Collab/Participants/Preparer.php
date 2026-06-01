<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Participants;

use Phalanx\Theatron\Collab\WorkContext;

interface Preparer
{
    public function __invoke(WorkContext $ctx): void;
}
