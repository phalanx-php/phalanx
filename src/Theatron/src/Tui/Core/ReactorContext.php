<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Scope\TaskScope;

class ReactorContext
{
    public function __construct(
        private(set) TaskScope $scope,
    ) {
    }
}
