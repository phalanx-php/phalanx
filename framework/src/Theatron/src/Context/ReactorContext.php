<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Context;

use Phalanx\Scope\TaskScope;

class ReactorContext
{
    public function __construct(
        private(set) TaskScope $scope,
    ) {
    }
}
