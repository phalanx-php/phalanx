<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Boot\AppContext;

interface HasRuntimeContext
{
    public function receiveRuntimeContext(AppContext $context): void;
}
