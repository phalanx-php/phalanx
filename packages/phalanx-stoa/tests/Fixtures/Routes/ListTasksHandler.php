<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Tests\Stoa\Fixtures\ListTasksQuery;

final class ListTasksHandler implements Executable
{
    /** @return array{page: int, limit: int, status: ?string, search: ?string} */
    public function __invoke(ExecutionScope $scope, ListTasksQuery $query): array
    {
        return [
            'page' => $query->page,
            'limit' => $query->limit,
            'status' => $query->status?->value,
            'search' => $query->search,
        ];
    }
}
