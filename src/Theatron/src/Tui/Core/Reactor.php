<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Core\ReactorContext;

interface Reactor
{
    public function __invoke(ReactorContext $ctx): void;
}
