<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Participants;

use Phalanx\Theatron\Collab\Reviews\ReviewVerdict;
use Phalanx\Theatron\Collab\WorkContext;

interface Reviewer
{
    public function __invoke(WorkContext $ctx): ReviewVerdict;
}
