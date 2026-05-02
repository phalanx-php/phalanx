<?php

declare(strict_types=1);

use Acme\StoaDemo\Contracts\Routes\CreateTask;
use Acme\StoaDemo\Contracts\Routes\ListTasks;
use Acme\StoaDemo\Contracts\Routes\ShowTask;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Validator\Param\IntInRange;

return RouteGroup::of([
    'GET /tasks' => ListTasks::class,
    'POST /tasks' => CreateTask::class,
    'GET /tasks/{id:int}' => ShowTask::class,
])->withPatterns([
    'id' => new IntInRange(1, 999),
]);
