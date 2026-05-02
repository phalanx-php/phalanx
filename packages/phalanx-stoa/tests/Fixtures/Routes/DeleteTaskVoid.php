<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

final class DeleteTaskVoid implements Executable
{
    public function __invoke(ExecutionScope $scope): void
    {
    }
}
