<?php

declare(strict_types=1);

use Acme\StoaDemo\Runtime\Routes\AdminScope;
use Acme\StoaDemo\Runtime\Routes\DisconnectProbe;
use Acme\StoaDemo\Runtime\Routes\Events;
use Acme\StoaDemo\Runtime\Routes\Health;
use Acme\StoaDemo\Runtime\Routes\SlowComplete;
use Phalanx\Stoa\RouteGroup;

return RouteGroup::of([
    'GET /runtime/events' => Events::class,
    'GET /runtime/health' => Health::class,
    'GET /runtime/slow' => SlowComplete::class,
    'GET /runtime/disconnect' => DisconnectProbe::class,
    'GET /runtime/admin/scope' => AdminScope::class,
]);
