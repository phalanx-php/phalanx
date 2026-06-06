<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Participants;

use Phalanx\Tui\Runtime\Reviews\ReviewVerdict;
use Phalanx\Tui\Runtime\WorkContext;

interface Reviewer
{
    public function __invoke(WorkContext $ctx): ReviewVerdict;
}
