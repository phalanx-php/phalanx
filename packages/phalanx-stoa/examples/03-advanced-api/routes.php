<?php

declare(strict_types=1);

use Acme\StoaDemo\Advanced\Http\AuditMiddleware;
use Acme\StoaDemo\Advanced\Routes\CreateJob;
use Acme\StoaDemo\Advanced\Routes\Health;
use Acme\StoaDemo\Advanced\Routes\MonthlyReport;
use Acme\StoaDemo\Advanced\Routes\WhoAmI;
use Phalanx\Stoa\Auth\Authenticate;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Validator\Param\IntInRange;

$public = RouteGroup::of([
    'GET /health' => Health::class,
    'GET /reports/{year:int}/{month:int}' => MonthlyReport::class,
])->withPatterns([
    'year' => new IntInRange(2024, 2030),
    'month' => new IntInRange(1, 12),
]);

$admin = RouteGroup::of([
    'GET /me' => WhoAmI::class,
    'POST /jobs' => CreateJob::class,
])->wrap(AuditMiddleware::class, Authenticate::class);

return RouteGroup::of([])
    ->mount('/api/v1', $public)
    ->mount('/api/v1/admin', $admin);
