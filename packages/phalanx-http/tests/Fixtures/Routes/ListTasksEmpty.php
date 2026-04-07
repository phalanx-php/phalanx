<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Fixtures\Routes;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Tests\Http\Fixtures\ListTasksQuery;

final class ListTasksEmpty implements Executable
{
    /** @return list<mixed> */
    public function __invoke(ExecutionScope $scope, ListTasksQuery $query): array
    {
        return [];
    }
}
