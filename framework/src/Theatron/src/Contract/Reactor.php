<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Context\ReactorContext;

interface Reactor
{
    public function __invoke(ReactorContext $ctx): void;
}
