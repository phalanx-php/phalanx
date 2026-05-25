<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Boot\AppContext;

interface HasRuntimeContext
{
    public function receiveRuntimeContext(AppContext $context): void;
}
