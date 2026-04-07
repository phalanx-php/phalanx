<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Fixtures\Routes;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Tests\Http\Fixtures\TaskResource;

final class ShowTask implements Executable
{
    public function __invoke(ExecutionScope $scope): TaskResource
    {
        throw new \RuntimeException('not called');
    }
}
