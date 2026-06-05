<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Participants;

use Phalanx\Tui\Collab\Reviews\ReviewVerdict;
use Phalanx\Tui\Collab\WorkContext;

interface Reviewer
{
    public function __invoke(WorkContext $ctx): ReviewVerdict;
}
