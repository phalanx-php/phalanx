<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Harness\Boundary;

use Phalanx\Scope\TaskScope;

interface Inlet
{
    public string $name { get; }

    public function __invoke(TaskScope $scope, InletChannel $incoming): void;
}
