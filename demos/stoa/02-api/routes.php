<?php

declare(strict_types=1);

use Acme\StoaDemo\Api\Http\AuditMiddleware;
use Acme\StoaDemo\Api\Routes\CreateTask;
use Acme\StoaDemo\Api\Routes\ShowTask;
use Acme\StoaDemo\Api\Routes\WhoAmI;
use Phalanx\Stoa\Auth\Authenticate;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Validator\Param\IntInRange;

$public = RouteGroup::of([
    'GET /tasks/{id:int}' => ShowTask::class,
])->withPatterns([
    'id' => new IntInRange(1, 999),
]);

$authed = RouteGroup::of([
    'POST /tasks' => CreateTask::class,
    'GET /me' => WhoAmI::class,
])->wrap(AuditMiddleware::class, Authenticate::class);

return RouteGroup::of([])
    ->mount('/api/v1', $public)
    ->mount('/api/v1', $authed);
