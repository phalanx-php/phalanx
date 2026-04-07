<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Fixtures\Routes;

use Phalanx\ExecutionScope;
use Phalanx\SelfDescribed;
use Phalanx\Tagged;
use Phalanx\Task\Executable;
use Phalanx\Tests\Http\Fixtures\ListTasksQuery;

final class DescribedListTasks implements Executable, SelfDescribed, Tagged
{
    public string $description { get => 'List all tasks with filtering'; }

    /** @var list<string> */
    public array $tags { get => ['tasks']; }

    /** @return list<mixed> */
    public function __invoke(ExecutionScope $scope, ListTasksQuery $query): array
    {
        return [];
    }
}
