<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Fixtures\Routes;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Stoa\Tests\Fixtures\TaskResource;

final class ShowTask implements Executable
{
    public function __invoke(ExecutionScope $scope): TaskResource
    {
        throw new \RuntimeException('not called');
    }
}
