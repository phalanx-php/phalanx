<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Participants;

use Phalanx\Tui\Runtime\WorkContext;

interface Preparer
{
    public function __invoke(WorkContext $ctx): void;
}
