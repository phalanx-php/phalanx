<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Fixtures\Routes;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Http\Tests\Fixtures\ListTasksQuery;

final class ListTasksEmpty implements Executable
{
    /** @return list<mixed> */
    public function __invoke(ExecutionScope $scope, ListTasksQuery $query): array
    {
        return [];
    }
}
