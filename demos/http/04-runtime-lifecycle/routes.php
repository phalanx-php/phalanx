<?php

declare(strict_types=1);

use Acme\HttpDemo\Runtime\Routes\AdminScope;
use Acme\HttpDemo\Runtime\Routes\DisconnectProbe;
use Acme\HttpDemo\Runtime\Routes\Events;
use Acme\HttpDemo\Runtime\Routes\Health;
use Acme\HttpDemo\Runtime\Routes\SlowComplete;
use Phalanx\Http\RouteGroup;

return RouteGroup::of([
    'GET /runtime/events' => Events::class,
    'GET /runtime/health' => Health::class,
    'GET /runtime/slow' => SlowComplete::class,
    'GET /runtime/disconnect' => DisconnectProbe::class,
    'GET /runtime/admin/scope' => AdminScope::class,
]);
