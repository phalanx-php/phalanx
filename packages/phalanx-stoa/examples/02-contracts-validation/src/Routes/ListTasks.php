<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Contracts\Routes;

use Acme\StoaDemo\Contracts\Input\ListTasksQuery;
use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;

final class ListTasks implements Scopeable
{
    /** @return array{owner: string, limit: int, tasks: list<array{id: int, title: string}>} */
    public function __invoke(RequestScope $scope, ListTasksQuery $query): array
    {
        return [
            'owner' => $query->owner,
            'limit' => $query->limit,
            'tasks' => array_slice([
                ['id' => 1, 'title' => 'Design route contracts'],
                ['id' => 2, 'title' => 'Validate request DTOs'],
                ['id' => 3, 'title' => 'Ship OpenSwoole demos'],
            ], 0, $query->limit),
        ];
    }
}
