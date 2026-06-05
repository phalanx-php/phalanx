<?php

declare(strict_types=1);

use Acme\HttpDemo\Api\Http\AuditMiddleware;
use Acme\HttpDemo\Api\Routes\CreateTask;
use Acme\HttpDemo\Api\Routes\ShowTask;
use Acme\HttpDemo\Api\Routes\WhoAmI;
use Phalanx\Http\Auth\Authenticate;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Validator\Param\IntInRange;

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
