<?php

declare(strict_types=1);

namespace Phalanx\Athena\Turn;

use Phalanx\Panoply\Runtime;
use Phalanx\Scope\TaskScope;

interface RuntimeFactory
{
    public function __invoke(TaskScope $scope): Runtime;
}
