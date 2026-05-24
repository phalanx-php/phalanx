<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Fixtures\Routes;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Stoa\Tests\Fixtures\ListTasksQuery;

final class ListTasksEmpty implements Executable
{
    /** @return list<mixed> */
    public function __invoke(ExecutionScope $scope, ListTasksQuery $query): array
    {
        return [];
    }
}
